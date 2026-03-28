<?php
// ==========================
// PDO Database Connection
// ==========================
try {
    $dsn = "mysql:host={$dbservername};port={$dbport};dbname={$dbname};charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $conn = new PDO($dsn, $dbusername, $dbpassword, $options);

} catch (PDOException $e) {
    error_log(date('[Y-m-d H:i:s] ') . '[database.php] Connection failed: ' . $e->getMessage());

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION['error_code'] = 500;
    $_SESSION['error_message'] = 'A database connection error occurred. Please try again later.';

    $currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($currentPage !== 'error.php') {
        header('Location: /inventory_system/error.php');
        exit;
    }

    http_response_code(500);
    exit('A database connection error occurred. Please try again later.');
}