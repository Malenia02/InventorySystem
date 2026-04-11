<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

if (php_sapi_name() !== 'cli' && !app_is_install_route() && !app_has_database_env()) {
    safe_redirect(app_install_url());
}

try {
    require_once DATABASE_PATH . '/database.php';
} catch (Throwable $e) {
    error_log('[bootstrap] ' . $e->getMessage());

    if (php_sapi_name() !== 'cli' && !app_is_install_route()) {
        safe_redirect(app_install_url());
    }

    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "Application startup failed.\n");
        exit(1);
    }

    if (!headers_sent()) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['error_code'] = 500;
        $_SESSION['error_message'] = 'A database connection error occurred. Please try again later.';
        header('Location: /inventory_system/error.php', true, 302);
        exit;
    }

    http_response_code(500);
    exit('Application startup failed.');
}

if (
    php_sapi_name() !== 'cli' &&
    isset($_SESSION['user_id']) &&
    (int) $_SESSION['user_id'] > 0
) {
    try {
        $sessionUserId = (int) $_SESSION['user_id'];

        $stmt = $conn->prepare("
            SELECT
                user_id,
                username,
                role,
                status,
                first_name,
                last_name,
                photo
            FROM users
            WHERE user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $sessionUserId]);

        $freshUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$freshUser || ($freshUser['status'] ?? 'inactive') !== 'active') {
            $_SESSION = [];
            session_unset();

            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'],
                    $params['domain'],
                    (bool) $params['secure'],
                    (bool) $params['httponly']
                );
            }

            session_destroy();

            if (app_is_ajax_or_json()) {
                http_response_code(401);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'success'  => false,
                    'error'    => 'Your account is unavailable. Please log in again.',
                    'redirect' => '/inventory_system/login.php'
                ]);
                exit;
            }

            safe_redirect('/inventory_system/login.php');
        }

        $_SESSION['username'] = (string) ($freshUser['username'] ?? $_SESSION['username'] ?? '');
        $_SESSION['role'] = (string) ($freshUser['role'] ?? $_SESSION['role'] ?? '');
        $_SESSION['first_name'] = (string) ($freshUser['first_name'] ?? $_SESSION['first_name'] ?? '');
        $_SESSION['last_name'] = (string) ($freshUser['last_name'] ?? $_SESSION['last_name'] ?? '');
        $_SESSION['photo'] = (string) ($freshUser['photo'] ?? $_SESSION['photo'] ?? '');
    } catch (Throwable $e) {
        error_log('[bootstrap session sync] ' . $e->getMessage());
    }
}

register_shutdown_function(function () {
    $error = error_get_last();

    if (!$error) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];

    if (!in_array($error['type'], $fatalTypes, true)) {
        return;
    }

    error_log('[shutdown] Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);

    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "Fatal application error.\n");
        return;
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($currentPage === 'error.php') {
        return;
    }

    $_SESSION['error_code'] = 500;
    $_SESSION['error_message'] = 'A fatal server error occurred. Please try again later.';

    if (!headers_sent()) {
        header('Location: /inventory_system/error.php', true, 302);
        exit;
    }
});

if (php_sapi_name() !== 'cli') {
    if (app_is_install_route() && app_is_install_locked()) {
        app_redirect_install_blocked('Installer access is disabled because setup has already been completed for this system.');
    }

    if (app_is_install_route() && isset($conn) && app_is_fully_installed($conn)) {
        app_redirect_install_blocked('Installer access is disabled because the application is already installed.');
    }

    if (!app_is_install_route() && isset($conn) && !app_is_fully_installed($conn)) {
        safe_redirect(app_install_url());
    }
}
