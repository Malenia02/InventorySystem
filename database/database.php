<?php
// ==========================
// Start session if not started
// ==========================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ==========================
// PDO Database Connection
// ==========================
try {
    $dsn = "mysql:host=$dbservername;dbname=$dbname;charset=utf8";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // throw exceptions
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // associative arrays
        PDO::ATTR_EMULATE_PREPARES   => false,                  // real prepared statements
    ];

    $conn = new PDO($dsn, $dbusername, $dbpassword, $options);

} catch (PDOException $e) {
    // Log real error
    error_log("Database connection failed: " . $e->getMessage());

    // Set session message for error.php
    $_SESSION['error_code'] = 500;
    $_SESSION['error_message'] = "A database connection error occurred. Please try again later.";

    // Redirect to error page
    header('Location: /inventory_system/error.php');
    exit;
}
?>
