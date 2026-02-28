<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/controllers/AuthController.php';

// Call logout method
AuthController::logout(
    $conn,
    $table_activity_logs,
    $activity_log_user_id,
    $activity_log_action,
    $activity_log_desc,
    $activity_log_ip,
    $activity_log_created
);