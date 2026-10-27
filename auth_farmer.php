<?php
// auth_farmer.php — Farmer authentication guard
// Include this at the top of every farmer-only page.
// Starts the session and redirects to login if not authenticated as Farmer.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Farmer') {
    header('Location: login.php?role=Farmer');
    exit;
}
?>
