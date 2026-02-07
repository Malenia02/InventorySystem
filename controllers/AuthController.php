<?php
// Prevent direct access to this file via browser
if (php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

class AuthController
{
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_TIME = 600; // 10 minutes

    private static function startSession()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    private static function getIpAddress(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    }

    // ==========================
    // CSRF TOKEN GENERATION
    // ==========================
    public static function generateCSRFToken()
    {
        self::startSession();

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    // ==========================
    // ACTIVITY LOGGER (YOUR TABLE STRUCTURE)
    // ==========================
    public static function logActivity(
        PDO $conn,
        string $table_activity_logs,
        string $col_user_id,
        string $col_action,
        string $col_description,
        string $col_ip_address,
        string $col_created_at,
        ?int $userId,
        string $action,
        string $description = ''
    ): void {
        try {
            $ip = self::getIpAddress();

            $stmt = $conn->prepare("
                INSERT INTO {$table_activity_logs}
                    ({$col_user_id}, {$col_action}, {$col_description}, {$col_ip_address}, {$col_created_at})
                VALUES
                    (:user_id, :action, :description, :ip_address, NOW())
            ");

            $stmt->execute([
                ':user_id'     => $userId,
                ':action'      => $action,
                ':description' => $description,
                ':ip_address'  => $ip
            ]);
        } catch (Exception $e) {
            error_log("Activity log error: " . $e->getMessage());
        }
    }

    // ==========================
    // LOGIN METHOD
    // ==========================
    public static function login(
        PDO $conn,

        // users table config
        string $table_users,
        string $user_username,
        string $user_password,
        string $user_id,
        string $user_role,
        string $user_status,

        // activity logs config
        string $table_activity_logs,
        string $activity_log_user_id,
        string $activity_log_action,
        string $activity_log_desc,
        string $activity_log_ip,
        string $activity_log_created
    ) {
        self::startSession();

        $username   = trim($_POST['username'] ?? '');
        $password   = trim($_POST['password'] ?? '');
        $csrf_token = $_POST['csrf_token'] ?? '';

        // 🔐 CSRF CHECK
        if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
            http_response_code(403);

            self::logActivity(
                $conn,
                $table_activity_logs,
                $activity_log_user_id,
                $activity_log_action,
                $activity_log_desc,
                $activity_log_ip,
                $activity_log_created,
                null,
                'login_failed',
                "Invalid CSRF token attempt for username: {$username}"
            );

            return "Invalid CSRF token.";
        }

        // Validate input
        if ($username === '' || $password === '') {
            self::logActivity(
                $conn,
                $table_activity_logs,
                $activity_log_user_id,
                $activity_log_action,
                $activity_log_desc,
                $activity_log_ip,
                $activity_log_created,
                null,
                'login_failed',
                "Empty username/password attempt"
            );
            return "Username and password are required.";
        }

        // Rate limiting
        if (!isset($_SESSION['login_attempts'])) $_SESSION['login_attempts'] = 0;
        if (!isset($_SESSION['last_attempt_time'])) $_SESSION['last_attempt_time'] = 0;

        if (
            $_SESSION['login_attempts'] >= self::MAX_ATTEMPTS &&
            time() - $_SESSION['last_attempt_time'] < self::LOCKOUT_TIME
        ) {
            self::logActivity(
                $conn,
                $table_activity_logs,
                $activity_log_user_id,
                $activity_log_action,
                $activity_log_desc,
                $activity_log_ip,
                $activity_log_created,
                null,
                'login_blocked',
                "Blocked login due to too many attempts for username: {$username}"
            );

            return "Too many login attempts. Try again later.";
        }

        try {
            $stmt = $conn->prepare(
                "SELECT * FROM {$table_users} WHERE {$user_username} = :username LIMIT 1"
            );
            $stmt->execute(['username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // user not found
            if (!$user) {
                $_SESSION['login_attempts']++;
                $_SESSION['last_attempt_time'] = time();

                self::logActivity(
                    $conn,
                    $table_activity_logs,
                    $activity_log_user_id,
                    $activity_log_action,
                    $activity_log_desc,
                    $activity_log_ip,
                    $activity_log_created,
                    null,
                    'login_failed',
                    "Invalid username: {$username}"
                );

                return "Invalid username or password.";
            }

            // verify password (hashed OR plain fallback)
            $dbPass = $user[$user_password];
            $isValid = password_verify($password, $dbPass) || hash_equals($dbPass, $password);

            if (!$isValid) {
                $_SESSION['login_attempts']++;
                $_SESSION['last_attempt_time'] = time();

                self::logActivity(
                    $conn,
                    $table_activity_logs,
                    $activity_log_user_id,
                    $activity_log_action,
                    $activity_log_desc,
                    $activity_log_ip,
                    $activity_log_created,
                    (int)$user[$user_id],
                    'login_failed',
                    "Wrong password for username: {$username}"
                );

                return "Invalid username or password.";
            }

            // Check account status
            if (isset($user[$user_status]) && $user[$user_status] !== 'active') {
                self::logActivity(
                    $conn,
                    $table_activity_logs,
                    $activity_log_user_id,
                    $activity_log_action,
                    $activity_log_desc,
                    $activity_log_ip,
                    $activity_log_created,
                    (int)$user[$user_id],
                    'login_failed',
                    "Inactive account login attempt for username: {$username}"
                );

                return "Your account is inactive. Please contact admin.";
            }

            // ✅ Successful login
            session_regenerate_id(true);

            $_SESSION['user_id']  = $user[$user_id];
            $_SESSION['username'] = $user[$user_username];
            $_SESSION['role']     = $user[$user_role];

            // Reset attempts
            $_SESSION['login_attempts'] = 0;

            // Regenerate CSRF after login
            unset($_SESSION['csrf_token']);
            self::generateCSRFToken();

            // Remember me cookie
            if (!empty($_POST['remember'])) {
                $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

                setcookie(
                    'user_id',
                    (string)$user[$user_id],
                    time() + (86400 * 30),
                    "/",
                    "",
                    $isHttps,
                    true
                );
            }

            // log login success
            self::logActivity(
                $conn,
                $table_activity_logs,
                $activity_log_user_id,
                $activity_log_action,
                $activity_log_desc,
                $activity_log_ip,
                $activity_log_created,
                (int)$user[$user_id],
                'login_success',
                "User logged in: Role: {$username}"
            );

            header("Location: /inventory_system/index.php");
            exit;

        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());

            self::logActivity(
                $conn,
                $table_activity_logs,
                $activity_log_user_id,
                $activity_log_action,
                $activity_log_desc,
                $activity_log_ip,
                $activity_log_created,
                null,
                'login_failed',
                "Database error during login for username: {$username}"
            );

            return "A database error occurred. Please try again later.";
        }
    }

    // ==========================
    // LOGOUT METHOD
    // ==========================
    public static function logout(
        PDO $conn,
        string $table_activity_logs,
        string $activity_log_user_id,
        string $activity_log_action,
        string $activity_log_desc,
        string $activity_log_ip,
        string $activity_log_created
    ) {
        self::startSession();

        $userId = $_SESSION['user_id'] ?? null;
        $username = $_SESSION['username'] ?? 'UNKNOWN';

        self::logActivity(
            $conn,
            $table_activity_logs,
            $activity_log_user_id,
            $activity_log_action,
            $activity_log_desc,
            $activity_log_ip,
            $activity_log_created,
            $userId ? (int)$userId : null,
            'logout',
            "User logged out: Role: {$username}"
        );

        $_SESSION = [];
        session_unset();
        session_destroy();

        if (isset($_COOKIE['user_id'])) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            setcookie('user_id', '', time() - 3600, '/', '', $isHttps, true);
        }

        header("Location: /inventory_system/login.php");
        exit;
    }
    
}


?>
