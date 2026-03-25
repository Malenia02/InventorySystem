<?php
/**
 * middleware/Middleware.php
 * Central middleware runner.
 * Use this instead of requiring individual middleware files.
 *
 * ── Usage examples ───────────────────────────────────────────
 *
 * // 1. Auth only (any logged-in user)
 * Middleware::auth();
 *
 * // 2. Auth + single role
 * Middleware::auth()->role('admin');
 *
 * // 3. Auth + multiple roles
 * Middleware::auth()->role(['admin', 'staff']);
 *
 * // 4. Auth + CSRF (for POST pages with forms)
 * Middleware::auth()->csrf();
 *
 * // 5. Auth + role + CSRF (full protection)
 * Middleware::auth()->role('admin')->csrf();
 *
 * // 6. AJAX endpoint — auth + role + CSRF
 * Middleware::auth()->role(['admin', 'cashier'])->csrf();
 *
 * ─────────────────────────────────────────────────────────────
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class Middleware
{
    // ── Fluent entry point ───────────────────────────────────
    public static function auth(): static
    {
        $instance = new static();
        $instance->runAuth();
        return $instance;
    }

    // ── Auth check ───────────────────────────────────────────
    private function runAuth(): void
    {
        if (!isset($_SESSION['user_id'])) {
            $this->denyAccess(401, 'Unauthenticated. Please log in.', '/inventory_system/login.php');
        }
    }

    // ── Role check ───────────────────────────────────────────
    public function role(string|array $allowed_roles): static
    {
        $allowed_roles = (array) $allowed_roles;
        $user_role     = $_SESSION['role'] ?? null;

        if (!$user_role || !in_array($user_role, $allowed_roles, true)) {
            error_log(sprintf(
                '[Middleware] Unauthorized — user_id: %s, role: %s, required: %s, uri: %s',
                $_SESSION['user_id'] ?? 'guest',
                $user_role ?? 'none',
                implode('|', $allowed_roles),
                $_SERVER['REQUEST_URI'] ?? ''
            ));
            $this->denyAccess(403, 'Access denied. Insufficient permissions.', '/inventory_system/error.php?code=403');
        }

        return $this;
    }

    // ── CSRF check ───────────────────────────────────────────
    public function csrf(): static
    {
        // Only validate on state-changing requests
        if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            return $this;
        }

        // Support both JSON body and form POST
        $token = null;

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            // Read from JSON body
            $body  = json_decode(file_get_contents('php://input'), true);
            $token = $body['csrf_token'] ?? '';
        } else {
            // Read from form POST
            $token = $_POST['csrf_token'] ?? '';
        }

        $sessionToken = $_SESSION['csrf_token'] ?? '';

        if (empty($token) || empty($sessionToken) || !hash_equals($sessionToken, $token)) {
            error_log(sprintf(
                '[Middleware] CSRF mismatch — user_id: %s, uri: %s',
                $_SESSION['user_id'] ?? 'guest',
                $_SERVER['REQUEST_URI'] ?? ''
            ));
            $this->denyAccess(403, 'Invalid or missing CSRF token.', '/inventory_system/error.php?code=403');
        }

        return $this;
    }

    // ── Deny access helper ───────────────────────────────────
    private function denyAccess(int $code, string $message, string $redirect): void
    {
        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        $isJson = isset($_SERVER['CONTENT_TYPE']) &&
                  str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');

        if ($isAjax || $isJson) {
            http_response_code($code);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error'   => $message,
            ]);
            exit;
        }

        http_response_code($code);
        header("Location: {$redirect}");
        exit;
    }

    // ── Generate a CSRF token ────────────────────────────────
    // Call this in your page controller to get a fresh token
    public static function generateCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    // ── Get current user info from session ───────────────────
    public static function user(): array
    {
        return [
            'id'       => $_SESSION['user_id']   ?? null,
            'username' => $_SESSION['username']   ?? null,
            'role'     => $_SESSION['role']       ?? null,
            'name'     => $_SESSION['first_name'] ?? null,
        ];
    }

    // ── Check role without blocking ──────────────────────────
    // Use for conditional UI rendering
    public static function is(string|array $roles): bool
    {
        $roles     = (array) $roles;
        $user_role = $_SESSION['role'] ?? null;
        return in_array($user_role, $roles, true);
    }
}