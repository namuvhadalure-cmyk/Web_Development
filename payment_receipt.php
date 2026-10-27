<?php
// payment_receipt.php — Receipt by payment ID (alias of receipt.php for compatibility)
// Redirects to receipt.php with correct parameters.

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['role'])) { header('Location: index.php'); exit; }

include('db.php');

$crop_id = (int)($_GET['crop_id'] ?? 0);

if ($crop_id <= 0) {
    echo '<p style="color:red;padding:20px;">Invalid request.</p>'; exit;
}

// Look up payment by crop_id
$stmt = $conn->prepare(
    'SELECT p.id, p.farmer_id FROM payments p
     JOIN farmer_sales fs ON fs.crop_id = p.id
     WHERE fs.crop_id = ? LIMIT 1'
);
$stmt->bind_param('i', $crop_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($row) {
    header("Location: receipt.php?farmer_id={$row['farmer_id']}&payment_id={$row['id']}");
} else {
    header("Location: owner_dashboard.php");
}
exit;
?>