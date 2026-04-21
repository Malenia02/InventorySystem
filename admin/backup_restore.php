<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/AuthController.php';
require_once __DIR__ . '/../controllers/DatabaseBackupController.php';

Middleware::auth()->role(['admin']);

if (isset($_GET['download']) && (string) $_GET['download'] === '1') {
    DatabaseBackupController::download($conn);
}

$csrfToken = Middleware::generateCsrfToken();
$successMessage = null;
$errorMessage = null;
$restoreSummary = null;
$manifest = DatabaseBackupController::manifest($conn);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!AuthController::validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Security token mismatch. Please refresh and try again.');
        }

        $restoreSummary = DatabaseBackupController::restoreFromUpload($conn, $_FILES['backup_file'] ?? []);
        $successMessage = 'Backup restored successfully.';
        $manifest = DatabaseBackupController::manifest($conn);
    }
} catch (Throwable $e) {
    error_log('[backup_restore.php] ' . $e->getMessage());
    $errorMessage = $e instanceof InvalidArgumentException || $e instanceof RuntimeException
        ? $e->getMessage()
        : 'Unable to process the backup right now.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/../components/head.php'; ?>
    <title>Backup & Restore</title>
</head>
<body>
<?php
require __DIR__ . '/../components/header.php';
require __DIR__ . '/../components/sidebar.php';
?>

<main id="main" class="main">
    <div class="pagetitle">
        <h1>Backup & Restore</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/inventory_system/index.php">Home</a></li>
                <li class="breadcrumb-item active">Backup & Restore</li>
            </ol>
        </nav>
    </div>

    <section class="section">
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title">Database Backup</h5>

                        <?php if ($successMessage !== null): ?>
                            <div class="alert alert-success"><?= e($successMessage) ?></div>
                        <?php endif; ?>

                        <?php if ($errorMessage !== null): ?>
                            <div class="alert alert-danger"><?= e($errorMessage) ?></div>
                        <?php endif; ?>

                        <?php if ($restoreSummary !== null): ?>
                            <div class="alert alert-info">
                                Restored <?= number_format((int) ($restoreSummary['rows_restored'] ?? 0)) ?> rows across
                                <?= number_format((int) ($restoreSummary['tables_cleared'] ?? 0)) ?> tables.
                            </div>
                        <?php endif; ?>

                        <div class="d-flex flex-wrap gap-2 mb-4">
                            <a href="?download=1" class="btn btn-primary">
                                Download Backup JSON
                            </a>
                            <a href="/inventory_system/admin/backup_restore.php" class="btn btn-outline-secondary">
                                Refresh
                            </a>
                        </div>

                        <form method="POST" enctype="multipart/form-data" class="row g-3">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                            <div class="col-12">
                                <label class="form-label">Restore Backup File</label>
                                <input type="file" name="backup_file" class="form-control" accept="application/json,.json">
                                <div class="form-text">Upload the JSON backup you previously downloaded from this page.</div>
                            </div>

                            <div class="col-12">
                                <button type="submit" class="btn btn-danger">
                                    Restore Backup
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <h5 class="card-title">What Is Included</h5>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Table</th>
                                        <th class="text-end">Rows</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($manifest as $item): ?>
                                        <tr>
                                            <td><?= e((string) ($item['table'] ?? '')) ?></td>
                                            <td class="text-end"><?= number_format((int) ($item['rows'] ?? 0)) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title">Restore Notes</h5>
                        <ul class="small mb-0 ps-3">
                            <li>Restore replaces the current data in the listed tables.</li>
                            <li>Foreign keys are handled automatically during restore.</li>
                            <li>Use a fresh backup from the same app version for best results.</li>
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
