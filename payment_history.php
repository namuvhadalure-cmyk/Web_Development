<?php
// payment_history.php — Full payment history (Owner only)
include('auth_owner.php');
include('db.php');

$history = $conn->prepare(
    'SELECT p.id, p.farmer_id, f.name AS farmer_name, f.mobile,
            p.amount, p.payment_type, p.payment_method, p.notes, p.payment_date,
            c.crop_name
     FROM payments p
     JOIN farmers f ON p.farmer_id = f.id
     LEFT JOIN crops c ON p.crop_id = c.id
     ORDER BY p.payment_date DESC, p.id DESC'
);
$history->execute();
$rows = $history->get_result();
$history->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment History — Crop Market Mandi</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="sidebar">
    <h2>🏢 Mandi Owner</h2>
    <a href="owner_dashboard.php">← Back to Dashboard</a>
    <a href="logout.php" class="logout-link">🚪 Logout</a>
</div>

<div class="main">
    <h1>🗒️ Payment History</h1>

    <div class="card">
        <?php if ($rows && $rows->num_rows > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Farmer</th>
                    <th>Mobile</th>
                    <th>Crop / Ref</th>
                    <th>Amount</th>
                    <th>Type</th>
                    <th>Method</th>
                    <th>Date</th>
                    <th>Receipt</th>
                </tr>
            </thead>
            <tbody>
            <?php $sno = 1; while ($row = $rows->fetch_assoc()): ?>
                <tr>
                    <td><?php echo $sno++; ?></td>
                    <td><strong><?php echo htmlspecialchars($row['farmer_name'], ENT_QUOTES); ?></strong></td>
                    <td><?php echo htmlspecialchars($row['mobile'] ?? '—', ENT_QUOTES); ?></td>
                    <td>
                        <?php
                        if (!empty($row['crop_name'])) {
                            echo '<strong>' . htmlspecialchars($row['crop_name'], ENT_QUOTES) . '</strong>';
                        } elseif (!empty($row['notes'])) {
                            echo htmlspecialchars($row['notes'], ENT_QUOTES);
                        } else {
                            echo '<span style="color:#94a3b8">General</span>';
                        }
                        ?>
                    </td>
                    <td><strong>₹<?php echo number_format((float)$row['amount'], 2); ?></strong></td>
                    <td><?php echo htmlspecialchars($row['payment_type'], ENT_QUOTES); ?></td>
                    <td><?php echo htmlspecialchars($row['payment_method'] ?? '—', ENT_QUOTES); ?></td>
                    <td><?php echo htmlspecialchars(date('d M Y, h:i A', strtotime($row['payment_date'])), ENT_QUOTES); ?></td>
                    <td>
                        <a href="receipt.php?farmer_id=<?php echo (int)$row['farmer_id']; ?>&payment_id=<?php echo (int)$row['id']; ?>"
                           target="_blank" class="btn-sm">View</a>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="empty-state">No payment records found.</p>
        <?php endif; ?>
    </div>
</div>

</body>
</html>