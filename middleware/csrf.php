<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Only protect POST, PUT, DELETE
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return;
}

$token = $_POST['csrf_token'] ?? '';

if (
    !isset($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $token)
) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid CSRF token'
    ]);
    exit;
}
