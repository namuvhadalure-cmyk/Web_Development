<?php
// owner_dashboard.php — Mandi Owner's management dashboard
// Requires valid Owner session (auth_owner.php enforces this).

include('auth_owner.php');   // session_start() + role check
include('db.php');

$owner_name = htmlspecialchars($_SESSION['name'] ?? 'Owner', ENT_QUOTES, 'UTF-8');
$owner_user_id = (int)$_SESSION['user_id'];

// ── Idempotency token ──────────────────────────────────────────────
if (empty($_SESSION['payment_token'])) {
    $_SESSION['payment_token'] = bin2hex(random_bytes(16));
}

// ── Messages & feedback (with flash support) ───────────────────────
$msg_text  = '';
$msg_class = '';
if (isset($_SESSION['msg_text'])) {
    $msg_text  = $_SESSION['msg_text'];
    $msg_class = $_SESSION['msg_class'] ?? 'success';
    unset($_SESSION['msg_text'], $_SESSION['msg_class']);
}

// ── Handle: Add Farmer ───────────────────────────────────────────
if (isset($_POST['add_farmer'])) {
    $fn      = trim($_POST['name']    ?? '');
    $fmobile = trim($_POST['mobile']  ?? '');
    $fphone  = trim($_POST['phone']   ?? '');
    $village = trim($_POST['village'] ?? '');
    $fsale   = (float)($_POST['sale'] ?? 0);

    if ($fn === '') {
        $msg_text = 'Farmer name is required.'; $msg_class = 'error';
    } elseif (!preg_match('/^[0-9]{10}$/', $fmobile)) {
        $msg_text = 'Mobile must be exactly 10 digits.'; $msg_class = 'error';
    } else {
        // Check if mobile already exists in users
        $chk = $conn->prepare('SELECT id FROM users WHERE mobile = ?');
        $chk->bind_param('s', $fmobile);
        $chk->execute();
        $chk->store_result();

        if ($chk->num_rows > 0) {
            $msg_text = 'A farmer with this mobile number already exists.'; $msg_class = 'error';
        } else {
            // Create a users entry (temporary password — farmer must reset via registration)
            $tmp_pass = password_hash('changeme123', PASSWORD_BCRYPT);
            $role_f   = 'Farmer';
            $ins_u    = $conn->prepare('INSERT INTO users (name, mobile, password, role) VALUES (?, ?, ?, ?)');
            $ins_u->bind_param('ssss', $fn, $fmobile, $tmp_pass, $role_f);
            $ins_u->execute();
            $new_uid  = $ins_u->insert_id;
            $ins_u->close();

            // Insert farmer profile
            $ins_f = $conn->prepare(
                'INSERT INTO farmers (name, user_id, phone, village, mobile, total_sale)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $ins_f->bind_param('sisssd', $fn, $new_uid, $fphone, $village, $fmobile, $fsale);
            $ins_f->execute();
            $ins_f->close();

            $msg_text  = "Farmer '{$fn}' added. Default password is 'changeme123' (farmer should change it).";
            $msg_class = 'success';
        }
        $chk->close();
    }
}

// ── Handle: Make Payment ─────────────────────────────────────────
if (isset($_POST['pay'])) {
    $pid     = (int)($_POST['farmer_id'] ?? 0);
    $crop_id = (int)($_POST['crop_id']   ?? 0);
    $amount  = (float)($_POST['amount']  ?? 0);
    $ptype   = $_POST['type']   ?? '';
    $pmethod = $_POST['method'] ?? 'Cash';
    $notes   = trim($_POST['notes'] ?? '');

    $allowed_types   = ['Advance', 'Final'];
    $allowed_methods = ['Cash', 'UPI', 'Bank'];

    if ($pid <= 0 || $amount <= 0) {
        $msg_text = 'Select a farmer and enter a valid amount.'; $msg_class = 'error';
    } elseif (!in_array($ptype, $allowed_types)) {
        $msg_text = 'Invalid payment type.'; $msg_class = 'error';
    } elseif (!in_array($pmethod, $allowed_methods)) {
        $msg_text = 'Invalid payment method.'; $msg_class = 'error';
    } else {
        $actual_crop_id = null;
        if ($crop_id > 0) {
            $cq = $conn->prepare('SELECT crop_name, price, quantity FROM crops WHERE id = ? AND farmer_id = ? LIMIT 1');
            $cq->bind_param('ii', $crop_id, $pid);
            $cq->execute();
            $crop_row = $cq->get_result()->fetch_assoc();
            $cq->close();

            if ($crop_row) {
                $actual_crop_id = $crop_id;
                if ($notes === '') {
                    $notes = "Payment for " . $crop_row['crop_name'] . " (" . number_format((float)$crop_row['quantity'], 2) . " Q)";
                }
            }
        }

        $ins_p = $conn->prepare(
            'INSERT INTO payments (farmer_id, crop_id, amount, payment_type, payment_method, notes) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ins_p->bind_param('iidsss', $pid, $actual_crop_id, $amount, $ptype, $pmethod, $notes);
        if ($ins_p->execute()) {
            $pay_id   = $ins_p->insert_id;
            $ins_p->close();

            // Update farmer balance
            $upd_f = $conn->prepare('UPDATE farmers SET balance = balance + ? WHERE id = ?');
            $upd_f->bind_param('di', $amount, $pid);
            $upd_f->execute();
            $upd_f->close();

            // Rotate token
            $_SESSION['payment_token'] = bin2hex(random_bytes(16));

            $msg_text  = "Payment of ₹" . number_format($amount, 2) . " recorded successfully!";
            $msg_class = 'success';
            header("Location: receipt.php?farmer_id={$pid}&payment_id={$pay_id}");
            exit;
        } else {
            error_log('Payment INSERT error: ' . $ins_p->error);
            $msg_text = 'Payment failed. Please try again.'; $msg_class = 'error';
            $ins_p->close();
        }
    }
}

// ── Handle: Update Crop Price ────────────────────────────────────
if (isset($_POST['update_price'])) {
    $cname  = trim($_POST['crop_name'] ?? '');
    $cprice = (float)($_POST['price'] ?? 0);

    if ($cname === '' || $cprice <= 0) {
        $msg_text = 'Enter a valid crop name and price.'; $msg_class = 'error';
    } else {
        $ins_cp = $conn->prepare('INSERT INTO crop_prices (crop_name, price) VALUES (?, ?)');
        $ins_cp->bind_param('sd', $cname, $cprice);
        $ins_cp->execute();
        $ins_cp->close();

        // Also update any pending crop submissions that currently have price = 0
        $upd_crops = $conn->prepare('UPDATE crops SET price = ? WHERE LOWER(crop_name) = LOWER(?) AND status = "Pending" AND price = 0');
        $upd_crops->bind_param('ds', $cprice, $cname);
        $upd_crops->execute();
        $affected = $upd_crops->affected_rows;
        $upd_crops->close();

        $msg_text = "Price for '{$cname}' updated to ₹" . number_format($cprice, 2) . ($affected > 0 ? " ({$affected} pending submission(s) updated)." : ".");
        $msg_class = 'success';
    }
}

// ── Handle: Chat ─────────────────────────────────────────────────
if (isset($_POST['send_msg'])) {
    $chat_farmer_id = (int)($_POST['farmer_id'] ?? 0);
    $chat_message   = trim($_POST['message']    ?? '');

    if ($chat_farmer_id <= 0 || $chat_message === '') {
        $msg_text = 'Select a farmer and enter a message.'; $msg_class = 'error';
    } else {
        // Find farmer's user_id
        $fuid_q = $conn->prepare('SELECT user_id FROM farmers WHERE id = ? LIMIT 1');
        $fuid_q->bind_param('i', $chat_farmer_id);
        $fuid_q->execute();
        $fuid_row = $fuid_q->get_result()->fetch_assoc();
        $fuid_q->close();
        $receiver_id = (int)($fuid_row['user_id'] ?? 0);

        $ins_m = $conn->prepare(
            'INSERT INTO messages (farmer_id, sender, sender_id, receiver_id, message)
             VALUES (?, "Owner", ?, ?, ?)'
        );
        $ins_m->bind_param('iiis', $chat_farmer_id, $owner_user_id, $receiver_id, $chat_message);
        $ins_m->execute();
        $ins_m->close();
        $msg_text = 'Message sent!'; $msg_class = 'success';
    }
}

// ── Pre-fetch farmer list (used in multiple sections) ────────────
$farmers_list = $conn->query('SELECT id, name, mobile, village FROM farmers ORDER BY name ASC');
$all_farmers  = [];
if ($farmers_list) {
    while ($r = $farmers_list->fetch_assoc()) {
        $all_farmers[] = $r;
    }
}

// ── Selected farmer for chat ─────────────────────────────────────
$selected_farmer_id = (int)($_GET['chat_farmer_id'] ?? $_POST['farmer_id'] ?? ($all_farmers[0]['id'] ?? 0));
$owner_chat_messages = [];
if ($selected_farmer_id > 0) {
    $oc_stmt = $conn->prepare(
        'SELECT sender, message, sent_at FROM messages WHERE farmer_id = ? ORDER BY sent_at ASC'
    );
    $oc_stmt->bind_param('i', $selected_farmer_id);
    $oc_stmt->execute();
    $oc_res = $oc_stmt->get_result();
    if ($oc_res) {
        while ($row = $oc_res->fetch_assoc()) {
            $owner_chat_messages[] = $row;
        }
    }
    $oc_stmt->close();
}

// ── Farmer details with pending ──────────────────────────────────
$farmers_detail = $conn->query('SELECT * FROM farmers ORDER BY name ASC');

// ── Latest crop prices ───────────────────────────────────────────
$latest_prices = $conn->query(
    'SELECT crop_name, price, updated_at FROM crop_prices ORDER BY updated_at DESC LIMIT 20'
);

// ── Pending crop approvals ────────────────────────────────────────
$pending_q = $conn->query(
    'SELECT c.id, c.crop_name, c.price, c.quantity, c.created_at,
            f.name AS farmer_name, f.mobile AS farmer_mobile, f.id AS farmer_id
     FROM crops c
     JOIN farmers f ON c.farmer_id = f.id
     WHERE c.status = "Pending"
     ORDER BY c.created_at ASC'
);
$pending_crops_list = [];
if ($pending_q) {
    while ($pc = $pending_q->fetch_assoc()) {
        $crop_price = (float)$pc['price'];
        // If price is 0, check latest crop_prices
        if ($crop_price <= 0) {
            $cp_chk = $conn->prepare('SELECT price FROM crop_prices WHERE LOWER(crop_name) = LOWER(?) ORDER BY updated_at DESC LIMIT 1');
            $cp_chk->bind_param('s', $pc['crop_name']);
            $cp_chk->execute();
            $cpr = $cp_chk->get_result()->fetch_assoc();
            $cp_chk->close();
            $crop_price = (float)($cpr['price'] ?? 0);
        }
        $pc['default_price'] = $crop_price;
        $pc['estimated_amount'] = $crop_price * (float)$pc['quantity'];
        $pending_crops_list[] = $pc;
    }
}

// ── Approved crops & payment tracking ─────────────────────────────
$approved_q = $conn->query(
    'SELECT c.id, c.crop_name, c.price, c.quantity, c.created_at,
            (c.price * c.quantity) AS total_amount,
            f.id AS farmer_id, f.name AS farmer_name, f.mobile AS farmer_mobile,
            COALESCE(SUM(p.amount), 0) AS total_paid
     FROM crops c
     JOIN farmers f ON c.farmer_id = f.id
     LEFT JOIN payments p ON p.crop_id = c.id
     WHERE c.status = "Approved"
     GROUP BY c.id
     ORDER BY c.id DESC'
);
$approved_crops_list = [];
if ($approved_q) {
    while ($ac = $approved_q->fetch_assoc()) {
        $tot  = (float)$ac['total_amount'];
        $paid = (float)$ac['total_paid'];
        $ac['pending_balance'] = max(0, $tot - $paid);
        if ($paid >= $tot && $tot > 0) {
            $ac['payment_status'] = 'Paid';
        } elseif ($paid > 0 && $paid < $tot) {
            $ac['payment_status'] = 'Partially Paid';
        } else {
            $ac['payment_status'] = 'Pending';
        }
        $approved_crops_list[] = $ac;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Dashboard — Crop Market Mandi</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<!-- ── SIDEBAR ──────────────────────────────────────────────────── -->
<div class="sidebar">
    <h2>🏢 Mandi Owner</h2>
    <p class="sidebar-user">👤 <?php echo $owner_name; ?></p>
    <a href="#addfarmer">➕ Add Farmer</a>
    <a href="#approvals">⏳ Pending Approvals</a>
    <a href="#approved_crops">🌾 Approved Crops</a>
    <a href="#farmerdetails">📋 Farmer Details</a>
    <a href="#payment">💸 Make Payment</a>
    <a href="#prices">📈 Update Prices</a>
    <a href="#chat">💬 Chat</a>
    <a href="payment_history.php">🗒️ Payment History</a>
    <a href="logout.php" class="logout-link">🚪 Logout</a>
</div>

<!-- ── MAIN CONTENT ──────────────────────────────────────────────── -->
<div class="main">
    <h1>Owner Dashboard</h1>

    <?php if ($msg_text !== ''): ?>
        <div class="msg <?php echo $msg_class; ?>"><?php echo htmlspecialchars($msg_text, ENT_QUOTES); ?></div>
    <?php endif; ?>

    <!-- ===== ADD FARMER ===== -->
    <div class="card" id="addfarmer">
        <h3>➕ Add New Farmer</h3>
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label>Farmer Name *</label>
                    <input type="text" name="name" placeholder="Full name" required>
                </div>
                <div class="form-group">
                    <label>Mobile Number *</label>
                    <input type="tel" name="mobile" placeholder="10-digit mobile" maxlength="10" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Phone (alternate)</label>
                    <input type="text" name="phone" placeholder="Alternate phone">
                </div>
                <div class="form-group">
                    <label>Village</label>
                    <input type="text" name="village" placeholder="Village name">
                </div>
            </div>
            <div class="form-group">
                <label>Total Sale Amount (₹)</label>
                <input type="number" name="sale" placeholder="0.00" min="0" step="0.01">
            </div>
            <button type="submit" name="add_farmer">Add Farmer</button>
        </form>
        <p class="hint">ℹ️ Added farmers get a default password <strong>'changeme123'</strong>. Ask them to re-register to set their own.</p>
    </div>

    <!-- ===== PENDING CROP APPROVALS ===== -->
    <div class="card" id="approvals">
        <h3>⏳ Pending Crop Approvals</h3>
        <p style="font-size:13px;color:#64748b;margin-top:-6px;margin-bottom:14px;">
            Set or edit purchase price per quintal. The estimated total calculates automatically. Click <strong>Set Price</strong> to notify the farmer or <strong>Approve &amp; Pay</strong> to finalize.
        </p>
        <?php if (!empty($pending_crops_list)): ?>
        <table>
            <thead>
                <tr>
                    <th>Farmer</th>
                    <th>Crop</th>
                    <th style="min-width:105px;">Qty (Q)</th>
                    <th style="min-width:115px;">Price/Q (₹)</th>
                    <th style="min-width:125px;">Est. Total (₹)</th>
                    <th>Date</th>
                    <th style="min-width:170px;">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($pending_crops_list as $pc):
                $cur_qty   = (float)$pc['quantity'];
                $cur_price = (float)$pc['default_price'];
                $est_tot   = $cur_qty * $cur_price;
            ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($pc['farmer_name'], ENT_QUOTES); ?></strong><br>
                        <small style="color:#64748b;"><?php echo htmlspecialchars($pc['farmer_mobile'] ?? '', ENT_QUOTES); ?></small>
                    </td>
                    <td><strong><?php echo htmlspecialchars($pc['crop_name'], ENT_QUOTES); ?></strong></td>
                    <form method="POST" action="approve.php" class="pending-approval-form">
                        <input type="hidden" name="crop_id" value="<?php echo (int)$pc['id']; ?>">
                        <input type="hidden" name="token" value="<?php echo htmlspecialchars($_SESSION['payment_token']); ?>">
                        <td>
                            <input type="number" step="0.01" min="0.01" name="quantity"
                                   value="<?php echo number_format($cur_qty, 2, '.', ''); ?>"
                                   class="table-input qty-input" required>
                        </td>
                        <td>
                            <input type="number" step="0.01" min="0.01" name="price"
                                   value="<?php echo $cur_price > 0 ? number_format($cur_price, 2, '.', '') : ''; ?>"
                                   class="table-input price-input" placeholder="Set Price (₹)" required>
                        </td>
                        <td>
                            <span class="est-total-val">₹<?php echo number_format($est_tot, 2); ?></span>
                        </td>
                        <td><?php echo htmlspecialchars(date('d M Y', strtotime($pc['created_at'])), ENT_QUOTES); ?></td>
                        <td>
                            <div class="action-btn-group">
                                <button type="submit" name="action" value="set_price" class="btn-sm" style="background:#2563eb;" title="Set price so farmer sees it">Set Price</button>
                                <button type="submit" name="action" value="approve_pay" class="btn-sm" style="background:#16a34a;" onclick="return confirmApproval(this);">Approve &amp; Pay</button>
                            </div>
                        </td>
                    </form>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="empty-state">No pending crop approvals.</p>
        <?php endif; ?>
    </div>

    <!-- ===== APPROVED CROPS & PAYMENT TRACKING ===== -->
    <div class="card" id="approved_crops">
        <h3>🌾 Approved Crops &amp; Payment Tracking</h3>
        <p style="font-size:13px;color:#64748b;margin-top:-6px;margin-bottom:14px;">
            Track all approved crop sales, total payments recorded, and remaining pending balances.
        </p>
        <?php if (!empty($approved_crops_list)): ?>
        <table>
            <thead>
                <tr>
                    <th>Farmer</th>
                    <th>Crop</th>
                    <th>Qty (Q)</th>
                    <th>Price/Q (₹)</th>
                    <th>Total Amount (₹)</th>
                    <th>Paid Amount (₹)</th>
                    <th>Pending Balance (₹)</th>
                    <th>Payment Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($approved_crops_list as $ac):
                $p_status = $ac['payment_status'];
                $badge_class = ($p_status === 'Paid') ? 'badge-success' : (($p_status === 'Partially Paid') ? 'badge-partial' : 'badge-pending');
            ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($ac['farmer_name'], ENT_QUOTES); ?></strong><br>
                        <small style="color:#64748b;"><?php echo htmlspecialchars($ac['farmer_mobile'] ?? '', ENT_QUOTES); ?></small>
                    </td>
                    <td><strong><?php echo htmlspecialchars($ac['crop_name'], ENT_QUOTES); ?></strong></td>
                    <td><?php echo number_format((float)$ac['quantity'], 2); ?></td>
                    <td>₹<?php echo number_format((float)$ac['price'], 2); ?></td>
                    <td><strong>₹<?php echo number_format((float)$ac['total_amount'], 2); ?></strong></td>
                    <td>₹<?php echo number_format((float)$ac['total_paid'], 2); ?></td>
                    <td class="<?php echo $ac['pending_balance'] > 0 ? 'text-danger' : 'text-success'; ?>">
                        ₹<?php echo number_format((float)$ac['pending_balance'], 2); ?>
                    </td>
                    <td><span class="badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($p_status, ENT_QUOTES); ?></span></td>
                    <td>
                        <?php if ($ac['pending_balance'] > 0): ?>
                            <a href="#payment" onclick="quickPayCrop(<?php echo (int)$ac['farmer_id']; ?>, <?php echo (int)$ac['id']; ?>, <?php echo (float)$ac['pending_balance']; ?>);" class="btn-sm" style="background:#f59e0b;">Pay Balance</a>
                        <?php else: ?>
                            <span class="badge badge-success">✓ Fully Paid</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="empty-state">No approved crop records yet.</p>
        <?php endif; ?>
    </div>

    <!-- ===== FARMER DETAILS ===== -->
    <div class="card" id="farmerdetails">
        <h3>📋 Farmer Details &amp; Pending Balance</h3>
        <?php if ($farmers_detail && $farmers_detail->num_rows > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Mobile</th>
                    <th>Village</th>
                    <th>Total Sale (₹)</th>
                    <th>Total Paid (₹)</th>
                    <th>Pending (₹)</th>
                </tr>
            </thead>
            <tbody>
            <?php while ($row = $farmers_detail->fetch_assoc()):
                $fid = (int)$row['id'];
                $paid_q = $conn->prepare('SELECT COALESCE(SUM(amount),0) AS tp FROM payments WHERE farmer_id = ?');
                $paid_q->bind_param('i', $fid);
                $paid_q->execute();
                $total_paid_f = (float)($paid_q->get_result()->fetch_assoc()['tp'] ?? 0);
                $paid_q->close();
                $pending_f = (float)($row['total_sale'] ?? 0) - $total_paid_f;
            ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['name'] ?? '', ENT_QUOTES); ?></td>
                    <td><?php echo htmlspecialchars($row['mobile'] ?? '', ENT_QUOTES); ?></td>
                    <td><?php echo htmlspecialchars($row['village'] ?? '—', ENT_QUOTES); ?></td>
                    <td>₹<?php echo number_format((float)($row['total_sale'] ?? 0), 2); ?></td>
                    <td>₹<?php echo number_format($total_paid_f, 2); ?></td>
                    <td class="<?php echo $pending_f > 0 ? 'text-danger' : 'text-success'; ?>">
                        ₹<?php echo number_format($pending_f, 2); ?>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="empty-state">No farmers registered yet.</p>
        <?php endif; ?>
    </div>

    <!-- ===== MAKE PAYMENT ===== -->
    <div class="card" id="payment">
        <h3>💸 Make Payment (Advance / Final)</h3>
        <?php if (!empty($all_farmers)): ?>
        <form method="POST">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($_SESSION['payment_token']); ?>">

            <div class="form-group">
                <label>Link to Approved Crop / Invoice (Optional)</label>
                <select name="crop_id" id="make_pay_crop_id" onchange="onCropSelected(this);">
                    <option value="">— General Account Payment —</option>
                    <?php foreach ($approved_crops_list as $ac): ?>
                        <?php if ($ac['pending_balance'] > 0): ?>
                            <option value="<?php echo (int)$ac['id']; ?>"
                                    data-farmer="<?php echo (int)$ac['farmer_id']; ?>"
                                    data-balance="<?php echo number_format((float)$ac['pending_balance'], 2, '.', ''); ?>">
                                <?php echo htmlspecialchars($ac['farmer_name'] . ' — ' . $ac['crop_name'] . ' (' . number_format((float)$ac['quantity'], 2) . ' Q, Due: ₹' . number_format((float)$ac['pending_balance'], 2) . ')'); ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Select Farmer *</label>
                    <select name="farmer_id" id="make_pay_farmer_id" required>
                        <option value="">— Select Farmer —</option>
                        <?php foreach ($all_farmers as $f): ?>
                            <option value="<?php echo (int)$f['id']; ?>">
                                <?php echo htmlspecialchars($f['name'], ENT_QUOTES) . (!empty($f['mobile']) ? ' (' . htmlspecialchars($f['mobile'], ENT_QUOTES) . ')' : ''); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Payment Amount (₹) *</label>
                    <input type="number" name="amount" id="make_pay_amount" placeholder="Enter amount" min="0.01" step="0.01" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Payment Type</label>
                    <select name="type" id="make_pay_type">
                        <option value="Final">Final</option>
                        <option value="Advance">Advance</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Payment Method</label>
                    <select name="method">
                        <option value="Cash">Cash (Manual Record)</option>
                        <option value="UPI">UPI</option>
                        <option value="Bank">Bank Transfer</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Notes / Reference (Optional)</label>
                <input type="text" name="notes" placeholder="e.g. Cheque #, RTGS Ref, or crop details">
            </div>

            <!-- UPI QR Placeholder (upi_qr.png not found in project) -->
            <div class="upi-notice" id="upi-notice" style="display:none;">
                <div class="qr-placeholder">
                    <p>📱 <strong>UPI QR Code</strong></p>
                    <p class="hint">Place your <code>upi_qr.png</code> image in the project folder to display it here.</p>
                </div>
            </div>

            <button type="submit" name="pay">Confirm &amp; Record Payment</button>
        </form>
        <script>
            // Show UPI QR placeholder only when UPI method is selected
            document.querySelector('[name="method"]').addEventListener('change', function(){
                document.getElementById('upi-notice').style.display =
                    this.value === 'UPI' ? 'block' : 'none';
            });
        </script>
        <?php else: ?>
            <p class="empty-state">Add at least one farmer before making payments.</p>
        <?php endif; ?>
    </div>

    <!-- ===== UPDATE CROP PRICES ===== -->
    <div class="card" id="prices">
        <h3>📈 Update Daily Crop Prices</h3>
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label>Crop Name *</label>
                    <input type="text" name="crop_name" placeholder="e.g. Wheat, Rice, Maize" required>
                </div>
                <div class="form-group">
                    <label>Price per Quintal (₹) *</label>
                    <input type="number" name="price" placeholder="e.g. 2000" min="1" step="0.01" required>
                </div>
            </div>
            <button type="submit" name="update_price">Post Price</button>
        </form>

        <?php if ($latest_prices && $latest_prices->num_rows > 0): ?>
        <h4 style="margin-top:20px;">Recent Prices</h4>
        <table>
            <thead><tr><th>Crop</th><th>Price (₹)</th><th>Updated</th></tr></thead>
            <tbody>
            <?php while ($cp = $latest_prices->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($cp['crop_name'], ENT_QUOTES); ?></td>
                    <td>₹<?php echo number_format((float)$cp['price'], 2); ?></td>
                    <td><?php echo htmlspecialchars(date('d M Y, h:i A', strtotime($cp['updated_at'])), ENT_QUOTES); ?></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="empty-state">No prices posted yet.</p>
        <?php endif; ?>
    </div>

    <!-- ===== CHAT ===== -->
    <div class="card" id="chat">
        <h3>💬 Chat with Farmer</h3>
        <?php if (!empty($all_farmers)): ?>
        <div class="form-group">
            <label>Select Farmer Conversation</label>
            <select onchange="window.location.href='owner_dashboard.php?chat_farmer_id=' + this.value + '#chat'">
                <?php foreach ($all_farmers as $f): ?>
                    <option value="<?php echo (int)$f['id']; ?>" <?php echo ((int)$f['id'] === $selected_farmer_id) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($f['name'], ENT_QUOTES) . (!empty($f['mobile']) ? ' (' . htmlspecialchars($f['mobile'], ENT_QUOTES) . ')' : ''); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <h4>Conversation History</h4>
        <div class="chat-box" style="margin-bottom:16px;">
        <?php if (!empty($owner_chat_messages)): ?>
            <?php foreach ($owner_chat_messages as $msg): ?>
                <div class="chat-msg <?php echo strtolower($msg['sender']); ?>">
                    <strong><?php echo htmlspecialchars($msg['sender'], ENT_QUOTES); ?>:</strong>
                    <?php echo nl2br(htmlspecialchars($msg['message'], ENT_QUOTES)); ?>
                    <br><small><?php echo htmlspecialchars(date('d M Y, h:i A', strtotime($msg['sent_at'])), ENT_QUOTES); ?></small>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="empty-state">No messages in this conversation yet. Send the first message below.</p>
        <?php endif; ?>
        </div>

        <form method="POST">
            <input type="hidden" name="farmer_id" value="<?php echo (int)$selected_farmer_id; ?>">
            <div class="form-group">
                <label>Send reply to selected farmer:</label>
                <textarea name="message" rows="3" placeholder="Type your message…" required></textarea>
            </div>
            <button type="submit" name="send_msg">Send Message</button>
        </form>
        <?php else: ?>
            <p class="empty-state">No farmers available to message.</p>
        <?php endif; ?>
    </div>

</div><!-- /.main -->

<script>
// ── Real-time calculation for Pending Crop Approvals ─────────────
document.addEventListener('DOMContentLoaded', function() {
    var forms = document.querySelectorAll('.pending-approval-form');
    forms.forEach(function(form) {
        var qtyInput   = form.querySelector('.qty-input');
        var priceInput = form.querySelector('.price-input');
        var totalSpan  = form.querySelector('.est-total-val');

        function updateEstTotal() {
            var q = parseFloat(qtyInput ? qtyInput.value : 0) || 0;
            var p = parseFloat(priceInput ? priceInput.value : 0) || 0;
            var total = q * p;
            if (totalSpan) {
                totalSpan.textContent = '₹' + total.toLocaleString('en-IN', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }
        }

        if (qtyInput)   qtyInput.addEventListener('input', updateEstTotal);
        if (priceInput) priceInput.addEventListener('input', updateEstTotal);
    });
});

// ── Confirm Approval & Prevent Duplicate Submission ──────────────
function confirmApproval(btn) {
    var form = btn.closest('form');
    var q = parseFloat(form.querySelector('.qty-input').value) || 0;
    var p = parseFloat(form.querySelector('.price-input').value) || 0;

    if (q <= 0 || p <= 0) {
        alert('Quantity and Price per quintal must both be greater than zero.');
        return false;
    }

    var total = (q * p).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    if (confirm('Approve this crop sale and record payment of ₹' + total + '?')) {
        btn.disabled = true;
        btn.textContent = 'Processing...';
        form.submit();
        return true;
    }
    return false;
}

// ── Helper: Quick pay for an approved crop ────────────────────────
function quickPayCrop(farmerId, cropId, balance) {
    var farmerSelect = document.getElementById('make_pay_farmer_id');
    var cropSelect   = document.getElementById('make_pay_crop_id');
    var amountInput  = document.getElementById('make_pay_amount');

    if (farmerSelect) farmerSelect.value = farmerId;
    if (cropSelect)   cropSelect.value = cropId;
    if (amountInput)  amountInput.value = parseFloat(balance).toFixed(2);

    // Scroll to payment section smoothly
    var payCard = document.getElementById('payment');
    if (payCard) payCard.scrollIntoView({ behavior: 'smooth' });
}

// ── Helper: When an approved crop is selected in Make Payment ─────
function onCropSelected(sel) {
    var opt = sel.options[sel.selectedIndex];
    var farmerId = opt.getAttribute('data-farmer');
    var balance  = opt.getAttribute('data-balance');

    var farmerSelect = document.getElementById('make_pay_farmer_id');
    var amountInput  = document.getElementById('make_pay_amount');

    if (farmerId && farmerSelect) {
        farmerSelect.value = farmerId;
    }
    if (balance && amountInput) {
        amountInput.value = parseFloat(balance).toFixed(2);
    }
}
</script>

</body>
</html>