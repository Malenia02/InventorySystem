<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap/app.php';
require_once __DIR__ . '/../../middleware/Middleware.php';
require_once __DIR__ . '/../../controllers/NotificationController.php';

header('Content-Type: application/json; charset=UTF-8');

Middleware::auth()
    ->ajax()
    ->methods(['POST'])
    ->csrf();

function jsonResponse(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

try {
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if ($userId <= 0) {
        jsonResponse([
            'success' => false,
            'error'   => 'Unauthorized.'
        ], 401);
    }

    NotificationController::markSeen($conn, $userId);

    jsonResponse([
        'success' => true,
        'message' => 'Notifications marked as seen.'
    ]);
} catch (Throwable $e) {
    error_log('[mark_notifications_seen] ' . $e->getMessage());

    jsonResponse([
        'success' => false,
        'error'   => 'Failed to mark notifications as seen.'
    ], 500);
}