<?php
declare(strict_types=1);

require_once __DIR__ . '/../controllers/AuthController.php';

class Middleware
{
    private const CSRF_TTL = 1800;
    private const ROLE_RECHECK_TTL = 300;

    public static function auth(): static
    {
        $instance = new static();
        $instance->runAuth();
        return $instance;
    }

    public static function guestOnly(): static
    {
        $instance = new static();

        if (!empty($_SESSION['user_id'])) {
            $instance->denyAccess(
                403,
                'You are already logged in.',
                '/inventory_system/index.php'
            );
        }

        return $instance;
    }

    private function runAuth(): void
    {
        if (
            !isset($_SESSION['user_id']) ||
            !is_numeric($_SESSION['user_id']) ||
            (int) $_SESSION['user_id'] <= 0
        ) {
            $this->denyAccess(
                401,
                'Unauthenticated. Please log in.',
                '/inventory_system/login.php'
            );
        }

        $appEnv = strtolower((string) env_value('APP_ENV', 'production'));
        if ($appEnv === 'production' && !app_is_https()) {
            $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/inventory_system/index.php');
            safe_redirect('https://' . $host . $uri, 301);
        }

        $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
        if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof PDO && $sessionUserId > 0) {
            if (!AuthController::validateSessionContext($GLOBALS['conn'], $sessionUserId)) {
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

                $this->denyAccess(
                    401,
                    'Session security check failed. Please log in again.',
                    '/inventory_system/login.php'
                );
            }
        }

        AuthController::rotateSessionIdIfDue();
    }

    public function role(string|array $allowedRoles, ?PDO $conn = null): static
    {
        $allowedRoles = (array) $allowedRoles;
        $userRole = $_SESSION['role'] ?? null;

        if ($conn !== null) {
            $userRole = $this->getFreshRole($conn) ?? $userRole;
        }

        if (!$userRole || !in_array($userRole, $allowedRoles, true)) {
            error_log(sprintf(
                '[Middleware] Unauthorized access - user_id: %s, role: %s, required: %s, uri: %s',
                $_SESSION['user_id'] ?? 'guest',
                $userRole ?? 'none',
                implode('|', $allowedRoles),
                $_SERVER['REQUEST_URI'] ?? ''
            ));

            $this->denyAccess(
                403,
                'Access denied. Insufficient permissions.',
                '/inventory_system/error.php?code=403'
            );
        }

        return $this;
    }

    public function ajax(): static
    {
        if (!$this->expectsJsonRequest()) {
            error_log(sprintf(
                '[Middleware] Non-AJAX/JSON access blocked - uri: %s, ip: %s',
                $_SERVER['REQUEST_URI'] ?? '',
                $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
            ));

            $this->denyAccess(
                403,
                'Direct access not allowed.',
                '/inventory_system/error.php?code=403'
            );
        }

        return $this;
    }

    public function methods(array $allowedMethods): static
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $allowedMethods = array_map(
            static fn ($m) => strtoupper((string) $m),
            $allowedMethods
        );

        if (!in_array($method, $allowedMethods, true)) {
            error_log(sprintf(
                '[Middleware] Method blocked - method: %s, uri: %s',
                $method,
                $_SERVER['REQUEST_URI'] ?? ''
            ));

            $this->denyAccess(
                405,
                'Method not allowed.',
                '/inventory_system/error.php?code=405'
            );
        }

        return $this;
    }

    public function sameOrigin(): static
    {
        $this->validateSameOrigin();
        return $this;
    }

    public function csrf(): static
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $this;
        }

        $this->validateSameOrigin();
        $this->validateCsrfToken();

        return $this;
    }

    public function throttle(string $scope, int $maxRequests, int $windowSeconds, string $message = 'Too many requests. Please slow down.'): static
    {
        $scope = trim($scope);
        if ($scope === '' || $maxRequests <= 0 || $windowSeconds <= 0) {
            return $this;
        }

        $path = $this->rateLimitPath($scope);
        if ($path === null) {
            return $this;
        }

        $directory = dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            return $this;
        }

        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            return $this;
        }

        try {
            $raw = stream_get_contents($fh);
            $decoded = json_decode($raw !== false ? $raw : '[]', true);
            $bucket = is_array($decoded) ? $decoded : [];
            $cutoff = time() - $windowSeconds;
            $bucket = array_values(array_filter($bucket, static fn($ts): bool => is_int($ts) && $ts >= $cutoff));

            if (count($bucket) >= $maxRequests) {
                error_log(sprintf(
                    '[Middleware] Rate limit exceeded - scope: %s, user_id: %s, ip: %s',
                    $scope,
                    $_SESSION['user_id'] ?? 'guest',
                    $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
                ));
                $this->denyAccess(429, $message, '/inventory_system/error.php?code=429');
            }

            $bucket[] = time();
            $payload = json_encode(array_values($bucket));
            if ($payload !== false) {
                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, $payload);
            }
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }

        return $this;
    }

    private function validateCsrfToken(): void
    {
        $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
        $tokenAge = time() - (int) ($_SESSION['csrf_token_time'] ?? 0);
        $requestToken = '';

        if ($sessionToken === '' || $tokenAge > self::CSRF_TTL) {
            error_log('[Middleware] CSRF token expired or missing from IP ' . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'));
            $this->denyAccess(
                403,
                'Security token expired. Please refresh and try again.',
                '/inventory_system/error.php?code=403'
            );
        }

        if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $requestToken = (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
        }

        if ($requestToken === '') {
            $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

            if (str_contains($contentType, 'application/json')) {
                $rawBody = file_get_contents('php://input');
                $decoded = json_decode($rawBody, true);

                if (is_array($decoded)) {
                    $requestToken = (string) ($decoded['csrf_token'] ?? '');
                }
            }
        }

        if ($requestToken === '') {
            $requestToken = (string) ($_POST['csrf_token'] ?? '');
        }

        if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
            error_log('[Middleware] CSRF validation failed from IP ' . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'));

            $this->denyAccess(
                403,
                'Security token mismatch or missing.',
                '/inventory_system/error.php?code=403'
            );
        }
    }

    private function validateSameOrigin(): void
    {
        $expectedOrigin = $this->getExpectedOrigin();

        $origin  = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');

        if ($origin !== '') {
            if (!$this->isSameOrigin($origin, $expectedOrigin)) {
                error_log('[Middleware] Origin check failed: ' . $origin);

                $this->denyAccess(
                    403,
                    'Invalid request origin.',
                    '/inventory_system/error.php?code=403'
                );
            }

            return;
        }

        if ($referer !== '') {
            if (!$this->isSameOrigin($referer, $expectedOrigin)) {
                error_log('[Middleware] Referer check failed: ' . $referer);

                $this->denyAccess(
                    403,
                    'Invalid request origin.',
                    '/inventory_system/error.php?code=403'
                );
            }

            return;
        }

        error_log('[Middleware] Missing Origin/Referer for sensitive request');

        $this->denyAccess(
            403,
            'Invalid request origin.',
            '/inventory_system/error.php?code=403'
        );
    }

    private function getExpectedOrigin(): string
    {
        $isHttps = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        );

        $scheme = $isHttps ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $scheme . '://' . $host;
    }

    private function isSameOrigin(string $candidate, string $expectedOrigin): bool
    {
        $candidateParts = parse_url($candidate);
        $expectedParts  = parse_url($expectedOrigin);

        if (!$candidateParts || !$expectedParts) {
            return false;
        }

        $candidateScheme = $candidateParts['scheme'] ?? '';
        $candidateHost   = $candidateParts['host'] ?? '';
        $candidatePort   = $candidateParts['port'] ?? ($candidateScheme === 'https' ? 443 : 80);

        $expectedScheme = $expectedParts['scheme'] ?? '';
        $expectedHost   = $expectedParts['host'] ?? '';
        $expectedPort   = $expectedParts['port'] ?? ($expectedScheme === 'https' ? 443 : 80);

        return $candidateScheme === $expectedScheme
            && $candidateHost === $expectedHost
            && (int) $candidatePort === (int) $expectedPort;
    }

    private function denyAccess(int $code, string $message, string $redirect): void
    {
        $_SESSION['error_code'] = $code;
        $_SESSION['error_message'] = $message;

        if ($this->expectsJsonRequest()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=UTF-8');

            echo json_encode([
                'success'  => false,
                'error'    => $message,
                'code'     => $code,
                'redirect' => $redirect
            ]);
            exit;
        }

        header('Location: ' . $redirect, true, 302);
        exit;
    }

    private function expectsJsonRequest(): bool
    {
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept        = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $contentType   = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

        return $requestedWith === 'xmlhttprequest'
            || str_contains($accept, 'application/json')
            || str_contains($contentType, 'application/json');
    }

    public static function generateCsrfToken(): string
    {
        if (
            empty($_SESSION['csrf_token']) ||
            empty($_SESSION['csrf_token_time']) ||
            (time() - (int) $_SESSION['csrf_token_time']) > self::CSRF_TTL
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

    public static function user(): array
    {
        $userId = isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])
            ? (int) $_SESSION['user_id']
            : null;
        $username = isset($_SESSION['username']) ? (string) $_SESSION['username'] : null;
        $role = isset($_SESSION['role']) ? (string) $_SESSION['role'] : null;
        $firstName = trim((string) ($_SESSION['first_name'] ?? ''));
        $lastName  = trim((string) ($_SESSION['last_name'] ?? ''));
        $fullName  = trim($firstName . ' ' . $lastName);

        return [
            'id'       => $userId,
            'username' => $username,
            'role'     => $role,
            'name'     => $fullName !== '' ? $fullName : null,
        ];
    }

    public static function is(string|array $roles): bool
    {
        $roles = (array) $roles;
        $userRole = $_SESSION['role'] ?? null;

        return in_array($userRole, $roles, true);
    }

    private function getFreshRole(PDO $conn): ?string
    {
        $lastCheck = (int) ($_SESSION['_role_checked_at'] ?? 0);
        if ((time() - $lastCheck) < self::ROLE_RECHECK_TTL) {
            return (string) ($_SESSION['role'] ?? '');
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        try {
            $stmt = $conn->prepare('SELECT role, status FROM users WHERE user_id = :id LIMIT 1');
            $stmt->execute([':id' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('[Middleware::getFreshRole] ' . $e->getMessage());
            return (string) ($_SESSION['role'] ?? '');
        }

        if (!$row || ($row['status'] ?? '') !== 'active') {
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
            $this->denyAccess(401, 'Your account is no longer active.', '/inventory_system/login.php');
        }

        $freshRole = (string) ($row['role'] ?? '');
        $_SESSION['role'] = $freshRole;
        $_SESSION['_role_checked_at'] = time();
        return $freshRole;
    }

    private function rateLimitPath(string $scope): ?string
    {
        if (!defined('LOG_PATH')) {
            return null;
        }

        $userId = (string) ($_SESSION['user_id'] ?? 'guest');
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN');
        $key = hash('sha256', strtolower($scope) . '|' . $userId . '|' . $ip);

        return LOG_PATH . '/rate_limits/' . $key . '.json';
    }
}
