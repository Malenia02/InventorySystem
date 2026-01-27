<?php
// Prevent direct access to this file via browser
if (php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

class AuthController
{
    // ==========================
    // SECURITY CONSTANTS
    // ==========================
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_TIME = 600; // 10 minutes

    // ==========================
    // SESSION START (SAFE)
    // ==========================
    private static function startSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    // ==========================
    // CSRF TOKEN GENERATION
    // ==========================
    public static function generateCSRFToken() {
        self::startSession();

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    // ==========================
    // CSRF TOKEN VERIFICATION
    // ==========================
    public static function verifyCSRFToken() {
        self::startSession();

        $headers = getallheaders();
        $token = $headers['X-CSRF-TOKEN'] ?? ($_POST['csrf_token'] ?? '');

        if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid CSRF token'
            ]);
            exit;
        }
    }

    // ==========================
    // LOGIN METHOD
    // ==========================
    public static function login(
        PDO $conn,
        string $table_users,
        string $user_username,
        string $user_password,
        string $user_id,
        string $user_role,
        string $user_status
    ) {
        self::startSession();

        $username   = trim($_POST['username'] ?? '');
        $password   = trim($_POST['password'] ?? '');
        $csrf_token = $_POST['csrf_token'] ?? '';

        // 🔐 CSRF CHECK
        if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
            http_response_code(403);
            return "Invalid CSRF token.";
        }

        // Validate input
        if ($username === '' || $password === '') {
            return "Username and password are required.";
        }

        // Rate limiting
        if (!isset($_SESSION['login_attempts'])) $_SESSION['login_attempts'] = 0;
        if (!isset($_SESSION['last_attempt_time'])) $_SESSION['last_attempt_time'] = 0;

        if ($_SESSION['login_attempts'] >= self::MAX_ATTEMPTS &&
            time() - $_SESSION['last_attempt_time'] < self::LOCKOUT_TIME) {
            return "Too many login attempts. Try again later.";
        }

        try {
            $stmt = $conn->prepare(
                "SELECT * FROM {$table_users} WHERE {$user_username} = :username LIMIT 1"
            );
            $stmt->execute(['username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Verify credentials
            if (!$user || !password_verify($password, $user[$user_password])) {
                $_SESSION['login_attempts']++;
                $_SESSION['last_attempt_time'] = time();
                return "Invalid username or password.";
            }

            // Check account status
            if (isset($user[$user_status]) && $user[$user_status] !== 'active') {
                return "Your account is inactive. Please contact admin.";
            }

            // ✅ Successful login
            session_regenerate_id(true); // prevent session fixation

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
                setcookie(
                    'user_id',
                    $user[$user_id],
                    time() + (86400 * 30),
                    "/",
                    "",
                    true,  // secure (HTTPS)
                    true   // httpOnly
                );
            }

            header("Location: /inventory_system/index.php");
            exit;

        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            return "A database error occurred. Please try again later.";
        }
    }

    // ==========================
    // LOGOUT METHOD
    // ==========================
    public static function logout() {
        self::startSession();

        // Clear session data
        $_SESSION = [];
        session_unset();
        session_destroy();

        // Clear remember-me cookie
        if (isset($_COOKIE['user_id'])) {
            setcookie('user_id', '', time() - 3600, '/', '', true, true);
        }

        // Clear CSRF cookie if used (optional)
        if (isset($_COOKIE['csrf_token'])) {
            setcookie('csrf_token', '', time() - 3600, '/', '', true, true);
        }

        header("Location: /inventory_system/login.php");
        exit;
    }

    // ==========================
    // AUTH CHECK (OPTIONAL HELPER)
    // ==========================
    public static function requireLogin() {
        self::startSession();

        if (!isset($_SESSION['user_id'])) {
            header("Location: /inventory_system/login.php");
            exit;
        }
    }

    // ==========================
    // ROLE CHECK (OPTIONAL)
    // ==========================
    public static function requireRole($role) {
        self::startSession();

        if (!isset($_SESSION['role']) || $_SESSION['role'] !== $role) {
            http_response_code(403);
            exit('Access denied.');
        }
    }
}
?>
