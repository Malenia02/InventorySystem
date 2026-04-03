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
        throw new RuntimeException('Unable to load your profile.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!AuthController::validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Security token mismatch. Please refresh and try again.');
        }

        $fullName = trim((string) ($_POST['first_name'] ?? '') . '_' . (string) ($_POST['last_name'] ?? ''));
        $photoPath = null;

        if (!empty($_FILES['photo']['name'])) {
            $photoPath = StaffController::handlePhotoUpload('photo', $fullName);
        }

        StaffController::updateStaff($conn, $sessionUserId, [
            'first_name' => (string) ($_POST['first_name'] ?? ''),
            'last_name'  => (string) ($_POST['last_name'] ?? ''),
            'email'      => (string) ($_POST['email'] ?? ''),
            'username'   => (string) ($_POST['username'] ?? ''),
            'password'   => '',
            'role'       => (string) ($user['role'] ?? 'staff'),
            'photo'      => $photoPath,
        ]);

        $user = StaffController::getStaffById($conn, $sessionUserId);
        if (!$user) {
            throw new RuntimeException('Unable to reload your updated profile.');
        }

        $_SESSION['username'] = (string) ($user['username'] ?? '');
        $_SESSION['first_name'] = (string) ($user['first_name'] ?? '');
        $_SESSION['last_name'] = (string) ($user['last_name'] ?? '');
        $_SESSION['photo'] = (string) ($user['photo'] ?? '');

        $successMessage = 'Profile updated successfully.';
    }
} catch (Throwable $e) {
    error_log('[profile.php] ' . $e->getMessage());
    $errorMessage = $e instanceof InvalidArgumentException || $e instanceof RuntimeException
        ? $e->getMessage()
        : 'Unable to save your profile right now.';

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

function e_profile(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/components/head.php'; ?>
    <title>My Profile</title>
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
                        src="<?= e_profile($profile['photo'] ?? '/inventory_system/assets/img/default-user.png') ?>"
                        alt="Profile"
                        class="rounded-circle mb-3"
                        style="width: 132px; height: 132px; object-fit: cover;"
                        onerror="this.src='/inventory_system/assets/img/default-user.png';this.onerror=null;"
                    >
                    <h4 class="mb-1"><?= e_profile($profile['full_name'] ?: 'User') ?></h4>
                    <div class="text-muted mb-2"><?= ucfirst(e_profile($profile['role'] ?? 'staff')) ?></div>
                    <span class="badge <?= ($profile['status'] ?? 'inactive') === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                        <?= e_profile($profile['status_label'] ?? 'Inactive') ?>
                    </span>
                    <hr>
                    <div class="text-start small">
                        <div class="mb-2"><strong>Username:</strong> <?= e_profile($profile['username'] ?? '-') ?></div>
                        <div class="mb-2"><strong>Email:</strong> <?= e_profile($profile['email'] ?? '-') ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-8">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">Edit Profile</h5>

                    <?php if ($successMessage !== null): ?>
                        <div class="alert alert-success"><?= e_profile($successMessage) ?></div>
                    <?php endif; ?>

                    <?php if ($errorMessage !== null): ?>
                        <div class="alert alert-danger"><?= e_profile($errorMessage) ?></div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= e_profile($csrfToken) ?>">

                        <div class="col-md-6">
                            <label class="form-label">First Name</label>
                            <input type="text" name="first_name" class="form-control" value="<?= e_profile($profile['first_name'] ?? '') ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Last Name</label>
                            <input type="text" name="last_name" class="form-control" value="<?= e_profile($profile['last_name'] ?? '') ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" value="<?= e_profile($profile['username'] ?? '') ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= e_profile($profile['email'] ?? '') ?>" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Profile Photo</label>
                            <input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                            <div class="form-text">Optional. JPG, PNG, or WEBP only. Maximum 2MB.</div>
                        </div>

                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Save Profile</button>
                            <a href="/inventory_system/settings.php" class="btn btn-outline-secondary">Go to Account Settings</a>
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
