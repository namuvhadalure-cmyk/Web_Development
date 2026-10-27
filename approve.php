<?php
// approve.php — Owner sets crop price, approves a crop, and records payment
// Requires Owner session. Uses atomic database transactions and prepared statements.

include('auth_owner.php');
include('db.php');

// Read input from POST or GET
$id     = (int)($_POST['crop_id'] ?? $_GET['id'] ?? 0);
$action = trim($_POST['action'] ?? ($_SERVER['REQUEST_METHOD'] === 'POST' ? 'approve_pay' : 'get_approve'));

if ($id <= 0) {
    $_SESSION['msg_text']  = 'Invalid crop request.';
    $_SESSION['msg_class'] = 'error';
    header('Location: owner_dashboard.php#approvals');
    exit;
}

// Fetch crop record with farmer details
$stmt = $conn->prepare(
    'SELECT c.*, f.name AS farmer_name, f.mobile AS farmer_mobile
     FROM crops c
     JOIN farmers f ON c.farmer_id = f.id
     WHERE c.id = ? LIMIT 1'
);
$stmt->bind_param('i', $id);
$stmt->execute();
$crop = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$crop) {
    $_SESSION['msg_text']  = 'Crop record not found.';
    $_SESSION['msg_class'] = 'error';
    header('Location: owner_dashboard.php#approvals');
    exit;
}

$farmer_id = (int)$crop['farmer_id'];
$crop_name = $crop['crop_name'];

// ─────────────────────────────────────────────────────────────────
// ACTION 1: SET PRICE ONLY
// ─────────────────────────────────────────────────────────────────
if ($action === 'set_price') {
    $price    = (float)($_POST['price'] ?? 0);
    $quantity = (float)($_POST['quantity'] ?? $crop['quantity']);

    if ($price <= 0) {
        $_SESSION['msg_text']  = "Price per quintal must be greater than zero for '{$crop_name}'.";
        $_SESSION['msg_class'] = 'error';
        header('Location: owner_dashboard.php#approvals');
        exit;
    }
    if ($quantity <= 0) {
        $_SESSION['msg_text']  = "Quantity must be greater than zero for '{$crop_name}'.";
        $_SESSION['msg_class'] = 'error';
        header('Location: owner_dashboard.php#approvals');
        exit;
    }

    // Update crop price and quantity in crops table
    $upd = $conn->prepare('UPDATE crops SET price = ?, quantity = ? WHERE id = ?');
    $upd->bind_param('ddi', $price, $quantity, $id);
    $upd->execute();
    $upd->close();

    // Also record this price in crop_prices for market reference
    $cp = $conn->prepare('INSERT INTO crop_prices (crop_name, price) VALUES (?, ?)');
    $cp->bind_param('sd', $crop_name, $price);
    $cp->execute();
    $cp->close();

    $est_total = $price * $quantity;
    $_SESSION['msg_text']  = "Price set successfully! Crop: '{$crop_name}' | Quantity: " . number_format($quantity, 2) . " Quintals | Price: ₹" . number_format($price, 2) . "/Q | Estimated Total: ₹" . number_format($est_total, 2);
    $_SESSION['msg_class'] = 'success';
    header('Location: owner_dashboard.php#approvals');
    exit;
}

// ─────────────────────────────────────────────────────────────────
// ACTION 2: APPROVE & PAY (OR APPROVE ONLY)
// ─────────────────────────────────────────────────────────────────
if ($action === 'approve_pay' || $action === 'get_approve') {
    // Check if already approved (prevents double approval / duplicate payment)
    if ($crop['status'] === 'Approved') {
        $_SESSION['msg_text']  = "Crop '{$crop_name}' has already been approved.";
        $_SESSION['msg_class'] = 'error';
        header('Location: owner_dashboard.php#approvals');
        exit;
    }

    $price    = (float)($_POST['price'] ?? $crop['price']);
    $quantity = (float)($_POST['quantity'] ?? $crop['quantity']);

    // If price wasn't passed or is 0, check latest crop_prices
    if ($price <= 0) {
        $cp_q = $conn->prepare('SELECT price FROM crop_prices WHERE LOWER(crop_name) = LOWER(?) ORDER BY updated_at DESC LIMIT 1');
        $cp_q->bind_param('s', $crop_name);
        $cp_q->execute();
        $cp_r = $cp_q->get_result()->fetch_assoc();
        $cp_q->close();
        $price = (float)($cp_r['price'] ?? 0);
    }

    if ($price <= 0) {
        $_SESSION['msg_text']  = "Cannot approve '{$crop_name}': Price per quintal must be greater than zero. Please set a price first.";
        $_SESSION['msg_class'] = 'error';
        header('Location: owner_dashboard.php#approvals');
        exit;
    }
    if ($quantity <= 0) {
        $_SESSION['msg_text']  = "Quantity must be greater than zero.";
        $_SESSION['msg_class'] = 'error';
        header('Location: owner_dashboard.php#approvals');
        exit;
    }

    $total_amount = $price * $quantity;

    // Payment amount to record now (defaults to full total_amount if not specified)
    if (isset($_POST['pay_amount']) && $_POST['pay_amount'] !== '') {
        $pay_amount = (float)$_POST['pay_amount'];
    } else {
        $pay_amount = $total_amount; // Full payment by default for 'Approve & Pay'
    }

    if ($pay_amount < 0) {
        $pay_amount = 0;
    }
    if ($pay_amount > $total_amount) {
        $pay_amount = $total_amount;
    }

    $method = trim($_POST['payment_method'] ?? 'Cash');
    if (!in_array($method, ['Cash', 'UPI', 'Bank'])) {
        $method = 'Cash';
    }

    // Begin database transaction for atomicity and locking
    $conn->begin_transaction();
    try {
        // Lock and verify crop status again inside transaction (concurrency / double click protection)
        $lock_stmt = $conn->prepare('SELECT status FROM crops WHERE id = ? FOR UPDATE');
        $lock_stmt->bind_param('i', $id);
        $lock_stmt->execute();
        $cur_status = $lock_stmt->get_result()->fetch_assoc()['status'] ?? '';
        $lock_stmt->close();

        if ($cur_status === 'Approved') {
            $conn->rollback();
            $_SESSION['msg_text']  = "Notice: Crop '{$crop_name}' was already approved.";
            $_SESSION['msg_class'] = 'error';
            header('Location: owner_dashboard.php#approvals');
            exit;
        }

        // 1. Mark crop approved with authoritative price and quantity
        $upd = $conn->prepare('UPDATE crops SET status = "Approved", price = ?, quantity = ? WHERE id = ?');
        $upd->bind_param('ddi', $price, $quantity, $id);
        $upd->execute();
        $upd->close();

        // 2. Record in farmer_sales for accurate total sale calculation
        $ins_fs = $conn->prepare(
            'INSERT INTO farmer_sales (farmer_id, crop_id, quantity, total_amount, sale_date)
             VALUES (?, ?, ?, ?, CURDATE())'
        );
        $ins_fs->bind_param('iidd', $farmer_id, $id, $quantity, $total_amount);
        $ins_fs->execute();
        $ins_fs->close();

        // 3. Record payment if pay_amount > 0
        $pay_id = 0;
        if ($pay_amount > 0) {
            $ptype = ($pay_amount >= $total_amount) ? 'Final' : 'Advance';
            $notes = "Crop Payment - " . $crop_name . " (" . number_format($quantity, 2) . " Q @ ₹" . number_format($price, 2) . "/Q)";

            $ins_p = $conn->prepare(
                'INSERT INTO payments (farmer_id, crop_id, amount, payment_type, payment_method, notes)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $ins_p->bind_param('iidsss', $farmer_id, $id, $pay_amount, $ptype, $method, $notes);
            $ins_p->execute();
            $pay_id = $ins_p->insert_id;
            $ins_p->close();

            // Update farmer balance and total_sale
            $upd_f = $conn->prepare('UPDATE farmers SET balance = balance + ?, total_sale = total_sale + ? WHERE id = ?');
            $upd_f->bind_param('ddi', $pay_amount, $total_amount, $farmer_id);
            $upd_f->execute();
            $upd_f->close();
        } else {
            // Update farmer total_sale only
            $upd_f = $conn->prepare('UPDATE farmers SET total_sale = total_sale + ? WHERE id = ?');
            $upd_f->bind_param('di', $total_amount, $farmer_id);
            $upd_f->execute();
            $upd_f->close();
        }

        // 4. Update crop_prices table with authoritative rate
        $cp = $conn->prepare('INSERT INTO crop_prices (crop_name, price) VALUES (?, ?)');
        $cp->bind_param('sd', $crop_name, $price);
        $cp->execute();
        $cp->close();

        $conn->commit();

        // Rotate session payment token to prevent double-submit
        $_SESSION['payment_token'] = bin2hex(random_bytes(16));

        // Format confirmation message with all required details (Req 9)
        $payment_status_label = ($pay_amount >= $total_amount) ? 'Paid' : (($pay_amount > 0) ? 'Partially Paid' : 'Pending');
        $msg = "Crop '{$crop_name}' approved successfully! Quantity: " . number_format($quantity, 2) . " Quintals | Price: ₹" . number_format($price, 2) . "/Q | Total Amount: ₹" . number_format($total_amount, 2) . " | Paid: ₹" . number_format($pay_amount, 2) . " | Payment Status: {$payment_status_label}.";

        $_SESSION['msg_text']  = $msg;
        $_SESSION['msg_class'] = 'success';

        // If a full payment was recorded and owner came from direct link, or can view receipt
        if ($pay_id > 0 && isset($_GET['receipt'])) {
            header("Location: receipt.php?farmer_id={$farmer_id}&payment_id={$pay_id}");
        } else {
            header('Location: owner_dashboard.php#approvals');
        }
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        error_log('approve.php transaction error: ' . $e->getMessage());
        $_SESSION['msg_text']  = 'Error processing crop approval: ' . $e->getMessage();
        $_SESSION['msg_class'] = 'error';
        header('Location: owner_dashboard.php#approvals');
        exit;
    }
}
?>
