<?php
// ==========================
// PDO Database Connection
// ==========================
try {
    // Use variables from config.php
    $dsn = "mysql:host=$dbservername;dbname=$dbname;charset=utf8";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $conn = new PDO($dsn, $dbusername, $dbpassword, $options);

} catch (PDOException $e) {
    // Log the actual error for server logs
    error_log(date('[Y-m-d H:i:s] ') . "Database connection failed: " . $e->getMessage());

    // Set session message for user-friendly error page
    $_SESSION['error_code'] = 500;
    $_SESSION['error_message'] =
        "A database connection error occurred. Please try again later.";

    // Redirect to error page
    header('Location: /inventory_system/error.php');
    exit;
}
