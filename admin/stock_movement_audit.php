<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/StockMovementAuditController.php';

Middleware::auth()->role(['admin']);

$movements = StockMovementAuditController::listMovements($conn, 600);
$products = StockMovementAuditController::productOptions($conn);
$summary = StockMovementAuditController::summary($movements);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function auditActor(array $row): string
{
    $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
    return $name !== '' ? $name : (string) ($row['username'] ?? 'System');
}

function auditActionLabel(string $action, string $referenceType): string
{
    $referenceType = strtolower($referenceType);
    if ($referenceType === 'sale_return') {
        return 'Sale Return';
    }

    return match (strtolower($action)) {
        'sale' => 'Sale Deduction',
        'stock_in' => 'Stock In',
        'stock_out' => 'Stock Out',
        'manual_adjust' => 'Manual Adjustment',
        default => ucwords(str_replace('_', ' ', $action)),
    };
}

function auditReferenceUrl(array $row): string
{
    $referenceType = strtolower((string) ($row['reference_type'] ?? ''));
    $referenceId = (int) ($row['reference_id'] ?? 0);

    if (in_array($referenceType, ['sale', 'sale_return'], true) && $referenceId > 0) {
        return '/inventory_system/cashier_sales_history.php?sale_id=' . $referenceId;
    }

    if ($referenceType === 'purchase_order' && $referenceId > 0) {
        return '/inventory_system/admin/purchase_receiving_history.php?po_id=' . $referenceId;
    }

    return '';
}

$actors = [];
$suppliers = [];
$referenceTypes = [];

foreach ($movements as $movement) {
    $actor = auditActor($movement);
    if ($actor !== '') {
        $actors[$actor] = $actor;
    }

    $supplier = trim((string) ($movement['supplier_name'] ?? ''));
    if ($supplier !== '') {
        $suppliers[$supplier] = $supplier;
    }

    $referenceType = trim((string) ($movement['reference_type'] ?? ''));
    if ($referenceType !== '') {
        $referenceTypes[$referenceType] = ucwords(str_replace('_', ' ', $referenceType));
    }
}

ksort($actors);
ksort($suppliers);
ksort($referenceTypes);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/../components/head.php'; ?>
    <title>Stock Movement Audit</title>
    <link rel="stylesheet" href="/inventory_system/assets/css/ops-suite.css">
</head>
<body>
<?php require __DIR__ . '/../components/header.php'; ?>
<?php require __DIR__ . '/../components/sidebar.php'; ?>

<main id="main" class="main ops-page">
    <section class="ops-hero">
        <div class="ops-hero__body">
            <div>
                <span class="ops-eyebrow">Inventory Traceability</span>
                <h1 class="ops-title">Stock Movement Audit</h1>
                <p class="ops-copy">Trace every product quantity change from sales, returns, stock in, stock out, and manual adjustments. This is the owner's answer to why stock changed.</p>
            </div>
            <div class="ops-hero__stats">
                <div class="ops-stat">
                    <span>Movements loaded</span>
                    <strong id="auditVisibleCount"><?= number_format((int) $summary['rows']) ?></strong>
                    <small>latest audit log rows</small>
                </div>
                <div class="ops-stat">
                    <span>Products touched</span>
                    <strong id="auditProductCount"><?= number_format((int) $summary['products']) ?></strong>
                    <small>unique products in view</small>
                </div>
                <div class="ops-stat">
                    <span>Stock added</span>
                    <strong id="auditStockInCount"><?= number_format((int) $summary['stock_in']) ?></strong>
                    <small>positive piece movements</small>
                </div>
                <div class="ops-stat">
                    <span>Stock removed</span>
                    <strong id="auditStockOutCount"><?= number_format((int) $summary['stock_out']) ?></strong>
                    <small>sale and stock-out pieces</small>
                </div>
            </div>
        </div>
    </section>

    <section class="ops-filter-card mb-4">
        <div class="ops-filter-grid">
            <div class="is-wide">
                <label>Search product, SKU, notes, actor, or reference</label>
                <input type="search" id="auditSearch" class="form-control" placeholder="Search stock movements">
            </div>
            <div>
                <label>Product</label>
                <select id="auditProduct" class="form-select">
                    <option value="all">All products</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) ($product['product_id'] ?? 0) ?>">
                            <?= e((string) ($product['product_name'] ?? 'Product')) ?><?= !empty($product['sku']) ? ' - ' . e((string) $product['sku']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Movement</label>
                <select id="auditAction" class="form-select">
                    <option value="all">All movements</option>
                    <option value="sale">Sale deductions</option>
                    <option value="stock_in">Stock in</option>
                    <option value="stock_out">Stock out</option>
                    <option value="manual_adjust">Manual adjustments</option>
                    <option value="sale_return">Sale returns</option>
                </select>
            </div>
            <div>
                <label>Actor</label>
                <select id="auditActor" class="form-select">
                    <option value="all">All users</option>
                    <?php foreach ($actors as $actor): ?>
                        <option value="<?= e(strtolower($actor)) ?>"><?= e($actor) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Supplier</label>
                <select id="auditSupplier" class="form-select">
                    <option value="all">All suppliers</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?= e(strtolower($supplier)) ?>"><?= e($supplier) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Reference</label>
                <select id="auditReference" class="form-select">
                    <option value="all">All references</option>
                    <?php foreach ($referenceTypes as $value => $label): ?>
                        <option value="<?= e(strtolower($value)) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Date from</label>
                <input type="date" id="auditDateFrom" class="form-control">
            </div>
            <div>
                <label>Date to</label>
                <input type="date" id="auditDateTo" class="form-control">
            </div>
        </div>
    </section>

    <section class="ops-table-card">
        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
            <div>
                <span class="ops-eyebrow">Audit Trail</span>
                <h2 class="h4 mb-1" style="color:#012970;font-weight:800;">Movement ledger</h2>
                <p class="ops-muted mb-0">Use this when stock looks wrong, when a customer asks about a return, or when the owner wants proof of changes.</p>
            </div>
            <div class="text-end">
                <div id="auditMeta" class="ops-muted small">Showing all loaded movements.</div>
                <div id="auditNetChange" class="fw-bold" style="color:#012970;">Net change: 0 pcs</div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table align-middle" id="stockMovementAuditTable">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Product</th>
                        <th>Movement</th>
                        <th class="text-end">Change</th>
                        <th class="text-end">Stock After</th>
                        <th>Actor</th>
                        <th>Reference</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($movements as $row): ?>
                        <?php
                        $change = (int) ($row['change_qty'] ?? 0);
                        $action = strtolower((string) ($row['action'] ?? ''));
                        $referenceType = strtolower((string) ($row['reference_type'] ?? ''));
                        $movementFilter = $referenceType === 'sale_return' ? 'sale_return' : $action;
                        $referenceLabel = $referenceType !== ''
                            ? ucwords(str_replace('_', ' ', $referenceType)) . (!empty($row['reference_id']) ? ' #' . (int) $row['reference_id'] : '')
                            : '-';
                        $referenceUrl = auditReferenceUrl($row);
                        $actor = auditActor($row);
                        $dateValue = substr((string) ($row['timestamp'] ?? ''), 0, 10);
                        $search = strtolower(implode(' ', [
                            (string) ($row['product_name'] ?? ''),
                            (string) ($row['sku'] ?? ''),
                            (string) ($row['category_name'] ?? ''),
                            (string) ($row['supplier_name'] ?? ''),
                            $actor,
                            $referenceLabel,
                            (string) ($row['notes'] ?? ''),
                        ]));
                        ?>
                        <tr
                            data-product-id="<?= (int) ($row['product_id'] ?? 0) ?>"
                            data-action="<?= e($movementFilter) ?>"
                            data-actor="<?= e(strtolower($actor)) ?>"
                            data-supplier="<?= e(strtolower((string) ($row['supplier_name'] ?? ''))) ?>"
                            data-reference="<?= e(strtolower($referenceType)) ?>"
                            data-date="<?= e($dateValue) ?>"
                            data-change="<?= $change ?>"
                            data-search="<?= e($search) ?>"
                        >
                            <td>
                                <strong><?= e(date('M d, Y', strtotime((string) ($row['timestamp'] ?? 'now')))) ?></strong>
                                <div class="small text-muted"><?= e(date('h:i A', strtotime((string) ($row['timestamp'] ?? 'now')))) ?></div>
                            </td>
                            <td>
                                <strong><?= e((string) ($row['product_name'] ?? 'Product')) ?></strong>
                                <div class="small text-muted">
                                    <?= e((string) ($row['category_name'] ?? 'Uncategorized')) ?><?= !empty($row['sku']) ? ' | SKU ' . e((string) $row['sku']) : '' ?>
                                </div>
                            </td>
                            <td>
                                <span class="ops-status <?= $change >= 0 ? 'is-success' : 'is-warning' ?>">
                                    <?= e(auditActionLabel($action, $referenceType)) ?>
                                </span>
                            </td>
                            <td class="text-end fw-bold <?= $change >= 0 ? 'text-success' : 'text-danger' ?>">
                                <?= $change > 0 ? '+' : '' ?><?= number_format($change) ?>
                            </td>
                            <td class="text-end"><?= number_format((int) ($row['current_qty'] ?? 0)) ?></td>
                            <td>
                                <?= e($actor) ?>
                                <?php if (!empty($row['role'])): ?>
                                    <div class="small text-muted"><?= e(ucfirst((string) $row['role'])) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($referenceUrl !== ''): ?>
                                    <a href="<?= e($referenceUrl) ?>" class="btn btn-sm btn-outline-primary"><?= e($referenceLabel) ?></a>
                                <?php else: ?>
                                    <?= e($referenceLabel) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) ($row['notes'] ?? '-')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="stockMovementAuditEmpty" class="ops-empty d-none mt-3">
            No stock movements match the current filters.
        </div>
    </section>
</main>

<script>
window.STOCK_MOVEMENT_AUDIT = <?= json_encode([
    'rows' => array_map(static fn(array $row): array => [
        'product_id' => (int) ($row['product_id'] ?? 0),
        'change_qty' => (int) ($row['change_qty'] ?? 0),
        'action' => (string) ($row['action'] ?? ''),
        'reference_type' => (string) ($row['reference_type'] ?? ''),
        'timestamp' => (string) ($row['timestamp'] ?? ''),
    ], $movements),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<?php require __DIR__ . '/../components/js_script.php'; ?>
<script src="/inventory_system/assets/js/stock-movement-audit.js"></script>
</body>
</html>
