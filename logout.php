<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/controllers/AuthController.php';

$logConfig = [
    'table'       => $table_activity_logs,
    'col_user_id' => $activity_log_user_id,
    'col_action'  => $activity_log_action,
    'col_desc'    => $activity_log_desc,
    'col_ip'      => $activity_log_ip,
    'col_created' => $activity_log_created,
];

AuthController::logout($conn, $logConfig);