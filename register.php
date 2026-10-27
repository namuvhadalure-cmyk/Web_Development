<?php
// register.php — Farmer and Owner registration
// Uses: users table (id, name, mobile, email, password, role)
// Passwords are hashed with password_hash() before storage.
// All DB operations use prepared statements (SQL-injection safe).

session_start();
include('db.php');

// Read requested role from GET parameter
$role = htmlspecialchars(trim($_GET['role'] ?? ''), ENT_QUOTES, 'UTF-8');
$error   = '';
$success = '';

if (isset($_POST['register'])) {
    // Re-read role from POST (the hidden field)
    $role     = htmlspecialchars(trim($_POST['role'] ?? ''), ENT_QUOTES, 'UTF-8');
    $name     = trim($_POST['name']     ?? '');
    $mobile   = trim($_POST['mobile']   ?? '');
    $password = trim($_POST['password'] ?? '');

    // ── Validation ────────────────────────────────────────────
    if ($name === '') {
        $error = 'Full name is required.';
    } elseif (!preg_match('/^[0-9]{10}$/', $mobile)) {
        $error = 'Mobile number must be exactly 10 digits.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif (!in_array($role, ['Farmer', 'Owner'])) {
        $error = 'Invalid role selected.';
    } else {
        // ── Check if mobile already registered ────────────────
        $stmt = $conn->prepare('SELECT id FROM users WHERE mobile = ?');
        $stmt->bind_param('s', $mobile);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $error = 'This mobile number is already registered.';
            $stmt->close();
        } else {
            $stmt->close();

            // ── Hash password and INSERT ───────────────────────
            $hashed = password_hash($password, PASSWORD_BCRYPT);

            $ins = $conn->prepare(
                'INSERT INTO users (name, mobile, password, role) VALUES (?, ?, ?, ?)'
            );
            $ins->bind_param('ssss', $name, $mobile, $hashed, $role);

            if ($ins->execute()) {
                $user_id = $ins->insert_id;
                $ins->close();

                // If Farmer, also insert profile row in farmers table
                if ($role === 'Farmer') {
                    $fp = $conn->prepare(
                        'INSERT INTO farmers (name, user_id, mobile) VALUES (?, ?, ?)'
                    );
                    $fp->bind_param('sis', $name, $user_id, $mobile);
                    $fp->execute();
                    $fp->close();
                }

                $success = $role . ' registered successfully! <a href="login.php?role=' . $role . '">Login now →</a>';
            } else {
                error_log('Register INSERT error: ' . $ins->error);
                $error = 'Registration failed. Please try again.';
                $ins->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — Crop Market Mandi</title>
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
        .register-container {
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
        .error   { background: #ffebee; color: #c62828; }
        .success { background: #e8f5e9; color: #2e7d32; }
        .back { text-align: center; margin-top: 15px; font-size: 14px; }
        .back a { text-decoration: none; color: #2e7d32; }
        .back a:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="register-container">
    <h2>🌾 <?php echo $role; ?> Registration</h2>

    <?php if ($error !== '')   echo '<div class="msg error">'   . $error   . '</div>'; ?>
    <?php if ($success !== '') echo '<div class="msg success">' . $success . '</div>'; ?>

    <?php if ($success === ''): ?>
    <form method="POST" novalidate>
        <input type="hidden" name="role" value="<?php echo $role; ?>">

        <label for="name">Full Name</label>
        <input type="text" id="name" name="name"
               placeholder="Enter your full name"
               value="<?php echo htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES); ?>"
               required>

        <label for="mobile">Mobile Number</label>
        <input type="tel" id="mobile" name="mobile"
               placeholder="10-digit mobile number"
               maxlength="10"
               value="<?php echo htmlspecialchars($_POST['mobile'] ?? '', ENT_QUOTES); ?>"
               required>

        <label for="password">Password</label>
        <input type="password" id="password" name="password"
               placeholder="Minimum 6 characters" required>

        <button type="submit" name="register">Register</button>
    </form>
    <?php endif; ?>

    <div class="back">
        <a href="login.php?role=<?php echo $role; ?>">Already registered? Login →</a><br>
        <a href="index.php">← Back to Home</a>
    </div>
</div>

</body>
</html>