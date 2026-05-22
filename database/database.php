<?php
declare(strict_types=1);

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $dbservername,
        $dbport,
        $dbname
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ];

    $conn = new PDO($dsn, $dbusername, $dbpassword, $options);
} catch (PDOException $e) {
    error_log('[database.php] Database connection failed: ' . $e->getMessage());
    throw new RuntimeException('Database connection failed.', 0, $e);
}