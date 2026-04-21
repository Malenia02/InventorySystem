<?php
declare(strict_types=1);

require_once __DIR__ . '/NotificationController.php';

final class AuthController
{
    private const TABLE = 'users';
    private const REMEMBER_TABLE = 'remember_tokens';
    private const LOGIN_ATTEMPTS_TABLE = 'login_attempts';

    private const MAX_ATTEMPTS_COMBO = 5;
    private const MAX_ATTEMPTS_USERNAME = 8;
    private const MAX_ATTEMPTS_IP = 15;
    private const CAPTCHA_THRESHOLD_COMBO = 3;
    private const CAPTCHA_THRESHOLD_USERNAME = 5;
    private const CAPTCHA_THRESHOLD_IP = 7;
    private const LOCKOUT_TIME = 600; // 10 minutes
    private const ALERT_COOLDOWN = 900; // 15 minutes
    private const REMEMBER_DAYS = 30;
    private const REMEMBER_COOKIE = 'remember_me';
    private const FAILURE_DELAY_MIN_US = 250000;
    private const FAILURE_DELAY_MAX_US = 450000;

    public static function generateCsrfToken(): string
    {
        if (
            empty($_SESSION['csrf_token']) ||
            empty($_SESSION['csrf_token_time']) ||
            (time() - (int) $_SESSION['csrf_token_time']) > 1800
        ) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = time();
        }

        return (string) $_SESSION['csrf_token'];
    }

    public static function rotateCsrfToken(): string
    {
        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
        return self::generateCsrfToken();
    }

    public static function validateCsrfToken(?string $token = null): bool
    {
        $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');

        if ($sessionToken === '') {
            return false;
        }

        if ($token === null) {
            $headerToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
            if ($headerToken !== '') {
                $token = $headerToken;
            } else {
                $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

                if (str_contains($contentType, 'application/json')) {
                    $raw = file_get_contents('php://input');
                    $body = json_decode($raw, true);
                    $token = is_array($body) ? (string) ($body['csrf_token'] ?? '') : '';
                } else {
                    $token = (string) ($_POST['csrf_token'] ?? '');
                }
            }
        }

        return $token !== '' && hash_equals($sessionToken, $token);
    }

    public static function login(PDO $conn, array $credentials, array $logConfig): array
    {
        $username = trim((string) ($credentials['username'] ?? ''));
        $password = trim((string) ($credentials['password'] ?? ''));
        $remember = !empty($credentials['remember']);
        $csrfToken = (string) ($credentials['csrf_token'] ?? '');
        $captchaAnswer = trim((string) ($credentials['captcha_answer'] ?? ''));
        $normalizedUsername = self::normalizeLoginIdentifier($username);
        $ip = self::getIpAddress();

        self::ensureLoginAttemptsTable($conn);

        if ($username === '' || $password === '') {
            self::applyFailureDelay();
            self::logActivity(
                $conn,
                $logConfig,
                null,
                'login_failed',
                self::buildLoginFailureDescription($normalizedUsername, 'empty_credentials'),
                'auth',
                null,
                'security'
            );
            return [
                'success' => false,
                'message' => 'Username and password are required.',
            ];
        }

        if (!self::validateCsrfToken($csrfToken)) {
            self::rotateCsrfToken();
            self::applyFailureDelay();
            self::logActivity(
                $conn,
                $logConfig,
                null,
                'login_failed',
                self::buildLoginFailureDescription($normalizedUsername, 'csrf_mismatch'),
                'auth',
                null,
                'security'
            );
            return [
                'success' => false,
                'message' => 'Invalid request. Please refresh and try again.',
                'code'    => 403,
            ];
        }

        $rateLimit = self::checkLoginRateLimit($conn, $logConfig, $normalizedUsername, $ip);
        if ($rateLimit !== null) {
            self::logActivity(
                $conn,
                $logConfig,
                null,
                'login_blocked',
                self::buildLoginBlockedDescription($normalizedUsername, $rateLimit['scope']),
                'auth',
                null,
                'security'
            );

            return [
                'success' => false,
                'message' => 'Too many login attempts. Please wait 10 minutes before trying again.',
                'code'    => 429,
            ];
        }

        if (self::isCaptchaRequired($conn, $normalizedUsername, $ip) && !self::validateCaptchaAnswer($captchaAnswer)) {
            self::recordFailedLoginAttempt($conn, $logConfig, null, $normalizedUsername, $ip, 'captcha_failed');
            self::rotateCsrfToken();
            self::applyFailureDelay();

            return [
                'success' => false,
                'message' => 'Please answer the verification challenge correctly.',
                'code'    => 422,
            ];
        }

        try {
            $stmt = $conn->prepare("
                SELECT user_id, username, password, role, first_name, last_name, photo, status
                FROM " . self::TABLE . "
                WHERE username = :username
                LIMIT 1
            ");
            $stmt->execute([':username' => $username]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                self::recordFailedLoginAttempt($conn, $logConfig, null, $normalizedUsername, $ip, 'username_not_found');
                self::rotateCsrfToken();
                self::applyFailureDelay();

                return [
                    'success' => false,
                    'message' => 'Invalid username or password.',
                ];
            }

            if (!password_verify($password, (string) $user['password'])) {
                self::recordFailedLoginAttempt($conn, $logConfig, (int) $user['user_id'], $normalizedUsername, $ip, 'wrong_password');
                self::rotateCsrfToken();
                self::applyFailureDelay();

                return [
                    'success' => false,
                    'message' => 'Invalid username or password.',
                ];
            }

            if (($user['status'] ?? 'active') !== 'active') {
                self::rotateCsrfToken();
                self::logActivity(
                    $conn,
                    $logConfig,
                    (int) $user['user_id'],
                    'login_failed',
                    self::buildLoginFailureDescription($normalizedUsername, 'inactive_account'),
                    'auth',
                    (int) $user['user_id'],
                    'security'
                );

                return [
                    'success' => false,
                    'message' => 'Your account is inactive. Please contact the administrator.',
                ];
            }

            session_regenerate_id(true);

            $_SESSION['user_id'] = (int) $user['user_id'];
            $_SESSION['username'] = (string) $user['username'];
            $_SESSION['role'] = (string) $user['role'];
            $_SESSION['first_name'] = (string) ($user['first_name'] ?? '');
            $_SESSION['last_name'] = (string) ($user['last_name'] ?? '');
            $_SESSION['photo'] = (string) ($user['photo'] ?? '');
            $_SESSION['last_activity'] = time();

            self::clearLoginAttempts($conn, $normalizedUsername, $ip);
            self::clearCaptchaChallenge();
            self::rotateCsrfToken();

            if ($remember) {
                self::setRememberMe($conn, (int) $user['user_id']);
            }

            self::logActivity($conn, $logConfig, (int) $user['user_id'], 'login_success', "User logged in: {$username}", 'auth', (int) $user['user_id']);

            return [
                'success'  => true,
                'message'  => 'Login successful.',
                'redirect' => '/inventory_system/index.php',
            ];
        } catch (PDOException $e) {
            error_log('[AuthController::login] ' . $e->getMessage());
            self::logActivity(
                $conn,
                $logConfig,
                null,
                'login_failed',
                self::buildLoginFailureDescription($normalizedUsername, 'db_error'),
                'auth',
                null,
                'security'
            );

            return [
                'success' => false,
                'message' => 'A server error occurred. Please try again later.',
            ];
        }
    }

    public static function getLoginSecurityState(PDO $conn, string $username = ''): array
    {
        self::ensureLoginAttemptsTable($conn);

        $normalizedUsername = self::normalizeLoginIdentifier($username);
        $ip = self::getIpAddress();
        $captchaRequired = self::isCaptchaRequired($conn, $normalizedUsername, $ip);

        return [
            'captcha_required' => $captchaRequired,
            'captcha_prompt'   => $captchaRequired ? self::getCaptchaChallengePrompt() : null,
        ];
    }

    public static function logout(PDO $conn, array $logConfig): void
    {
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $username = (string) ($_SESSION['username'] ?? 'UNKNOWN');

        $cookie = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if ($cookie !== '' && str_contains($cookie, ':')) {
            [$selector] = explode(':', $cookie, 2);

            $delete = $conn->prepare("
                DELETE FROM " . self::REMEMBER_TABLE . "
                WHERE selector = :selector
            ");
            $delete->execute([':selector' => $selector]);
        }

        self::clearRememberCookie();
        self::logActivity($conn, $logConfig, $userId, 'logout', "User logged out: {$username}", 'auth', $userId);

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
    }

    public static function consumeRememberMe(PDO $conn): bool
    {
        if (!empty($_SESSION['user_id'])) {
            return true;
        }

        $cookie = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if ($cookie === '' || !str_contains($cookie, ':')) {
            return false;
        }

        [$selector, $token] = explode(':', $cookie, 2);
        if ($selector === '' || $token === '') {
            return false;
        }

        $stmt = $conn->prepare("
            SELECT
                rt.user_id,
                rt.selector,
                rt.token_hash,
                rt.expires_at,
                rt.user_agent,
                rt.ip_address,
                u.user_id AS u_user_id,
                u.username,
                u.role,
                u.first_name,
                u.last_name,
                u.photo,
                u.status
            FROM " . self::REMEMBER_TABLE . " rt
            INNER JOIN " . self::TABLE . " u
                ON u.user_id = rt.user_id
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

        if (!hash_equals((string) $row['token_hash'], $tokenHash)) {
            $delete = $conn->prepare("DELETE FROM " . self::REMEMBER_TABLE . " WHERE selector = :selector");
            $delete->execute([':selector' => $selector]);

            self::clearRememberCookie();
            return false;
        }

        if (($row['status'] ?? 'active') !== 'active') {
            $delete = $conn->prepare("DELETE FROM " . self::REMEMBER_TABLE . " WHERE selector = :selector");
            $delete->execute([':selector' => $selector]);

            self::clearRememberCookie();
            return false;
        }

        if (self::rememberContextMismatch($row)) {
            $delete = $conn->prepare("DELETE FROM " . self::REMEMBER_TABLE . " WHERE selector = :selector");
            $delete->execute([':selector' => $selector]);

            self::clearRememberCookie();
            return false;
        }

        session_regenerate_id(true);

        $_SESSION['user_id'] = (int) $row['u_user_id'];
        $_SESSION['username'] = (string) $row['username'];
        $_SESSION['role'] = (string) $row['role'];
        $_SESSION['first_name'] = (string) ($row['first_name'] ?? '');
        $_SESSION['last_name'] = (string) ($row['last_name'] ?? '');
        $_SESSION['photo'] = (string) ($row['photo'] ?? '');
        $_SESSION['last_activity'] = time();

        self::rotateCsrfToken();

        $delete = $conn->prepare("DELETE FROM " . self::REMEMBER_TABLE . " WHERE selector = :selector");
        $delete->execute([':selector' => $selector]);

        self::setRememberMe($conn, (int) $row['u_user_id']);

        return true;
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
                INSERT INTO {$logConfig['table']} (
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
                VALUES (
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
                ':user_agent'  => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (Throwable $e) {
            error_log('[AuthController::logActivity] ' . $e->getMessage());
        }
    }

    private static function setRememberMe(PDO $conn, int $userId): void
    {
        $selector = bin2hex(random_bytes(12));
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expires = (new DateTimeImmutable('+' . self::REMEMBER_DAYS . ' days'))->format('Y-m-d H:i:s');

        $stmt = $conn->prepare("
            INSERT INTO " . self::REMEMBER_TABLE . " (
                user_id,
                selector,
                token_hash,
                expires_at,
                user_agent,
                ip_address
            )
            VALUES (
                :user_id,
                :selector,
                :token_hash,
                :expires_at,
                :user_agent,
                :ip_address
            )
        ");

        $stmt->execute([
            ':user_id'    => $userId,
            ':selector'   => $selector,
            ':token_hash' => $hash,
            ':expires_at' => $expires,
            ':user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ':ip_address' => self::getIpAddress(),
        ]);

        $cookieValue = $selector . ':' . $token;
        $isHttps = self::rememberCookieSecureFlag();

        setcookie(self::REMEMBER_COOKIE, $cookieValue, [
            'expires'  => time() + (86400 * self::REMEMBER_DAYS),
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function clearRememberCookie(): void
    {
        $isHttps = self::rememberCookieSecureFlag();

        setcookie(self::REMEMBER_COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function getIpAddress(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN');
    }

    private static function rememberContextMismatch(array $row): bool
    {
        $currentUserAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $storedUserAgent = (string) ($row['user_agent'] ?? '');

        if ($storedUserAgent !== '' && $currentUserAgent !== '' && !hash_equals($storedUserAgent, $currentUserAgent)) {
            return true;
        }

        $storedIp = trim((string) ($row['ip_address'] ?? ''));
        $currentIp = trim(self::getIpAddress());

        if ($storedIp === '' || $storedIp === 'UNKNOWN' || $currentIp === '' || $currentIp === 'UNKNOWN') {
            return false;
        }

        $enforceIpBinding = true;
        if (function_exists('app_is_private_or_local_ip')) {
            $enforceIpBinding = app_is_private_or_local_ip($storedIp) || app_is_private_or_local_ip($currentIp);
        }

        return $enforceIpBinding && !hash_equals($storedIp, $currentIp);
    }

    private static function rememberCookieSecureFlag(): bool
    {
        if (function_exists('app_is_https')) {
            return app_is_https();
        }

        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }

    private static function normalizeLoginIdentifier(string $username): string
    {
        return strtolower(trim($username));
    }

    private static function buildLoginFailureDescription(string $username, string $reason): string
    {
        return sprintf('target_username=%s; reason=%s', $username, $reason);
    }

    private static function buildLoginBlockedDescription(string $username, string $scope): string
    {
        return sprintf('target_username=%s; scope=%s', $username, $scope);
    }

    private static function checkLoginRateLimit(PDO $conn, array $logConfig, string $username, string $ip): ?array
    {
        $states = self::getAttemptStates($conn, $username, $ip);
        $now = time();

        foreach (['username_ip', 'username', 'ip'] as $scopeType) {
            $lockedUntil = isset($states[$scopeType]['locked_until']) ? strtotime((string) $states[$scopeType]['locked_until']) : false;
            if ($lockedUntil !== false && $lockedUntil > $now) {
                return ['scope' => $scopeType];
            }
        }

        return null;
    }

    private static function isCaptchaRequired(PDO $conn, string $username, string $ip): bool
    {
        $states = self::getAttemptStates($conn, $username, $ip);

        return ($states['username_ip']['attempt_count'] ?? 0) >= self::CAPTCHA_THRESHOLD_COMBO
            || ($states['username']['attempt_count'] ?? 0) >= self::CAPTCHA_THRESHOLD_USERNAME
            || ($states['ip']['attempt_count'] ?? 0) >= self::CAPTCHA_THRESHOLD_IP;
    }

    private static function recordFailedLoginAttempt(
        PDO $conn,
        array $logConfig,
        ?int $userId,
        string $username,
        string $ip,
        string $reason
    ): void {
        self::ensureLoginAttemptsTable($conn);

        $now = new DateTimeImmutable();
        $scopes = self::buildAttemptScopes($username, $ip);

        foreach ($scopes as $scopeType => $scope) {
            $row = self::fetchAttemptRow($conn, $scopeType, $scope['scope_key']);
            $attemptCount = self::isAttemptRowExpired($row, $now) ? 0 : (int) ($row['attempt_count'] ?? 0);
            $attemptCount++;

            $maxAttempts = self::scopeMaxAttempts($scopeType);
            $lockedUntil = $attemptCount >= $maxAttempts
                ? $now->modify('+' . self::LOCKOUT_TIME . ' seconds')->format('Y-m-d H:i:s')
                : null;
            $captchaRequired = $attemptCount >= self::scopeCaptchaThreshold($scopeType) ? 1 : 0;
            $firstAttemptAt = ($row && !self::isAttemptRowExpired($row, $now))
                ? (string) ($row['first_attempt_at'] ?? $now->format('Y-m-d H:i:s'))
                : $now->format('Y-m-d H:i:s');
            $lastNotifiedAt = (!$row || self::isAttemptRowExpired($row, $now)) ? null : ($row['last_notified_at'] ?? null);

            self::upsertAttemptRow(
                $conn,
                $scopeType,
                $scope,
                $attemptCount,
                $now->format('Y-m-d H:i:s'),
                $lockedUntil,
                $captchaRequired,
                $firstAttemptAt,
                $lastNotifiedAt
            );

            if ($lockedUntil !== null) {
                self::notifyLockoutIfNeeded($conn, $scopeType, $scope, $attemptCount, $lastNotifiedAt);
            }
        }

        self::logActivity(
            $conn,
            $logConfig,
            $userId,
            'login_failed',
            self::buildLoginFailureDescription($username, $reason),
            'auth',
            $userId,
            'security'
        );
    }

    private static function notifyLockoutIfNeeded(
        PDO $conn,
        string $scopeType,
        array $scope,
        int $attemptCount,
        ?string $lastNotifiedAt
    ): void {
        $now = time();
        $lastNotifiedTs = $lastNotifiedAt ? strtotime($lastNotifiedAt) : false;

        if ($lastNotifiedTs !== false && ($now - $lastNotifiedTs) < self::ALERT_COOLDOWN) {
            return;
        }

        $message = match ($scopeType) {
            'username_ip' => sprintf(
                'Login blocked for username "%s" from IP %s after %d failed attempts.',
                $scope['username'] ?? 'unknown',
                $scope['ip_address'] ?? 'unknown',
                $attemptCount
            ),
            'username' => sprintf(
                'Login blocked for username "%s" after repeated failed attempts.',
                $scope['username'] ?? 'unknown'
            ),
            default => sprintf(
                'Login blocked from IP %s after repeated failed attempts.',
                $scope['ip_address'] ?? 'unknown'
            ),
        };

        try {
            NotificationController::create(
                $conn,
                null,
                'admin',
                'security',
                'Brute-force login blocked',
                $message,
                'bi-shield-lock',
                'text-danger',
                '/inventory_system/admin/activity_log.php'
            );
        } catch (Throwable $e) {
            error_log('[AuthController::notifyLockoutIfNeeded] ' . $e->getMessage());
        }

        $stmt = $conn->prepare("
            UPDATE " . self::LOGIN_ATTEMPTS_TABLE . "
            SET last_notified_at = :last_notified_at
            WHERE scope_type = :scope_type
              AND scope_key = :scope_key
        ");
        $stmt->execute([
            ':last_notified_at' => date('Y-m-d H:i:s', $now),
            ':scope_type'       => $scopeType,
            ':scope_key'        => $scope['scope_key'],
        ]);
    }

    private static function getAttemptStates(PDO $conn, string $username, string $ip): array
    {
        self::ensureLoginAttemptsTable($conn);

        $states = [];
        foreach (self::buildAttemptScopes($username, $ip) as $scopeType => $scope) {
            $states[$scopeType] = self::fetchAttemptRow($conn, $scopeType, $scope['scope_key']) ?? [
                'attempt_count' => 0,
                'locked_until'  => null,
            ];
        }

        return $states;
    }

    private static function fetchAttemptRow(PDO $conn, string $scopeType, string $scopeKey): ?array
    {
        $stmt = $conn->prepare("
            SELECT
                scope_type,
                scope_key,
                username,
                ip_address,
                attempt_count,
                first_attempt_at,
                last_attempt_at,
                locked_until,
                captcha_required,
                last_notified_at
            FROM " . self::LOGIN_ATTEMPTS_TABLE . "
            WHERE scope_type = :scope_type
              AND scope_key = :scope_key
            LIMIT 1
        ");
        $stmt->execute([
            ':scope_type' => $scopeType,
            ':scope_key'  => $scopeKey,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        if (self::isAttemptRowExpired($row, new DateTimeImmutable())) {
            self::deleteAttemptRow($conn, $scopeType, $scopeKey);
            return null;
        }

        return $row;
    }

    private static function upsertAttemptRow(
        PDO $conn,
        string $scopeType,
        array $scope,
        int $attemptCount,
        string $lastAttemptAt,
        ?string $lockedUntil,
        int $captchaRequired,
        string $firstAttemptAt,
        ?string $lastNotifiedAt
    ): void {
        $stmt = $conn->prepare("
            INSERT INTO " . self::LOGIN_ATTEMPTS_TABLE . " (
                scope_type,
                scope_key,
                username,
                ip_address,
                attempt_count,
                first_attempt_at,
                last_attempt_at,
                locked_until,
                captcha_required,
                last_notified_at
            ) VALUES (
                :scope_type,
                :scope_key,
                :username,
                :ip_address,
                :attempt_count,
                :first_attempt_at,
                :last_attempt_at,
                :locked_until,
                :captcha_required,
                :last_notified_at
            )
            ON DUPLICATE KEY UPDATE
                username = VALUES(username),
                ip_address = VALUES(ip_address),
                attempt_count = VALUES(attempt_count),
                first_attempt_at = VALUES(first_attempt_at),
                last_attempt_at = VALUES(last_attempt_at),
                locked_until = VALUES(locked_until),
                captcha_required = VALUES(captcha_required),
                last_notified_at = VALUES(last_notified_at)
        ");

        $stmt->execute([
            ':scope_type'       => $scopeType,
            ':scope_key'        => $scope['scope_key'],
            ':username'         => $scope['username'],
            ':ip_address'       => $scope['ip_address'],
            ':attempt_count'    => $attemptCount,
            ':first_attempt_at' => $firstAttemptAt,
            ':last_attempt_at'  => $lastAttemptAt,
            ':locked_until'     => $lockedUntil,
            ':captcha_required' => $captchaRequired,
            ':last_notified_at' => $lastNotifiedAt,
        ]);
    }

    private static function clearLoginAttempts(PDO $conn, string $username, string $ip): void
    {
        self::ensureLoginAttemptsTable($conn);

        foreach (self::buildAttemptScopes($username, $ip) as $scopeType => $scope) {
            self::deleteAttemptRow($conn, $scopeType, $scope['scope_key']);
        }
    }

    private static function deleteAttemptRow(PDO $conn, string $scopeType, string $scopeKey): void
    {
        $stmt = $conn->prepare("
            DELETE FROM " . self::LOGIN_ATTEMPTS_TABLE . "
            WHERE scope_type = :scope_type
              AND scope_key = :scope_key
        ");
        $stmt->execute([
            ':scope_type' => $scopeType,
            ':scope_key'  => $scopeKey,
        ]);
    }

    private static function buildAttemptScopes(string $username, string $ip): array
    {
        return [
            'username' => [
                'scope_key'  => 'u:' . $username,
                'username'   => $username !== '' ? $username : null,
                'ip_address' => null,
            ],
            'ip' => [
                'scope_key'  => 'ip:' . $ip,
                'username'   => null,
                'ip_address' => $ip,
            ],
            'username_ip' => [
                'scope_key'  => 'uip:' . hash('sha256', $username . '|' . $ip),
                'username'   => $username !== '' ? $username : null,
                'ip_address' => $ip,
            ],
        ];
    }

    private static function scopeMaxAttempts(string $scopeType): int
    {
        return match ($scopeType) {
            'username' => self::MAX_ATTEMPTS_USERNAME,
            'ip' => self::MAX_ATTEMPTS_IP,
            default => self::MAX_ATTEMPTS_COMBO,
        };
    }

    private static function scopeCaptchaThreshold(string $scopeType): int
    {
        return match ($scopeType) {
            'username' => self::CAPTCHA_THRESHOLD_USERNAME,
            'ip' => self::CAPTCHA_THRESHOLD_IP,
            default => self::CAPTCHA_THRESHOLD_COMBO,
        };
    }

    private static function isAttemptRowExpired(?array $row, DateTimeImmutable $now): bool
    {
        if (!$row) {
            return true;
        }

        $lastAttemptAt = isset($row['last_attempt_at']) ? strtotime((string) $row['last_attempt_at']) : false;
        $lockedUntil = isset($row['locked_until']) && $row['locked_until'] !== null
            ? strtotime((string) $row['locked_until'])
            : false;
        $nowTs = $now->getTimestamp();

        if ($lockedUntil !== false && $lockedUntil > $nowTs) {
            return false;
        }

        if ($lastAttemptAt === false) {
            return true;
        }

        return ($nowTs - $lastAttemptAt) > self::LOCKOUT_TIME;
    }

    private static function ensureLoginAttemptsTable(PDO $conn): void
    {
        static $ready = false;

        if ($ready) {
            return;
        }

        $conn->exec("
            CREATE TABLE IF NOT EXISTS " . self::LOGIN_ATTEMPTS_TABLE . " (
                attempt_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                scope_type ENUM('username','ip','username_ip') NOT NULL,
                scope_key VARCHAR(191) NOT NULL,
                username VARCHAR(100) NULL,
                ip_address VARCHAR(45) NULL,
                attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
                first_attempt_at DATETIME NOT NULL,
                last_attempt_at DATETIME NOT NULL,
                locked_until DATETIME NULL,
                captcha_required TINYINT(1) NOT NULL DEFAULT 0,
                last_notified_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_scope (scope_type, scope_key),
                KEY idx_username (username),
                KEY idx_ip_address (ip_address),
                KEY idx_locked_until (locked_until),
                KEY idx_last_attempt_at (last_attempt_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        $ready = true;
    }

    private static function getCaptchaChallengePrompt(): string
    {
        if (
            empty($_SESSION['login_captcha']) ||
            !is_array($_SESSION['login_captcha']) ||
            (time() - (int) ($_SESSION['login_captcha']['generated_at'] ?? 0)) > 600
        ) {
            $a = random_int(2, 9);
            $b = random_int(1, 9);

            $_SESSION['login_captcha'] = [
                'a'            => $a,
                'b'            => $b,
                'answer'       => $a + $b,
                'generated_at' => time(),
            ];
        }

        return (int) $_SESSION['login_captcha']['a'] . ' + ' . (int) $_SESSION['login_captcha']['b'] . ' = ?';
    }

    private static function validateCaptchaAnswer(string $answer): bool
    {
        if (
            empty($_SESSION['login_captcha']) ||
            !is_array($_SESSION['login_captcha']) ||
            (time() - (int) ($_SESSION['login_captcha']['generated_at'] ?? 0)) > 600
        ) {
            self::clearCaptchaChallenge();
            return false;
        }

        $expected = (string) ($_SESSION['login_captcha']['answer'] ?? '');
        $provided = trim($answer);
        $valid = $expected !== '' && $provided !== '' && hash_equals($expected, $provided);

        if ($valid) {
            self::clearCaptchaChallenge();
        }

        return $valid;
    }

    private static function clearCaptchaChallenge(): void
    {
        unset($_SESSION['login_captcha']);
    }

    private static function applyFailureDelay(): void
    {
        usleep(random_int(self::FAILURE_DELAY_MIN_US, self::FAILURE_DELAY_MAX_US));
    }
}
