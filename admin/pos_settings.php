<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/AuthController.php';
require_once __DIR__ . '/../controllers/PosConfigController.php';

Middleware::auth()->role(['admin']);

$csrfToken = Middleware::generateCsrfToken();
$successMessage = null;
$errorMessage = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!AuthController::validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Security token mismatch. Please refresh and try again.');
        }

        $adminPassword = (string) ($_POST['admin_password'] ?? '');
        AuthController::requireStepUpOrPassword($conn, (int) ($_SESSION['user_id'] ?? 0), $adminPassword);

        $config = PosConfigController::save($conn, $_POST, $_FILES);
        AuthController::markStepUpVerified();
        $successMessage = 'POS configuration saved successfully.';
    } else {
        $config = PosConfigController::get($conn);
    }
} catch (Throwable $e) {
    error_log('[pos_settings.php] ' . $e->getMessage());
    $errorMessage = $e instanceof InvalidArgumentException || $e instanceof RuntimeException
        ? $e->getMessage()
        : 'Unable to save POS configuration right now.';
    $config = PosConfigController::get($conn);
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$logoPreviewUrl = PosConfigController::logoUrl($config['logo'] ?? null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/../components/head.php'; ?>
    <title>POS & Shift Settings</title>
    <link rel="stylesheet" href="/inventory_system/assets/css/pos-settings.css">
</head>
<body>
<?php
require __DIR__ . '/../components/header.php';
require __DIR__ . '/../components/sidebar.php';
?>

<main id="main" class="main">
    <div class="settings-hero card border-0 shadow-sm">
        <div class="settings-hero__body">
            <div>
                <span class="settings-kicker">Store Controls</span>
                <h1>POS & Shift Settings</h1>
                <p>Manage store branding, receipt details, tax defaults, and the approval windows that protect shift corrections.</p>
            </div>
            <div class="settings-hero__stats">
                <div class="settings-stat">
                    <span>Cashier edit window</span>
                    <strong><?= (int) ($config['shift_edit_window_hours'] ?? 8) ?> hr</strong>
                </div>
                <div class="settings-stat">
                    <span>Approved unlock</span>
                    <strong><?= (int) ($config['shift_unlock_window_hours'] ?? 2) ?> hr</strong>
                </div>
            </div>
        </div>
    </div>

    <section class="section mt-4">
        <div class="row g-4">
            <div class="col-xl-8">
                <div class="card settings-panel shadow-sm">
                    <div class="card-body">
                        <div class="settings-panel__head">
                            <div>
                                <span class="settings-kicker">Configuration</span>
                                <h2>Store Profile</h2>
                            </div>
                        </div>

                        <?php if ($successMessage !== null): ?>
                            <div class="alert alert-success"><?= e($successMessage) ?></div>
                        <?php endif; ?>

                        <?php if ($errorMessage !== null): ?>
                            <div class="alert alert-danger"><?= e($errorMessage) ?></div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data" class="row g-4">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="logo_current" value="<?= e($config['logo'] ?? '') ?>">

                            <div class="col-12">
                                <div class="settings-block">
                                    <div class="settings-block__head">
                                        <div>
                                            <h3>Store Identity</h3>
                                            <p>These values appear across receipts, branding, and store-facing pages.</p>
                                        </div>
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Store Name</label>
                                            <input type="text" name="store_name" class="form-control" value="<?= e($config['store_name'] ?? '') ?>" required>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Store Phone</label>
                                            <input type="text" name="store_phone" class="form-control" value="<?= e($config['store_phone'] ?? '') ?>" placeholder="e.g. 0917-000-1111">
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Store Email</label>
                                            <input type="email" name="store_email" class="form-control" value="<?= e($config['store_email'] ?? '') ?>" placeholder="e.g. store@example.com">
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Store Logo</label>
                                            <input type="file" name="logo_upload" class="form-control" accept="image/jpeg,image/png,image/webp">
                                            <div class="form-text">Upload a JPG, PNG, or WEBP logo. It will be stored securely outside the web root.</div>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Current Logo</label>
                                            <div class="settings-logo-preview h-100 d-flex align-items-center gap-3">
                                                <?php if ($logoPreviewUrl !== null): ?>
                                                    <img src="<?= e($logoPreviewUrl) ?>" alt="Current POS logo" class="settings-logo-preview__image">
                                                    <div class="small text-muted">
                                                        This is the logo currently used for POS receipts.
                                                    </div>
                                                <?php else: ?>
                                                    <div class="small text-muted mb-0">No POS logo uploaded yet.</div>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label">Store Address</label>
                                            <textarea name="store_address" class="form-control" rows="3" placeholder="Store address for receipts and POS"><?= e($config['store_address'] ?? '') ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="settings-block">
                                    <div class="settings-block__head">
                                        <div>
                                            <h3>Store Operations</h3>
                                            <p>Control your business defaults for taxation, currency, and operating hours.</p>
                                        </div>
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Opening Hours</label>
                                            <input type="time" name="opening_hours" class="form-control" value="<?= e($config['opening_hours'] ?? '') ?>">
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Closing Hours</label>
                                            <input type="time" name="closing_hours" class="form-control" value="<?= e($config['closing_hours'] ?? '') ?>">
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">VAT Rate (%)</label>
                                            <input type="number" name="tax_rate" class="form-control" value="<?= e(number_format((float) ($config['tax_rate'] ?? 12), 2, '.', '')) ?>" step="0.01" min="0" max="100" required>
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Currency</label>
                                            <input type="text" name="currency" class="form-control" value="<?= e($config['currency'] ?? 'PHP') ?>" maxlength="10" required>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="settings-block settings-block--accent">
                                    <div class="settings-block__head">
                                        <div>
                                            <h3>Shift Management Rules</h3>
                                            <p>Choose how long a cashier can update a closed shift, and how long an approved unlock should remain open.</p>
                                        </div>
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Cashier Shift Edit Window (Hours)</label>
                                            <input
                                                type="number"
                                                name="shift_edit_window_hours"
                                                class="form-control"
                                                value="<?= (int) ($config['shift_edit_window_hours'] ?? 8) ?>"
                                                min="1"
                                                max="72"
                                                required
                                            >
                                            <div class="form-text">After this window, cashiers must request approval before editing a closed shift.</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Approved Unlock Duration (Hours)</label>
                                            <input
                                                type="number"
                                                name="shift_unlock_window_hours"
                                                class="form-control"
                                                value="<?= (int) ($config['shift_unlock_window_hours'] ?? 2) ?>"
                                                min="1"
                                                max="24"
                                                required
                                            >
                                            <div class="form-text">When admin approves a request, this defines how long the shift stays editable again.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 d-flex gap-2">
                                <div class="flex-grow-1">
                                    <input type="password" name="admin_password" class="form-control" autocomplete="current-password" placeholder="Confirm admin password for sensitive changes">
                                </div>
                                <button type="submit" class="btn btn-primary">Save Configuration</button>
                                <a href="/inventory_system/shift_edit_requests.php" class="btn btn-outline-primary">Open Request Center</a>
                                <a href="/inventory_system/product_management/pos.php" class="btn btn-outline-secondary">Open POS</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card settings-panel shadow-sm">
                    <div class="card-body">
                        <div class="settings-panel__head">
                            <div>
                                <span class="settings-kicker">Guide</span>
                                <h2>What These Settings Control</h2>
                            </div>
                        </div>
                        <div class="settings-info-list">
                            <div class="settings-info-item">
                                <strong>Branding</strong>
                                <span>Header, login, favicon, and receipt store identity.</span>
                            </div>
                            <div class="settings-info-item">
                                <strong>POS Defaults</strong>
                                <span>Tax rate, currency label, and receipt contact details.</span>
                            </div>
                            <div class="settings-info-item">
                                <strong>Shift Protection</strong>
                                <span>How long a cashier can revise a closing, and how long an approved unlock lasts.</span>
                            </div>
                            <div class="settings-info-item">
                                <strong>Owner Control</strong>
                                <span>Pairs with Shift Edit Requests so corrections stay approved, timed, and auditable.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<?php require __DIR__ . '/../components/footer.php'; ?>
<?php require __DIR__ . '/../components/js_script.php'; ?>
</body>
</html>
