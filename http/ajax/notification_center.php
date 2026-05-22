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
    ->throttle('notification_center', 60, 60, 'Too many notification filter requests. Please wait a moment.');

function notificationCenterJson(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $role = strtolower((string) ($_SESSION['role'] ?? ''));

    if ($userId <= 0 || $role === '') {
        notificationCenterJson([
            'success' => false,
            'error' => 'Unauthorized.',
        ], 401);
    }

    $filters = [
        'search' => trim((string) ($_GET['q'] ?? '')),
        'category' => strtolower(trim((string) ($_GET['category'] ?? 'all'))),
        'status' => strtolower(trim((string) ($_GET['status'] ?? 'all'))),
        'period' => strtolower(trim((string) ($_GET['period'] ?? 'all'))),
        'actor_id' => $role === 'admin' ? (int) ($_GET['actor_id'] ?? 0) : 0,
    ];

    $allowedCategories = ['all', 'sales', 'shift', 'inventory', 'security', 'system'];
    $allowedStatuses = ['all', 'unread', 'previous'];
    $allowedPeriods = ['all', 'today', 'week', 'month'];

    if (!in_array($filters['category'], $allowedCategories, true)) {
        $filters['category'] = 'all';
    }

    if (!in_array($filters['status'], $allowedStatuses, true)) {
        $filters['status'] = 'all';
    }

    if (!in_array($filters['period'], $allowedPeriods, true)) {
        $filters['period'] = 'all';
    }

    $center = NotificationController::getNotificationCenter($conn, $userId, $role, $filters, 80);

    notificationCenterJson([
        'success' => true,
        'items' => $center['items'],
        'stats' => $center['stats'],
        'filters' => $filters,
        'last_seen' => $center['last_seen'],
    ]);
} catch (Throwable $e) {
    error_log('[notification_center.php] ' . $e->getMessage());

    notificationCenterJson([
        'success' => false,
        'error' => 'Failed to load notifications.',
    ], 500);
}
