<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Protect POST, PUT, DELETE
if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE'])) {
    return;
}

$token = $_POST['csrf_token'] ?? '';

if (empty($token) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(403);
    error_log("CSRF token mismatch for user " . ($_SESSION['user_id'] ?? 'guest'));
    echo json_encode([
        'success' => false,
        'error' => 'Invalid CSRF token'
    ]);
    exit;
}