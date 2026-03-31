<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap/app.php';
require_once __DIR__ . '/../../middleware/Middleware.php';
require_once __DIR__ . '/../../controllers/NotificationController.php';

header('Content-Type: application/json; charset=UTF-8');

Middleware::auth()
    ->ajax()
    ->methods(['GET'])
    ->sameOrigin();

function jsonResponse(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

try {
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $role   = (string) ($_SESSION['role'] ?? '');

    if ($userId <= 0 || $role === '') {
        jsonResponse([
            'success' => false,
            'error'   => 'Unauthorized.'
        ], 401);
    }

    $feed = NotificationController::getNotificationFeed($conn, $userId, $role, 20);
    $count = NotificationController::getUnreadCount($conn, $userId, $role);

    jsonResponse([
        'success'       => true,
        'count'         => $count,
        'notifications' => $feed['all'],
        'unread'        => $feed['unread'],
        'previous'      => $feed['previous'],
        'last_seen'     => $feed['last_seen'],
    ]);
} catch (Throwable $e) {
    error_log('[fetch_notifications] ' . $e->getMessage());

    jsonResponse([
        'success' => false,
        'error'   => 'Failed to fetch notifications.'
    ], 500);
}
