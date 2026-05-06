<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/ProductController.php';

Middleware::auth()->role(['admin']);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatMoney(float $amount): string
{
    return 'PHP ' . number_format($amount, 2);
}

function buildQueryUrl(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);

    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        }
    }

    return '?' . http_build_query($query);
}

function stockBadgeClass(string $status, int $quantity, int $reorderLevel): string
{
    if ($status !== 'active') {
        return 'bg-secondary';
    }

    if ($quantity <= 0) {
        return 'bg-danger';
    }

    if ($quantity <= $reorderLevel) {
        return 'bg-warning text-dark';
    }

    return 'bg-success';
}

function stockMovementReferenceLabel(array $row): ?string
{
    $referenceType = trim((string) ($row['reference_type'] ?? ''));
    $referenceId = (int) ($row['reference_id'] ?? 0);

    if ($referenceType === '') {
        return null;
    }

    $label = ucwords(str_replace('_', ' ', $referenceType));
    if ($referenceId > 0) {
        $label .= ' #' . $referenceId;
    }

    return $label;
}

function summarizeMovementNote(?string $note, int $maxLength = 110): ?string
{
    $note = trim((string) $note);
    if ($note === '') {
        return null;
    }

    if (mb_strlen($note) <= $maxLength) {
        return $note;
    }

    return rtrim(mb_substr($note, 0, $maxLength - 1)) . '...';
}

function movementActionMeta(string $action): array
{
    return match ($action) {
        'stock_in' => [
            'color' => 'text-success',
            'badge' => 'bg-success-subtle text-success',
            'label' => 'Stock In',
        ],
        'stock_out' => [
            'color' => 'text-danger',
            'badge' => 'bg-danger-subtle text-danger',
            'label' => 'Stock Out',
        ],
        'sale' => [
            'color' => 'text-primary',
            'badge' => 'bg-primary-subtle text-primary',
            'label' => 'Sale',
        ],
        default => [
            'color' => 'text-muted',
            'badge' => 'bg-light text-dark',
            'label' => ucwords(str_replace('_', ' ', $action)),
        ],
    };
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPageOptions = [10, 25, 50, 100];
$perPage = (int) ($_GET['per_page'] ?? 25);
$perPage = in_array($perPage, $perPageOptions, true) ? $perPage : 25;
$offset = ($page - 1) * $perPage;

$search = trim((string) ($_GET['search'] ?? ''));
$stockStatus = strtolower(trim((string) ($_GET['stock_status'] ?? 'all')));
$categoryId = (int) ($_GET['category_id'] ?? 0);
$export = strtolower(trim((string) ($_GET['export'] ?? '')));
$allowedStockStatuses = ['all', 'in_stock', 'low_stock', 'out_of_stock', 'inactive'];

if (!in_array($stockStatus, $allowedStockStatuses, true)) {
    $stockStatus = 'all';
}

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(
        p.product_name LIKE :search
        OR p.sku LIKE :search
        OR c.category_name LIKE :search
    )';
    $params[':search'] = '%' . $search . '%';
}

if ($categoryId > 0) {
    $where[] = 'p.category_id = :category_id';
    $params[':category_id'] = $categoryId;
}

switch ($stockStatus) {
    case 'in_stock':
        $where[] = "p.status = 'active' AND p.quantity > p.reorder_level";
        break;
    case 'low_stock':
        $where[] = "p.status = 'active' AND p.quantity > 0 AND p.quantity <= p.reorder_level";
        break;
    case 'out_of_stock':
        $where[] = "p.status = 'active' AND p.quantity <= 0";
        break;
    case 'inactive':
        $where[] = "p.status <> 'active'";
        break;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$summary = [
    'total_products' => 0,
    'total_units' => 0,
    'low_stock_count' => 0,
    'out_of_stock_count' => 0,
    'inventory_value' => 0.0,
];
$products = [];
$stockActivity = [];
$activityFeed = [];
$movementDetails = [];
$categories = [];
$stockStatusSummary = [
    'in_stock' => 0,
    'low_stock' => 0,
    'out_of_stock' => 0,
    'inactive' => 0,
];
$totalRows = 0;
$totalPages = 1;
$errorMsg = null;
$activityFeedLimit = 6;
$movementDetailsLimit = 5;
$stockActivityQueryLimit = max($activityFeedLimit, $movementDetailsLimit);

try {
    ProductController::ensureStockMovementSchema($conn);

    $categoryStmt = $conn->query("\n        SELECT category_id, category_name\n        FROM categories\n        ORDER BY category_name ASC\n    ");
    $categories = $categoryStmt ? ($categoryStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    $summaryStmt = $conn->query("\n        SELECT\n            COUNT(*) AS total_products,\n            IFNULL(SUM(quantity), 0) AS total_units,\n            IFNULL(SUM(CASE WHEN status = 'active' AND quantity > 0 AND quantity <= reorder_level THEN 1 ELSE 0 END), 0) AS low_stock_count,\n            IFNULL(SUM(CASE WHEN status = 'active' AND quantity <= 0 THEN 1 ELSE 0 END), 0) AS out_of_stock_count,\n            IFNULL(SUM(quantity * price), 0) AS inventory_value\n        FROM products\n    ");
    $summary = $summaryStmt ? ($summaryStmt->fetch(PDO::FETCH_ASSOC) ?: $summary) : $summary;

    $statusBreakdownStmt = $conn->query("\n        SELECT\n            SUM(CASE WHEN status = 'active' AND quantity > reorder_level THEN 1 ELSE 0 END) AS in_stock,\n            SUM(CASE WHEN status = 'active' AND quantity > 0 AND quantity <= reorder_level THEN 1 ELSE 0 END) AS low_stock,\n            SUM(CASE WHEN status = 'active' AND quantity <= 0 THEN 1 ELSE 0 END) AS out_of_stock,\n            SUM(CASE WHEN status <> 'active' THEN 1 ELSE 0 END) AS inactive\n        FROM products\n    ");
    $stockStatusSummary = $statusBreakdownStmt ? ($statusBreakdownStmt->fetch(PDO::FETCH_ASSOC) ?: $stockStatusSummary) : $stockStatusSummary;

    $countSql = "
        SELECT COUNT(*)
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.category_id
        {$whereSql}
    ";
    $countStmt = $conn->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalRows = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalRows / $perPage));

    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $productsSql = "
        SELECT
            p.product_id,
            p.product_name,
            p.sku,
            p.photo,
            p.price,
            p.quantity,
            p.reorder_level,
            p.status,
            c.category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.category_id
        {$whereSql}
        ORDER BY p.product_name ASC, p.product_id ASC
        LIMIT :limit OFFSET :offset
    ";
    $productsStmt = $conn->prepare($productsSql);
    foreach ($params as $key => $value) {
        $productsStmt->bindValue($key, $value);
    }
    $productsStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $productsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $productsStmt->execute();
    $products = $productsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stockActivitySql = "
        SELECT
            sal.log_id,
            sal.action,
            sal.change_qty,
            sal.current_qty,
            sal.reference_type,
            sal.reference_id,
            sal.notes,
            sal.timestamp,
            p.product_name,
            u.first_name,
            u.last_name,
            u.username
        FROM stock_audit_log sal
        INNER JOIN products p ON sal.product_id = p.product_id
        LEFT JOIN users u ON sal.user_id = u.user_id
        WHERE sal.action <> 'manual_adjust'
        ORDER BY sal.timestamp DESC, sal.log_id DESC
        LIMIT :activity_limit
    ";
    $stockActivityStmt = $conn->prepare($stockActivitySql);
    $stockActivityStmt->bindValue(':activity_limit', $stockActivityQueryLimit, PDO::PARAM_INT);
    $stockActivityStmt->execute();
    $stockActivity = $stockActivityStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $activityFeed = array_slice($stockActivity, 0, $activityFeedLimit);
    $movementDetails = array_slice($stockActivity, 0, $movementDetailsLimit);

    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="inventory-report-' . date('Ymd-His') . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Product ID', 'Product Name', 'SKU', 'Category', 'Price', 'Quantity', 'Reorder Level', 'Status']);

        $exportSql = "
            SELECT
                p.product_id,
                p.product_name,
                p.sku,
                p.price,
                p.quantity,
                p.reorder_level,
                p.status,
                c.category_name
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            {$whereSql}
            ORDER BY p.product_name ASC, p.product_id ASC
        ";
        $exportStmt = $conn->prepare($exportSql);
        foreach ($params as $key => $value) {
            $exportStmt->bindValue($key, $value);
        }
        $exportStmt->execute();

        while ($row = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [
                $row['product_id'],
                $row['product_name'],
                $row['sku'],
                $row['category_name'],
                $row['price'],
                $row['quantity'],
                $row['reorder_level'],
                $row['status'],
            ]);
        }

        fclose($out);
        exit;
    }
} catch (Throwable $e) {
    error_log('[inventory_report.php] ' . $e->getMessage());
    $errorMsg = 'Failed to load inventory report.';
}

$stockLabels = json_encode(['In Stock', 'Low Stock', 'Out of Stock', 'Inactive']);
$stockTotals = json_encode([
    (int) ($stockStatusSummary['in_stock'] ?? 0),
    (int) ($stockStatusSummary['low_stock'] ?? 0),
    (int) ($stockStatusSummary['out_of_stock'] ?? 0),
    (int) ($stockStatusSummary['inactive'] ?? 0),
]);
$pageTitle = 'Inventory Report';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require __DIR__ . '/../components/head.php'; ?>
<link rel="stylesheet" href="/inventory_system/assets/css/inventory-report.css">
</head>
<body>

<?php
require __DIR__ . '/../components/header.php';
require __DIR__ . '/../components/sidebar.php';
?>

<main id="main" class="main inventory-report-page">
    <div class="inventory-report-hero">
        <div class="inventory-report-hero-copy">
            <p class="inventory-report-eyebrow">Stock Intelligence</p>
            <h1 class="inventory-report-title">Inventory Report</h1>
            <p class="inventory-report-copy">Review stock health, current valuation, movement signals, and product-level inventory data from one workspace.</p>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/inventory_system/index.php">Home</a></li>
                    <li class="breadcrumb-item active">Inventory Report</li>
                </ol>
            </nav>
        </div>
        <div class="inventory-report-hero-panel">
            <div class="inventory-report-hero-stat">
                <span class="inventory-report-hero-label">Tracked products</span>
                <strong><?= number_format((int) ($summary['total_products'] ?? 0)) ?></strong>
                <span class="inventory-report-hero-note">active and inactive catalog items</span>
            </div>
            <div class="inventory-report-hero-stat">
                <span class="inventory-report-hero-label">Current stock value</span>
                <strong><?= e(formatMoney((float) ($summary['inventory_value'] ?? 0))) ?></strong>
                <span class="inventory-report-hero-note">estimated value on hand</span>
            </div>
        </div>
    </div>

    <section class="section dashboard">
        <div class="row">
            <div class="col-12">
                <div class="card inventory-filter-card">
                    <div class="card-body">
                        <div class="inventory-section-head">
                            <div>
                                <p class="inventory-section-kicker">Filter</p>
                                <h5 class="card-title">Inventory View</h5>
                                <p class="inventory-section-copy mb-0">Search the catalog and focus the report by category, stock health, and page size.</p>
                            </div>
                        </div>

                        <?php if ($errorMsg !== null): ?>
                            <div class="alert alert-danger inventory-inline-alert"><?= e($errorMsg) ?></div>
                        <?php endif; ?>

                        <form method="GET" class="row g-3 inventory-filter-form">
                            <div class="col-md-4">
                                <label class="form-label inventory-label">Search</label>
                                <input type="text" name="search" class="form-control" value="<?= e($search) ?>" placeholder="Product name, SKU, or category">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label inventory-label">Category</label>
                                <select name="category_id" class="form-select">
                                    <option value="0">All categories</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= (int) $category['category_id'] ?>" <?= $categoryId === (int) $category['category_id'] ? 'selected' : '' ?>>
                                            <?= e((string) $category['category_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label inventory-label">Stock Status</label>
                                <select name="stock_status" class="form-select">
                                    <option value="all" <?= $stockStatus === 'all' ? 'selected' : '' ?>>All</option>
                                    <option value="in_stock" <?= $stockStatus === 'in_stock' ? 'selected' : '' ?>>In Stock</option>
                                    <option value="low_stock" <?= $stockStatus === 'low_stock' ? 'selected' : '' ?>>Low Stock</option>
                                    <option value="out_of_stock" <?= $stockStatus === 'out_of_stock' ? 'selected' : '' ?>>Out of Stock</option>
                                    <option value="inactive" <?= $stockStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label inventory-label">Rows</label>
                                <select name="per_page" class="form-select">
                                    <?php foreach ($perPageOptions as $size): ?>
                                        <option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-1 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100 inventory-apply-btn">Apply</button>
                            </div>
                            <div class="col-12 d-flex gap-2 inventory-filter-actions">
                                <a href="<?= e(buildQueryUrl(['export' => 'csv', 'page' => 1])) ?>" class="btn btn-success btn-sm inventory-soft-btn">Export CSV</a>
                                <a href="/inventory_system/reports/inventory_report.php" class="btn btn-outline-secondary btn-sm inventory-soft-btn">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-xxl-3 col-md-6">
                <div class="card inventory-kpi-card is-indigo">
                    <div class="card-body">
                        <div class="inventory-kpi-head"><p class="inventory-section-kicker">Catalog</p><h5 class="card-title">Products</h5></div>
                        <div class="d-flex align-items-center inventory-kpi-body">
                            <div class="card-icon rounded-circle d-flex align-items-center justify-content-center inventory-kpi-icon">
                                <i class="bi bi-box-seam"></i>
                            </div>
                            <div class="ps-3">
                                <h6><?= number_format((int) ($summary['total_products'] ?? 0)) ?></h6>
                                <span class="text-muted small pt-2">Tracked items</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xxl-3 col-md-6">
                <div class="card inventory-kpi-card is-green">
                    <div class="card-body">
                        <div class="inventory-kpi-head"><p class="inventory-section-kicker">Value</p><h5 class="card-title">Inventory Value</h5></div>
                        <div class="d-flex align-items-center inventory-kpi-body">
                            <div class="card-icon rounded-circle d-flex align-items-center justify-content-center inventory-kpi-icon">
                                <i class="bi bi-cash-coin"></i>
                            </div>
                            <div class="ps-3">
                                <h6><?= e(formatMoney((float) ($summary['inventory_value'] ?? 0))) ?></h6>
                                <span class="text-muted small pt-2">Stock on hand</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xxl-3 col-md-6">
                <div class="card inventory-kpi-card is-amber">
                    <div class="card-body">
                        <div class="inventory-kpi-head"><p class="inventory-section-kicker">Alert</p><h5 class="card-title">Low Stock</h5></div>
                        <div class="d-flex align-items-center inventory-kpi-body">
                            <div class="card-icon rounded-circle d-flex align-items-center justify-content-center inventory-kpi-icon">
                                <i class="bi bi-exclamation-triangle"></i>
                            </div>
                            <div class="ps-3">
                                <h6><?= number_format((int) ($summary['low_stock_count'] ?? 0)) ?></h6>
                                <span class="text-muted small pt-2">Below reorder level</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xxl-3 col-md-6">
                <div class="card inventory-kpi-card is-slate">
                    <div class="card-body">
                        <div class="inventory-kpi-head"><p class="inventory-section-kicker">Volume</p><h5 class="card-title">Units On Hand</h5></div>
                        <div class="d-flex align-items-center inventory-kpi-body">
                            <div class="card-icon rounded-circle d-flex align-items-center justify-content-center inventory-kpi-icon">
                                <i class="bi bi-archive"></i>
                            </div>
                            <div class="ps-3">
                                <h6><?= number_format((int) ($summary['total_units'] ?? 0)) ?></h6>
                                <span class="text-muted small pt-2">Total quantity</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card recent-sales overflow-auto inventory-panel">
                    <div class="card-body">
                        <div class="inventory-section-head">
                            <div>
                                <p class="inventory-section-kicker">Ledger</p>
                                <h5 class="card-title">Inventory Table</h5>
                                <p class="inventory-section-copy mb-0">Product-level stock, pricing, and current catalog state for the selected filters.</p>
                            </div>
                        </div>
                        <div class="inventory-table-shell">
                            <table class="table table-borderless datatable inventory-main-table">
                            <thead>
                                <tr>
                                    <th scope="col">Preview</th>
                                    <th scope="col">Product</th>
                                    <th scope="col">Category</th>
                                    <th scope="col">SKU</th>
                                    <th scope="col">Price</th>
                                    <th scope="col">Stock</th>
                                    <th scope="col">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($products)): ?>
                                    <tr><td colspan="7" class="text-center text-muted py-3">No products found for this filter.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($products as $product): ?>
                                        <?php
                                        $qty = (int) ($product['quantity'] ?? 0);
                                        $reorderLevel = (int) ($product['reorder_level'] ?? 0);
                                        $status = (string) ($product['status'] ?? 'inactive');
                                        ?>
                                        <tr>
                                            <th scope="row">
                                                <img src="<?= e((string) ($product['photo'] ?: '/inventory_system/assets/img/card.jpg')) ?>" alt="" class="inventory-thumb">
                                            </th>
                                            <td>
                                                <span class="text-primary fw-bold"><?= e((string) $product['product_name']) ?></span>
                                                <div class="small text-muted">#<?= (int) $product['product_id'] ?> | Reorder <?= number_format($reorderLevel) ?></div>
                                            </td>
                                            <td><?= e((string) ($product['category_name'] ?? 'Uncategorized')) ?></td>
                                            <td><?= e((string) ($product['sku'] ?? '-')) ?></td>
                                            <td><?= e(formatMoney((float) ($product['price'] ?? 0))) ?></td>
                                            <td><?= number_format($qty) ?></td>
                                            <td><span class="badge inventory-pill <?= e(stockBadgeClass($status, $qty, $reorderLevel)) ?>"><?= e(ucwords(str_replace('_', ' ', $status))) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            </table>
                        </div>

                        <?php if ($totalPages > 1): ?>
                            <nav class="mt-3">
                                <ul class="pagination mb-0">
                                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                        <a class="page-link" href="<?= $page <= 1 ? '#' : e(buildQueryUrl(['page' => $page - 1])) ?>">Previous</a>
                                    </li>
                                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                            <a class="page-link" href="<?= e(buildQueryUrl(['page' => $i])) ?>"><?= $i ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                        <a class="page-link" href="<?= $page >= $totalPages ? '#' : e(buildQueryUrl(['page' => $page + 1])) ?>">Next</a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card inventory-panel">
                    <div class="card-body pb-0">
                        <div class="inventory-section-head">
                            <div>
                                <p class="inventory-section-kicker">Mix</p>
                                <h5 class="card-title">Stock Health</h5>
                            </div>
                        </div>
                        <div id="stockHealthChart" style="min-height: 320px;" class="echart"></div>

                        <script>
                        document.addEventListener('DOMContentLoaded', () => {
                            const labels = <?= $stockLabels ?>;
                            const values = <?= $stockTotals ?>;
                            const target = document.querySelector('#stockHealthChart');

                            if (!values.some(value => value > 0)) {
                                target.innerHTML = '<p class="text-center text-muted py-5">No inventory data available.</p>';
                                return;
                            }

                            echarts.init(target).setOption({
                                tooltip: { trigger: 'item', formatter: '{b}: {c} ({d}%)' },
                                legend: { top: '5%', left: 'center' },
                                series: [{
                                    name: 'Stock Status',
                                    type: 'pie',
                                    radius: ['40%', '70%'],
                                    avoidLabelOverlap: false,
                                    label: { show: false, position: 'center' },
                                    emphasis: { label: { show: true, fontSize: '18', fontWeight: 'bold' } },
                                    labelLine: { show: false },
                                    data: labels.map((label, index) => ({ name: label, value: values[index] }))
                                }]
                            });
                        });
                        </script>
                    </div>
                </div>

                <div class="card inventory-report-panel">
                    <div class="card-body">
                        <div class="inventory-report-panel-head">
                            <div>
                                <h5 class="inventory-report-panel-title">Stock Movement Feed</h5>
                                <p class="inventory-report-panel-subtitle">Latest inventory changes with a shorter, cleaner feed.</p>
                            </div>
                            <span class="inventory-report-panel-pill">Latest <?= count($activityFeed) ?></span>
                        </div>
                        <div class="inventory-report-feed">
                            <?php if (empty($activityFeed)): ?>
                                <p class="text-muted text-center mb-0 py-4">No recent activity</p>
                            <?php else: ?>
                                <?php
                                foreach ($activityFeed as $row):
                                    $meta = movementActionMeta((string) ($row['action'] ?? ''));
                                    $qty = (int) ($row['change_qty'] ?? 0) > 0 ? '+' . (int) $row['change_qty'] : (string) (int) ($row['change_qty'] ?? 0);
                                    $actor = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
                                    $referenceLabel = stockMovementReferenceLabel($row);
                                    $movementNote = summarizeMovementNote((string) ($row['notes'] ?? ''), 72);
                                    if ($actor === '') {
                                        $actor = (string) ($row['username'] ?? 'System');
                                    }
                                ?>
                                    <div class="inventory-report-feed-item">
                                        <div class="inventory-report-feed-time">
                                            <?= e(date('M d', strtotime((string) $row['timestamp']))) ?><br>
                                            <?= e(date('h:i A', strtotime((string) $row['timestamp']))) ?>
                                        </div>
                                        <div class="inventory-report-feed-main">
                                            <div class="inventory-report-feed-top">
                                                <span class="badge <?= e($meta['badge']) ?>"><?= e($meta['label']) ?></span>
                                                <p class="inventory-report-feed-product" title="<?= e((string) ($row['product_name'] ?? '-')) ?>"><?= e((string) ($row['product_name'] ?? '-')) ?></p>
                                                <span class="inventory-report-feed-change <?= e($meta['color']) ?>"><?= e($qty) ?></span>
                                            </div>
                                            <div class="inventory-report-feed-meta">
                                                by <?= e($actor) ?> | current stock <?= (int) ($row['current_qty'] ?? 0) ?>
                                                <?php if ($referenceLabel !== null): ?>
                                                    | <?= e($referenceLabel) ?>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($movementNote !== null): ?>
                                                <div class="inventory-report-feed-note" title="<?= e((string) ($row['notes'] ?? '')) ?>"><?= e($movementNote) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card inventory-report-panel mt-3">
                    <div class="card-body">
                        <div class="inventory-report-panel-head">
                            <div>
                                <h5 class="inventory-report-panel-title">Movement Details</h5>
                                <p class="inventory-report-panel-subtitle">Compact audit context for the most recent stock changes.</p>
                            </div>
                            <span class="inventory-report-panel-pill">Latest <?= count($movementDetails) ?></span>
                        </div>
                        <div class="table-responsive inventory-report-table-wrap">
                            <table class="table table-sm align-middle inventory-report-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Product</th>
                                        <th>Action</th>
                                        <th>Reference</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($movementDetails)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">No stock movement details yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($movementDetails as $row): ?>
                                            <?php $referenceLabel = stockMovementReferenceLabel($row); ?>
                                            <?php $movementNote = summarizeMovementNote((string) ($row['notes'] ?? ''), 80); ?>
                                            <?php $meta = movementActionMeta((string) ($row['action'] ?? 'unknown')); ?>
                                            <tr>
                                                <td class="small text-muted" style="white-space: nowrap;">
                                                    <?= e(date('M d, h:i A', strtotime((string) $row['timestamp']))) ?>
                                                </td>
                                                <td class="fw-semibold text-primary" title="<?= e((string) ($row['product_name'] ?? '-')) ?>"><?= e((string) ($row['product_name'] ?? '-')) ?></td>
                                                <td>
                                                    <span class="badge <?= e($meta['badge']) ?>">
                                                        <?= e($meta['label']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($referenceLabel !== null): ?>
                                                        <span class="inventory-report-ref"><?= e($referenceLabel) ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="small inventory-report-note-cell">
                                                    <?php if ($movementNote !== null): ?>
                                                        <span class="inventory-report-note-text" title="<?= e((string) ($row['notes'] ?? '')) ?>"><?= e($movementNote) ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">No notes</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
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
