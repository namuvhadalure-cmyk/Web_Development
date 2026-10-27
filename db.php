<?php
// db.php — Database connection
// Uses MySQLi with proper error handling and UTF-8 charset.
// Credentials: host=localhost, user=root, password='', db=crop_market

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'crop_market';

$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);

if (!$conn) {
    // Log the real error; show a generic message to the user
    error_log('DB Connection Failed: ' . mysqli_connect_error());
    die('<p style="color:red;text-align:center;padding:20px;">Database connection error. Please contact the administrator.</p>');
}

// Set UTF-8 encoding to handle special characters correctly
mysqli_set_charset($conn, 'utf8mb4');
?>