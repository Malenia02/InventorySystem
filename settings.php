<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap/app.php';
require_once __DIR__ . '/middleware/Middleware.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/StaffController.php';

Middleware::auth();

$csrfToken = Middleware::generateCsrfToken();
$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
$successMessage = null;
$errorMessage = null;

try {
    $user = StaffController::getStaffById($conn, $sessionUserId);
    if (!$user) {
        throw new RuntimeException('Unable to load your account settings.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!AuthController::validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Security token mismatch. Please refresh and try again.');
        }

        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            throw new InvalidArgumentException('Please fill in all password fields.');
        }

        if ($newPassword !== $confirmPassword) {
            throw new InvalidArgumentException('New password and confirm password do not match.');
        }

        if (strlen($newPassword) < 8) {
            throw new InvalidArgumentException('New password must be at least 8 characters long.');
        }

        $passwordStmt = $conn->prepare("
            SELECT password
            FROM users
            WHERE user_id = :user_id
            LIMIT 1
        ");
        $passwordStmt->execute([':user_id' => $sessionUserId]);
        $storedPasswordHash = (string) $passwordStmt->fetchColumn();

        if ($storedPasswordHash === '' || !password_verify($currentPassword, $storedPasswordHash)) {
            throw new RuntimeException('Current password is incorrect.');
        }

        if (password_verify($newPassword, $storedPasswordHash)) {
            throw new InvalidArgumentException('Please choose a new password that is different from the current password.');
        }

        $updatePasswordStmt = $conn->prepare("
            UPDATE users
            SET password = :password
            WHERE user_id = :user_id
        ");
        $updatePasswordStmt->execute([
            ':password' => password_hash($newPassword, PASSWORD_DEFAULT),
            ':user_id'  => $sessionUserId,
        ]);

        $successMessage = 'Password updated successfully.';
    }
} catch (Throwable $e) {
    error_log('[settings.php] ' . $e->getMessage());
    $errorMessage = $e instanceof InvalidArgumentException || $e instanceof RuntimeException
        ? $e->getMessage()
        : 'Unable to update your password right now.';

    if (empty($user)) {
        $user = [
            'first_name' => (string) ($_SESSION['first_name'] ?? ''),
            'last_name'  => (string) ($_SESSION['last_name'] ?? ''),
            'email'      => '',
            'username'   => (string) ($_SESSION['username'] ?? ''),
            'role'       => (string) ($_SESSION['role'] ?? 'staff'),
            'status'     => 'active',
            'photo'      => (string) ($_SESSION['photo'] ?? ''),
        ];
    }
}

$profile = StaffController::normalizeForView($user);

function e_settings(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/components/head.php'; ?>
    <title>Account Settings</title>
</head>
<body>
<?php
require __DIR__ . '/components/header.php';
require __DIR__ . '/components/sidebar.php';
require __DIR__ . '/components/breadcrumb.php';
?>

<section class="section">
    <div class="row">
        <div class="col-xl-4">
            <div class="card shadow-sm">
                <div class="card-body text-center pt-4">
                    <img
                        src="<?= e_settings($profile['photo'] ?? '/inventory_system/assets/img/default-user.png') ?>"
                        alt="Profile"
                        class="rounded-circle mb-3"
                        style="width: 110px; height: 110px; object-fit: cover;"
                        onerror="this.src='/inventory_system/assets/img/default-user.png';this.onerror=null;"
                    >
                    <h5 class="mb-1"><?= e_settings($profile['full_name'] ?: 'User') ?></h5>
                    <div class="text-muted"><?= ucfirst(e_settings($profile['role'] ?? 'staff')) ?></div>
                    <hr>
                    <div class="small text-start">
                        <div class="mb-2"><strong>Username:</strong> <?= e_settings($profile['username'] ?? '-') ?></div>
                        <div class="mb-2"><strong>Status:</strong> <?= e_settings($profile['status_label'] ?? 'Inactive') ?></div>
                        <div><strong>Password:</strong> Hidden for security</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-8">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">Change Password</h5>

                    <?php if ($successMessage !== null): ?>
                        <div class="alert alert-success"><?= e_settings($successMessage) ?></div>
                    <?php endif; ?>

                    <?php if ($errorMessage !== null): ?>
                        <div class="alert alert-danger"><?= e_settings($errorMessage) ?></div>
                    <?php endif; ?>

                    <form method="POST" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= e_settings($csrfToken) ?>">

                        <div class="col-12">
                            <label class="form-label">Current Password</label>
                            <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">New Password</label>
                            <input type="password" name="new_password" class="form-control" required autocomplete="new-password">
                            <div class="form-text">Use at least 8 characters.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" required autocomplete="new-password">
                        </div>

                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Update Password</button>
                            <a href="/inventory_system/profile.php" class="btn btn-outline-secondary">Back to My Profile</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/components/js_script.php'; ?>
</body>
</html>
