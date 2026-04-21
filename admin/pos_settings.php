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

        $config = PosConfigController::save($conn, $_POST, $_FILES);
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
    <title>POS Configuration</title>
</head>
<body>
<?php
require __DIR__ . '/../components/header.php';
require __DIR__ . '/../components/sidebar.php';
?>

<main id="main" class="main">
    <div class="pagetitle">
        <h1>POS Configuration</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/inventory_system/index.php">Home</a></li>
                <li class="breadcrumb-item active">POS Configuration</li>
            </ol>
        </nav>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title">Store Settings</h5>

                        <?php if ($successMessage !== null): ?>
                            <div class="alert alert-success"><?= e($successMessage) ?></div>
                        <?php endif; ?>

                        <?php if ($errorMessage !== null): ?>
                            <div class="alert alert-danger"><?= e($errorMessage) ?></div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data" class="row g-3">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="logo_current" value="<?= e($config['logo'] ?? '') ?>">

                            <div class="col-md-6">
                                <label class="form-label">Store Name</label>
                                <input type="text" name="store_name" class="form-control" value="<?= e($config['store_name'] ?? '') ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Currency</label>
                                <input type="text" name="currency" class="form-control" value="<?= e($config['currency'] ?? 'PHP') ?>" maxlength="10" required>
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
                                <label class="form-label">Store Logo</label>
                                <input type="file" name="logo_upload" class="form-control" accept="image/jpeg,image/png,image/webp">
                                <div class="form-text">Upload a JPG, PNG, or WEBP logo. It will be stored securely outside the web root.</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Current Logo</label>
                                <div class="border rounded-3 p-3 bg-light h-100 d-flex align-items-center gap-3">
                                    <?php if ($logoPreviewUrl !== null): ?>
                                        <img src="<?= e($logoPreviewUrl) ?>" alt="Current POS logo" style="width:72px;height:72px;object-fit:contain;background:#fff;" class="border rounded">
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

                            <div class="col-12 d-flex gap-2">
                                <button type="submit" class="btn btn-primary">Save Configuration</button>
                                <a href="/inventory_system/product_management/pos.php" class="btn btn-outline-secondary">Open POS</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title">What This Controls</h5>
                        <ul class="small mb-0 ps-3">
                            <li>Receipt store name, address, and phone</li>
                            <li>Displayed VAT rate on the POS</li>
                            <li>Default currency label</li>
                            <li>Store hours for future use</li>
                        </ul>
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
