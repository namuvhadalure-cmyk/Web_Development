<?php
// receipt.php — Print-friendly payment receipt
// Accessible by both Farmer (own receipts) and Owner (any receipt).
// Requires a valid session.

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['role'])) {
    header('Location: index.php'); exit;
}

include('db.php');

$farmer_id  = (int)($_GET['farmer_id']  ?? 0);
$payment_id = (int)($_GET['payment_id'] ?? 0);

// Farmers can only view their own receipts
if ($_SESSION['role'] === 'Farmer') {
    if ((int)($_SESSION['farmer_id'] ?? 0) !== $farmer_id) {
        header('Location: farmer_dashboard.php'); exit;
    }
}

if ($farmer_id <= 0 || $payment_id <= 0) {
    echo '<p style="color:red;padding:20px;">Invalid receipt request.</p>'; exit;
}

// Fetch farmer info from users (via farmers table)
$stmt_f = $conn->prepare(
    'SELECT f.name, f.mobile, f.village FROM farmers f WHERE f.id = ? LIMIT 1'
);
$stmt_f->bind_param('i', $farmer_id);
$stmt_f->execute();
$farmer = $stmt_f->get_result()->fetch_assoc();
$stmt_f->close();

// Fetch the specific payment with optional crop details
$stmt_p = $conn->prepare(
    'SELECT p.amount, p.payment_type, p.payment_method, p.payment_date, p.notes,
            c.crop_name, c.quantity, c.price
     FROM payments p
     LEFT JOIN crops c ON p.crop_id = c.id
     WHERE p.id = ? AND p.farmer_id = ? LIMIT 1'
);
$stmt_p->bind_param('ii', $payment_id, $farmer_id);
$stmt_p->execute();
$payment = $stmt_p->get_result()->fetch_assoc();
$stmt_p->close();

if (!$farmer || !$payment) {
    echo '<p style="color:red;padding:20px;">Receipt not found.</p>'; exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt — Crop Market Mandi</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f6f9; padding: 40px 20px; }
        .receipt {
            border: 2px solid #2e7d32;
            padding: 30px 40px;
            max-width: 520px;
            margin: auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .receipt-header { text-align: center; margin-bottom: 24px; }
        .receipt-header h2 { color: #2e7d32; margin: 0 0 4px; }
        .receipt-header p  { color: #777; margin: 0; font-size: 13px; }
        .divider { border: none; border-top: 1px dashed #ccc; margin: 18px 0; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 15px; }
        .row .label { color: #555; }
        .row .value { font-weight: bold; color: #1b1b1b; }
        .amount-row .value { color: #2e7d32; font-size: 20px; }
        .footer-note { text-align: center; margin-top: 20px; font-size: 12px; color: #999; }
        .btn-row { text-align: center; margin-top: 24px; display: flex; gap: 12px; justify-content: center; }
        button {
            padding: 10px 24px; border: none; border-radius: 8px;
            font-weight: bold; cursor: pointer; font-size: 14px;
        }
        .btn-print { background: #2e7d32; color: white; }
        .btn-back  { background: #e0e0e0; color: #333; }
        @media print {
            .btn-row { display: none; }
            body { background: white; padding: 0; }
            .receipt { border: 2px solid #000; box-shadow: none; }
        }
    </style>
</head>
<body>
<div class="receipt">
    <div class="receipt-header">
        <h2>🌾 Crop Market Mandi</h2>
        <p>Official Payment Receipt</p>
    </div>

    <hr class="divider">

    <div class="row">
        <span class="label">Farmer Name</span>
        <span class="value"><?php echo htmlspecialchars($farmer['name'] ?? '—', ENT_QUOTES); ?></span>
    </div>
    <div class="row">
        <span class="label">Mobile</span>
        <span class="value"><?php echo htmlspecialchars($farmer['mobile'] ?? '—', ENT_QUOTES); ?></span>
    </div>
    <div class="row">
        <span class="label">Village</span>
        <span class="value"><?php echo htmlspecialchars($farmer['village'] ?? '—', ENT_QUOTES); ?></span>
    </div>

    <hr class="divider">

    <?php if (!empty($payment['crop_name'])): ?>
    <div class="row">
        <span class="label">Crop Description</span>
        <span class="value"><?php echo htmlspecialchars($payment['crop_name'], ENT_QUOTES) . ' (' . number_format((float)$payment['quantity'], 2) . ' Q @ ₹' . number_format((float)$payment['price'], 2) . '/Q)'; ?></span>
    </div>
    <?php elseif (!empty($payment['notes'])): ?>
    <div class="row">
        <span class="label">Reference / Note</span>
        <span class="value"><?php echo htmlspecialchars($payment['notes'], ENT_QUOTES); ?></span>
    </div>
    <?php endif; ?>

    <div class="row amount-row">
        <span class="label">Amount Paid</span>
        <span class="value">₹<?php echo number_format((float)$payment['amount'], 2); ?></span>
    </div>
    <div class="row">
        <span class="label">Payment Type</span>
        <span class="value"><?php echo htmlspecialchars($payment['payment_type'] ?? '—', ENT_QUOTES); ?></span>
    </div>
    <div class="row">
        <span class="label">Payment Method</span>
        <span class="value"><?php echo htmlspecialchars($payment['payment_method'] ?? '—', ENT_QUOTES); ?></span>
    </div>
    <div class="row">
        <span class="label">Date</span>
        <span class="value"><?php echo htmlspecialchars(date('d M Y, h:i A', strtotime($payment['payment_date'])), ENT_QUOTES); ?></span>
    </div>

    <hr class="divider">

    <div class="footer-note">
        This is a computer-generated receipt. No signature required.
    </div>

    <div class="btn-row">
        <button class="btn-print" onclick="window.print()">🖨️ Print / Download PDF</button>
        <button class="btn-back" onclick="history.back()">← Back</button>
    </div>
</div>
</body>
</html>