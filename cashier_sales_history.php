<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap/app.php';
require_once __DIR__ . '/middleware/Middleware.php';
require_once __DIR__ . '/controllers/SaleController.php';

Middleware::auth()->role(['admin', 'cashier']);

$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
$sessionRole = strtolower((string) ($_SESSION['role'] ?? 'cashier'));
$isAdmin = $sessionRole === 'admin';

if ($sessionUserId <= 0) {
    header('Location: /inventory_system/login.php');
    exit;
}

SaleController::ensureReturnSchema($conn);

$salesSql = "
    SELECT
        s.sale_id,
        s.sale_date,
        s.total_amount,
        s.discount,
        s.tax,
        s.payment_method,
        s.status,
        s.user_id,
        u.first_name,
        u.last_name,
        u.username,
        COUNT(si.sale_item_id) AS line_count,
        IFNULL(SUM((si.quantity - COALESCE(si.returned_quantity, 0)) * COALESCE(si.unit_multiplier, 1)), 0) AS net_pieces
    FROM {$table_sales} s
    LEFT JOIN {$table_users} u ON u.user_id = s.user_id
    LEFT JOIN {$table_sale_items} si ON si.sale_id = s.sale_id
";

$params = [];
if (!$isAdmin) {
    $salesSql .= " WHERE s.user_id = :viewer_id";
    $params[':viewer_id'] = $sessionUserId;
}

$salesSql .= "
    GROUP BY
        s.sale_id, s.sale_date, s.total_amount, s.discount, s.tax, s.payment_method, s.status, s.user_id,
        u.first_name, u.last_name, u.username
    ORDER BY s.sale_date DESC, s.sale_id DESC
    LIMIT 250
";

$stmt = $conn->prepare($salesSql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_INT);
}
$stmt->execute();
$sales = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$loadedRevenue = array_sum(array_map(static fn(array $sale): float => (float) ($sale['total_amount'] ?? 0), $sales));

$cashiers = [];
if ($isAdmin) {
    $cashierStmt = $conn->query("
        SELECT user_id, first_name, last_name, username
        FROM users
        WHERE status = 'active' AND role = 'cashier'
        ORDER BY first_name ASC, last_name ASC, username ASC
    ");
    $cashiers = $cashierStmt ? ($cashierStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function personLabel(array $row): string
{
    $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
    return $name !== '' ? $name : (string) ($row['username'] ?? 'Cashier');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/components/head.php'; ?>
    <title>Cashier Sales History</title>
    <link rel="stylesheet" href="/inventory_system/assets/css/ops-suite.css">
</head>
<body>
<?php require __DIR__ . '/components/header.php'; ?>
<?php require __DIR__ . '/components/sidebar.php'; ?>

<main id="main" class="main ops-page">
    <section class="ops-hero">
        <div class="ops-hero__body">
            <div>
                <span class="ops-eyebrow"><?= $isAdmin ? 'Sales Lookup' : 'Cashier History' ?></span>
                <h1 class="ops-title">Cashier Sales History</h1>
                <p class="ops-copy">
                    <?= $isAdmin
                        ? 'Review recent cashier transactions, inspect line items, and reprint receipts without opening the full admin sales report.'
                        : 'Review your recent transactions, open the sale breakdown, and reprint a customer receipt when needed.' ?>
                </p>
            </div>
            <div class="ops-hero__stats">
                <div class="ops-stat">
                    <span>Transactions loaded</span>
                    <strong id="cashierHistoryCount"><?= number_format(count($sales)) ?></strong>
                    <small>recent sales in this view</small>
                </div>
                <div class="ops-stat">
                    <span>Loaded revenue</span>
                    <strong id="cashierHistoryRevenue">PHP <?= number_format($loadedRevenue, 2) ?></strong>
                    <small>totals from the visible queue</small>
                </div>
                <div class="ops-stat">
                    <span>Current mode</span>
                    <strong><?= $isAdmin ? 'Admin' : 'Cashier' ?></strong>
                    <small><?= $isAdmin ? 'can inspect all cashier rows' : 'limited to your own sales' ?></small>
                </div>
            </div>
        </div>
    </section>

    <section class="ops-filter-card mb-4">
        <div class="ops-filter-grid">
            <div class="is-wide">
                <label>Search transaction, cashier, or payment</label>
                <input type="search" id="historySearch" class="form-control" placeholder="Search transaction no, cashier, payment method">
            </div>
            <div>
                <label>Payment</label>
                <select id="historyPayment" class="form-select">
                    <option value="all">All payments</option>
                    <option value="cash">Cash</option>
                    <option value="gcash">GCash</option>
                    <option value="card">Card</option>
                </select>
            </div>
            <div>
                <label>Status</label>
                <select id="historyStatus" class="form-select">
                    <option value="all">All statuses</option>
                    <option value="completed">Completed</option>
                    <option value="partial_returned">Partial returned</option>
                    <option value="returned">Returned</option>
                    <option value="voided">Voided</option>
                </select>
            </div>
            <div>
                <label>Date from</label>
                <input type="date" id="historyDateFrom" class="form-control">
            </div>
            <div>
                <label>Date to</label>
                <input type="date" id="historyDateTo" class="form-control">
            </div>
            <?php if ($isAdmin): ?>
                <div>
                    <label>Cashier</label>
                    <select id="historyCashier" class="form-select">
                        <option value="all">All cashiers</option>
                        <?php foreach ($cashiers as $cashier): ?>
                            <option value="<?= (int) ($cashier['user_id'] ?? 0) ?>"><?= e(personLabel($cashier)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="ops-table-card">
        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
            <div>
                <span class="ops-eyebrow">Recent Transactions</span>
                <h2 class="h4 mb-1" style="color:#012970;font-weight:800;">Sales Queue</h2>
                <p class="ops-muted mb-0">Click a transaction to inspect sale details or reprint the receipt.</p>
            </div>
            <div id="cashierHistoryMeta" class="ops-muted small">Showing all loaded transactions.</div>
        </div>

        <div class="table-responsive">
            <table class="table align-middle" id="cashierSalesHistoryTable">
                <thead>
                    <tr>
                        <th>Transaction</th>
                        <th>Cashier</th>
                        <th>Payment</th>
                        <th>Date</th>
                        <th class="text-end">Lines</th>
                        <th class="text-end">Pieces</th>
                        <th class="text-end">Total</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sales as $sale): ?>
                        <?php
                        $cashierName = personLabel($sale);
                        $transactionNo = SaleController::transactionNumber((int) ($sale['sale_id'] ?? 0), (string) ($sale['sale_date'] ?? ''));
                        $status = strtolower((string) ($sale['status'] ?? 'completed'));
                        ?>
                        <tr
                            class="ops-clickable-row cashier-history-row"
                            data-sale-id="<?= (int) ($sale['sale_id'] ?? 0) ?>"
                            data-search="<?= e(strtolower($transactionNo . ' ' . $cashierName . ' ' . (string) ($sale['payment_method'] ?? ''))) ?>"
                            data-status="<?= e($status) ?>"
                            data-payment="<?= e(strtolower((string) ($sale['payment_method'] ?? ''))) ?>"
                            data-date="<?= e(substr((string) ($sale['sale_date'] ?? ''), 0, 10)) ?>"
                            data-cashier-id="<?= (int) ($sale['user_id'] ?? 0) ?>"
                            data-total-amount="<?= e((string) ($sale['total_amount'] ?? '0')) ?>"
                        >
                            <td>
                                <strong><?= e($transactionNo) ?></strong>
                            </td>
                            <td><?= e($cashierName) ?></td>
                            <td><?= e(strtoupper((string) ($sale['payment_method'] ?? 'cash'))) ?></td>
                            <td><?= e(date('M d, Y g:i A', strtotime((string) ($sale['sale_date'] ?? 'now')))) ?></td>
                            <td class="text-end"><?= number_format((int) ($sale['line_count'] ?? 0)) ?></td>
                            <td class="text-end"><?= number_format((int) ($sale['net_pieces'] ?? 0)) ?></td>
                            <td class="text-end fw-bold">PHP <?= number_format((float) ($sale['total_amount'] ?? 0), 2) ?></td>
                            <td>
                                <span class="ops-status <?= e(match ($status) {
                                    'voided' => 'is-danger',
                                    'partial_returned' => 'is-warning',
                                    'returned' => 'is-info',
                                    default => 'is-success',
                                }) ?>">
                                    <?= e(ucwords(str_replace('_', ' ', $status))) ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary cashier-sale-open" data-sale-id="<?= (int) ($sale['sale_id'] ?? 0) ?>">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-light border cashier-sale-print" data-sale-id="<?= (int) ($sale['sale_id'] ?? 0) ?>">
                                        <i class="bi bi-printer"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="cashierSalesHistoryEmpty" class="ops-empty d-none mt-3">
            No transactions match the current filters.
        </div>
    </section>
</main>

<div class="modal fade" id="cashierSaleDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable ops-preview-dialog">
        <div class="modal-content ops-preview-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="cashierSaleDetailsLabel">Sale Breakdown</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="cashierSaleDetailsBody">
                <div class="text-muted">Loading sale details...</div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="cashierSaleRequestModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content ops-preview-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="cashierSaleRequestTitle">Request Sale Action</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="cashierSaleRequestForm" class="ops-review-form">
                    <input type="hidden" name="csrf_token" value="<?= e(Middleware::generateCsrfToken()) ?>">
                    <input type="hidden" name="sale_id" id="cashierRequestSaleId">
                    <input type="hidden" name="sale_item_id" id="cashierRequestSaleItemId">
                    <input type="hidden" name="action_type" id="cashierRequestActionType">
                    <div class="mb-3" id="cashierRequestQuantityWrap">
                        <label for="cashierRequestQuantity">Quantity</label>
                        <input type="number" id="cashierRequestQuantity" class="form-control" min="1" value="1">
                    </div>
                    <label for="cashierRequestReason">Reason</label>
                    <textarea id="cashierRequestReason" class="form-control" rows="4" required placeholder="Explain why this sale needs admin review"></textarea>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="cashierSaleRequestSubmit">Send request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
window.CASHIER_HISTORY_DATA = <?= json_encode([
    'sales' => array_map(static function (array $sale): array {
        return [
            'sale_id' => (int) ($sale['sale_id'] ?? 0),
            'cashier_id' => (int) ($sale['user_id'] ?? 0),
            'date' => substr((string) ($sale['sale_date'] ?? ''), 0, 10),
        ];
    }, $sales),
    'isAdmin' => $isAdmin,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<?php require __DIR__ . '/components/js_script.php'; ?>
<script src="/inventory_system/assets/js/cashier-sales-history.js"></script>
</body>
</html>
