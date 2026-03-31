<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap/app.php';
require_once __DIR__ . '/middleware/Middleware.php';
require_once __DIR__ . '/controllers/AuthController.php';

Middleware::auth()
    ->methods(['POST'])
    ->csrf();

$logConfig = [
    'table'       => $table_activity_logs,
    'col_user_id' => $activity_log_user_id,
    'col_action'  => $activity_log_action,
    'col_desc'    => $activity_log_desc,
    'col_ip'      => $activity_log_ip,
    'col_created' => $activity_log_created,
];

AuthController::logout($conn, $logConfig);

header('Location: /inventory_system/login.php');
exit;
