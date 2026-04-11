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

function normalizeDate(?string $date, bool $endOfDay = false): ?string
{
    if (!$date) {
        return null;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt) {
        return null;
    }

    return $endOfDay
        ? $dt->format('Y-m-d 23:59:59')
        : $dt->format('Y-m-d 00:00:00');
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

function paymentBadgeClass(string $paymentMethod): string
{
    return match (strtolower(trim($paymentMethod))) {
        'cash' => 'bg-success',
        'card' => 'bg-primary',
        'gcash' => 'bg-info text-dark',
        'maya' => 'bg-warning text-dark',
        default => 'bg-secondary',
    };
}

$today = new DateTimeImmutable('today');
$defaultFrom = $today->modify('first day of this month')->format('Y-m-d');
$defaultTo = $today->format('Y-m-d');

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPageOptions = [10, 25, 50, 100];
$perPage = (int) ($_GET['per_page'] ?? 25);
$perPage = in_array($perPage, $perPageOptions, true) ? $perPage : 25;
$offset = ($page - 1) * $perPage;

$dateFromRaw = (string) ($_GET['date_from'] ?? $defaultFrom);
$dateToRaw = (string) ($_GET['date_to'] ?? $defaultTo);
$paymentMethod = trim((string) ($_GET['payment_method'] ?? ''));
$cashierId = (int) ($_GET['cashier_id'] ?? 0);
$export = strtolower(trim((string) ($_GET['export'] ?? '')));

$dateFrom = normalizeDate($dateFromRaw, false);
$dateTo = normalizeDate($dateToRaw, true);

if ($dateFrom === null) {
    $dateFromRaw = $defaultFrom;
    $dateFrom = normalizeDate($dateFromRaw, false);
}

if ($dateTo === null) {
    $dateToRaw = $defaultTo;
    $dateTo = normalizeDate($dateToRaw, true);
}

$where = [];
$params = [];

if ($dateFrom !== null) {
    $where[] = 's.sale_date >= :date_from';
    $params[':date_from'] = $dateFrom;
}

if ($dateTo !== null) {
    $where[] = 's.sale_date <= :date_to';
    $params[':date_to'] = $dateTo;
}

if ($paymentMethod !== '') {
    $where[] = 's.payment_method = :payment_method';
    $params[':payment_method'] = $paymentMethod;
}

if ($cashierId > 0) {
    $where[] = 's.user_id = :cashier_id';
    $params[':cashier_id'] = $cashierId;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$summary = [
    'sale_count' => 0,
    'total_revenue' => 0.0,
    'average_sale' => 0.0,
    'items_sold' => 0,
];
$sales = [];
$paymentBreakdown = [];
$topProducts = [];
$cashiers = [];
$paymentOptions = [];
$chartRows = [];
$totalRows = 0;
$totalPages = 1;
$errorMsg = null;

try {
    $cashierStmt = $conn->query("\n        SELECT user_id, first_name, last_name, username\n        FROM users\n        WHERE status = 'active'\n        ORDER BY first_name ASC, last_name ASC, username ASC\n    ");
    $cashiers = $cashierStmt ? ($cashierStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    $paymentStmt = $conn->query("\n        SELECT DISTINCT payment_method\n        FROM sales\n        WHERE payment_method IS NOT NULL AND payment_method <> ''\n        ORDER BY payment_method ASC\n    ");
    $paymentOptions = $paymentStmt ? ($paymentStmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];

    $summarySql = "
        SELECT
            COUNT(DISTINCT s.sale_id) AS sale_count,
            IFNULL(SUM(s.total_amount), 0) AS total_revenue,
            IFNULL(AVG(s.total_amount), 0) AS average_sale,
            IFNULL(SUM(si.quantity * COALESCE(si.unit_multiplier, 1)), 0) AS items_sold
        FROM sales s
        LEFT JOIN sale_items si ON s.sale_id = si.sale_id
        {$whereSql}
    ";
    $summaryStmt = $conn->prepare($summarySql);
    foreach ($params as $key => $value) {
        $summaryStmt->bindValue($key, $value);
    }
    $summaryStmt->execute();
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: $summary;

    $countSql = "
        SELECT COUNT(*)
        FROM sales s
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

    $salesSql = "
        SELECT
            s.sale_id,
            s.sale_date,
            s.total_amount,
            s.payment_method,
            s.user_id,
            u.first_name,
            u.last_name,
            u.username,
            COUNT(si.sale_item_id) AS item_lines,
            IFNULL(SUM(si.quantity * COALESCE(si.unit_multiplier, 1)), 0) AS total_items
        FROM sales s
        LEFT JOIN users u ON s.user_id = u.user_id
        LEFT JOIN sale_items si ON s.sale_id = si.sale_id
        {$whereSql}
        GROUP BY
            s.sale_id, s.sale_date, s.total_amount, s.payment_method, s.user_id,
            u.first_name, u.last_name, u.username
        ORDER BY s.sale_date DESC, s.sale_id DESC
        LIMIT :limit OFFSET :offset
    ";
    $salesStmt = $conn->prepare($salesSql);
    foreach ($params as $key => $value) {
        $salesStmt->bindValue($key, $value);
    }
    $salesStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $salesStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $salesStmt->execute();
    $sales = $salesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $paymentSql = "
        SELECT
            s.payment_method,
            COUNT(*) AS sale_count,
            IFNULL(SUM(s.total_amount), 0) AS total_amount
        FROM sales s
        {$whereSql}
        GROUP BY s.payment_method
        ORDER BY total_amount DESC, s.payment_method ASC
    ";
    $paymentBreakdownStmt = $conn->prepare($paymentSql);
    foreach ($params as $key => $value) {
        $paymentBreakdownStmt->bindValue($key, $value);
    }
    $paymentBreakdownStmt->execute();
    $paymentBreakdown = $paymentBreakdownStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $topProductsSql = "
        SELECT
            p.product_id,
            p.product_name,
            p.photo,
            p.price,
            IFNULL(SUM(si.quantity * COALESCE(si.unit_multiplier, 1)), 0) AS total_sold,
            IFNULL(SUM(si.quantity * si.unit_price), 0) AS total_revenue
        FROM sales s
        INNER JOIN sale_items si ON s.sale_id = si.sale_id
        INNER JOIN products p ON si.product_id = p.product_id
        {$whereSql}
        GROUP BY p.product_id, p.product_name, p.photo, p.price
        ORDER BY total_sold DESC, total_revenue DESC
        LIMIT 5
    ";
    $topProductsStmt = $conn->prepare($topProductsSql);
    foreach ($params as $key => $value) {
        $topProductsStmt->bindValue($key, $value);
    }
    $topProductsStmt->execute();
    $topProducts = $topProductsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $chartSql = "
        SELECT
            DATE(s.sale_date) AS chart_day,
            COUNT(*) AS sale_count,
            IFNULL(SUM(s.total_amount), 0) AS revenue_total
        FROM sales s
        {$whereSql}
        GROUP BY DATE(s.sale_date)
        ORDER BY chart_day ASC
    ";
    $chartStmt = $conn->prepare($chartSql);
    foreach ($params as $key => $value) {
        $chartStmt->bindValue($key, $value);
    }
    $chartStmt->execute();
    $chartRows = $chartStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="sales-report-' . date('Ymd-His') . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Sale ID', 'Date', 'Cashier', 'Payment Method', 'Item Lines', 'Items Sold', 'Total Amount']);

        $exportSql = "
            SELECT
                s.sale_id,
                s.sale_date,
                s.total_amount,
                s.payment_method,
                u.first_name,
                u.last_name,
                u.username,
                COUNT(si.sale_item_id) AS item_lines,
                IFNULL(SUM(si.quantity * COALESCE(si.unit_multiplier, 1)), 0) AS total_items
            FROM sales s
            LEFT JOIN users u ON s.user_id = u.user_id
            LEFT JOIN sale_items si ON s.sale_id = si.sale_id
            {$whereSql}
            GROUP BY
                s.sale_id, s.sale_date, s.total_amount, s.payment_method,
                u.first_name, u.last_name, u.username
            ORDER BY s.sale_date DESC, s.sale_id DESC
        ";
        $exportStmt = $conn->prepare($exportSql);
        foreach ($params as $key => $value) {
            $exportStmt->bindValue($key, $value);
        }
        $exportStmt->execute();

        while ($row = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
            $cashierName = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
            if ($cashierName === '') {
                $cashierName = (string) ($row['username'] ?? 'Unknown');
            }

            fputcsv($out, [
                $row['sale_id'],
                $row['sale_date'],
                $cashierName,
                $row['payment_method'],
                $row['item_lines'],
                $row['total_items'],
                $row['total_amount'],
            ]);
        }

        fclose($out);
        exit;
    }
} catch (Throwable $e) {
    error_log('[sales_report.php] ' . $e->getMessage());
    $errorMsg = 'Failed to load sales report.';
}

$chartDates = json_encode(array_column($chartRows, 'chart_day'));
$chartSales = json_encode(array_map('intval', array_column($chartRows, 'sale_count')));
$chartRevenue = json_encode(array_map('floatval', array_column($chartRows, 'revenue_total')));
$payLabels = json_encode(array_map(static fn($value): string => ucfirst((string) $value), array_column($paymentBreakdown, 'payment_method')));
$payTotals = json_encode(array_map('floatval', array_column($paymentBreakdown, 'total_amount')));
$pageTitle = 'Sales Report';
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
        <h1>Sales Report</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/inventory_system/index.php">Home</a></li>
                <li class="breadcrumb-item active">Sales Report</li>
            </ol>
        </nav>
    </div>

    <section class="section dashboard">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Filters <span>| Sales Range</span></h5>

                        <?php if ($errorMsg !== null): ?>
                            <div class="alert alert-danger"><?= e($errorMsg) ?></div>
                        <?php endif; ?>

                        <form method="GET" class="row g-3">
                            <div class="col-md-2">
                                <label class="form-label">From</label>
                                <input type="date" name="date_from" class="form-control" value="<?= e($dateFromRaw) ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">To</label>
                                <input type="date" name="date_to" class="form-control" value="<?= e($dateToRaw) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Payment Method</label>
                                <select name="payment_method" class="form-select">
                                    <option value="">All payment methods</option>
                                    <?php foreach ($paymentOptions as $option): ?>
                                        <option value="<?= e((string) $option) ?>" <?= $paymentMethod === (string) $option ? 'selected' : '' ?>>
                                            <?= e((string) $option) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Cashier</label>
                                <select name="cashier_id" class="form-select">
                                    <option value="0">All cashiers</option>
                                    <?php foreach ($cashiers as $cashier): ?>
                                        <?php
                                        $cashierName = trim((string) ($cashier['first_name'] ?? '') . ' ' . (string) ($cashier['last_name'] ?? ''));
                                        if ($cashierName === '') {
                                            $cashierName = (string) ($cashier['username'] ?? 'User #' . (int) $cashier['user_id']);
                                        }
                                        ?>
                                        <option value="<?= (int) $cashier['user_id'] ?>" <?= $cashierId === (int) $cashier['user_id'] ? 'selected' : '' ?>>
                                            <?= e($cashierName) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-1">
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
                                <a href="/inventory_system/reports/sales_report.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-xxl-3 col-md-6">
                <div class="card info-card sales-card">
                    <div class="card-body">
                        <h5 class="card-title">Transactions <span>| Filtered</span></h5>
                        <div class="d-flex align-items-center">
                            <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                                <i class="bi bi-receipt"></i>
                            </div>
                            <div class="ps-3">
                                <h6><?= number_format((int) ($summary['sale_count'] ?? 0)) ?></h6>
                                <span class="text-muted small pt-2">Completed sales</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xxl-3 col-md-6">
                <div class="card info-card revenue-card">
                    <div class="card-body">
                        <h5 class="card-title">Revenue <span>| Filtered</span></h5>
                        <div class="d-flex align-items-center">
                            <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                            <div class="ps-3">
                                <h6><?= e(formatMoney((float) ($summary['total_revenue'] ?? 0))) ?></h6>
                                <span class="text-muted small pt-2">Gross sales</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xxl-3 col-md-6">
                <div class="card info-card customers-card">
                    <div class="card-body">
                        <h5 class="card-title">Average Sale <span>| Filtered</span></h5>
                        <div class="d-flex align-items-center">
                            <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                                <i class="bi bi-graph-up"></i>
                            </div>
                            <div class="ps-3">
                                <h6><?= e(formatMoney((float) ($summary['average_sale'] ?? 0))) ?></h6>
                                <span class="text-muted small pt-2">Per transaction</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xxl-3 col-md-6">
                <div class="card info-card customers-card">
                    <div class="card-body">
                        <h5 class="card-title">Items Sold <span>| Filtered</span></h5>
                        <div class="d-flex align-items-center">
                            <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                                <i class="bi bi-box-seam"></i>
                            </div>
                            <div class="ps-3">
                                <h6><?= number_format((int) ($summary['items_sold'] ?? 0)) ?></h6>
                                <span class="text-muted small pt-2">Units sold</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Sales Trend <span>| Daily</span></h5>
                        <div id="salesReportChart"></div>

                        <script>
                        document.addEventListener('DOMContentLoaded', () => {
                            new ApexCharts(document.querySelector('#salesReportChart'), {
                                series: [
                                    { name: 'Sales', data: <?= $chartSales ?> },
                                    { name: 'Revenue', data: <?= $chartRevenue ?> }
                                ],
                                chart: {
                                    height: 350,
                                    type: 'area',
                                    toolbar: { show: false }
                                },
                                colors: ['#4154f1', '#2eca6a'],
                                fill: {
                                    type: 'gradient',
                                    gradient: { shadeIntensity: 1, opacityFrom: 0.25, opacityTo: 0.35, stops: [0, 90, 100] }
                                },
                                dataLabels: { enabled: false },
                                stroke: { curve: 'smooth', width: 2 },
                                markers: { size: 4 },
                                xaxis: {
                                    type: 'datetime',
                                    categories: <?= $chartDates ?>
                                },
                                tooltip: { x: { format: 'MMM dd, yyyy' } }
                            }).render();
                        });
                        </script>
                    </div>
                </div>

                <div class="card recent-sales overflow-auto">
                    <div class="card-body">
                        <h5 class="card-title">Recent Sales <span>| Filtered Results</span></h5>
                        <table class="table table-borderless datatable">
                            <thead>
                                <tr>
                                    <th scope="col">#</th>
                                    <th scope="col">Cashier</th>
                                    <th scope="col">Items</th>
                                    <th scope="col">Total</th>
                                    <th scope="col">Payment</th>
                                    <th scope="col">Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($sales)): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-3">No sales found for this filter.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($sales as $sale): ?>
                                        <?php
                                        $cashierName = trim((string) ($sale['first_name'] ?? '') . ' ' . (string) ($sale['last_name'] ?? ''));
                                        if ($cashierName === '') {
                                            $cashierName = (string) ($sale['username'] ?? 'Unknown');
                                        }
                                        ?>
                                        <tr>
                                            <th scope="row"><a href="#">#<?= (int) $sale['sale_id'] ?></a></th>
                                            <td><?= e($cashierName) ?></td>
                                            <td><?= (int) $sale['total_items'] ?> item<?= (int) $sale['total_items'] !== 1 ? 's' : '' ?></td>
                                            <td><?= e(formatMoney((float) $sale['total_amount'])) ?></td>
                                            <td><span class="badge <?= e(paymentBadgeClass((string) ($sale['payment_method'] ?? ''))) ?>"><?= e(ucfirst((string) ($sale['payment_method'] ?? 'N/A'))) ?></span></td>
                                            <td class="text-muted small"><?= e(date('M d, Y h:i A', strtotime((string) $sale['sale_date']))) ?></td>
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
                        <h5 class="card-title">Payment Methods <span>| Breakdown</span></h5>
                        <div id="salesPaymentChart" style="min-height: 320px;" class="echart"></div>

                        <script>
                        document.addEventListener('DOMContentLoaded', () => {
                            const labels = <?= $payLabels ?>;
                            const values = <?= $payTotals ?>;
                            const target = document.querySelector('#salesPaymentChart');

                            if (!labels.length) {
                                target.innerHTML = '<p class="text-center text-muted py-5">No payment data available.</p>';
                                return;
                            }

                            echarts.init(target).setOption({
                                tooltip: { trigger: 'item', formatter: '{b}: PHP {c} ({d}%)' },
                                legend: { top: '5%', left: 'center' },
                                series: [{
                                    name: 'Payment',
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

                <div class="card top-selling overflow-auto">
                    <div class="card-body pb-0">
                        <h5 class="card-title">Top Selling <span>| Top 5</span></h5>
                        <table class="table table-borderless">
                            <thead>
                                <tr>
                                    <th scope="col">Preview</th>
                                    <th scope="col">Product</th>
                                    <th scope="col">Price</th>
                                    <th scope="col">Sold</th>
                                    <th scope="col">Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($topProducts)): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-3">No product sales yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($topProducts as $product): ?>
                                        <tr>
                                            <th scope="row">
                                                <a href="#">
                                                    <img src="<?= e((string) ($product['photo'] ?: '/inventory_system/assets/uploads/products/images.jpeg')) ?>" alt="" style="width:50px; height:50px; object-fit:cover; border-radius:6px;">
                                                </a>
                                            </th>
                                            <td><a href="#" class="text-primary fw-bold"><?= e((string) $product['product_name']) ?></a></td>
                                            <td><?= e(formatMoney((float) ($product['price'] ?? 0))) ?></td>
                                            <td class="fw-bold"><?= number_format((int) ($product['total_sold'] ?? 0)) ?></td>
                                            <td><?= e(formatMoney((float) ($product['total_revenue'] ?? 0))) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
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
