<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crop Market Mandi</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Segoe UI', sans-serif;
            background: linear-gradient(to bottom, #dcedc8, #a5d6a7);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .main-container {
            background: white;
            padding: 50px 40px;
            border-radius: 20px;
            text-align: center;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 420px;
        }
        h1 { color: #2e7d32; margin-bottom: 6px; font-size: 28px; }
        .subtitle { color: #555; margin-bottom: 24px; font-size: 15px; }
        .session-banner {
            background: #e8f5e9;
            border: 1px solid #a5d6a7;
            border-radius: 10px;
            padding: 10px 14px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #1b5e20;
            line-height: 1.6;
        }
        .section-title {
            margin-top: 24px;
            margin-bottom: 8px;
            font-weight: bold;
            color: #1b5e20;
            font-size: 15px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .role-btn {
            display: block;
            width: 100%;
            padding: 13px;
            margin: 8px 0;
            font-size: 15px;
            font-weight: bold;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
            text-decoration: none;
        }
        .role-btn:active { transform: scale(0.98); }
        .login-btn    { background: #43a047; color: white; }
        .login-btn:hover  { background: #2e7d32; }
        .register-btn { background: #e8f5e9; color: #2e7d32; border: 2px solid #a5d6a7; }
        .register-btn:hover { background: #c8e6c9; }
        .divider { border: none; border-top: 1px solid #e0e0e0; margin: 24px 0 8px; }
        .footer { margin-top: 24px; font-size: 12px; color: #aaa; }
    </style>
</head>
<body>

<div class="main-container">
    <h1>🌾 Crop Market Mandi</h1>
    <p class="subtitle">Agriculture Management System</p>

    <?php if (isset($_SESSION['role'])): ?>
    <div class="session-banner">
        Currently logged in as: <strong><?php echo htmlspecialchars($_SESSION['name'] ?? $_SESSION['role'], ENT_QUOTES); ?></strong> (<?php echo htmlspecialchars($_SESSION['role'], ENT_QUOTES); ?>)<br>
        <a href="<?php echo $_SESSION['role'] === 'Farmer' ? 'farmer_dashboard.php' : 'owner_dashboard.php'; ?>" style="color:#2e7d32;font-weight:bold;text-decoration:none;">Go to Dashboard &rarr;</a> &bull;
        <a href="logout.php" style="color:#c62828;text-decoration:none;margin-left:6px;">Logout</a>
    </div>
    <?php endif; ?>

    <div class="section-title">👨‍🌾 Farmer</div>
    <a class="role-btn login-btn"    href="login.php?role=Farmer">Login as Farmer</a>
    <a class="role-btn register-btn" href="register.php?role=Farmer">Register as Farmer</a>

    <hr class="divider">

    <div class="section-title">🏢 Mandi Owner</div>
    <a class="role-btn login-btn"    href="login.php?role=Owner">Login as Owner</a>
    <a class="role-btn register-btn" href="register.php?role=Owner">Register as Owner</a>

    <div class="footer">Agriculture Management System &copy; <?php echo date('Y'); ?></div>
</div>

</body>
</html>