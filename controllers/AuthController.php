<?php
if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION['error_code'] = 403;
    $_SESSION['error_message'] = 'Direct access is not allowed.';

    header('Location: /inventory_system/error.php');
    exit;
}

class AuthController
{
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_TIME = 600; // 10 minutes
    private const REMEMBER_DAYS = 30;
    private const REMEMBER_COOKIE = 'remember_me';

    private static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    private static function getIpAddress(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    }

    public static function generateCsrfToken(): string
    {
        self::startSession();

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = time();
        }

        return $_SESSION['csrf_token'];
    }

    public static function validateCsrfToken(): bool
    {
        self::startSession();

        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if (empty($sessionToken)) {
            return false;
        }

        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!empty($headerToken)) {
            return hash_equals($sessionToken, $headerToken);
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $body = json_decode(file_get_contents('php://input'), true);
            $token = $body['csrf_token'] ?? '';
            return !empty($token) && hash_equals($sessionToken, $token);
        }

        $token = $_POST['csrf_token'] ?? '';
        return !empty($token) && hash_equals($sessionToken, $token);
    }

    private static function rotateCsrfToken(): void
    {
        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
        self::generateCsrfToken();
    }

   public static function logActivity(
    PDO $conn,
    array $logConfig,
    ?int $userId,
    string $action,
    string $description = '',
    ?string $entityType = null,
    ?int $entityId = null,
    string $level = 'info'
): void {
    try {
        $stmt = $conn->prepare("
            INSERT INTO {$logConfig['table']}
            (
                {$logConfig['col_user_id']},
                {$logConfig['col_action']},
                {$logConfig['col_desc']},
                {$logConfig['col_ip']},
                {$logConfig['col_created']},
                entity_type,
                entity_id,
                level,
                user_agent
            )
            VALUES
            (
                :user_id,
                :action,
                :description,
                :ip,
                NOW(),
                :entity_type,
                :entity_id,
                :level,
                :user_agent
            )
        ");

        $stmt->execute([
            ':user_id'     => $userId,
            ':action'      => $action,
            ':description' => $description,
            ':ip'          => self::getIpAddress(),
            ':entity_type' => $entityType,
            ':entity_id'   => $entityId,
            ':level'       => $level,
            ':user_agent'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);

    } catch (Exception $e) {
        error_log('[AuthController] logActivity error: ' . $e->getMessage());
    }
}

    private static function setRememberMe(PDO $conn, int $userId): void
    {
        $selector = bin2hex(random_bytes(12));
        $token    = bin2hex(random_bytes(32));
        $hash     = hash('sha256', $token);
        $expires  = (new DateTimeImmutable('+' . self::REMEMBER_DAYS . ' days'))->format('Y-m-d H:i:s');

        $stmt = $conn->prepare("
            INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at, user_agent, ip_address)
            VALUES (:user_id, :selector, :token_hash, :expires_at, :user_agent, :ip_address)
        ");

        $stmt->execute([
            ':user_id'    => $userId,
            ':selector'   => $selector,
            ':token_hash' => $hash,
            ':expires_at' => $expires,
            ':user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ':ip_address' => self::getIpAddress(),
        ]);

        $cookieValue = $selector . ':' . $token;
        $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

        setcookie(self::REMEMBER_COOKIE, $cookieValue, [
            'expires'  => time() + (86400 * self::REMEMBER_DAYS),
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function consumeRememberMe(PDO $conn, array $userConfig): bool
    {
        self::startSession();

        if (!empty($_SESSION['user_id'])) {
            return true;
        }

        $cookie = $_COOKIE[self::REMEMBER_COOKIE] ?? '';
        if ($cookie === '' || !str_contains($cookie, ':')) {
            return false;
        }

        [$selector, $token] = explode(':', $cookie, 2);
        if ($selector === '' || $token === '') {
            return false;
        }

        $stmt = $conn->prepare("
            SELECT rt.*, u.*
            FROM remember_tokens rt
            INNER JOIN {$userConfig['table']} u
                ON u.{$userConfig['col_id']} = rt.user_id
            WHERE rt.selector = :selector
              AND rt.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([':selector' => $selector]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            self::clearRememberCookie();
            return false;
        }

        $tokenHash = hash('sha256', $token);
        if (!hash_equals($row['token_hash'], $tokenHash)) {
            $delete = $conn->prepare("DELETE FROM remember_tokens WHERE selector = :selector");
            $delete->execute([':selector' => $selector]);
            self::clearRememberCookie();
            return false;
        }

        if (($row[$userConfig['col_status']] ?? 'active') !== 'active') {
            self::clearRememberCookie();
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id']       = (int)$row[$userConfig['col_id']];
        $_SESSION['username']      = $row[$userConfig['col_username']];
        $_SESSION['role']          = $row[$userConfig['col_role']];
        $_SESSION['first_name']    = $row[$userConfig['col_first_name']] ?? '';
        $_SESSION['last_name']     = $row[$userConfig['col_last_name']] ?? '';
        $_SESSION['photo']         = $row[$userConfig['col_photo']] ?? '';
        $_SESSION['last_activity'] = time();

        self::rotateCsrfToken();

        // Rotate remember token
        $delete = $conn->prepare("DELETE FROM remember_tokens WHERE selector = :selector");
        $delete->execute([':selector' => $selector]);
        self::setRememberMe($conn, (int)$row[$userConfig['col_id']]);

        $update = $conn->prepare("UPDATE remember_tokens SET last_used_at = NOW() WHERE selector = :selector");
        $update->execute([':selector' => $selector]);

        return true;
    }

    private static function clearRememberCookie(): void
    {
        $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie(self::REMEMBER_COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function login(PDO $conn, array $userConfig, array $logConfig): ?string
    {
        self::startSession();

        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $ip       = self::getIpAddress();

        if ($username === '' || $password === '') {
            self::logActivity($conn, $logConfig, null, 'login_failed', 'Empty username or password');
            return 'Username and password are required.';
        }

        $csrfInput   = $_POST['csrf_token'] ?? '';
        $csrfSession = $_SESSION['csrf_token'] ?? '';

        if (empty($csrfInput) || empty($csrfSession) || !hash_equals($csrfSession, $csrfInput)) {
            http_response_code(403);
            self::rotateCsrfToken();
            self::logActivity($conn, $logConfig, null, 'login_failed', "CSRF mismatch for: {$username}");
            return 'Invalid request. Please refresh and try again.';
        }

        $attemptKey = 'login_attempts_' . hash('sha256', strtolower($username) . '|' . $ip);

        $attempts = $_SESSION[$attemptKey]['count'] ?? 0;
        $lastTime = $_SESSION[$attemptKey]['last'] ?? 0;

        if ($attempts >= self::MAX_ATTEMPTS && (time() - $lastTime) >= self::LOCKOUT_TIME) {
            unset($_SESSION[$attemptKey]);
            $attempts = 0;
            $lastTime = 0;
        }

        if ($attempts >= self::MAX_ATTEMPTS) {
            $remaining = self::LOCKOUT_TIME - (time() - $lastTime);
            $minutes   = ceil($remaining / 60);
            self::logActivity($conn, $logConfig, null, 'login_blocked', "Too many attempts for: {$username}");
            return "Too many login attempts. Try again in {$minutes} minute(s).";
        }

        try {
            $stmt = $conn->prepare("
                SELECT * FROM {$userConfig['table']}
                WHERE {$userConfig['col_username']} = :username
                LIMIT 1
            ");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $_SESSION[$attemptKey] = ['count' => $attempts + 1, 'last' => time()];
                self::rotateCsrfToken();
                self::logActivity($conn, $logConfig, null, 'login_failed', "Username not found: {$username}");
                return 'Invalid username or password.';
            }

            if (!password_verify($password, $user[$userConfig['col_password']])) {
                $_SESSION[$attemptKey] = ['count' => $attempts + 1, 'last' => time()];
                self::rotateCsrfToken();
                self::logActivity($conn, $logConfig, (int)$user[$userConfig['col_id']], 'login_failed', "Wrong password for: {$username}");
                return 'Invalid username or password.';
            }

            if (($user[$userConfig['col_status']] ?? 'active') !== 'active') {
                self::rotateCsrfToken();
                self::logActivity($conn, $logConfig, (int)$user[$userConfig['col_id']], 'login_failed', "Inactive account: {$username}");
                return 'Your account is inactive. Please contact the administrator.';
            }

            session_regenerate_id(true);

            $_SESSION['user_id']       = (int)$user[$userConfig['col_id']];
            $_SESSION['username']      = $user[$userConfig['col_username']];
            $_SESSION['role']          = $user[$userConfig['col_role']];
            $_SESSION['first_name']    = $user[$userConfig['col_first_name']] ?? '';
            $_SESSION['last_name']     = $user[$userConfig['col_last_name']] ?? '';
            $_SESSION['photo']         = $user[$userConfig['col_photo']] ?? '';
            $_SESSION['last_activity'] = time();

            unset($_SESSION[$attemptKey]);

            self::rotateCsrfToken();

            if (!empty($_POST['remember'])) {
                self::setRememberMe($conn, (int)$user[$userConfig['col_id']]);
            }

            self::logActivity($conn, $logConfig, (int)$user[$userConfig['col_id']], 'login_success', "User logged in: {$username}");

            header('Location: /inventory_system/index.php');
            exit;

        } catch (PDOException $e) {
            error_log('[AuthController] Login error: ' . $e->getMessage());
            self::logActivity($conn, $logConfig, null, 'login_failed', "DB error for: {$username}");
            return 'A server error occurred. Please try again later.';
        }
    }

    public static function logout(PDO $conn, array $logConfig): void
    {
        self::startSession();

        $userId   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $username = $_SESSION['username'] ?? 'UNKNOWN';

        if (!empty($_COOKIE[self::REMEMBER_COOKIE]) && str_contains($_COOKIE[self::REMEMBER_COOKIE], ':')) {
            [$selector] = explode(':', $_COOKIE[self::REMEMBER_COOKIE], 2);
            $delete = $conn->prepare("DELETE FROM remember_tokens WHERE selector = :selector");
            $delete->execute([':selector' => $selector]);
        }

        self::clearRememberCookie();

        self::logActivity($conn, $logConfig, $userId, 'logout', "User logged out: {$username}");

        $_SESSION = [];
        session_unset();

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
        }

        session_destroy();

        header('Location: /inventory_system/login.php');
        exit;
    }
}