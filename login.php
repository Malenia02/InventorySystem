<?php
declare(strict_types=1);

define('AUTH_CONTEXT', 'public');

require_once __DIR__ . '/bootstrap/app.php';
require_once __DIR__ . '/controllers/AuthController.php';

// Activity log config
$logConfig = [
    'table'       => $table_activity_logs,
    'col_user_id' => $activity_log_user_id,
    'col_action'  => $activity_log_action,
    'col_desc'    => $activity_log_desc,
    'col_ip'      => $activity_log_ip,
    'col_created' => $activity_log_created,
];

// Try secure remember-me auto-login first
AuthController::consumeRememberMe($conn);

// Redirect already logged-in users
if (isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] > 0) {
    header('Location: /inventory_system/index.php');
    exit;
}

// Generate CSRF token for form
$csrf_token = AuthController::generateCsrfToken();
$error_message = '';
$submittedUsername = (string) ($_POST['username'] ?? '');
$loginSecurity = AuthController::getLoginSecurityState($conn, $submittedUsername);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = AuthController::login($conn, $_POST, $logConfig);

    if (!empty($result['success'])) {
        header('Location: ' . ($result['redirect'] ?? '/inventory_system/index.php'));
        exit;
    }

    $error_message = (string) ($result['message'] ?? 'Login failed.');
}

// Re-read token after POST because failed login may rotate it
$csrf_token = AuthController::generateCsrfToken();
$submittedUsername = (string) ($_POST['username'] ?? '');
$loginSecurity = AuthController::getLoginSecurityState($conn, $submittedUsername);
?>
<!DOCTYPE html>
<html lang="en">
<?php require __DIR__ . '/components/head.php'; ?>

<body>
<main>
    <div class="container">
        <section class="section register min-vh-100 d-flex flex-column align-items-center justify-content-center py-4">
            <div class="container">
                <div class="row justify-content-center">
                    <div class="col-lg-4 col-md-6 d-flex flex-column align-items-center justify-content-center">

                        <div class="d-flex justify-content-center py-4">
                            <a href="/inventory_system/index.php" class="logo d-flex align-items-center w-auto">
                                <img src="/inventory_system/assets/img/logo.png" alt="Logo">
                                <span class="d-none d-lg-block">NiceAdmin</span>
                            </a>
                        </div>

                        <div class="card mb-3 shadow-sm">
                            <div class="card-body">
                                <div class="pt-4 pb-2">
                                    <h5 class="card-title text-center pb-0 fs-4">Login to Your Account</h5>
                                    <p class="text-center small">Enter your username and password to log in</p>
                                </div>

                                <form class="row g-3 needs-validation" novalidate method="POST" action="">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                                    <?php if ($error_message !== ''): ?>
                                        <div class="col-12">
                                            <div class="alert alert-danger text-center small mb-0">
                                                <?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="col-12">
                                        <label for="yourUsername" class="form-label">Username</label>
                                        <div class="input-group has-validation">
                                            <span class="input-group-text">@</span>
                                            <input
                                                type="text"
                                                name="username"
                                                class="form-control"
                                                id="yourUsername"
                                                required
                                                autocomplete="username"
                                                value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            >
                                            <div class="invalid-feedback">Please enter your username.</div>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <label for="yourPassword" class="form-label">Password</label>
                                        <input
                                            type="password"
                                            name="password"
                                            class="form-control"
                                            id="yourPassword"
                                            required
                                            autocomplete="current-password"
                                        >
                                        <div class="invalid-feedback">Please enter your password.</div>
                                    </div>

                                    <div class="col-12">
                                        <div class="form-check">
                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                name="remember"
                                                value="1"
                                                id="rememberMe"
                                                <?= !empty($_POST['remember']) ? 'checked' : '' ?>
                                            >
                                            <label class="form-check-label" for="rememberMe">Remember me</label>
                                        </div>
                                    </div>

                                    <?php if (!empty($loginSecurity['captcha_required'])): ?>
                                        <div class="col-12">
                                            <label for="captchaAnswer" class="form-label">
                                                Verification Challenge
                                            </label>
                                            <div class="input-group has-validation">
                                                <span class="input-group-text">
                                                    <?= htmlspecialchars((string) ($loginSecurity['captcha_prompt'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                                <input
                                                    type="text"
                                                    name="captcha_answer"
                                                    class="form-control"
                                                    id="captchaAnswer"
                                                    inputmode="numeric"
                                                    pattern="[0-9]+"
                                                    required
                                                    autocomplete="off"
                                                >
                                                <div class="invalid-feedback">
                                                    Please answer the verification challenge.
                                                </div>
                                            </div>
                                            <div class="form-text">
                                                This appears only after repeated failed login attempts.
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="col-12">
                                        <button class="btn btn-primary w-100" type="submit">Login</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="credits text-center small">
                            Designed by <a href="https://bootstrapmade.com/" target="_blank" rel="noopener noreferrer">BootstrapMade</a>
                        </div>

                    </div>
                </div>
            </div>
        </section>
    </div>
</main>

<script>
(() => {
    'use strict';
    const forms = document.querySelectorAll('.needs-validation');

    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });
})();
</script>

</body>
</html>
