<?php
// logout.php — Destroys session and redirects to home page.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION = [];                          // Clear all session variables
session_unset();
session_destroy();                       // Destroy the session

// Also clear the session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

header('Location: index.php');
exit;
?>
