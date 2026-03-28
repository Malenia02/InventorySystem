<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class Middleware
{
    public static function auth(): static
    {
        $instance = new static();
        $instance->runAuth();
        return $instance;
    }

    public static function guestOnly(): static
    {
        $instance = new static();

        if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
            $instance->denyAccess(403, 'You are already logged in.', '/inventory_system/index.php');
        }

        return $instance;
    }

    private function runAuth(): void
    {
        if (
            !isset($_SESSION['user_id']) ||
            !is_numeric($_SESSION['user_id']) ||
            (int)$_SESSION['user_id'] <= 0
        ) {
            $this->denyAccess(401, 'Unauthenticated. Please log in.', '/inventory_system/login.php');
        }
    }

    public function role(string|array $allowed_roles): static
    {
        $allowed_roles = (array)$allowed_roles;
        $user_role     = $_SESSION['role'] ?? null;

        if (!$user_role || !in_array($user_role, $allowed_roles, true)) {
            error_log(sprintf(
                '[Middleware] Unauthorized — user_id: %s, role: %s, required: %s, uri: %s',
                $_SESSION['user_id'] ?? 'guest',
                $user_role ?? 'none',
                implode('|', $allowed_roles),
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
        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if (!$isAjax) {
            error_log(sprintf(
                '[Security] Direct access blocked for AJAX endpoint: %s from IP %s',
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
        $allowedMethods = array_map('strtoupper', $allowedMethods);

        if (!in_array($method, $allowedMethods, true)) {
            error_log(sprintf(
                '[Security] Method blocked: %s on %s',
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
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            return $this;
        }

        $this->validateSameOrigin();
        $this->validateCsrfToken();

        return $this;
    }

    private function validateCsrfToken(): void
    {
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        $token = '';

        if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $token = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
        }

        if ($token === '') {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (str_contains($contentType, 'application/json')) {
                $raw = file_get_contents('php://input');
                $body = json_decode($raw, true);

                if (is_array($body)) {
                    $token = (string)($body['csrf_token'] ?? '');
                }
            }
        }

        if ($token === '') {
            $token = (string)($_POST['csrf_token'] ?? '');
        }

        if ($sessionToken === '' || $token === '' || !hash_equals($sessionToken, $token)) {
            error_log('[Security] CSRF Blocked: Missing or Invalid token from IP ' . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'));
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

        $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';

        if ($origin !== '') {
            if (!$this->isSameOrigin($origin, $expectedOrigin)) {
                error_log('[Security] Origin check failed: ' . $origin);
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
                error_log('[Security] Referer check failed: ' . $referer);
                $this->denyAccess(
                    403,
                    'Invalid request origin.',
                    '/inventory_system/error.php?code=403'
                );
            }
            return;
        }

        error_log('[Security] Missing Origin/Referer for sensitive request');
        $this->denyAccess(
            403,
            'Invalid request origin.',
            '/inventory_system/error.php?code=403'
        );
    }

    private function getExpectedOrigin(): string
    {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443);

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
        $candidatePort   = $candidateParts['port'] ?? null;

        $expectedScheme = $expectedParts['scheme'] ?? '';
        $expectedHost   = $expectedParts['host'] ?? '';
        $expectedPort   = $expectedParts['port'] ?? null;

        $candidatePort = $candidatePort ?? ($candidateScheme === 'https' ? 443 : 80);
        $expectedPort  = $expectedPort ?? ($expectedScheme === 'https' ? 443 : 80);

        return $candidateScheme === $expectedScheme
            && $candidateHost === $expectedHost
            && $candidatePort === $expectedPort;
    }

   private function denyAccess(int $code, string $message, string $redirect): void
{
    $_SESSION['error_code'] = $code;
    $_SESSION['error_message'] = $message;

    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if ($isAjax) {
        http_response_code($code);
        header('Content-Type: application/json');

        echo json_encode([
            'success' => false,
            'error'   => $message,
            'code'    => $code
        ]);
        exit;
    }

    header("Location: /inventory_system/error.php");
    exit;
}

    public static function generateCsrfToken(): string
    {
        if (
            empty($_SESSION['csrf_token']) ||
            empty($_SESSION['csrf_token_time']) ||
            (time() - $_SESSION['csrf_token_time']) > 1800
        ) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = time();
        }

        return $_SESSION['csrf_token'];
    }

    public static function rotateCsrfToken(): string
    {
        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
        return self::generateCsrfToken();
    }

    public static function user(): array
    {
        return [
            'id'       => $_SESSION['user_id'] ?? null,
            'username' => $_SESSION['username'] ?? null,
            'role'     => $_SESSION['role'] ?? null,
            'name'     => $_SESSION['first_name'] ?? null,
        ];
    }

    public static function is(string|array $roles): bool
    {
        $roles     = (array)$roles;
        $user_role = $_SESSION['role'] ?? null;
        return in_array($user_role, $roles, true);
    }
}