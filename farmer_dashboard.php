<?php
// farmer_dashboard.php — Farmer's personal dashboard
// Requires valid Farmer session (auth_farmer.php enforces this).

include('auth_farmer.php');   // session_start() + role check
include('db.php');

$user_id   = (int) ($_SESSION['user_id'] ?? 0);
$farmer_id = (int) ($_SESSION['farmer_id'] ?? 0);

// Auto-resolve farmer_id if not present in session
if ($farmer_id <= 0 && $user_id > 0) {
    $fp = $conn->prepare('SELECT id FROM farmers WHERE user_id = ? LIMIT 1');
    $fp->bind_param('i', $user_id);
    $fp->execute();
    $fr = $fp->get_result()->fetch_assoc();
    $farmer_id = (int)($fr['id'] ?? 0);
    $_SESSION['farmer_id'] = $farmer_id;
    $fp->close();
}

$farmer_name = htmlspecialchars($_SESSION['name'] ?? 'Farmer', ENT_QUOTES, 'UTF-8');

// ── Fetch farmer profile ──────────────────────────────────────────
$stmt = $conn->prepare('SELECT * FROM farmers WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $farmer_id);
$stmt->execute();
$farmer = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── Payment Summary ───────────────────────────────────────────────
// Total sale value from approved crops
$s = $conn->prepare(
    'SELECT COALESCE(SUM(price * quantity), 0) AS total_sale
     FROM crops WHERE farmer_id = ? AND status = "Approved"'
);
$s->bind_param('i', $farmer_id);
$s->execute();
$total_sale = (float)($s->get_result()->fetch_assoc()['total_sale'] ?? 0);
$s->close();

// Fallback to farmer_sales if crops sum was 0
if ($total_sale <= 0) {
    $fs_q = $conn->prepare('SELECT COALESCE(SUM(total_amount), 0) AS ts FROM farmer_sales WHERE farmer_id = ?');
    $fs_q->bind_param('i', $farmer_id);
    $fs_q->execute();
    $total_sale = (float)($fs_q->get_result()->fetch_assoc()['ts'] ?? 0);
    $fs_q->close();
}

// Total paid (all payments)
$p = $conn->prepare(
    'SELECT COALESCE(SUM(amount), 0) AS total_paid FROM payments WHERE farmer_id = ?'
);
$p->bind_param('i', $farmer_id);
$p->execute();
$total_paid = (float)($p->get_result()->fetch_assoc()['total_paid'] ?? 0);
$p->close();

// Total advance taken
$a = $conn->prepare(
    'SELECT COALESCE(SUM(amount), 0) AS total_advance FROM payments WHERE farmer_id = ? AND payment_type = "Advance"'
);
$a->bind_param('i', $farmer_id);
$a->execute();
$total_advance = (float)($a->get_result()->fetch_assoc()['total_advance'] ?? 0);
$a->close();

$pending = max(0, $total_sale - $total_paid);

// ── Handle chat message submit ────────────────────────────────────
$chat_msg_sent = false;
if (isset($_POST['send_msg'])) {
    $message = trim($_POST['message'] ?? '');
    if ($message !== '') {
        // Find the owner's user_id (any owner — use first one found)
        $owner_res = $conn->query('SELECT id FROM users WHERE role = "Owner" LIMIT 1');
        $owner_row = $owner_res ? $owner_res->fetch_assoc() : null;
        $receiver_id = $owner_row ? (int)$owner_row['id'] : 0;

        $cm = $conn->prepare(
            'INSERT INTO messages (farmer_id, sender, sender_id, receiver_id, message)
             VALUES (?, "Farmer", ?, ?, ?)'
        );
        $cm->bind_param('iiis', $farmer_id, $user_id, $receiver_id, $message);
        $cm->execute();
        $cm->close();
        $chat_msg_sent = true;
    }
}

// ── Handle: Submit Crop for Sale ─────────────────────────────────
$crop_msg       = '';
$crop_msg_class = '';
if (isset($_POST['submit_crop'])) {
    $crop_name = trim($_POST['crop_name'] ?? '');
    $quantity  = (float)($_POST['quantity']  ?? 0);
    $sale_date = trim($_POST['sale_date']    ?? '');

    if ($crop_name === '') {
        $crop_msg = 'Crop name is required.'; $crop_msg_class = 'error';
    } elseif ($quantity <= 0) {
        $crop_msg = 'Quantity must be greater than zero.'; $crop_msg_class = 'error';
    } elseif ($sale_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sale_date)) {
        $crop_msg = 'Please select a valid sale date.'; $crop_msg_class = 'error';
    } else {
        // Look up current price for this crop (case-insensitive latest entry)
        $price_q = $conn->prepare(
            'SELECT price FROM crop_prices WHERE LOWER(crop_name) = LOWER(?) ORDER BY updated_at DESC LIMIT 1'
        );
        $price_q->bind_param('s', $crop_name);
        $price_q->execute();
        $price_row = $price_q->get_result()->fetch_assoc();
        $price_q->close();
        $unit_price = (float)($price_row['price'] ?? 0);

        $ins_c = $conn->prepare(
            'INSERT INTO crops (crop_name, price, quantity, farmer_id, status)
             VALUES (?, ?, ?, ?, "Pending")'
        );
        $ins_c->bind_param('sddi', $crop_name, $unit_price, $quantity, $farmer_id);
        if ($ins_c->execute()) {
            if ($unit_price > 0) {
                $crop_msg = "Crop '{$crop_name}' (" . number_format($quantity, 2) . " Q @ ₹" . number_format($unit_price, 2) . "/Q) submitted successfully! Awaiting owner approval.";
            } else {
                $crop_msg = "Crop '{$crop_name}' (" . number_format($quantity, 2) . " Q) submitted successfully! Awaiting owner price & approval.";
            }
            $crop_msg_class = 'success';
        } else {
            error_log('Crop submit error: ' . $ins_c->error);
            $crop_msg = 'Failed to submit crop. Please try again.'; $crop_msg_class = 'error';
        }
        $ins_c->close();
    }
}

// ── Fetch submitted crops with payment tracking (this farmer) ────
$my_crops = $conn->prepare(
    'SELECT c.id, c.crop_name, c.price, c.quantity, c.status, c.created_at,
            (c.price * c.quantity) AS estimated_amount,
            COALESCE(SUM(p.amount), 0) AS total_paid
     FROM crops c
     LEFT JOIN payments p ON p.crop_id = c.id
     WHERE c.farmer_id = ?
     GROUP BY c.id
     ORDER BY c.created_at DESC'
);
$my_crops->bind_param('i', $farmer_id);
$my_crops->execute();
$my_crops_rows = $my_crops->get_result();
$my_crops->close();

// ── Fetch available crop names from price list (for datalist) ────
$crop_names_q = $conn->query('SELECT DISTINCT crop_name FROM crop_prices ORDER BY crop_name ASC');
$crop_names   = [];
if ($crop_names_q) {
    while ($cn = $crop_names_q->fetch_assoc()) {
        $crop_names[] = $cn['crop_name'];
    }
}

// ── Fetch crop prices ────────────────────────────────────────────
$prices_result = $conn->query(
    'SELECT cp.crop_name, cp.price, cp.updated_at
     FROM crop_prices cp
     ORDER BY cp.updated_at DESC'
);

// ── Fetch chat messages ──────────────────────────────────────────
$chat_result = $conn->prepare(
    'SELECT sender, message, sent_at FROM messages
     WHERE farmer_id = ? OR (receiver_id = ? AND sender = "Owner")
     ORDER BY sent_at ASC'
);
$chat_result->bind_param('ii', $farmer_id, $user_id);
$chat_result->execute();
$chat_res = $chat_result->get_result();
$chat_messages = [];
if ($chat_res) {
    while ($m = $chat_res->fetch_assoc()) {
        $chat_messages[] = $m;
    }
}
$chat_result->close();

// ── Fetch payment receipts ───────────────────────────────────────
$pay_result = $conn->prepare(
    'SELECT id, amount, payment_type, payment_method, payment_date
     FROM payments WHERE farmer_id = ? ORDER BY payment_date DESC'
);
$pay_result->bind_param('i', $farmer_id);
$pay_result->execute();
$pay_rows = $pay_result->get_result();
$pay_result->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Farmer Dashboard — Crop Market Mandi</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<!-- ── SIDEBAR ──────────────────────────────────────────────────── -->
<div class="sidebar">
    <h2>🌾 Farmer Panel</h2>
    <p class="sidebar-user">👤 <?php echo $farmer_name; ?></p>
    <a href="#summary">📊 Payment Summary</a>
    <a href="#submit-crop">🌿 Submit Crop</a>
    <a href="#prices">💰 Daily Prices</a>
    <a href="#chat">💬 Chat with Owner</a>
    <a href="#receipts">🧾 My Receipts</a>
    <a href="logout.php" class="logout-link">🚪 Logout</a>
</div>

<!-- ── MAIN CONTENT ──────────────────────────────────────────────── -->
<div class="main">
    <h1>Welcome, <?php echo $farmer_name; ?>!</h1>

    <!-- ===== PAYMENT SUMMARY ===== -->
    <div class="card" id="summary">
        <h3>📊 Payment Summary</h3>
        <div class="stats-grid">
            <div class="stat-box">
                <div class="stat-label">Total Sale</div>
                <div class="stat-value">₹<?php echo number_format($total_sale, 2); ?></div>
            </div>
            <div class="stat-box advance">
                <div class="stat-label">Advance Taken</div>
                <div class="stat-value">₹<?php echo number_format($total_advance, 2); ?></div>
            </div>
            <div class="stat-box paid">
                <div class="stat-label">Total Paid</div>
                <div class="stat-value">₹<?php echo number_format($total_paid, 2); ?></div>
            </div>
            <div class="stat-box pending">
                <div class="stat-label">Pending Amount</div>
                <div class="stat-value">₹<?php echo number_format($pending, 2); ?></div>
            </div>
        </div>
    </div>

    <!-- ===== SUBMIT CROP FOR SALE ===== -->
    <div class="card" id="submit-crop">
        <h3>🌿 Submit Crop for Sale</h3>
        <p style="font-size:14px;color:#64748b;margin-top:-8px;">
            Enter your crop details below. The owner will review and approve the sale. Price is auto-filled from the latest market rate.
        </p>

        <?php if ($crop_msg !== ''): ?>
            <div class="msg <?php echo $crop_msg_class; ?>"><?php echo htmlspecialchars($crop_msg, ENT_QUOTES); ?></div>
        <?php endif; ?>

        <form method="POST">
            <!-- Datalist: auto-complete crop names from market price list -->
            <datalist id="crop-list">
                <?php foreach ($crop_names as $cn): ?>
                    <option value="<?php echo htmlspecialchars($cn, ENT_QUOTES); ?>">
                <?php endforeach; ?>
            </datalist>

            <div class="form-row">
                <div class="form-group">
                    <label for="crop_name">Crop Name *</label>
                    <input type="text" id="crop_name" name="crop_name"
                           list="crop-list"
                           placeholder="e.g. Wheat, Rice, Maize"
                           value="<?php echo htmlspecialchars($_POST['crop_name'] ?? '', ENT_QUOTES); ?>"
                           required>
                </div>
                <div class="form-group">
                    <label for="quantity">Quantity (Quintals) *</label>
                    <input type="number" id="quantity" name="quantity"
                           placeholder="e.g. 20"
                           min="0.01" step="0.01"
                           value="<?php echo htmlspecialchars($_POST['quantity'] ?? '', ENT_QUOTES); ?>"
                           required>
                </div>
            </div>
            <div class="form-group">
                <label for="sale_date">Sale Date *</label>
                <input type="date" id="sale_date" name="sale_date"
                       max="<?php echo date('Y-m-d'); ?>"
                       value="<?php echo htmlspecialchars($_POST['sale_date'] ?? date('Y-m-d'), ENT_QUOTES); ?>"
                       required>
            </div>
            <button type="submit" name="submit_crop">Submit for Approval</button>
        </form>

        <!-- My submitted crops list -->
        <h4 style="margin-top:24px;">🌿 My Submitted Crops &amp; Payment Status</h4>
        <?php if ($my_crops_rows && $my_crops_rows->num_rows > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Crop Name</th>
                    <th>Quantity (Q)</th>
                    <th>Price/Q (₹)</th>
                    <th>Estimated Total (₹)</th>
                    <th>Approval Status</th>
                    <th>Payment Status</th>
                    <th>Amount Paid (₹)</th>
                    <th>Pending Balance (₹)</th>
                    <th>Submitted On</th>
                </tr>
            </thead>
            <tbody>
            <?php while ($cr = $my_crops_rows->fetch_assoc()):
                $unit_price = (float)$cr['price'];
                $qty        = (float)$cr['quantity'];
                $est_total  = $unit_price * $qty;
                $paid       = (float)$cr['total_paid'];
                $balance    = max(0, $est_total - $paid);

                // Approval Status Badge
                $appr_badge = ($cr['status'] === 'Approved')
                    ? '<span class="badge badge-success">Approved</span>'
                    : '<span class="badge badge-pending">Pending</span>';

                // Payment Status Badge
                if ($cr['status'] === 'Pending') {
                    $pay_badge = '<span class="badge badge-pending">Pending</span>';
                } else {
                    if ($paid >= $est_total && $est_total > 0) {
                        $pay_badge = '<span class="badge badge-success">Paid</span>';
                    } elseif ($paid > 0 && $paid < $est_total) {
                        $pay_badge = '<span class="badge badge-partial">Partially Paid</span>';
                    } else {
                        $pay_badge = '<span class="badge badge-pending">Pending</span>';
                    }
                }
            ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($cr['crop_name'], ENT_QUOTES); ?></strong></td>
                    <td><?php echo number_format($qty, 2); ?></td>
                    <td>
                        <?php echo ($unit_price > 0)
                            ? '₹' . number_format($unit_price, 2)
                            : '<span class="badge badge-pending">Price Pending</span>'; ?>
                    </td>
                    <td>
                        <?php echo ($unit_price > 0)
                            ? '<strong>₹' . number_format($est_total, 2) . '</strong>'
                            : '<span style="color:#94a3b8">—</span>'; ?>
                    </td>
                    <td><?php echo $appr_badge; ?></td>
                    <td><?php echo $pay_badge; ?></td>
                    <td>₹<?php echo number_format($paid, 2); ?></td>
                    <td class="<?php echo ($balance > 0 && $unit_price > 0) ? 'text-danger' : 'text-success'; ?>">
                        <?php echo ($unit_price > 0) ? '₹' . number_format($balance, 2) : '—'; ?>
                    </td>
                    <td><?php echo htmlspecialchars(date('d M Y', strtotime($cr['created_at'])), ENT_QUOTES); ?></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="empty-state">You haven't submitted any crops yet.</p>
        <?php endif; ?>
    </div>

    <!-- ===== DAILY CROP PRICES ===== -->

    <div class="card" id="prices">
        <h3>💰 Today's Crop Prices</h3>
        <?php if ($prices_result && $prices_result->num_rows > 0): ?>
        <table>
            <thead>
                <tr><th>Crop Name</th><th>Price per Quintal (₹)</th><th>Updated On</th></tr>
            </thead>
            <tbody>
            <?php while ($row = $prices_result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['crop_name'], ENT_QUOTES); ?></td>
                    <td>₹<?php echo number_format((float)$row['price'], 2); ?></td>
                    <td><?php echo htmlspecialchars(date('d M Y, h:i A', strtotime($row['updated_at'])), ENT_QUOTES); ?></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="empty-state">No crop prices posted yet. Check back later.</p>
        <?php endif; ?>
    </div>

    <!-- ===== CHAT ===== -->
    <div class="card" id="chat">
        <h3>💬 Chat with Owner</h3>

        <?php if ($chat_msg_sent): ?>
            <div class="msg success">✅ Message sent successfully!</div>
        <?php endif; ?>

        <form method="POST">
            <label for="message">Your message:</label>
            <textarea id="message" name="message" rows="3" placeholder="Type your message to the owner…" required></textarea>
            <button type="submit" name="send_msg">Send Message</button>
        </form>

        <h4>Chat History</h4>
        <div class="chat-box">
        <?php foreach ($chat_messages as $msg): ?>
            <div class="chat-msg <?php echo strtolower($msg['sender']); ?>">
                <strong><?php echo htmlspecialchars($msg['sender'], ENT_QUOTES); ?>:</strong>
                <?php echo nl2br(htmlspecialchars($msg['message'], ENT_QUOTES)); ?>
                <br><small><?php echo htmlspecialchars(date('d M Y, h:i A', strtotime($msg['sent_at'])), ENT_QUOTES); ?></small>
            </div>
        <?php endforeach; ?>
        <?php if (empty($chat_messages)): ?>
            <p class="empty-state">No messages yet.</p>
        <?php endif; ?>
        </div>
    </div>

    <!-- ===== PAYMENT RECEIPTS ===== -->
    <div class="card" id="receipts">
        <h3>🧾 My Payment Receipts</h3>
        <?php if ($pay_rows && $pay_rows->num_rows > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Amount</th>
                    <th>Type</th>
                    <th>Method</th>
                    <th>Date</th>
                    <th>Receipt</th>
                </tr>
            </thead>
            <tbody>
            <?php while ($row = $pay_rows->fetch_assoc()): ?>
                <tr>
                    <td>₹<?php echo number_format((float)$row['amount'], 2); ?></td>
                    <td><?php echo htmlspecialchars($row['payment_type'], ENT_QUOTES); ?></td>
                    <td><?php echo htmlspecialchars($row['payment_method'] ?? '—', ENT_QUOTES); ?></td>
                    <td><?php echo htmlspecialchars(date('d M Y', strtotime($row['payment_date'])), ENT_QUOTES); ?></td>
                    <td>
                        <a href="receipt.php?farmer_id=<?php echo (int)$farmer_id; ?>&payment_id=<?php echo (int)$row['id']; ?>"
                           target="_blank" class="btn-sm">Download</a>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="empty-state">No payment records found.</p>
        <?php endif; ?>
    </div>

</div><!-- /.main -->
</body>
</html>