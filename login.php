<?php
// login.php — Farmer and Owner login
// Looks up user in the `users` table by mobile + role.
// Uses password_verify() — safe for hashed passwords.
// All DB queries use prepared statements.

session_start();
include('db.php');

$role  = htmlspecialchars(trim($_GET['role'] ?? ''), ENT_QUOTES, 'UTF-8');
$error = '';

// If already logged in as the requested role, redirect directly to that dashboard
if (isset($_SESSION['role']) && $_SESSION['role'] === $role && $role !== '') {
    header('Location: ' . ($role === 'Farmer' ? 'farmer_dashboard.php' : 'owner_dashboard.php'));
    exit;
}

if (isset($_POST['login'])) {
    $role     = htmlspecialchars(trim($_POST['role']     ?? ''), ENT_QUOTES, 'UTF-8');
    $mobile   = trim($_POST['mobile']   ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($mobile === '' || $password === '') {
        $error = 'Please enter mobile number and password.';
    } elseif (!in_array($role, ['Farmer', 'Owner'])) {
        $error = 'Invalid role.';
    } else {
        // Fetch user by mobile and role
        $stmt = $conn->prepare(
            'SELECT id, name, password FROM users WHERE mobile = ? AND role = ? LIMIT 1'
        );
        $stmt->bind_param('ss', $mobile, $role);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if (password_verify($password, $user['password'])) {
                // ── Login successful ──────────────────────────
                session_regenerate_id(true);   // Prevent session fixation
                $_SESSION['role']    = $role;
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name']    = $user['name'];

                $stmt->close();

                if ($role === 'Farmer') {
                    // Fetch farmer profile id
                    $fp = $conn->prepare('SELECT id FROM farmers WHERE user_id = ? LIMIT 1');
                    $fp->bind_param('i', $user['id']);
                    $fp->execute();
                    $fr = $fp->get_result()->fetch_assoc();
                    $_SESSION['farmer_id'] = $fr['id'] ?? null;
                    $fp->close();
                    header('Location: farmer_dashboard.php');
                } else {
                    header('Location: owner_dashboard.php');
                }
                exit;
            } else {
                $error = 'Incorrect password. Please try again.';
                $stmt->close();
            }
        } else {
            $error = 'No ' . $role . ' account found with that mobile number.';
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Crop Market Mandi</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Segoe UI', sans-serif;
            background: linear-gradient(to bottom, #dcedc8, #a5d6a7);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .login-container {
            background: white;
            padding: 40px;
            border-radius: 20px;
            width: 100%;
            max-width: 380px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        }
        h2 { text-align: center; color: #2e7d32; margin-bottom: 20px; }
        label { display: block; font-size: 13px; color: #555; margin: 12px 0 4px; }
        input {
            width: 100%; padding: 12px; border-radius: 8px;
            border: 1px solid #c8e6c9; font-size: 15px; outline: none;
            transition: border-color .2s;
        }
        input:focus { border-color: #43a047; }
        button {
            width: 100%; padding: 13px; background: #43a047; color: white;
            border: none; border-radius: 8px; font-weight: bold;
            font-size: 16px; cursor: pointer; margin-top: 18px;
            transition: background .2s;
        }
        button:hover { background: #2e7d32; }
        .msg { text-align: center; padding: 10px; border-radius: 6px; margin-bottom: 12px; font-size: 14px; }
        .error { background: #ffebee; color: #c62828; }
        .back { text-align: center; margin-top: 15px; font-size: 14px; }
        .back a { text-decoration: none; color: #2e7d32; }
        .back a:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="login-container">
    <h2>🌾 <?php echo $role; ?> Login</h2>

    <?php if ($error !== '') echo '<div class="msg error">' . htmlspecialchars($error, ENT_QUOTES) . '</div>'; ?>

    <form method="POST" novalidate>
        <input type="hidden" name="role" value="<?php echo $role; ?>">

        <label for="mobile">Mobile Number</label>
        <input type="tel" id="mobile" name="mobile"
               placeholder="10-digit mobile number"
               maxlength="10" required>

        <label for="password">Password</label>
        <input type="password" id="password" name="password"
               placeholder="Enter password" required>

        <button type="submit" name="login">Login</button>
    </form>

    <div class="back">
        <a href="register.php?role=<?php echo $role; ?>">New user? Register here →</a><br>
        <a href="index.php">← Back to Role Selection</a>
    </div>
</div>

</body>
</html>