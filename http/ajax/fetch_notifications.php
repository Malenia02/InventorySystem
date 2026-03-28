<?php
header('Content-Type: application/json');

require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/middleware/Middleware.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/controllers/NotificationController.php';

Middleware::auth()
    ->ajax()
    ->methods(['GET'])
    ->sameOrigin();

$userId = (int)($_SESSION['user_id'] ?? 0);
$role   = $_SESSION['role'] ?? 'staff';

if ($userId <= 0) {
    echo json_encode([
        'success' => false,
        'error'   => 'Unauthorized'
    ]);
    exit;
}

$notifications = NotificationController::getNotifications($conn, $userId, $role, 8);
$count = NotificationController::getUnreadCount($conn, $userId, $role);

foreach ($notifications as &$notif) {
    $notif['time_ago'] = NotificationController::timeAgo($notif['time']);
}
unset($notif);

echo json_encode([
    'success'       => true,
    'count'         => $count,
    'notifications' => $notifications
]);
exit;