<?php
require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/config/config.php';

// Destroy session
session_unset();
session_destroy();

// Remove the "remember me" cookie if set
if (isset($_COOKIE['user_id'])) {
    setcookie('user_id', '', time() - 3600, '/');
}

// Redirect to login page
header('Location: /inventory_system/login.php');
exit;
