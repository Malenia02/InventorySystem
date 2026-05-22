<?php
declare(strict_types=1);

define('AUTH_CONTEXT', 'public');

require_once __DIR__ . '/bootstrap/app.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/components/branding.php';

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
$success_message = '';
$submittedUsername = (string) ($_POST['username'] ?? '');
$loginSecurity = AuthController::getLoginSecurityState($conn, $submittedUsername);
$brand = app_branding($conn ?? null);

if (!empty($_SESSION['setup_success'])) {
    $success_message = (string) $_SESSION['setup_success'];
    unset($_SESSION['setup_success']);
}

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
<style>
    body {
        min-height: 100vh;
        background:
            linear-gradient(rgba(28, 26, 23, 0.48), rgba(28, 26, 23, 0.48)),
            linear-gradient(135deg, rgba(184, 130, 42, 0.18), rgba(245, 244, 240, 0.12)),
            url('/inventory_system/assets/img/supermarket-unsplash.jpg') center center / cover no-repeat fixed;
    }

    .login-shell {
        width: min(100%, 1180px);
        margin: 0 auto;
    }

    .login-panel {
        background: rgba(255, 255, 255, 0.92);
        border: 1px solid rgba(255, 255, 255, 0.4);
        border-radius: 24px;
        box-shadow: 0 24px 70px rgba(17, 24, 39, 0.18);
        backdrop-filter: blur(8px);
    }

    .login-panel .card {
        background: transparent;
        border: 0;
        box-shadow: none !important;
    }

    .login-brand-copy {
        color: #fffdf8;
        text-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
    }

    .login-brand-copy .eyebrow {
        display: inline-block;
        margin-bottom: 14px;
        padding: 6px 12px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.14);
        border: 1px solid rgba(255, 255, 255, 0.2);
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .login-brand-copy h1 {
        font-size: clamp(2rem, 4vw, 3.4rem);
        font-weight: 700;
        line-height: 1.05;
        margin-bottom: 12px;
    }

    .login-brand-copy p {
        max-width: 32rem;
        margin: 0;
        font-size: 1rem;
        color: rgba(255, 253, 248, 0.86);
    }

    .login-logo span {
        color: #ffffff;
        font-weight: 700;
        letter-spacing: 0.01em;
        font-size: clamp(1.7rem, 4vw, 2.25rem);
        line-height: 1;
    }   

    .login-logo img,
    .login-logo .login-brand-mark {
        width: 86px;
        height: 78px;
        max-height: none;
        border-radius: 0;
        object-fit: contain;
        background: transparent;
        padding: 0;
        box-shadow: none;
        margin-right: 0;
    }

    .login-logo {
        gap: 6px;
        width: auto !important;
        justify-content: center;
    }

    .login-logo .login-brand-mark.is-default {
        background: transparent;
        padding: 0;
        box-shadow: none;
    }

    @media (max-width: 991.98px) {
        body {
            background-attachment: scroll;
        }

        .login-brand-copy {
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .login-brand-copy p {
            margin: 0 auto;
        }
    }
</style>
<main>
    <div class="container login-shell">
        <section class="section register min-vh-100 d-flex flex-column align-items-center justify-content-center py-4">
            <div class="container">
                <div class="row justify-content-center align-items-center g-4">
                    <div class="col-lg-6 d-flex align-items-center">
                        <div class="login-brand-copy">
                            <span class="eyebrow">Store Access</span>
                            <?php if (app_is_public_demo()): ?>
                                <div class="mb-3">
                                    <span class="badge rounded-pill text-bg-warning px-3 py-2">Public Demo</span>
                                </div>
                            <?php endif; ?>
                            <h1>Run your store from one clean dashboard.</h1>
                            <p>Secure login for inventory, sales, and reporting in a light storefront-inspired experience.</p>
                        </div>
                    </div>

                    <div class="col-lg-5 col-md-7 d-flex flex-column align-items-center justify-content-center">

                        <div class="d-flex justify-content-center py-4">
                            <a href="/inventory_system/index.php" class="logo login-logo d-flex align-items-center w-auto">
                                <img
                                    src="<?= htmlspecialchars((string) $brand['logo'], ENT_QUOTES, 'UTF-8') ?>"
                                    alt="<?= htmlspecialchars((string) $brand['name'], ENT_QUOTES, 'UTF-8') ?> logo"
                                    class="login-brand-mark <?= !empty($brand['has_custom_logo']) ? '' : 'is-default' ?>"
                                >
                                <span class="d-none d-lg-block"><?= htmlspecialchars((string) $brand['name'], ENT_QUOTES, 'UTF-8') ?></span>
                            </a>
                        </div>

                        <div class="card mb-3 shadow-sm login-panel">
                            <div class="card-body p-4 p-lg-4">
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

                                    <?php if ($success_message !== ''): ?>
                                        <div class="col-12">
                                            <div class="alert alert-success text-center small mb-0">
                                                <?= htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8') ?>
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
