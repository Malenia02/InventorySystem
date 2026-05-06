<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/AuthController.php';
require_once __DIR__ . '/../controllers/DatabaseBackupController.php';

Middleware::auth()->role(['admin']);

if (app_is_public_demo()) {
    $_SESSION['error_code'] = 403;
    $_SESSION['error_message'] = 'Backup download and restore are disabled on the public demo.';
    safe_redirect('/inventory_system/error.php');
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

        $adminPassword = (string) ($_POST['admin_password'] ?? '');
        if (!AuthController::verifyCurrentUserPassword($conn, (int) ($_SESSION['user_id'] ?? 0), $adminPassword)) {
            throw new RuntimeException('Admin password confirmation failed.');
        }
        AuthController::markStepUpVerified();

        $action = (string) ($_POST['action'] ?? 'restore');
        if ($action === 'download') {
            DatabaseBackupController::download($conn);
        }

        if ($action !== 'restore') {
            throw new RuntimeException('Invalid backup action.');
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
    <link rel="stylesheet" href="/inventory_system/assets/css/ops-suite.css">
</head>
<body>
<?php
require __DIR__ . '/../components/header.php';
require __DIR__ . '/../components/sidebar.php';
?>

<main id="main" class="main ops-page backup-restore-page">
    <section class="ops-hero">
        <div class="ops-hero__body">
            <div>
                <span class="ops-eyebrow">Data Safety</span>
                <h1 class="ops-title">Backup & Restore</h1>
                <p class="ops-copy">Download a portable JSON backup or restore a known-good backup with guarded confirmation. Restore replaces current records, so this page is intentionally cautious.</p>
            </div>
            <div class="ops-hero__stats">
                <div class="ops-stat">
                    <span>Tracked tables</span>
                    <strong><?= number_format(count($manifest)) ?></strong>
                    <small>included in backup</small>
                </div>
                <div class="ops-stat">
                    <span>Total rows</span>
                    <strong><?= number_format(array_sum(array_map(static fn(array $item): int => (int) ($item['rows'] ?? 0), $manifest))) ?></strong>
                    <small>current data volume</small>
                </div>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="row g-4">
            <div class="col-lg-7">
                <div class="ops-panel">
                    <span class="ops-eyebrow">Backup Control</span>
                    <h2 class="h4 mt-2 mb-2" style="color:#012970;font-weight:900;">Create or restore backup</h2>
                    <p class="ops-muted">Download first before every restore. The restore button unlocks only after selecting a JSON file and typing the confirmation word.</p>

                    <?php if ($successMessage !== null): ?>
                        <div class="alert alert-success rounded-4"><?= e($successMessage) ?></div>
                    <?php endif; ?>

                    <?php if ($errorMessage !== null): ?>
                        <div class="alert alert-danger rounded-4"><?= e($errorMessage) ?></div>
                    <?php endif; ?>

                    <?php if ($restoreSummary !== null): ?>
                        <div class="alert alert-info rounded-4">
                            Restored <?= number_format((int) ($restoreSummary['rows_restored'] ?? 0)) ?> rows across
                            <?= number_format((int) ($restoreSummary['tables_cleared'] ?? 0)) ?> tables.
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="d-flex flex-wrap gap-2 mb-4 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="download">
                        <div class="flex-grow-1">
                            <label class="form-label">Admin password required for backup download</label>
                            <input type="password" name="admin_password" class="form-control" autocomplete="current-password" required>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-download me-1"></i> Download Backup JSON
                        </button>
                        <a href="/inventory_system/admin/backup_restore.php" class="btn btn-outline-primary">
                            <i class="bi bi-arrow-clockwise me-1"></i> Refresh
                        </a>
                    </form>

                    <form method="POST" enctype="multipart/form-data" class="row g-3" id="backupRestoreForm">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="restore">

                        <div class="col-12">
                            <label class="form-label">Restore backup file</label>
                            <input type="file" name="backup_file" id="backupRestoreFile" class="form-control" accept="application/json,.json">
                            <div class="form-text">Upload a JSON backup generated by this StockWise installation.</div>
                        </div>

                        <div class="col-12">
                            <div class="backup-file-preview" id="backupFilePreview">
                                <i class="bi bi-file-earmark-lock"></i>
                                <div>
                                    <strong>No file selected</strong>
                                    <span>Select a backup file to inspect its name and size before restore.</span>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Type RESTORE to unlock</label>
                            <input type="text" id="backupRestoreConfirm" class="form-control" autocomplete="off" placeholder="RESTORE">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Confirm admin password</label>
                            <input type="password" name="admin_password" id="backupRestorePassword" class="form-control" autocomplete="current-password" required>
                        </div>

                        <div class="col-12">
                            <button type="submit" id="backupRestoreSubmit" class="btn btn-danger" disabled>
                                <i class="bi bi-exclamation-triangle me-1"></i> Restore Backup
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="ops-table-card mb-4">
                    <span class="ops-eyebrow">Backup Manifest</span>
                    <h2 class="h4 mt-2 mb-3" style="color:#012970;font-weight:900;">What is included</h2>
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

                <div class="ops-panel">
                    <span class="ops-eyebrow">Restore Guardrails</span>
                    <h2 class="h4 mt-2 mb-3" style="color:#012970;font-weight:900;">Before restoring</h2>
                    <div class="ops-detail-grid">
                        <div><span class="ops-detail-label">Impact</span><p class="mb-0">Restore replaces current data in the listed tables.</p></div>
                        <div><span class="ops-detail-label">Safety</span><p class="mb-0">Always download a fresh backup before restoring.</p></div>
                        <div><span class="ops-detail-label">Compatibility</span><p class="mb-0">Use a backup from the same app version for best results.</p></div>
                        <div><span class="ops-detail-label">Access</span><p class="mb-0">Admin-only and disabled on public demo mode.</p></div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<?php require __DIR__ . '/../components/footer.php'; ?>
<?php require __DIR__ . '/../components/js_script.php'; ?>
<script src="/inventory_system/assets/js/backup-restore.js"></script>
</body>
</html>
