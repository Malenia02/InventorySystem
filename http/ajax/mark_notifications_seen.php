<?php
header('Content-Type: application/json');

require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/middleware/Middleware.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/controllers/NotificationController.php';

Middleware::auth()
    ->ajax()
    ->methods(['POST'])
    ->csrf();

$userId = (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    echo json_encode([
        'success' => false,
        'error'   => 'Unauthorized'
    ]);
    exit;
}

try {
    NotificationController::markSeen($conn, $userId);

    echo json_encode([
        'success' => true,
        'message' => 'Notifications marked as seen.'
    ]);
    exit;
} catch (\Throwable $e) {
    error_log('[mark_notifications_seen] ' . $e->getMessage());

    echo json_encode([
        'success' => false,
        'error'   => 'Failed to mark notifications as seen.'
    ]);
    exit;
}