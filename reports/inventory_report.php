<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';

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

try {
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

    $stockActivityStmt = $conn->query("\n        SELECT\n            sal.log_id,\n            sal.action,\n            sal.change_qty,\n            sal.current_qty,\n            sal.timestamp,\n            p.product_name,\n            u.first_name,\n            u.last_name,\n            u.username\n        FROM stock_audit_log sal\n        INNER JOIN products p ON sal.product_id = p.product_id\n        LEFT JOIN users u ON sal.user_id = u.user_id\n        WHERE sal.action <> 'manual_adjust'\n        ORDER BY sal.timestamp DESC, sal.log_id DESC\n        LIMIT 12\n    ");
    $stockActivity = $stockActivityStmt ? ($stockActivityStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

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
<?php require __DIR__ . '/../components/head.php'; ?>
<body>

<?php
require __DIR__ . '/../components/header.php';
require __DIR__ . '/../components/sidebar.php';
?>

<main id="main" class="main">
    <div class="pagetitle">
        <h1>Inventory Report</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/inventory_system/index.php">Home</a></li>
                <li class="breadcrumb-item active">Inventory Report</li>
            </ol>
        </nav>
    </div>

    <section class="section dashboard">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Filters <span>| Inventory View</span></h5>

                        <?php if ($errorMsg !== null): ?>
                            <div class="alert alert-danger"><?= e($errorMsg) ?></div>
                        <?php endif; ?>

                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" value="<?= e($search) ?>" placeholder="Product name, SKU, or category">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Category</label>
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
                                <label class="form-label">Stock Status</label>
                                <select name="stock_status" class="form-select">
                                    <option value="all" <?= $stockStatus === 'all' ? 'selected' : '' ?>>All</option>
                                    <option value="in_stock" <?= $stockStatus === 'in_stock' ? 'selected' : '' ?>>In Stock</option>
                                    <option value="low_stock" <?= $stockStatus === 'low_stock' ? 'selected' : '' ?>>Low Stock</option>
                                    <option value="out_of_stock" <?= $stockStatus === 'out_of_stock' ? 'selected' : '' ?>>Out of Stock</option>
                                    <option value="inactive" <?= $stockStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Rows</label>
                                <select name="per_page" class="form-select">
                                    <?php foreach ($perPageOptions as $size): ?>
                                        <option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-1 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">Apply</button>
                            </div>
                            <div class="col-12 d-flex gap-2">
                                <a href="<?= e(buildQueryUrl(['export' => 'csv', 'page' => 1])) ?>" class="btn btn-success btn-sm">Export CSV</a>
                                <a href="/inventory_system/reports/inventory_report.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-xxl-3 col-md-6">
                <div class="card info-card sales-card">
                    <div class="card-body">
                        <h5 class="card-title">Products <span>| Total</span></h5>
                        <div class="d-flex align-items-center">
                            <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
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
                <div class="card info-card revenue-card">
                    <div class="card-body">
                        <h5 class="card-title">Inventory Value <span>| Current</span></h5>
                        <div class="d-flex align-items-center">
                            <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
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
                <div class="card info-card customers-card">
                    <div class="card-body">
                        <h5 class="card-title">Low Stock <span>| Needs Attention</span></h5>
                        <div class="d-flex align-items-center">
                            <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
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
                <div class="card info-card customers-card">
                    <div class="card-body">
                        <h5 class="card-title">Units On Hand <span>| Current</span></h5>
                        <div class="d-flex align-items-center">
                            <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
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
                <div class="card recent-sales overflow-auto">
                    <div class="card-body">
                        <h5 class="card-title">Inventory Table <span>| Filtered Results</span></h5>
                        <table class="table table-borderless datatable">
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
                                                <a href="#">
                                                    <img src="<?= e((string) ($product['photo'] ?: '/inventory_system/assets/uploads/products/images.jpeg')) ?>" alt="" style="width:50px; height:50px; object-fit:cover; border-radius:6px;">
                                                </a>
                                            </th>
                                            <td>
                                                <a href="#" class="text-primary fw-bold"><?= e((string) $product['product_name']) ?></a>
                                                <div class="small text-muted">#<?= (int) $product['product_id'] ?> � Reorder <?= number_format($reorderLevel) ?></div>
                                            </td>
                                            <td><?= e((string) ($product['category_name'] ?? 'Uncategorized')) ?></td>
                                            <td><?= e((string) ($product['sku'] ?? '-')) ?></td>
                                            <td><?= e(formatMoney((float) ($product['price'] ?? 0))) ?></td>
                                            <td><?= number_format($qty) ?></td>
                                            <td><span class="badge <?= e(stockBadgeClass($status, $qty, $reorderLevel)) ?>"><?= e(ucwords(str_replace('_', ' ', $status))) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>

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
                <div class="card">
                    <div class="card-body pb-0">
                        <h5 class="card-title">Stock Health <span>| Current Mix</span></h5>
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

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Recent Activity <span>| Stock</span></h5>
                        <div class="activity">
                            <?php if (empty($stockActivity)): ?>
                                <p class="text-muted text-center">No recent activity</p>
                            <?php else: ?>
                                <?php
                                $actionColors = ['sale' => 'text-primary', 'stock_in' => 'text-success', 'stock_out' => 'text-danger'];
                                foreach ($stockActivity as $row):
                                    $color = $actionColors[$row['action']] ?? 'text-muted';
                                    $qty = (int) $row['change_qty'] > 0 ? '+' . (int) $row['change_qty'] : (string) (int) $row['change_qty'];
                                    $actor = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
                                    if ($actor === '') {
                                        $actor = (string) ($row['username'] ?? 'System');
                                    }
                                ?>
                                    <div class="activity-item d-flex">
                                        <div class="activite-label"><?= e(date('M d, h:i A', strtotime((string) $row['timestamp']))) ?></div>
                                        <i class="bi bi-circle-fill activity-badge <?= e($color) ?> align-self-start"></i>
                                        <div class="activity-content">
                                            <span class="fw-bold"><?= e((string) $row['product_name']) ?></span>
                                            <span class="<?= e($color) ?>"><?= e($qty) ?></span>
                                            <span class="text-muted">(<?= e(str_replace('_', ' ', (string) $row['action'])) ?>)</span><br>
                                            <small class="text-muted">by <?= e($actor) ?> � current <?= (int) $row['current_qty'] ?></small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
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

