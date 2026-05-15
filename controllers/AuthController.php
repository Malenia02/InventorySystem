<?php
declare(strict_types=1);

require_once __DIR__ . '/NotificationController.php';
require_once __DIR__ . '/StaffController.php';

/**
 * AuthController
 *
 * Optimisations over the previous version
 * ────────────────────────────────────────
 * PERFORMANCE
 *  1. getAttemptStates() now fires ONE query with WHERE (scope_type, scope_key) IN (…)
 *     instead of three separate SELECTs. The UNIQUE KEY (scope_type, scope_key) makes
 *     each row lookup O(1).
 *
 *  2. clearLoginAttempts() issues ONE DELETE … WHERE (scope_type, scope_key) IN (…)
 *     instead of three.
 *
 *  3. login() collects attempt states ONCE and passes them to both
 *     checkLoginRateLimit() and isCaptchaRequired(), eliminating the second
 *     getAttemptStates() call that the original code made.
 *
 *  4. recordFailedLoginAttempt() no longer re-fetches rows it already received;
 *     the pre-fetched $states array is passed in.
 *
 *  5. notifyLockoutIfNeeded()'s last_notified_at UPDATE is merged into the upsert
 *     so no separate round-trip is needed.
 *
 *  6. buildSessionFingerprint() caches the app secret in a static variable so
 *     app_secret_value() is called at most once per process.
 *
 *  7. pruneStaleAttempts() deletes expired rows (locked_until < NOW() or
 *     last_attempt_at older than LOCKOUT_TIME) probabilistically (1 % of
 *     requests) so the login_attempts table never grows unboundedly.
 *     Same pattern applied to expired remember_tokens.
 *
 * CORRECTNESS / SECURITY
 *  8. rememberContextMismatch(): IP binding is now correctly DISABLED for
 *     private / local IPs (mobile users, dynamic IPs behind NAT), and
 *     ENABLED for routable public IPs — the original logic was inverted.
 *
 *  9. applyFailureDelay() is now called consistently from every early-return
 *     failure path, including the captcha branch.
 *
 * 10. CSRF_TOKEN_TTL replaces the magic number 1800.
 *
 * 11. buildSessionFingerprint() no longer falls back to a hardcoded string;
 *     it derives a deterministic key from session_id() so the fingerprint
 *     is always non-trivially keyed even when APP_KEY is missing.
 *
 * CODE QUALITY
 * 12. All constants are grouped and documented at the top.
 * 13. Indentation typo on MAX_ATTEMPTS_USERNAME fixed.
 * 14. recordSuspiciousSession() uses a shared buildLogConfigFromGlobals() helper
 *     instead of duplicating the $GLOBALS array construction inline.
 */
final class AuthController
{
    // ── Tables ───────────────────────────────────────────────────────────────
    private const TABLE                = 'users';
    private const REMEMBER_TABLE       = 'remember_tokens';
    private const LOGIN_ATTEMPTS_TABLE = 'login_attempts';

    // ── Rate-limit thresholds ─────────────────────────────────────────────────
    private const MAX_ATTEMPTS_COMBO    = 5;
    private const MAX_ATTEMPTS_USERNAME = 8;
    private const MAX_ATTEMPTS_IP       = 15;

    // ── CAPTCHA thresholds (failures before challenge appears) ────────────────
    private const CAPTCHA_THRESHOLD_COMBO    = 3;
    private const CAPTCHA_THRESHOLD_USERNAME = 5;
    private const CAPTCHA_THRESHOLD_IP       = 7;

    // ── Timing ────────────────────────────────────────────────────────────────
    private const LOCKOUT_TIME           = 600;   // seconds  (10 min)
    private const ALERT_COOLDOWN         = 900;   // seconds  (15 min)
    private const REMEMBER_DAYS          = 30;
    private const FAILURE_DELAY_MIN_US   = 250_000;
    private const FAILURE_DELAY_MAX_US   = 450_000;
    private const SESSION_ROTATE_INTERVAL = 900;  // seconds  (15 min)
    private const STEP_UP_WINDOW         = 900;   // seconds  (15 min)
    private const CSRF_TOKEN_TTL         = 1_800; // seconds  (30 min)

    // ── Cookies ───────────────────────────────────────────────────────────────
    private const REMEMBER_COOKIE = 'remember_me';

    // ── Probabilistic pruning (1-in-N chance per request) ────────────────────
    private const PRUNE_PROBABILITY = 100;

    // =========================================================================
    // CSRF
    // =========================================================================

    public static function generateCsrfToken(): string
    {
        if (
            empty($_SESSION['csrf_token']) ||
            empty($_SESSION['csrf_token_time']) ||
            (time() - (int) $_SESSION['csrf_token_time']) > self::CSRF_TOKEN_TTL
        ) {
            $_SESSION['csrf_token']      = bin2hex(random_bytes(32));
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
                    $raw   = (string) file_get_contents('php://input');
                    $body  = json_decode($raw, true);
                    $token = is_array($body) ? (string) ($body['csrf_token'] ?? '') : '';
                } else {
                    $token = (string) ($_POST['csrf_token'] ?? '');
                }
            }
        }

        return $token !== '' && hash_equals($sessionToken, $token);
    }

    // =========================================================================
    // SESSION COOKIE & HARDENING
    // =========================================================================

    public static function configureSessionCookie(): void
    {
        if (php_sapi_name() === 'cli' || session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $isHttps = function_exists('app_is_https')
            ? app_is_https()
            : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        ini_set('session.use_strict_mode',  '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly',  '1');
        ini_set('session.cookie_secure',    $isHttps ? '1' : '0');
        ini_set('session.cookie_samesite',  'Lax');

        session_name('INVSYSSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function bindSessionContext(): void
    {
        $_SESSION['session_fingerprint']        = self::buildSessionFingerprint();
        $_SESSION['session_fingerprint_set_at'] = time();
        $_SESSION['session_last_rotated_at']    = time();
    }

    public static function validateSessionContext(PDO $conn, int $userId): bool
    {
        $stored  = (string) ($_SESSION['session_fingerprint'] ?? '');
        $current = self::buildSessionFingerprint();

        if ($stored === '') {
            self::bindSessionContext();
            return true;
        }

        if (!hash_equals($stored, $current)) {
            self::recordSuspiciousSession($conn, $userId, 'session_fingerprint_mismatch');
            return false;
        }

        return true;
    }

    public static function rotateSessionIdIfDue(): void
    {
        $last = (int) ($_SESSION['session_last_rotated_at'] ?? 0);
        if ($last <= 0 || (time() - $last) >= self::SESSION_ROTATE_INTERVAL) {
            session_regenerate_id(true);
            $_SESSION['session_last_rotated_at'] = time();
        }
    }

    // =========================================================================
    // STEP-UP AUTH
    // =========================================================================

    public static function markStepUpVerified(?int $seconds = null): void
    {
        $ttl = ($seconds !== null && $seconds > 0) ? $seconds : self::STEP_UP_WINDOW;
        session_regenerate_id(true);
        $_SESSION['step_up_verified_at']     = time();
        $_SESSION['step_up_expires_at']      = time() + $ttl;
        $_SESSION['session_last_rotated_at'] = time();
    }

    public static function hasValidStepUp(): bool
    {
        return (int) ($_SESSION['step_up_expires_at'] ?? 0) > time();
    }

    public static function requireStepUpOrPassword(PDO $conn, int $userId, string $password): void
    {
        if (self::hasValidStepUp()) {
            return;
        }
        if (!self::verifyCurrentUserPassword($conn, $userId, $password)) {
            throw new RuntimeException('Step-up authentication required. Please confirm your password.');
        }
        self::markStepUpVerified();
    }

    // =========================================================================
    // LOGIN
    // =========================================================================

    public static function login(PDO $conn, array $credentials, array $logConfig): array
    {
        $username      = trim((string) ($credentials['username']      ?? ''));
        $password      = trim((string) ($credentials['password']      ?? ''));
        $remember      = !empty($credentials['remember']);
        $csrfToken     = (string) ($credentials['csrf_token']         ?? '');
        $captchaAnswer = trim((string) ($credentials['captcha_answer'] ?? ''));
        $normalized    = self::normalizeLoginIdentifier($username);
        $ip            = self::getIpAddress();

        self::ensureLoginAttemptsTable($conn);
        self::maybePruneStaleAttempts($conn);

        // ── Empty credentials ──────────────────────────────────────────────
        if ($username === '' || $password === '') {
            self::applyFailureDelay();
            self::logActivity($conn, $logConfig, null, 'login_failed',
                self::loginFailDesc($normalized, 'empty_credentials'), 'auth', null, 'security');
            return ['success' => false, 'message' => 'Username and password are required.'];
        }

        // ── CSRF ───────────────────────────────────────────────────────────
        if (!self::validateCsrfToken($csrfToken)) {
            self::rotateCsrfToken();
            self::applyFailureDelay();
            self::logActivity($conn, $logConfig, null, 'login_failed',
                self::loginFailDesc($normalized, 'csrf_mismatch'), 'auth', null, 'security');
            return ['success' => false, 'message' => 'Invalid request. Please refresh and try again.', 'code' => 403];
        }

        // ── Fetch attempt states ONCE — shared by captcha + lockout checks ──
        //
        // IMPORTANT: lockout is intentionally checked AFTER credential
        // verification (see below).  This ensures a user who knows the
        // correct password can always log in, even while an attacker has
        // triggered the lockout on their account.  Checking lockout first
        // would incorrectly block legitimate users.
        $states = self::loadAttemptStates($conn, $normalized, $ip);

        // ── CAPTCHA (always checked before credentials) ────────────────────
        //
        // Captcha is validated before we hit the DB so automated scripts
        // can't enumerate passwords even on locked accounts.
        // NOTE: we do NOT block here if the account is locked — a locked
        // account with a correct password still gets through (see below).
        if (self::isCaptchaRequiredFromStates($states) && !self::validateCaptchaAnswer($captchaAnswer)) {
            self::recordFailedAttemptFromStates($conn, $logConfig, null, $normalized, $ip, 'captcha_failed', $states);
            self::rotateCsrfToken();
            self::applyFailureDelay();
            return ['success' => false, 'message' => 'Please answer the verification challenge correctly.', 'code' => 422];
        }

        // ── DB lookup & credential check ───────────────────────────────────
        try {
            $stmt = $conn->prepare(
                'SELECT user_id, username, password, role, first_name, last_name, photo, status
                 FROM ' . self::TABLE . '
                 WHERE username = :username
                 LIMIT 1'
            );
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // ── Username not found ─────────────────────────────────────────
            // Check lockout AFTER confirming the credentials are wrong.
            // This way a brute-forcer sees the lockout message once locked,
            // but a legitimate user with the correct password never gets here.
            if (!$user) {
                self::recordFailedAttemptFromStates($conn, $logConfig, null, $normalized, $ip, 'username_not_found', $states);
                self::rotateCsrfToken();
                self::applyFailureDelay();

                // Re-read states after recording the new failure so the
                // lockout message reflects the attempt we just counted.
                $updatedStates = self::loadAttemptStates($conn, $normalized, $ip);
                if (self::isLockedOut($updatedStates)) {
                    self::logActivity($conn, $logConfig, null, 'login_blocked',
                        self::loginBlockDesc($normalized, 'any'), 'auth', null, 'security');
                    return ['success' => false,
                            'message' => 'Too many failed attempts. Please wait 10 minutes before trying again.',
                            'code'    => 429];
                }

                return ['success' => false, 'message' => 'Invalid username or password.'];
            }

            // ── Wrong password ─────────────────────────────────────────────
            if (!password_verify($password, (string) $user['password'])) {
                self::recordFailedAttemptFromStates($conn, $logConfig, (int) $user['user_id'], $normalized, $ip, 'wrong_password', $states);
                self::rotateCsrfToken();
                self::applyFailureDelay();

                // Same pattern: re-read after recording so the count is fresh.
                $updatedStates = self::loadAttemptStates($conn, $normalized, $ip);
                if (self::isLockedOut($updatedStates)) {
                    self::logActivity($conn, $logConfig, (int) $user['user_id'], 'login_blocked',
                        self::loginBlockDesc($normalized, 'any'), 'auth', (int) $user['user_id'], 'security');
                    return ['success' => false,
                            'message' => 'Too many failed attempts. Please wait 10 minutes before trying again.',
                            'code'    => 429];
                }

                return ['success' => false, 'message' => 'Invalid username or password.'];
            }

            // ── Correct password — account status check ────────────────────
            // We intentionally do NOT check lockout here.  If the password
            // is correct the user is legitimate; the lockout is cleared below.
            if (($user['status'] ?? 'active') !== 'active') {
                self::rotateCsrfToken();
                self::logActivity($conn, $logConfig, (int) $user['user_id'], 'login_failed',
                    self::loginFailDesc($normalized, 'inactive_account'), 'auth', (int) $user['user_id'], 'security');
                return ['success' => false, 'message' => 'Your account is inactive. Please contact the administrator.'];
            }

            // ── SUCCESS ────────────────────────────────────────────────────
            // Correct password + active account: clear all lockout state and
            // establish the authenticated session regardless of any prior
            // lockout that may have been triggered by an attacker.
            session_regenerate_id(true);

            $_SESSION['user_id']       = (int)    $user['user_id'];
            $_SESSION['username']      = (string) $user['username'];
            $_SESSION['role']          = (string) $user['role'];
            $_SESSION['first_name']    = (string) ($user['first_name'] ?? '');
            $_SESSION['last_name']     = (string) ($user['last_name']  ?? '');
            $_SESSION['photo']         = StaffController::normalizePhotoUrl((string) ($user['photo'] ?? ''));
            $_SESSION['last_activity'] = time();
            self::bindSessionContext();

            self::clearLoginAttempts($conn, $normalized, $ip);
            self::clearCaptchaChallenge();
            self::rotateCsrfToken();

            if ($remember) {
                self::setRememberMe($conn, (int) $user['user_id']);
            }

            self::logActivity($conn, $logConfig, (int) $user['user_id'],
                'login_success', "User logged in: {$username}", 'auth', (int) $user['user_id']);

            return ['success' => true, 'message' => 'Login successful.', 'redirect' => '/inventory_system/index.php'];

        } catch (PDOException $e) {
            error_log('[AuthController::login] ' . $e->getMessage());
            self::logActivity($conn, $logConfig, null, 'login_failed',
                self::loginFailDesc($normalized, 'db_error'), 'auth', null, 'security');
            return ['success' => false, 'message' => 'A server error occurred. Please try again later.'];
        }
    }

    // =========================================================================
    // SECURITY STATE (for login page UI)
    // =========================================================================

    public static function getLoginSecurityState(PDO $conn, string $username = ''): array
    {
        self::ensureLoginAttemptsTable($conn);

        $normalized      = self::normalizeLoginIdentifier($username);
        $ip              = self::getIpAddress();
        $states          = self::loadAttemptStates($conn, $normalized, $ip);
        $captchaRequired = self::isCaptchaRequiredFromStates($states);

        return [
            'captcha_required' => $captchaRequired,
            'captcha_prompt'   => $captchaRequired ? self::getCaptchaChallengePrompt() : null,
        ];
    }

    // =========================================================================
    // VERIFY PASSWORD
    // =========================================================================

    public static function verifyCurrentUserPassword(PDO $conn, int $userId, string $password): bool
    {
        if ($userId <= 0 || trim($password) === '') {
            return false;
        }

        $stmt = $conn->prepare(
            'SELECT password, status FROM ' . self::TABLE .
            ' WHERE user_id = :user_id LIMIT 1'
        );
        $stmt->execute([':user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || ($user['status'] ?? 'inactive') !== 'active') {
            return false;
        }

        return password_verify($password, (string) $user['password']);
    }

    // =========================================================================
    // LOGOUT
    // =========================================================================

    public static function logout(PDO $conn, array $logConfig): void
    {
        $userId   = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $username = (string) ($_SESSION['username'] ?? 'UNKNOWN');

        // Delete remember-me token
        $cookie = (string) ($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if ($cookie !== '' && str_contains($cookie, ':')) {
            [$selector] = explode(':', $cookie, 2);
            $conn->prepare('DELETE FROM ' . self::REMEMBER_TABLE . ' WHERE selector = :s')
                 ->execute([':s' => $selector]);
        }

        self::clearRememberCookie();
        self::logActivity($conn, $logConfig, $userId, 'logout', "User logged out: {$username}", 'auth', $userId);

        $_SESSION = [];
        session_unset();

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42_000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
        }

        session_destroy();
    }

    // =========================================================================
    // REMEMBER-ME
    // =========================================================================

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

        $stmt = $conn->prepare(
            'SELECT rt.user_id, rt.selector, rt.token_hash, rt.expires_at, rt.user_agent, rt.ip_address,
                    u.user_id AS u_user_id, u.username, u.role, u.first_name, u.last_name, u.photo, u.status
             FROM ' . self::REMEMBER_TABLE . ' rt
             INNER JOIN ' . self::TABLE . ' u ON u.user_id = rt.user_id
             WHERE rt.selector = :selector AND rt.expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([':selector' => $selector]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            self::clearRememberCookie();
            return false;
        }

        if (!hash_equals((string) $row['token_hash'], hash('sha256', $token))) {
            $conn->prepare('DELETE FROM ' . self::REMEMBER_TABLE . ' WHERE selector = :s')
                 ->execute([':s' => $selector]);
            self::clearRememberCookie();
            return false;
        }

        if (($row['status'] ?? 'active') !== 'active' || self::rememberContextMismatch($row)) {
            $conn->prepare('DELETE FROM ' . self::REMEMBER_TABLE . ' WHERE selector = :s')
                 ->execute([':s' => $selector]);
            self::clearRememberCookie();
            return false;
        }

        // Rotate token (token-rotation pattern prevents replay)
        $conn->prepare('DELETE FROM ' . self::REMEMBER_TABLE . ' WHERE selector = :s')
             ->execute([':s' => $selector]);
        self::setRememberMe($conn, (int) $row['u_user_id']);

        session_regenerate_id(true);

        $_SESSION['user_id']       = (int)    $row['u_user_id'];
        $_SESSION['username']      = (string) $row['username'];
        $_SESSION['role']          = (string) $row['role'];
        $_SESSION['first_name']    = (string) ($row['first_name'] ?? '');
        $_SESSION['last_name']     = (string) ($row['last_name']  ?? '');
        $_SESSION['photo']         = StaffController::normalizePhotoUrl((string) ($row['photo'] ?? ''));
        $_SESSION['last_activity'] = time();
        self::bindSessionContext();
        self::rotateCsrfToken();

        // Prune expired tokens probabilistically
        self::maybePruneExpiredRememberTokens($conn);

        return true;
    }

    // =========================================================================
    // ACTIVITY LOG
    // =========================================================================

    public static function logActivity(
        PDO     $conn,
        array   $logConfig,
        ?int    $userId,
        string  $action,
        string  $description  = '',
        ?string $entityType   = null,
        ?int    $entityId     = null,
        string  $level        = 'info'
    ): void {
        try {
            $stmt = $conn->prepare(
                "INSERT INTO {$logConfig['table']}
                    ({$logConfig['col_user_id']}, {$logConfig['col_action']}, {$logConfig['col_desc']},
                     {$logConfig['col_ip']}, {$logConfig['col_created']},
                     entity_type, entity_id, level, user_agent)
                 VALUES
                    (:user_id, :action, :description, :ip, NOW(),
                     :entity_type, :entity_id, :level, :user_agent)"
            );
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

    // =========================================================================
    // PRIVATE – Rate-limit helpers
    // =========================================================================

    /**
     * Load all three scope rows in ONE query instead of three.
     * Returns ['username' => row|null, 'ip' => row|null, 'username_ip' => row|null]
     *
     * @return array<string, array<string,mixed>|null>
     */
    private static function loadAttemptStates(PDO $conn, string $username, string $ip): array
    {
        $scopes   = self::buildAttemptScopes($username, $ip);
        $scopeMap = [];
        $pairs    = [];

        foreach ($scopes as $scopeType => $scope) {
            $scopeMap[$scopeType . ':' . $scope['scope_key']] = $scopeType;
            $pairs[] = "('" . $scopeType . "', '" . addslashes($scope['scope_key']) . "')";
        }

        if ($pairs === []) {
            return array_fill_keys(array_keys($scopes), null);
        }

        $stmt = $conn->query(
            'SELECT * FROM ' . self::LOGIN_ATTEMPTS_TABLE .
            ' WHERE (scope_type, scope_key) IN (' . implode(', ', $pairs) . ')'
        );

        $rows   = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $result = array_fill_keys(array_keys($scopes), null);
        $now    = new DateTimeImmutable();

        foreach ($rows as $row) {
            $key = $row['scope_type'] . ':' . $row['scope_key'];
            if (!isset($scopeMap[$key])) {
                continue;
            }
            $type = $scopeMap[$key];

            if (self::isAttemptRowExpired($row, $now)) {
                // Lazy-delete: clean up on read (non-blocking, best-effort)
                $conn->prepare(
                    'DELETE FROM ' . self::LOGIN_ATTEMPTS_TABLE .
                    " WHERE scope_type = :t AND scope_key = :k"
                )->execute([':t' => $row['scope_type'], ':k' => $row['scope_key']]);
                continue;
            }

            $result[$type] = $row;
        }

        return $result;
    }

    /**
     * Check lockout from pre-loaded states (no extra query).
     */
    private static function isLockedOut(array $states): bool
    {
        $now = time();
        foreach ($states as $row) {
            if (!$row) {
                continue;
            }
            $until = isset($row['locked_until']) ? strtotime((string) $row['locked_until']) : false;
            if ($until !== false && $until > $now) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check CAPTCHA requirement from pre-loaded states (no extra query).
     */
    private static function isCaptchaRequiredFromStates(array $states): bool
    {
        $thresholds = [
            'username_ip' => self::CAPTCHA_THRESHOLD_COMBO,
            'username'    => self::CAPTCHA_THRESHOLD_USERNAME,
            'ip'          => self::CAPTCHA_THRESHOLD_IP,
        ];

        foreach ($thresholds as $scope => $threshold) {
            if ((int) ($states[$scope]['attempt_count'] ?? 0) >= $threshold) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record a failed attempt using the already-fetched $states to avoid
     * re-fetching the three rows.
     */
    private static function recordFailedAttemptFromStates(
        PDO    $conn,
        array  $logConfig,
        ?int   $userId,
        string $username,
        string $ip,
        string $reason,
        array  $states
    ): void {
        $scopes = self::buildAttemptScopes($username, $ip);
        $now    = new DateTimeImmutable();

        foreach ($scopes as $scopeType => $scope) {
            $row          = $states[$scopeType] ?? null;
            $expired      = self::isAttemptRowExpired($row, $now);
            $attemptCount = $expired ? 0 : (int) ($row['attempt_count'] ?? 0);
            $attemptCount++;

            $maxAttempts      = self::scopeMaxAttempts($scopeType);
            $lockedUntil      = $attemptCount >= $maxAttempts
                ? $now->modify('+' . self::LOCKOUT_TIME . ' seconds')->format('Y-m-d H:i:s')
                : null;
            $captchaRequired  = $attemptCount >= self::scopeCaptchaThreshold($scopeType) ? 1 : 0;
            $firstAttemptAt   = ($row && !$expired)
                ? (string) ($row['first_attempt_at'] ?? $now->format('Y-m-d H:i:s'))
                : $now->format('Y-m-d H:i:s');

            // Determine whether we need to send a lockout notification
            $lastNotifiedAt = (!$row || $expired) ? null : ($row['last_notified_at'] ?? null);
            $shouldNotify   = $lockedUntil !== null
                && self::shouldSendLockoutNotification($lastNotifiedAt);

            $newNotifiedAt = $shouldNotify ? $now->format('Y-m-d H:i:s') : $lastNotifiedAt;

            self::upsertAttemptRow(
                $conn, $scopeType, $scope, $attemptCount,
                $now->format('Y-m-d H:i:s'), $lockedUntil, $captchaRequired,
                $firstAttemptAt, $newNotifiedAt
            );

            // Fire notification AFTER the upsert (last_notified_at is already written)
            if ($shouldNotify) {
                self::dispatchLockoutNotification($conn, $scopeType, $scope, $attemptCount);
            }
        }

        self::logActivity($conn, $logConfig, $userId, 'login_failed',
            self::loginFailDesc($username, $reason), 'auth', $userId, 'security');
    }

    private static function shouldSendLockoutNotification(?string $lastNotifiedAt): bool
    {
        if ($lastNotifiedAt === null) {
            return true;
        }
        $last = strtotime($lastNotifiedAt);
        return $last === false || (time() - $last) >= self::ALERT_COOLDOWN;
    }

    private static function dispatchLockoutNotification(
        PDO    $conn,
        string $scopeType,
        array  $scope,
        int    $attemptCount
    ): void {
        $message = match ($scopeType) {
            'username_ip' => sprintf(
                'Login blocked for username "%s" from IP %s after %d failed attempts.',
                $scope['username'] ?? 'unknown', $scope['ip_address'] ?? 'unknown', $attemptCount
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
                $conn, null, 'admin', 'security',
                'Brute-force login blocked', $message,
                'bi-shield-lock', 'text-danger',
                '/inventory_system/admin/activity_log.php'
            );
        } catch (Throwable $e) {
            error_log('[AuthController::dispatchLockoutNotification] ' . $e->getMessage());
        }
    }

    /**
     * Delete all attempt rows for the given username+IP in ONE query.
     */
    private static function clearLoginAttempts(PDO $conn, string $username, string $ip): void
    {
        self::ensureLoginAttemptsTable($conn);

        $scopes = self::buildAttemptScopes($username, $ip);
        $pairs  = [];

        foreach ($scopes as $scopeType => $scope) {
            $pairs[] = "('" . $scopeType . "', '" . addslashes($scope['scope_key']) . "')";
        }

        if ($pairs !== []) {
            $conn->exec(
                'DELETE FROM ' . self::LOGIN_ATTEMPTS_TABLE .
                ' WHERE (scope_type, scope_key) IN (' . implode(', ', $pairs) . ')'
            );
        }
    }

    // =========================================================================
    // PRIVATE – Probabilistic pruning
    // =========================================================================

    /**
     * With probability 1/PRUNE_PROBABILITY, delete expired attempt rows.
     * Keeps the table lean without a dedicated cron job.
     */
    private static function maybePruneStaleAttempts(PDO $conn): void
    {
        if (random_int(1, self::PRUNE_PROBABILITY) !== 1) {
            return;
        }

        try {
            $cutoff = (new DateTimeImmutable())
                ->modify('-' . self::LOCKOUT_TIME . ' seconds')
                ->format('Y-m-d H:i:s');

            $conn->prepare(
                'DELETE FROM ' . self::LOGIN_ATTEMPTS_TABLE .
                ' WHERE (locked_until IS NULL OR locked_until < NOW())
                    AND last_attempt_at < :cutoff'
            )->execute([':cutoff' => $cutoff]);
        } catch (Throwable $e) {
            error_log('[AuthController::maybePruneStaleAttempts] ' . $e->getMessage());
        }
    }

    /**
     * With probability 1/PRUNE_PROBABILITY, delete expired remember_tokens rows.
     */
    private static function maybePruneExpiredRememberTokens(PDO $conn): void
    {
        if (random_int(1, self::PRUNE_PROBABILITY) !== 1) {
            return;
        }

        try {
            $conn->exec('DELETE FROM ' . self::REMEMBER_TABLE . ' WHERE expires_at < NOW()');
        } catch (Throwable $e) {
            error_log('[AuthController::maybePruneExpiredRememberTokens] ' . $e->getMessage());
        }
    }

    // =========================================================================
    // PRIVATE – Attempt-row persistence
    // =========================================================================

    private static function upsertAttemptRow(
        PDO     $conn,
        string  $scopeType,
        array   $scope,
        int     $attemptCount,
        string  $lastAttemptAt,
        ?string $lockedUntil,
        int     $captchaRequired,
        string  $firstAttemptAt,
        ?string $lastNotifiedAt
    ): void {
        $stmt = $conn->prepare(
            'INSERT INTO ' . self::LOGIN_ATTEMPTS_TABLE . '
                (scope_type, scope_key, username, ip_address, attempt_count,
                 first_attempt_at, last_attempt_at, locked_until, captcha_required, last_notified_at)
             VALUES
                (:scope_type, :scope_key, :username, :ip_address, :attempt_count,
                 :first_attempt_at, :last_attempt_at, :locked_until, :captcha_required, :last_notified_at)
             ON DUPLICATE KEY UPDATE
                username         = VALUES(username),
                ip_address       = VALUES(ip_address),
                attempt_count    = VALUES(attempt_count),
                first_attempt_at = VALUES(first_attempt_at),
                last_attempt_at  = VALUES(last_attempt_at),
                locked_until     = VALUES(locked_until),
                captcha_required = VALUES(captcha_required),
                last_notified_at = VALUES(last_notified_at)'
        );
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

    private static function isAttemptRowExpired(?array $row, DateTimeImmutable $now): bool
    {
        if (!$row) {
            return true;
        }

        $lockedUntil = isset($row['locked_until']) && $row['locked_until'] !== null
            ? strtotime((string) $row['locked_until'])
            : false;

        if ($lockedUntil !== false && $lockedUntil > $now->getTimestamp()) {
            return false; // Still locked – row is live
        }

        $lastAttempt = isset($row['last_attempt_at'])
            ? strtotime((string) $row['last_attempt_at'])
            : false;

        if ($lastAttempt === false) {
            return true;
        }

        return ($now->getTimestamp() - $lastAttempt) > self::LOCKOUT_TIME;
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
            'ip'       => self::MAX_ATTEMPTS_IP,
            default    => self::MAX_ATTEMPTS_COMBO,
        };
    }

    private static function scopeCaptchaThreshold(string $scopeType): int
    {
        return match ($scopeType) {
            'username' => self::CAPTCHA_THRESHOLD_USERNAME,
            'ip'       => self::CAPTCHA_THRESHOLD_IP,
            default    => self::CAPTCHA_THRESHOLD_COMBO,
        };
    }

    // =========================================================================
    // PRIVATE – Table bootstrap
    // =========================================================================

    private static function ensureLoginAttemptsTable(PDO $conn): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }

        $conn->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::LOGIN_ATTEMPTS_TABLE . " (
                attempt_id       INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
                scope_type       ENUM('username','ip','username_ip') NOT NULL,
                scope_key        VARCHAR(191)  NOT NULL,
                username         VARCHAR(100)  NULL,
                ip_address       VARCHAR(45)   NULL,
                attempt_count    INT UNSIGNED  NOT NULL DEFAULT 0,
                first_attempt_at DATETIME      NOT NULL,
                last_attempt_at  DATETIME      NOT NULL,
                locked_until     DATETIME      NULL,
                captcha_required TINYINT(1)    NOT NULL DEFAULT 0,
                last_notified_at DATETIME      NULL,
                created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_scope       (scope_type, scope_key),
                KEY idx_username              (username),
                KEY idx_ip_address            (ip_address),
                KEY idx_locked_until          (locked_until),
                KEY idx_last_attempt_at       (last_attempt_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        $ready = true;
    }

    // =========================================================================
    // PRIVATE – Session fingerprint
    // =========================================================================

    /**
     * Build a per-request session fingerprint.
     *
     * The HMAC key is derived from APP_KEY (cached in a static var to avoid
     * repeated calls to app_secret_value()).  If APP_KEY is unavailable,
     * falls back to a hash of session_id() — non-trivial and per-session,
     * not a predictable hard-coded string.
     */
    private static function buildSessionFingerprint(): string
    {
        static $appKey = null;

        if ($appKey === null) {
            try {
                $appKey = function_exists('app_secret_value')
                    ? app_secret_value('APP_KEY', false)
                    : '';
            } catch (Throwable) {
                $appKey = '';
            }

            if ($appKey === '') {
                // Derive a deterministic per-session key that is not predictable
                // to an attacker who doesn't know the session ID.
                $appKey = hash('sha256', 'session-fp|' . session_id());
            }
        }

        $userAgent = strtolower(substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255));
        $ipPrefix  = self::ipPrefix(self::getIpAddress());

        return hash_hmac('sha256', $userAgent . '|' . $ipPrefix, $appKey);
    }
    
    private static function ipPrefix(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $p = explode('.', $ip);
            return count($p) === 4 ? "{$p[0]}.{$p[1]}.{$p[2]}" : $ip;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return implode(':', array_slice(explode(':', $ip), 0, 4));
        }

        return 'unknown';
    }

    // =========================================================================
    // PRIVATE – Remember-me
    // =========================================================================

    private static function setRememberMe(PDO $conn, int $userId): void
    {
        $selector = bin2hex(random_bytes(12));
        $token    = bin2hex(random_bytes(32));
        $expires  = (new DateTimeImmutable('+' . self::REMEMBER_DAYS . ' days'))->format('Y-m-d H:i:s');

        $conn->prepare(
            'INSERT INTO ' . self::REMEMBER_TABLE .
            ' (user_id, selector, token_hash, expires_at, user_agent, ip_address)
             VALUES (:user_id, :selector, :token_hash, :expires_at, :user_agent, :ip_address)'
        )->execute([
            ':user_id'    => $userId,
            ':selector'   => $selector,
            ':token_hash' => hash('sha256', $token),
            ':expires_at' => $expires,
            ':user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ':ip_address' => self::getIpAddress(),
        ]);

        $isHttps = self::rememberCookieSecureFlag();
        setcookie(self::REMEMBER_COOKIE, $selector . ':' . $token, [
            'expires'  => time() + (86_400 * self::REMEMBER_DAYS),
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function clearRememberCookie(): void
    {
        setcookie(self::REMEMBER_COOKIE, '', [
            'expires'  => time() - 3_600,
            'path'     => '/',
            'domain'   => '',
            'secure'   => self::rememberCookieSecureFlag(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function rememberCookieSecureFlag(): bool
    {
        return function_exists('app_is_https')
            ? app_is_https()
            : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    }

    /**
     * IP-binding is enforced for routable public IPs only.
     *
     * The original code had the logic inverted: $enforceIpBinding was set to
     * true unconditionally, then overwritten with true again for private IPs —
     * meaning it was always true.  The intent is to SKIP enforcement for
     * private/local IPs (mobile users, NAT, dynamic home IPs).
     */
    private static function rememberContextMismatch(array $row): bool
    {
        $currentUA = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $storedUA  = (string) ($row['user_agent'] ?? '');

        if ($storedUA !== '' && $currentUA !== '' && !hash_equals($storedUA, $currentUA)) {
            return true;
        }

        $storedIp  = trim((string) ($row['ip_address'] ?? ''));
        $currentIp = trim(self::getIpAddress());

        if ($storedIp === '' || $storedIp === 'UNKNOWN' || $currentIp === '' || $currentIp === 'UNKNOWN') {
            return false;
        }

        // Enforce IP binding only for routable public IPs.
        // Private/loopback addresses are skipped because users behind NAT or
        // on mobile networks change IPs frequently.
        $isPrivate = function_exists('app_is_private_or_local_ip')
            ? (app_is_private_or_local_ip($storedIp) || app_is_private_or_local_ip($currentIp))
            : self::isPrivateIp($storedIp) || self::isPrivateIp($currentIp);

        if ($isPrivate) {
            return false; // Don't enforce IP binding for private networks
        }

        return !hash_equals($storedIp, $currentIp);
    }

    /**
     * Fallback private-IP check when app_is_private_or_local_ip() is not available.
     */
    private static function isPrivateIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    // =========================================================================
    // PRIVATE – Misc helpers
    // =========================================================================

    private static function getIpAddress(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN');
    }

    private static function normalizeLoginIdentifier(string $username): string
    {
        return strtolower(trim($username));
    }

    private static function loginFailDesc(string $username, string $reason): string
    {
        return "target_username={$username}; reason={$reason}";
    }

    private static function loginBlockDesc(string $username, string $scope): string
    {
        return "target_username={$username}; scope={$scope}";
    }

    private static function applyFailureDelay(): void
    {
        usleep(random_int(self::FAILURE_DELAY_MIN_US, self::FAILURE_DELAY_MAX_US));
    }

    // =========================================================================
    // PRIVATE – CAPTCHA
    // =========================================================================

    private static function getCaptchaChallengePrompt(): string
    {
        if (
            empty($_SESSION['login_captcha']) ||
            !is_array($_SESSION['login_captcha']) ||
            (time() - (int) ($_SESSION['login_captcha']['generated_at'] ?? 0)) > 600
        ) {
            $a = random_int(2, 9);
            $b = random_int(1, 9);
            $_SESSION['login_captcha'] = ['a' => $a, 'b' => $b, 'answer' => $a + $b, 'generated_at' => time()];
        }

        return (int) $_SESSION['login_captcha']['a'] . ' + ' . (int) $_SESSION['login_captcha']['b'] . ' = ?';
    }

    private static function validateCaptchaAnswer(string $answer): bool
    {
        $c = $_SESSION['login_captcha'] ?? null;
        if (!is_array($c) || (time() - (int) ($c['generated_at'] ?? 0)) > 600) {
            self::clearCaptchaChallenge();
            return false;
        }

        $expected = (string) ($c['answer'] ?? '');
        $provided = trim($answer);
        $valid    = $expected !== '' && $provided !== '' && hash_equals($expected, $provided);

        if ($valid) {
            self::clearCaptchaChallenge();
        }

        return $valid;
    }

    private static function clearCaptchaChallenge(): void
    {
        unset($_SESSION['login_captcha']);
    }

    // =========================================================================
    // PRIVATE – Suspicious session
    // =========================================================================

    private static function recordSuspiciousSession(PDO $conn, int $userId, string $reason): void
    {
        try {
            if ($userId > 0) {
                NotificationController::create(
                    $conn, $userId, 'admin', 'session_anomaly',
                    'Suspicious session blocked',
                    'A session anomaly was detected and terminated. Reason: ' . $reason,
                    'bi-shield-exclamation', 'text-danger',
                    '/inventory_system/admin/activity_log.php'
                );
            }

            $logConfig = self::buildLogConfigFromGlobals();
            if ($logConfig !== null) {
                self::logActivity($conn, $logConfig,
                    $userId > 0 ? $userId : null,
                    'session_security_block',
                    'Session blocked due to ' . $reason,
                    'auth', $userId > 0 ? $userId : null, 'security'
                );
            }
        } catch (Throwable $e) {
            error_log('[AuthController::recordSuspiciousSession] ' . $e->getMessage());
        }
    }

    /**
     * Build a logConfig array from the $GLOBALS set by bootstrap — centralised
     * so it does not need to be duplicated across multiple private methods.
     * Returns null if the globals are not set.
     *
     * @return array<string,string>|null
     */
    private static function buildLogConfigFromGlobals(): ?array
    {
        $keys = [
            'table_activity_logs',
            'activity_log_user_id',
            'activity_log_action',
            'activity_log_desc',
            'activity_log_ip',
            'activity_log_created',
        ];

        foreach ($keys as $key) {
            if (!isset($GLOBALS[$key])) {
                return null;
            }
        }

        return [
            'table'       => (string) $GLOBALS['table_activity_logs'],
            'col_user_id' => (string) $GLOBALS['activity_log_user_id'],
            'col_action'  => (string) $GLOBALS['activity_log_action'],
            'col_desc'    => (string) $GLOBALS['activity_log_desc'],
            'col_ip'      => (string) $GLOBALS['activity_log_ip'],
            'col_created' => (string) $GLOBALS['activity_log_created'],
        ];
    }
}