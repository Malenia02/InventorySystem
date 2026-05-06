<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap/app.php';
require_once __DIR__ . '/../../middleware/Middleware.php';
require_once __DIR__ . '/../../controllers/NotificationController.php';

header('Content-Type: application/json; charset=UTF-8');

Middleware::auth()
    ->ajax()
    ->methods(['GET'])
    ->sameOrigin()
    ->throttle('fetch_notifications', 60, 60, 'Too many notification refreshes. Please wait a moment.');

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

    $feed = NotificationController::getNotificationSnapshot($conn, $userId, $role, 20);

    jsonResponse([
        'success'       => true,
        'count'         => (int) ($feed['count'] ?? 0),
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
