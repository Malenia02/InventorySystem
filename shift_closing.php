<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap/app.php';
require_once __DIR__ . '/middleware/Middleware.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/ShiftClosingController.php';

Middleware::auth()->role(['admin', 'cashier']);

$isAdmin = Middleware::is('admin');
$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);

if ($sessionUserId <= 0) {
    header('Location: /inventory_system/login.php');
    exit;
}

$viewUserId = $sessionUserId;
if ($isAdmin && isset($_GET['user_id']) && (int) $_GET['user_id'] > 0) {
    $viewUserId = (int) $_GET['user_id'];
}

$shiftDate = (string) ($_GET['shift_date'] ?? date('Y-m-d'));
$summary = ShiftClosingController::summaryForUser($conn, $viewUserId, $shiftDate);
$recentClosings = ShiftClosingController::recentClosings($conn, $isAdmin ? null : $sessionUserId, 10);
$cashiers = [];
$successMessage = null;
$errorMessage = null;

if (isset($_SESSION['shift_closing_flash']) && is_array($_SESSION['shift_closing_flash'])) {
    $successMessage = isset($_SESSION['shift_closing_flash']['success'])
        ? (string) $_SESSION['shift_closing_flash']['success']
        : null;
    $errorMessage = isset($_SESSION['shift_closing_flash']['error'])
        ? (string) $_SESSION['shift_closing_flash']['error']
        : null;

    unset($_SESSION['shift_closing_flash']);
}

try {
    $cashierStmt = $conn->query("
        SELECT user_id, first_name, last_name, username, role
        FROM users
        WHERE status = 'active'
          AND role IN ('cashier', 'admin')
        ORDER BY role ASC, first_name ASC, last_name ASC, username ASC
    ");
    $cashiers = $cashierStmt ? ($cashierStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
    error_log('[shift_closing.php] ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!AuthController::validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Security token mismatch. Please refresh and try again.');
        }

        $targetUserId = $sessionUserId;
        if ($isAdmin && isset($_POST['user_id']) && (int) $_POST['user_id'] > 0) {
            $targetUserId = (int) $_POST['user_id'];
        }

        $saved = ShiftClosingController::closeShift($conn, $targetUserId, $_POST);

        $_SESSION['shift_closing_flash'] = [
            'success' => $saved['was_updated']
                ? 'Shift closing updated successfully.'
                : 'Shift closed successfully.',
        ];

        $redirectParams = [
            'shift_date' => $saved['shift_date'],
        ];

        if ($isAdmin) {
            $redirectParams['user_id'] = $targetUserId;
        }

        header('Location: /inventory_system/shift_closing.php?' . http_build_query($redirectParams));
        exit;
    } catch (Throwable $e) {
        error_log('[shift_closing.php] ' . $e->getMessage());
        $errorMessage = $e instanceof InvalidArgumentException || $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Unable to close the shift right now.';
    }
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money(float $value): string
{
    return '₱' . number_format($value, 2);
}

function userLabel(array $row): string
{
    $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
    return $name !== '' ? $name : (string) ($row['username'] ?? 'User');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/components/head.php'; ?>
    <title>Shift Closing</title>
</head>
<body>
<?php
require __DIR__ . '/components/header.php';
require __DIR__ . '/components/sidebar.php';
?>

<main id="main" class="main">
    <div class="pagetitle">
        <h1>Shift Closing</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/inventory_system/index.php">Home</a></li>
                <li class="breadcrumb-item active">Shift Closing</li>
            </ol>
        </nav>
    </div>

    <section class="section">
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <h5 class="card-title mb-0">Closing Summary</h5>
                            <span class="badge bg-primary"><?= e(date('M d, Y', strtotime($shiftDate))) ?></span>
                        </div>

                        <?php if ($successMessage !== null): ?>
                            <div class="alert alert-success mt-3"><?= e($successMessage) ?></div>
                        <?php endif; ?>

                        <?php if ($errorMessage !== null): ?>
                            <div class="alert alert-danger mt-3"><?= e($errorMessage) ?></div>
                        <?php endif; ?>

                        <div class="row g-3 mt-1">
                            <div class="col-md-3">
                                <div class="border rounded-3 p-3 h-100 bg-light">
                                    <div class="text-muted small">Transactions</div>
                                    <div class="h4 mb-0"><?= number_format((int) ($summary['total_transactions'] ?? 0)) ?></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="border rounded-3 p-3 h-100 bg-light">
                                    <div class="text-muted small">Total Sales</div>
                                    <div class="h4 mb-0"><?= e(money((float) ($summary['total_sales'] ?? 0))) ?></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="border rounded-3 p-3 h-100 bg-light">
                                    <div class="text-muted small">Expected Cash</div>
                                    <div class="h4 mb-0"><?= e(money((float) ($summary['expected_cash'] ?? 0))) ?></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="border rounded-3 p-3 h-100 bg-light">
                                    <div class="text-muted small">Cash Sales</div>
                                    <div class="h4 mb-0"><?= e(money((float) ($summary['cash_sales'] ?? 0))) ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mt-0">
                            <div class="col-md-6">
                                <div class="border rounded-3 p-3 h-100">
                                    <div class="text-muted small mb-2">Payment breakdown</div>
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Method</th>
                                                    <th class="text-end">Sales</th>
                                                    <th class="text-end">Amount</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (!empty($summary['payment_breakdown'])): ?>
                                                    <?php foreach ($summary['payment_breakdown'] as $row): ?>
                                                        <tr>
                                                            <td><?= e(ucfirst((string) ($row['payment_method'] ?? 'Unknown'))) ?></td>
                                                            <td class="text-end"><?= number_format((int) ($row['sale_count'] ?? 0)) ?></td>
                                                            <td class="text-end"><?= e(money((float) ($row['total_amount'] ?? 0))) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr><td colspan="3" class="text-center text-muted">No payments recorded for this date.</td></tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="border rounded-3 p-3 h-100">
                                    <div class="text-muted small mb-2">Close shift</div>
                                    <form method="POST" class="row g-3">
                                        <input type="hidden" name="csrf_token" value="<?= e(Middleware::generateCsrfToken()) ?>">
                                        <input type="hidden" name="shift_date" value="<?= e($shiftDate) ?>">
                                        <?php if ($isAdmin): ?>
                                            <div class="col-12">
                                                <label class="form-label">Cashier</label>
                                                <select name="user_id" class="form-select">
                                                    <?php foreach ($cashiers as $cashier): ?>
                                                        <option value="<?= (int) $cashier['user_id'] ?>" <?= $viewUserId === (int) $cashier['user_id'] ? 'selected' : '' ?>>
                                                            <?= e(userLabel($cashier)) ?> (<?= e(ucfirst((string) ($cashier['role'] ?? '')) ) ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        <?php endif; ?>

                                        <div class="col-12">
                                            <label class="form-label">Counted Cash</label>
                                            <input type="number" name="counted_cash" class="form-control" step="0.01" min="0" placeholder="0.00" required>
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label">Notes</label>
                                            <textarea name="notes" class="form-control" rows="3" placeholder="Optional shift notes"></textarea>
                                        </div>

                                        <div class="col-12">
                                            <button type="submit" class="btn btn-primary">Save Closing</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm mt-4">
                    <div class="card-body">
                        <h5 class="card-title">Recent Closings</h5>
                        <div class="table-responsive">
                            <table class="table table-striped align-middle">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>User</th>
                                        <th class="text-end">Sales</th>
                                        <th class="text-end">Expected Cash</th>
                                        <th class="text-end">Counted Cash</th>
                                        <th class="text-end">Variance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($recentClosings)): ?>
                                        <?php foreach ($recentClosings as $row): ?>
                                            <tr>
                                                <td><?= e(date('M d, Y', strtotime((string) ($row['shift_date'] ?? 'now')))) ?></td>
                                                <td><?= e(userLabel($row)) ?></td>
                                                <td class="text-end"><?= number_format((float) ($row['total_sales'] ?? 0), 2) ?></td>
                                                <td class="text-end"><?= e(money((float) ($row['expected_cash'] ?? 0))) ?></td>
                                                <td class="text-end"><?= e(money((float) ($row['counted_cash'] ?? 0))) ?></td>
                                                <td class="text-end <?= ((float) ($row['variance'] ?? 0) < 0) ? 'text-danger' : 'text-success' ?>">
                                                    <?= e(money((float) ($row['variance'] ?? 0))) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="6" class="text-center text-muted">No shift closings found yet.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title">Shift Snapshot</h5>
                        <ul class="small mb-0 ps-3">
                            <li>Total items sold: <?= number_format((int) ($summary['total_items'] ?? 0)) ?></li>
                            <li>Total tax: <?= e(money((float) ($summary['total_tax'] ?? 0))) ?></li>
                            <li>Total discount: <?= e(money((float) ($summary['total_discount'] ?? 0))) ?></li>
                            <li>Last sale: <?= !empty($summary['last_sale_at']) ? e(date('M d, g:i A', strtotime((string) $summary['last_sale_at']))) : 'No sales yet' ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<?php require __DIR__ . '/components/footer.php'; ?>
<?php require __DIR__ . '/components/js_script.php'; ?>
</body>
</html>
