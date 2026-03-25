<?php
define('AUTH_CONTEXT', 'public');

require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/controllers/AuthController.php';

// Redirect already logged-in users
if (isset($_SESSION['user_id'])) {
    header('Location: /inventory_system/index.php');
    exit;
}

// Generate CSRF token for form
$csrf_token    = AuthController::generateCsrfToken();
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Users table config
    $userConfig = [
        'table'          => $table_users,
        'col_id'         => $user_id,
        'col_username'   => $user_username,
        'col_password'   => $user_password,
        'col_role'       => $user_role,
        'col_status'     => $user_status,
        'col_first_name' => $user_firstname,
        'col_last_name'  => $user_lastname,
        'col_photo'      => $user_photoPath,
    ];

    // Activity log config
    $logConfig = [
        'table'       => $table_activity_logs,
        'col_user_id' => $activity_log_user_id,
        'col_action'  => $activity_log_action,
        'col_desc'    => $activity_log_desc,
        'col_ip'      => $activity_log_ip,
        'col_created' => $activity_log_created,
    ];

    $error_message = AuthController::login($conn, $userConfig, $logConfig);
}

// Always re-read after POST — token may have rotated on failure
$csrf_token = AuthController::generateCsrfToken();
?>

<!DOCTYPE html>
<html lang="en">
<?php require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/components/head.php'; ?>
<body>
<main>
    <div class="container">
        <section class="section register min-vh-100 d-flex flex-column align-items-center justify-content-center py-4">
            <div class="container">
                <div class="row justify-content-center">
                    <div class="col-lg-4 col-md-6 d-flex flex-column align-items-center justify-content-center">

                        <div class="d-flex justify-content-center py-4">
                            <a href="index.php" class="logo d-flex align-items-center w-auto">
                                <img src="assets/img/logo.png" alt="">
                                <span class="d-none d-lg-block">NiceAdmin</span>
                            </a>
                        </div>

                        <div class="card mb-3">
                            <div class="card-body">
                                <div class="pt-4 pb-2">
                                    <h5 class="card-title text-center pb-0 fs-4">Login to Your Account</h5>
                                    <p class="text-center small">Enter your username & password to login</p>
                                </div>

                                <form class="row g-3 needs-validation" novalidate method="POST">

                                    <!-- CSRF token -->
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                                    <?php if (!empty($error_message)): ?>
                                        <div class="col-12">
                                            <div class="alert alert-danger text-center small">
                                                <?= htmlspecialchars($error_message) ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="col-12">
                                        <label for="yourUsername" class="form-label">Username</label>
                                        <div class="input-group has-validation">
                                            <span class="input-group-text">@</span>
                                            <input type="text" name="username" class="form-control"
                                                   id="yourUsername" required
                                                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                                            <div class="invalid-feedback">Please enter your username.</div>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <label for="yourPassword" class="form-label">Password</label>
                                        <input type="password" name="password" class="form-control"
                                               id="yourPassword" required>
                                        <div class="invalid-feedback">Please enter your password!</div>
                                    </div>

                                    <div class="col-12">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox"
                                                   name="remember" value="true" id="rememberMe"
                                                   <?= !empty($_POST['remember']) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="rememberMe">Remember me</label>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <button class="btn btn-primary w-100" type="submit">Login</button>
                                    </div>

                                </form>
                            </div>
                        </div>

                        <div class="credits">
                            Designed by <a href="https://bootstrapmade.com/">BootstrapMade</a>
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
    })();
</script>

</body>
</html>