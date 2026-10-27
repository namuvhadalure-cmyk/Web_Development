<?php
// auth_owner.php — Owner authentication guard
// Include this at the top of every owner-only page.
// Starts the session and redirects to login if not authenticated as Owner.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Owner') {
    header('Location: login.php?role=Owner');
    exit;
}
?>
