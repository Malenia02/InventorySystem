<?php
declare(strict_types=1);

/**
 * bootstrap/app.php
 *
 * Optimisations over the previous version
 * ────────────────────────────────────────
 * CORRECTNESS
 *  1.  The fatal-error shutdown handler is registered FIRST, before any code
 *      that can throw, so it catches bootstrap failures that the original
 *      placement at step 7 would have missed.
 *
 *  2.  app_is_install_route() is called once and cached in $isInstallRoute so
 *      the helper is not executed five or more times across the file.
 *
 *  3.  $_SESSION = [] + session_unset() redundancy removed — session_unset()
 *      alone is sufficient before session_destroy().
 *
 *  4.  Session fixation guard (step 2) now stamps _session_init before calling
 *      session_regenerate_id() and only fires when the stamp is absent, so it
 *      runs exactly ONCE per new unauthenticated session, not on every request
 *      that lacks the stamp.
 *
 *  5.  Timeout and deactivated-account teardown share a single helper
 *      bootstrapDestroySession() to remove ~40 lines of duplicated code.
 *
 *  6.  Cache-Control headers are only sent for non-AJAX authenticated requests
 *      (AJAX responses carry their own cache headers).
 *
 * PERFORMANCE
 *  7.  Session sync result is stored in APCu (key: "sess_sync_{userId}") for
 *      BOOTSTRAP_SESSION_SYNC_TTL seconds.  On cache hit the SELECT is skipped
 *      entirely; on cache miss the DB is queried and the result cached.
 *      Falls back to the original direct-query behaviour when APCu is absent.
 *
 *  8.  BOOTSTRAP_SESSION_SYNC_TTL is defined at the top with other constants
 *      instead of mid-file.
 */

// ── Constants ─────────────────────────────────────────────────────────────────
define('BOOTSTRAP_SESSION_SYNC_TTL', 60); // seconds between user-data re-syncs

// ── 0. Fatal-error shutdown handler (must be first) ──────────────────────────
register_shutdown_function(static function (): void {
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
        return; // Prevent redirect loop
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

// ── Shared requires ───────────────────────────────────────────────────────────
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../controllers/AuthController.php';
require_once __DIR__ . '/../controllers/StaffController.php';

// ── Shared helpers (internal to this file) ────────────────────────────────────

/**
 * Destroy the current session cleanly and redirect.
 * Works for both browser and AJAX callers.
 *
 * @param string $redirectUrl  URL for browser redirects.
 * @param string $message      JSON error message for AJAX callers.
 * @param string $reason       Optional query-string reason appended to $redirectUrl.
 */
function bootstrapDestroySession(string $redirectUrl, string $message, string $reason = ''): never
{
    session_unset();

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(
            session_name(), '',
            time() - 42_000,
            $p['path'], $p['domain'],
            (bool) $p['secure'], (bool) $p['httponly']
        );
    }

    session_destroy();

    if (function_exists('app_is_ajax_or_json') && app_is_ajax_or_json()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success'  => false,
            'error'    => $message,
            'redirect' => $redirectUrl,
        ]);
        exit;
    }

    $target = $reason !== '' ? $redirectUrl . '?reason=' . rawurlencode($reason) : $redirectUrl;
    safe_redirect($target);
}

// ── 1. Install-route / env guard ─────────────────────────────────────────────
// Cache the result — used multiple times below.
$isInstallRoute = php_sapi_name() !== 'cli' && function_exists('app_is_install_route') && app_is_install_route();

if (php_sapi_name() !== 'cli' && !$isInstallRoute && function_exists('app_has_database_env') && !app_has_database_env()) {
    safe_redirect(app_install_url());
}

// ── 2. Session bootstrap ──────────────────────────────────────────────────────
// Harden cookie params THEN start session.  AuthController::configureSessionCookie()
// MUST run before session_start().
if (php_sapi_name() !== 'cli' && session_status() === PHP_SESSION_NONE) {
    if (method_exists('AuthController', 'configureSessionCookie')) {
        AuthController::configureSessionCookie();
    } else {
        // Fallback: inline hardening (reached only if the require above failed)
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

// ── 3. Session fixation guard ─────────────────────────────────────────────────
// For unauthenticated sessions that have never been stamped, regenerate the ID
// ONCE and stamp the session.  Without this, a pre-seeded session cookie from
// an attacker would persist into the authenticated state.
if (
    php_sapi_name() !== 'cli' &&
    empty($_SESSION['user_id']) &&
    empty($_SESSION['_session_init'])
) {
    // Stamp first so the regenerated session carries the flag.
    $_SESSION['_session_init'] = true;
    session_regenerate_id(true);
}

// ── 4. Database connection ────────────────────────────────────────────────────
try {
    require_once DATABASE_PATH . '/database.php';
} catch (Throwable $e) {
    error_log('[bootstrap] DB connection failed: ' . $e->getMessage());

    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "Application startup failed.\n");
        exit(1);
    }

    if (!$isInstallRoute) {
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

// ── 5. Remember-me token consumption ─────────────────────────────────────────
// Must run after DB is available and before any auth/middleware check.
if (
    php_sapi_name() !== 'cli' &&
    isset($conn) &&
    empty($_SESSION['user_id']) &&
    method_exists('AuthController', 'consumeRememberMe')
) {
    try {
        AuthController::consumeRememberMe($conn);
    } catch (Throwable $e) {
        error_log('[bootstrap remember-me] ' . $e->getMessage());
    }
}

// ── 6. Session sync ───────────────────────────────────────────────────────────
// Refresh user data from DB at most once per BOOTSTRAP_SESSION_SYNC_TTL seconds.
// Force-logout if the account is deleted or deactivated mid-session.
// Skipped on error.php to prevent redirect loops.

$isErrorPage = php_sapi_name() !== 'cli'
    && basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'error.php';

if (
    php_sapi_name() !== 'cli' &&
    !$isErrorPage &&
    isset($conn, $_SESSION['user_id']) &&
    (int) $_SESSION['user_id'] > 0
) {
    $sessionUserId = (int) $_SESSION['user_id'];

    // ── Inactivity timeout ────────────────────────────────────────────────
    $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
    if ($lastActivity > 0 && (time() - $lastActivity) > (int) SESSION_TIMEOUT) {
        bootstrapDestroySession(
            '/inventory_system/login.php',
            'Session expired. Please log in again.',
            'timeout'
        );
    }

    // ── User-data sync ────────────────────────────────────────────────────
    $lastSync = (int) ($_SESSION['_user_synced_at'] ?? 0);
    $syncDue  = (time() - $lastSync) >= BOOTSTRAP_SESSION_SYNC_TTL;

    if ($syncDue) {
        $freshUser = null;

        // APCu cache check — skips the DB SELECT on cache hit
        $apCacheKey = 'sess_sync_' . $sessionUserId;
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($apCacheKey, $hit);
            if ($hit && is_array($cached)) {
                $freshUser = $cached;
            }
        }

        // Cache miss — query the DB
        if ($freshUser === null) {
            try {
                $stmt = $conn->prepare(
                    'SELECT user_id, username, role, status, first_name, last_name, photo
                     FROM   users
                     WHERE  user_id = :user_id
                     LIMIT  1'
                );
                $stmt->execute([':user_id' => $sessionUserId]);
                $freshUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

                // Store in APCu for the next BOOTSTRAP_SESSION_SYNC_TTL seconds
                if ($freshUser && function_exists('apcu_store')) {
                    apcu_store($apCacheKey, $freshUser, BOOTSTRAP_SESSION_SYNC_TTL);
                }
            } catch (Throwable $e) {
                error_log('[bootstrap session sync] ' . $e->getMessage());
            }
        }

        // Account deactivated or deleted mid-session
        if (!$freshUser || ($freshUser['status'] ?? 'inactive') !== 'active') {
            // Bust the APCu cache for this user so the next login re-fetches
            if (function_exists('apcu_delete')) {
                apcu_delete($apCacheKey);
            }

            bootstrapDestroySession(
                '/inventory_system/login.php',
                'Your account is unavailable. Please log in again.',
                'deactivated'
            );
        }

        // Refresh session data with latest DB values
        $_SESSION['username']        = (string) ($freshUser['username']  ?? $_SESSION['username']   ?? '');
        $_SESSION['role']            = (string) ($freshUser['role']       ?? $_SESSION['role']        ?? '');
        $_SESSION['first_name']      = (string) ($freshUser['first_name'] ?? $_SESSION['first_name']  ?? '');
        $_SESSION['last_name']       = (string) ($freshUser['last_name']  ?? $_SESSION['last_name']   ?? '');
        $_SESSION['photo']           = StaffController::normalizePhotoUrl(
            (string) ($freshUser['photo'] ?? $_SESSION['photo'] ?? '')
        );
        $_SESSION['_user_synced_at'] = time();
    }

    // Always update last activity
    $_SESSION['last_activity'] = time();

    // Cache-Control only for non-AJAX responses (AJAX sets its own headers)
    if (!function_exists('app_is_ajax_or_json') || !app_is_ajax_or_json()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}

// ── 7. CSRF token initialisation ──────────────────────────────────────────────
// Ensure every authenticated session has a valid CSRF token so forms always
// have protection without relying on per-page token-generation calls.
if (
    php_sapi_name() !== 'cli' &&
    !empty($_SESSION['user_id']) &&
    session_status() === PHP_SESSION_ACTIVE
) {
    if (method_exists('Middleware', 'generateCsrfToken')) {
        Middleware::generateCsrfToken();
    } elseif (method_exists('AuthController', 'generateCsrfToken')) {
        AuthController::generateCsrfToken();
    }
}

// ── 8. Install-lock guards ────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    if (function_exists('app_is_public_demo') && app_is_public_demo() && $isInstallRoute) {
        app_redirect_install_blocked(
            'Installer access is disabled on the public demo.'
        );
    }

    if ($isInstallRoute && function_exists('app_is_install_locked') && app_is_install_locked()) {
        app_redirect_install_blocked(
            'Installer access is disabled because setup has already been completed.'
        );
    }

    if ($isInstallRoute && isset($conn) && function_exists('app_is_fully_installed') && app_is_fully_installed($conn)) {
        app_redirect_install_blocked(
            'Installer access is disabled because the application is already installed.'
        );
    }

    if (
        !$isInstallRoute &&
        isset($conn) &&
        function_exists('app_is_fully_installed') &&
        app_is_fully_installed($conn) &&
        function_exists('app_migration_status')
    ) {
        $migrationStatus = app_migration_status($conn);
        $pendingMigrations = is_array($migrationStatus['pending'] ?? null) ? $migrationStatus['pending'] : [];
        $driftedMigrations = is_array($migrationStatus['drifted'] ?? null) ? $migrationStatus['drifted'] : [];

        if ($driftedMigrations !== []) {
            error_log('[bootstrap] Applied migration checksum mismatch: ' . implode(', ', $driftedMigrations));
            http_response_code(500);
            exit('Application migration integrity error. Review applied migrations before continuing.');
        }

        if ($pendingMigrations !== []) {
            if (function_exists('app_runtime_schema_changes_allowed') && app_runtime_schema_changes_allowed()) {
                app_apply_pending_migrations($conn);
            } else {
                error_log('[bootstrap] Pending database migrations: ' . implode(', ', $pendingMigrations));
                http_response_code(503);
                exit('Database migrations are pending. Run the required migrations before serving this application.');
            }
        }
    }

    if (!$isInstallRoute && isset($conn) && function_exists('app_is_fully_installed') && !app_is_fully_installed($conn)) {
        safe_redirect(app_install_url());
    }
}
