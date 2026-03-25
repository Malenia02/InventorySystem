<?php
/**
 * controllers/AuthController.php
 *
 * Handles login, logout, CSRF, and activity logging.
 * Clean version — no duplicate CSRF, no plain text password fallback,
 * first_name/last_name/photo stored in session, logActivity simplified.
 */

// Prevent direct browser access
if (php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

class AuthController
{
    // ================================================================
    //  CONSTANTS
    // ================================================================
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_TIME = 600; // 10 minutes

    // ================================================================
    //  SESSION HELPER
    // ================================================================
    private static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    // ================================================================
    //  IP ADDRESS
    // ================================================================
    private static function getIpAddress(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    }

    // ================================================================
    //  CSRF — generate token
    //  Called by Middleware::csrfToken() and after successful login.
    // ================================================================
    public static function generateCsrfToken(): string
    {
        self::startSession();

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    // ================================================================
    //  CSRF — validate token
    //  Called ONLY by Middleware::csrf().
    //  Login validates its own CSRF inline (user not authenticated yet).
    //
    //  Supports three token sources (in priority order):
    //    1. HTTP header:  X-CSRF-TOKEN (preferred for fetch/AJAX)
    //    2. JSON body:    { "csrf_token": "..." }
    //    3. Form POST:    $_POST['csrf_token']
    // ================================================================
    public static function validateCsrfToken(): bool
    {
        self::startSession();

        $sessionToken = $_SESSION['csrf_token'] ?? '';

        if (empty($sessionToken)) {
            return false;
        }

        // 1. Header (fetch with X-CSRF-TOKEN)
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!empty($headerToken)) {
            return hash_equals($sessionToken, $headerToken);
        }

        // 2. JSON body
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $body  = json_decode(file_get_contents('php://input'), true);
            $token = $body['csrf_token'] ?? '';
            return !empty($token) && hash_equals($sessionToken, $token);
        }

        // 3. Form POST
        $token = $_POST['csrf_token'] ?? '';
        return !empty($token) && hash_equals($sessionToken, $token);
    }

    // ================================================================
    //  CSRF — rotate token (call after any failed login attempt)
    // ================================================================
    private static function rotateCsrfToken(): void
    {
        unset($_SESSION['csrf_token']);
        self::generateCsrfToken();
    }

    // ================================================================
    //  ACTIVITY LOGGER — simplified with $logConfig array
    //
    //  Build $logConfig once in config.php or your page:
    //
    //  $logConfig = [
    //      'table'       => $table_activity_logs,
    //      'col_user_id' => $activity_log_user_id,
    //      'col_action'  => $activity_log_action,
    //      'col_desc'    => $activity_log_desc,
    //      'col_ip'      => $activity_log_ip,
    //      'col_created' => $activity_log_created,
    //  ];
    // ================================================================
    public static function logActivity(
        PDO    $conn,
        array  $logConfig,
        ?int   $userId,
        string $action,
        string $description = ''
    ): void {
        try {
            $stmt = $conn->prepare("
                INSERT INTO {$logConfig['table']}
                    ({$logConfig['col_user_id']},
                     {$logConfig['col_action']},
                     {$logConfig['col_desc']},
                     {$logConfig['col_ip']},
                     {$logConfig['col_created']})
                VALUES
                    (:user_id, :action, :description, :ip, NOW())
            ");

            $stmt->execute([
                ':user_id'     => $userId,
                ':action'      => $action,
                ':description' => $description,
                ':ip'          => self::getIpAddress(),
            ]);
        } catch (Exception $e) {
            error_log('[AuthController] logActivity error: ' . $e->getMessage());
        }
    }

    // ================================================================
    //  LOGIN
    //
    //  $userConfig = [
    //      'table'          => $table_users,
    //      'col_id'         => $user_id,
    //      'col_username'   => $user_username,
    //      'col_password'   => $user_password,
    //      'col_role'       => $user_role,
    //      'col_status'     => $user_status,
    //      'col_first_name' => $user_firstname,
    //      'col_last_name'  => $user_lastname,
    //      'col_photo'      => $user_photoPath,
    //  ];
    // ================================================================
    public static function login(
        PDO   $conn,
        array $userConfig,
        array $logConfig
    ): string|null {
        self::startSession();

        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        // ── Empty input ──────────────────────────────────────
        if ($username === '' || $password === '') {
            self::logActivity($conn, $logConfig, null,
                'login_failed', 'Empty username or password');
            return 'Username and password are required.';
        }

        // ── CSRF (inline — user not authenticated yet) ───────
        $csrfInput   = $_POST['csrf_token'] ?? '';
        $csrfSession = $_SESSION['csrf_token'] ?? '';

        if (empty($csrfInput) || empty($csrfSession) || !hash_equals($csrfSession, $csrfInput)) {
            http_response_code(403);
            self::rotateCsrfToken();
            self::logActivity($conn, $logConfig, null,
                'login_failed', "CSRF mismatch for: {$username}");
            return 'Invalid request. Please refresh and try again.';
        }

        // ── Rate limiting ────────────────────────────────────
        $attempts = $_SESSION['login_attempts']    ?? 0;
        $lastTime = $_SESSION['last_attempt_time'] ?? 0;

        // Reset if lockout period has passed
        if ($attempts >= self::MAX_ATTEMPTS && (time() - $lastTime) >= self::LOCKOUT_TIME) {
            $_SESSION['login_attempts']    = 0;
            $_SESSION['last_attempt_time'] = 0;
            $attempts = 0;
        }

        if ($attempts >= self::MAX_ATTEMPTS) {
            $remaining = self::LOCKOUT_TIME - (time() - $lastTime);
            $minutes   = ceil($remaining / 60);
            self::logActivity($conn, $logConfig, null,
                'login_blocked', "Too many attempts for: {$username}");
            return "Too many login attempts. Try again in {$minutes} minute(s).";
        }

        // ── Query user ───────────────────────────────────────
        try {
            $stmt = $conn->prepare("
                SELECT * FROM {$userConfig['table']}
                WHERE {$userConfig['col_username']} = :username
                LIMIT 1
            ");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // User not found
            if (!$user) {
                $_SESSION['login_attempts']    = $attempts + 1;
                $_SESSION['last_attempt_time'] = time();
                self::rotateCsrfToken();
                self::logActivity($conn, $logConfig, null,
                    'login_failed', "Username not found: {$username}");
                return 'Invalid username or password.';
            }

            // ── Verify password — bcrypt only ────────────────
            if (!password_verify($password, $user[$userConfig['col_password']])) {
                $_SESSION['login_attempts']    = $attempts + 1;
                $_SESSION['last_attempt_time'] = time();
                self::rotateCsrfToken();
                self::logActivity($conn, $logConfig, (int)$user[$userConfig['col_id']],
                    'login_failed', "Wrong password for: {$username}");
                return 'Invalid username or password.';
            }

            // ── Account status ───────────────────────────────
            if (($user[$userConfig['col_status']] ?? 'active') !== 'active') {
                self::rotateCsrfToken();
                self::logActivity($conn, $logConfig, (int)$user[$userConfig['col_id']],
                    'login_failed', "Inactive account: {$username}");
                return 'Your account is inactive. Please contact the administrator.';
            }

            // ── Successful login ─────────────────────────────
            session_regenerate_id(true);

            $_SESSION['user_id']    = (int)$user[$userConfig['col_id']];
            $_SESSION['username']   = $user[$userConfig['col_username']];
            $_SESSION['role']       = $user[$userConfig['col_role']];
            $_SESSION['first_name'] = $user[$userConfig['col_first_name']] ?? '';
            $_SESSION['last_name']  = $user[$userConfig['col_last_name']]  ?? '';
            $_SESSION['photo']      = $user[$userConfig['col_photo']]      ?? '';

            // Reset rate limit
            $_SESSION['login_attempts']    = 0;
            $_SESSION['last_attempt_time'] = 0;

            // Regenerate CSRF after login
            unset($_SESSION['csrf_token']);
            self::generateCsrfToken();

            // Remember me cookie
            if (!empty($_POST['remember'])) {
                $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
                setcookie('user_id', (string)$user[$userConfig['col_id']],
                    time() + (86400 * 30), '/', '', $isHttps, true);
            }

            self::logActivity($conn, $logConfig, (int)$user[$userConfig['col_id']],
                'login_success', "User logged in: {$username}");

            header('Location: /inventory_system/index.php');
            exit;

        } catch (PDOException $e) {
            error_log('[AuthController] Login error: ' . $e->getMessage());
            self::logActivity($conn, $logConfig, null,
                'login_failed', "DB error for: {$username}");
            return 'A server error occurred. Please try again later.';
        }
    }

    // ================================================================
    //  LOGOUT
    // ================================================================
    public static function logout(PDO $conn, array $logConfig): void
    {
        self::startSession();

        $userId   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $username = $_SESSION['username'] ?? 'UNKNOWN';

        self::logActivity($conn, $logConfig, $userId,
            'logout', "User logged out: {$username}");

        $_SESSION = [];
        session_unset();
        session_destroy();

        if (isset($_COOKIE['user_id'])) {
            $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            setcookie('user_id', '', time() - 3600, '/', '', $isHttps, true);
        }

        header('Location: /inventory_system/login.php');
        exit;
    }
}