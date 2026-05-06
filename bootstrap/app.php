<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../controllers/AuthController.php';
require_once __DIR__ . '/../controllers/StaffController.php';

// -----------------------------------------------------------------------------
// 0. Install-route / env guard — must run before session start
// -----------------------------------------------------------------------------

if (php_sapi_name() !== 'cli' && !app_is_install_route() && !app_has_database_env()) {
    safe_redirect(app_install_url());
}

// -----------------------------------------------------------------------------
// 1. Session bootstrap — harden cookie params THEN start session
//    AuthController::configureSessionCookie() must run before session_start().
//    We call it here so it is guaranteed on every request, not just on login.
// -----------------------------------------------------------------------------

if (php_sapi_name() !== 'cli' && session_status() === PHP_SESSION_NONE) {
    if (
        class_exists('AuthController') &&
        method_exists('AuthController', 'configureSessionCookie')
    ) {
        AuthController::configureSessionCookie();
    } else {
        // Fallback: harden inline if AuthController is not loaded yet
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    session_start();
}

// -----------------------------------------------------------------------------
// 2. Session fixation guard
//    If a session has no authenticated user AND no fixation-prevention stamp,
//    regenerate the session ID to invalidate any externally planted IDs.
// -----------------------------------------------------------------------------

if (
    php_sapi_name() !== 'cli' &&
    empty($_SESSION['user_id']) &&
    empty($_SESSION['_session_init'])
) {
    session_regenerate_id(true);
    $_SESSION['_session_init'] = true;
}

// -----------------------------------------------------------------------------
// 3. Database connection
// -----------------------------------------------------------------------------

try {
    require_once DATABASE_PATH . '/database.php';
} catch (Throwable $e) {
    error_log('[bootstrap] ' . $e->getMessage());

    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "Application startup failed.\n");
        exit(1);
    }

    if (!app_is_install_route()) {
        safe_redirect(app_install_url());
    }

    if (!headers_sent()) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['error_code']    = 500;
        $_SESSION['error_message'] = 'A database connection error occurred. Please try again later.';
        header('Location: /inventory_system/error.php', true, 302);
        exit;
    }

    http_response_code(500);
    exit('Application startup failed.');
}

// -----------------------------------------------------------------------------
// 4. Remember-me token consumption
//    Must run after DB is available and before any auth/middleware check.
//    Promotes a valid remember-me cookie into a full authenticated session.
// -----------------------------------------------------------------------------

if (
    php_sapi_name() !== 'cli' &&
    isset($conn) &&
    empty($_SESSION['user_id']) &&
    class_exists('AuthController') &&
    method_exists('AuthController', 'consumeRememberMe')
) {
    try {
        AuthController::consumeRememberMe($conn);
    } catch (Throwable $e) {
        error_log('[bootstrap remember-me] ' . $e->getMessage());
    }
}

// -----------------------------------------------------------------------------
// 5. Session sync — refresh user data from DB on authenticated requests
//    Uses a TTL so we don't query on every single request (e.g. frequent AJAX).
//    Force-logout if the account is deleted or deactivated mid-session.
//    Skipped on error.php to prevent redirect loops.
// -----------------------------------------------------------------------------

define('BOOTSTRAP_SESSION_SYNC_TTL', 60); // re-sync at most once per minute

$isErrorPage  = php_sapi_name() !== 'cli'
    && basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'error.php';

if (
    php_sapi_name() !== 'cli' &&
    !$isErrorPage &&
    isset($conn, $_SESSION['user_id']) &&
    (int) $_SESSION['user_id'] > 0
) {
    $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
    if ($lastActivity > 0 && (time() - $lastActivity) > (int) SESSION_TIMEOUT) {
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
                'error'    => 'Session expired. Please log in again.',
                'redirect' => '/inventory_system/login.php'
            ]);
            exit;
        }

        safe_redirect('/inventory_system/login.php?reason=timeout');
    }

    $lastSync     = (int) ($_SESSION['_user_synced_at'] ?? 0);
    $syncDue      = (time() - $lastSync) >= BOOTSTRAP_SESSION_SYNC_TTL;

    if ($syncDue) {
        try {
            $sessionUserId = (int) $_SESSION['user_id'];

            $stmt = $conn->prepare("
                SELECT user_id, username, role, status, first_name, last_name, photo
                FROM   users
                WHERE  user_id = :user_id
                LIMIT  1
            ");
            $stmt->execute([':user_id' => $sessionUserId]);
            $freshUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$freshUser || ($freshUser['status'] ?? 'inactive') !== 'active') {
                // Account deactivated or deleted — full session destruction
                $_SESSION = [];
                session_unset();

                if (ini_get('session.use_cookies')) {
                    $params = session_get_cookie_params();
                    setcookie(
                        session_name(), '',
                        time() - 42_000,
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
                        'redirect' => '/inventory_system/login.php',
                    ]);
                    exit;
                }

                safe_redirect('/inventory_system/login.php');
            }

            // Refresh session data with latest DB values
            $_SESSION['username']        = (string) ($freshUser['username']   ?? $_SESSION['username']   ?? '');
            $_SESSION['role']            = (string) ($freshUser['role']        ?? $_SESSION['role']        ?? '');
            $_SESSION['first_name']      = (string) ($freshUser['first_name']  ?? $_SESSION['first_name']  ?? '');
            $_SESSION['last_name']       = (string) ($freshUser['last_name']   ?? $_SESSION['last_name']   ?? '');
            $_SESSION['photo']           = StaffController::normalizePhotoUrl((string) ($freshUser['photo'] ?? $_SESSION['photo'] ?? ''));
            $_SESSION['_user_synced_at'] = time();

        } catch (Throwable $e) {
            error_log('[bootstrap session sync] ' . $e->getMessage());
        }
    }

    // Always update last activity (used by Middleware inactivity timeout)
    $_SESSION['last_activity'] = time();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// -----------------------------------------------------------------------------
// 6. CSRF token initialization
//    Ensure every authenticated session has a valid CSRF token so forms always
//    have protection without relying on per-page token generation calls.
// -----------------------------------------------------------------------------

if (
    php_sapi_name() !== 'cli' &&
    !empty($_SESSION['user_id']) &&
    session_status() === PHP_SESSION_ACTIVE
) {
    if (
        class_exists('Middleware') &&
        method_exists('Middleware', 'generateCsrfToken')
    ) {
        Middleware::generateCsrfToken();
    } elseif (
        class_exists('AuthController') &&
        method_exists('AuthController', 'generateCsrfToken')
    ) {
        AuthController::generateCsrfToken();
    }
}

// -----------------------------------------------------------------------------
// 7. Fatal error shutdown handler
// -----------------------------------------------------------------------------

register_shutdown_function(function () {
    $error = error_get_last();

    if (!$error) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];

    if (!in_array($error['type'], $fatalTypes, true)) {
        return;
    }

    error_log(sprintf(
        '[shutdown] Fatal error: %s in %s:%d',
        $error['message'],
        $error['file'],
        $error['line']
    ));

    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "Fatal application error.\n");
        return;
    }

    $currentPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($currentPage === 'error.php') {
        return;  // Prevent redirect loop on the error page itself
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION['error_code']    = 500;
    $_SESSION['error_message'] = 'A fatal server error occurred. Please try again later.';

    if (!headers_sent()) {
        header('Location: /inventory_system/error.php', true, 302);
        exit;
    }
});

// -----------------------------------------------------------------------------
// 8. Install-lock guards
// -----------------------------------------------------------------------------

if (php_sapi_name() !== 'cli') {
    if (app_is_public_demo() && app_is_install_route()) {
        app_redirect_install_blocked(
            'Installer access is disabled on the public demo. Local client setup should be done on the client machine only.'
        );
    }

    if (app_is_install_route() && app_is_install_locked()) {
        app_redirect_install_blocked(
            'Installer access is disabled because setup has already been completed for this system.'
        );
    }

    if (app_is_install_route() && isset($conn) && app_is_fully_installed($conn)) {
        app_redirect_install_blocked(
            'Installer access is disabled because the application is already installed.'
        );
    }

    if (!app_is_install_route() && isset($conn) && !app_is_fully_installed($conn)) {
        safe_redirect(app_install_url());
    }
}
