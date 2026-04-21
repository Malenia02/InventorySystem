<!DOCTYPE html>
<html lang="en">

<?php
require_once __DIR__ . '/bootstrap/app.php';
require_once __DIR__ . '/middleware/Middleware.php';
require_once __DIR__ . '/controllers/DashboardController.php';

$pageTitle = 'Dashboard';

if (!isset($_SESSION['user_id'])) {
    header('Location: /inventory_system/login.php');
    exit;
}

$currentRole = strtolower(trim((string) ($_SESSION['role'] ?? '')));
$isAdmin = $currentRole === 'admin';
$isCashier = $currentRole === 'cashier';


$allowedPeriods = ['today', 'week', 'month', 'year'];

function sanitizePeriod(string $key, string $default = 'today'): string {
    global $allowedPeriods;
    $val = $_GET[$key] ?? $default;
    return in_array($val, $allowedPeriods, true) ? $val : $default;
}

function periodLabel(string $period): string {
    return match($period) {
        'today' => 'Today',
        'week' => 'This Week',
        'month' => 'This Month',
        'year'  => 'This Year',
        default => 'Today',
    };
}

function filterUrl(string $key, string $period): string {
    $params = $_GET;
    $params[$key] = $period;
    return '?' . http_build_query($params);
}

function formatTransactionNumber(int $saleId, string $saleDate): string {
    return 'SALE-' . date('Ymd', strtotime($saleDate)) . '-' . str_pad((string) $saleId, 6, '0', STR_PAD_LEFT);
}

function dashboardEscape(?string $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function dashboardMoneyKpiClass(string $formattedAmount): string
{
    $length = strlen($formattedAmount) + 1;

    if ($length >= 17) {
        return ' dashboard-kpi-value--money-compact';
    }

    if ($length >= 13) {
        return ' dashboard-kpi-value--money-tight';
    }

    return '';
}

// Ã¢â€â‚¬Ã¢â€â‚¬ Per-card filter periods Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
$salesPeriod   = sanitizePeriod('sales_period',   'today');
$revPeriod     = sanitizePeriod('rev_period',     'month');
$reportPeriod  = sanitizePeriod('report_period',  'month');
$recentPeriod  = sanitizePeriod('recent_period',  'month');
$payPeriod     = sanitizePeriod('pay_period',     'month');
$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
$chatbotCsrfToken = Middleware::generateCsrfToken();

// Ã¢â€â‚¬Ã¢â€â‚¬ Fetch all dashboard data Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬
$salesData    = DashboardController::salesChange($conn, $salesPeriod);
$revenueData  = DashboardController::revenueChange($conn, $revPeriod);
$revenueSalesCount = DashboardController::salesCount($conn, $revPeriod);
$lowStockCount = DashboardController::lowStockCount($conn);
$outOfStock   = DashboardController::outOfStockCount($conn);
$chartData    = DashboardController::salesChartData($conn, $reportPeriod);
$activityLimit = min(20, max(6, (int)($_GET['activity_limit'] ?? 6)));
$recentStock  = DashboardController::recentStockActivity($conn, $activityLimit, $isAdmin ? null : $sessionUserId);
$payBreakdown = DashboardController::paymentBreakdown($conn, $payPeriod);
$paymentLeader = DashboardController::topPaymentMethodSummary($conn, $payPeriod);
$reorderWatchlist = DashboardController::chatbotReorderSuggestions($conn, 5);
$slowMovingWatchlist = DashboardController::chatbotSlowMovingProducts($conn, 5);
$dormantProducts = DashboardController::noRecentSalesProducts($conn, 30, 5);
$dormantProductCount = DashboardController::noRecentSalesCount($conn, 30);
$cashierHighlights = DashboardController::cashierPerformance($conn, $salesPeriod, 5);
$cashierSalesCount = $isCashier ? DashboardController::userSalesCount($conn, $sessionUserId, $salesPeriod) : 0;
$cashierRevenue = $isCashier ? DashboardController::userRevenue($conn, $sessionUserId, $revPeriod) : 0.0;
$cashierItemsSold = $isCashier ? DashboardController::userItemsSold($conn, $sessionUserId, $salesPeriod) : 0;
$cashierRecentSales = $isCashier ? DashboardController::userRecentSales($conn, $sessionUserId, 10, $recentPeriod) : [];
$cashierChartData = $isCashier ? DashboardController::userSalesChartData($conn, $sessionUserId, $reportPeriod) : [];
$cashierPaymentBreakdown = $isCashier ? DashboardController::userPaymentBreakdown($conn, $sessionUserId, $payPeriod) : [];
$cashierPaymentLeader = $isCashier ? ($cashierPaymentBreakdown[0] ?? null) : null;
$cashierTodaySalesCount = $isCashier ? DashboardController::userSalesCount($conn, $sessionUserId, 'today') : 0;
$cashierTodayRevenue = $isCashier ? DashboardController::userRevenue($conn, $sessionUserId, 'today') : 0.0;
$cashierTodayItemsSold = $isCashier ? DashboardController::userItemsSold($conn, $sessionUserId, 'today') : 0;
$cashierLastSale = $isCashier ? ($cashierRecentSales[0] ?? null) : null;
$cashierAverageSale = $cashierTodaySalesCount > 0 ? round($cashierTodayRevenue / $cashierTodaySalesCount, 2) : 0.0;
$inventoryRiskCount = $lowStockCount + $outOfStock;
$averageSaleValue = $revenueSalesCount > 0 ? ((float) ($revenueData['current'] ?? 0) / $revenueSalesCount) : null;
$adminRevenueAmount = (float) ($revenueData['current'] ?? 0);
$cashierRevenueAmount = (float) $cashierRevenue;
$averageSaleAmount = (float) ($averageSaleValue ?? 0);
$adminRevenueKpiDisplay = number_format($adminRevenueAmount, 2);
$cashierRevenueKpiDisplay = number_format($cashierRevenueAmount, 2);
$averageSaleKpiDisplay = number_format($averageSaleAmount, 2);
$adminRevenueKpiClass = dashboardMoneyKpiClass($adminRevenueKpiDisplay);
$cashierRevenueKpiClass = dashboardMoneyKpiClass($cashierRevenueKpiDisplay);
$averageSaleKpiClass = dashboardMoneyKpiClass($averageSaleKpiDisplay);
$stockCoverageDays = null;
$stockCoverageLowCount = 0;

foreach ($reorderWatchlist as $item) {
    $qty = (int) ($item['quantity'] ?? 0);
    $cover = $item['cover_days'] ?? null;

    if ($qty === 0) {
        $stockCoverageLowCount++;
        $stockCoverageDays = $stockCoverageDays === null ? 0.0 : min($stockCoverageDays, 0.0);
    }

    if ($cover !== null) {
        $cover = (float) $cover;
        $stockCoverageDays = $stockCoverageDays === null ? $cover : min($stockCoverageDays, $cover);
    }
}

$stockCoverageValue = $stockCoverageDays === null ? 'N/A' : number_format($stockCoverageDays, 1) . 'd';
$stockCoverageNote = $stockCoverageDays === null
    ? (empty($reorderWatchlist) ? 'No items need replenishment right now' : 'No recent sales history in the watchlist')
    : ($stockCoverageLowCount > 0
        ? number_format($stockCoverageLowCount) . ' out-of-stock item(s) in watchlist'
        : 'Soonest depletion in the watchlist');
$chatbotRole = $isAdmin ? 'admin' : ($isCashier ? 'cashier' : 'staff');
$chatbotGreetingText = $isCashier
    ? 'Hello. Store Assistant is ready to help with your sales, shift summary, and recent transactions.'
    : 'Hello. Store Assistant is ready to help with your inventory and sales.';
$chatbotQuickQuestions = $isCashier ? [
    'My sales today' => 'My sales today',
    'My revenue today' => 'My revenue today',
    'My items sold today' => 'My items sold today',
    'My recent transactions' => 'My recent transactions',
    'My payment methods' => 'My payment methods',
    'My shift summary' => 'My shift summary',
    'My last receipt' => 'My last receipt',
    'What was my last transaction?' => 'What was my last transaction?',
] : [
    'Low stock' => 'What products are low in stock?',
    'Best sellers today' => 'What sold best today?',
    'Best sellers week' => 'What sold best this week?',
    'Revenue today' => 'What is the total revenue today?',
    'Revenue month' => 'What is the total revenue this month?',
    'Top revenue products' => 'What products earn the most revenue?',
    'No sales this month' => 'Which products have no sales this month?',
    'Reorder advice' => 'Which items should I reorder?',
    'Slow-moving' => 'Show me slow-moving products.',
    'Payments' => 'What payment method is used most this month?',
    'Top category' => 'What category sold best this month?',
    'Out of stock' => 'How many out-of-stock products are there?',
    'Last system error' => 'What was the last system error?',
    'Chatbot errors' => 'Show recent chatbot errors.',
];

// Chart arrays for JS
$chartDates   = json_encode(array_column($chartData, 'day'));
$chartSales   = json_encode(array_map('intval',   array_column($chartData, 'sales_count')));
$chartRevenue = json_encode(array_map('floatval', array_column($chartData, 'revenue')));
$payLabels    = json_encode(array_map('ucfirst',  array_column($payBreakdown, 'payment_method')));
$payTotals    = json_encode(array_map('floatval', array_column($payBreakdown, 'total')));
$cashierChartDates = json_encode(array_column($cashierChartData, 'day'));
$cashierChartSales = json_encode(array_map('intval', array_column($cashierChartData, 'sales_count')));
$cashierChartRevenue = json_encode(array_map('floatval', array_column($cashierChartData, 'revenue')));
$cashierPayLabels = json_encode(array_map('ucfirst', array_column($cashierPaymentBreakdown, 'payment_method')));
$cashierPayTotals = json_encode(array_map('floatval', array_column($cashierPaymentBreakdown, 'total')));

$dashboardState = [
    'isAdmin' => $isAdmin,
    'isCashier' => $isCashier,
    'salesPeriod' => $salesPeriod,
    'revPeriod' => $revPeriod,
    'reportPeriod' => $reportPeriod,
    'recentPeriod' => $recentPeriod,
    'payPeriod' => $payPeriod,
    'activityLimit' => $activityLimit,
    'salesData' => $salesData,
    'revenueData' => $revenueData,
    'lowStockCount' => $lowStockCount,
    'outOfStock' => $outOfStock,
    'chartDates' => array_column($chartData, 'day'),
    'chartSales' => array_map('intval', array_column($chartData, 'sales_count')),
    'chartRevenue' => array_map('floatval', array_column($chartData, 'revenue')),
    'payLabels' => array_map('ucfirst', array_column($payBreakdown, 'payment_method')),
    'payTotals' => array_map('floatval', array_column($payBreakdown, 'total')),
    'reorderWatchlist' => $reorderWatchlist,
    'slowMovingWatchlist' => $slowMovingWatchlist,
    'dormantProducts' => $dormantProducts,
    'dormantProductCount' => $dormantProductCount,
    'cashierHighlights' => $cashierHighlights,
    'cashierSalesCount' => $cashierSalesCount,
    'cashierRevenue' => $cashierRevenue,
    'cashierItemsSold' => $cashierItemsSold,
    'cashierRecentSales' => $cashierRecentSales,
    'cashierChartDates' => array_column($cashierChartData, 'day'),
    'cashierChartSales' => array_map('intval', array_column($cashierChartData, 'sales_count')),
    'cashierChartRevenue' => array_map('floatval', array_column($cashierChartData, 'revenue')),
    'cashierPayLabels' => array_map('ucfirst', array_column($cashierPaymentBreakdown, 'payment_method')),
    'cashierPayTotals' => array_map('floatval', array_column($cashierPaymentBreakdown, 'total')),
    'cashierPaymentLeader' => $cashierPaymentLeader,
    'cashierTodaySalesCount' => $cashierTodaySalesCount,
    'cashierTodayRevenue' => $cashierTodayRevenue,
    'cashierTodayItemsSold' => $cashierTodayItemsSold,
    'cashierLastSale' => $cashierLastSale,
    'cashierAverageSale' => $cashierAverageSale,
    'paymentLeader' => $paymentLeader,
];

require __DIR__ . '/components/head.php';
?>
<link rel="stylesheet" href="/inventory_system/assets/css/dashboard-chat.css">
<link rel="stylesheet" href="/inventory_system/assets/css/owner-command-center.css">
<link rel="stylesheet" href="/inventory_system/assets/css/dashboard-modern.css">
<script type="application/json" id="dashboardState"><?= htmlspecialchars(json_encode($dashboardState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_NOQUOTES, 'UTF-8') ?></script>

<body>

  <?php
    require __DIR__ . '/components/header.php';
    require __DIR__ . '/components/sidebar.php';
    require __DIR__ . '/components/breadcrumb.php';
  ?>

    <section class="section dashboard dashboard-modern">
      <div class="row">

        <!-- Left side columns -->
        <div class="col-lg-8 dashboard-main-column">
          <div class="row dashboard-kpi-row">

            <!-- Sales Card -->
            <div class="col-xxl-4 col-md-6 dashboard-kpi-col">
              <div class="card info-card sales-card dashboard-kpi-card dashboard-panel">
                <div class="card-body">
                  <h5 class="card-title dashboard-kpi-title">
                    <?= $isCashier ? 'My Sales' : 'Critical Items' ?>
                    <span>| <?= $isCashier ? periodLabel($salesPeriod) : 'Critical' ?></span>
                  </h5>
                  <div class="dashboard-kpi-content d-flex align-items-center">
                    <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                      <i class="bi <?= $isCashier ? 'bi-cart' : 'bi-exclamation-triangle' ?>"></i>
                    </div>
                    <div class="ps-3 dashboard-kpi-copy">
                      <?php if ($isCashier): ?>
                        <h6 class="dashboard-kpi-value" title="<?= dashboardEscape(number_format((int) $cashierSalesCount)) ?>"><?= number_format((int) $cashierSalesCount) ?></h6>
                        <span class="dashboard-kpi-note">Your completed transactions for <?= strtolower(periodLabel($salesPeriod)) ?></span>
                      <?php else: ?>
                        <h6 class="dashboard-kpi-value" title="<?= dashboardEscape(number_format((int) $inventoryRiskCount)) ?>"><?= number_format((int) $inventoryRiskCount) ?></h6>
                        <span class="dashboard-kpi-note">Low stock + out of stock items</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            </div><!-- End Sales Card -->

            <?php if ($isAdmin): ?>
            <!-- Revenue Card -->
            <div class="col-xxl-4 col-md-6 dashboard-kpi-col">
              <div class="card info-card revenue-card dashboard-kpi-card dashboard-kpi-card--money dashboard-panel">
                <div class="filter">
                  <a class="icon" href="#" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></a>
                  <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                    <li class="dropdown-header text-start"><h6>Filter</h6></li>
                    <li><a class="dropdown-item <?= $revPeriod === 'today' ? 'active' : '' ?>" href="<?= filterUrl('rev_period', 'today') ?>">Today</a></li>
                    <li><a class="dropdown-item <?= $revPeriod === 'week' ? 'active' : '' ?>" href="<?= filterUrl('rev_period', 'week') ?>">This Week</a></li>
                    <li><a class="dropdown-item <?= $revPeriod === 'month' ? 'active' : '' ?>" href="<?= filterUrl('rev_period', 'month') ?>">This Month</a></li>
                    <li><a class="dropdown-item <?= $revPeriod === 'year'  ? 'active' : '' ?>" href="<?= filterUrl('rev_period', 'year') ?>">This Year</a></li>
                  </ul>
                </div>
                <div class="card-body">
                  <h5 class="card-title dashboard-kpi-title">Revenue <span>| <?= periodLabel($revPeriod) ?></span></h5>
                  <div class="dashboard-kpi-content d-flex align-items-center">
                    <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                      <i class="bi bi-currency-dollar"></i>
                    </div>
                    <div class="ps-3 dashboard-kpi-copy">
                      <h6 class="dashboard-kpi-value dashboard-kpi-value--money<?= $adminRevenueKpiClass ?>" title="&#8369;<?= dashboardEscape($adminRevenueKpiDisplay) ?>">&#8369;<?= dashboardEscape($adminRevenueKpiDisplay) ?></h6>
                      <?php if ($revenueData['change'] > 0): ?>
                        <div class="dashboard-kpi-trend">
                          <span class="dashboard-kpi-trend-value <?= $revenueData['direction'] === 'up' ? 'text-success' : 'text-danger' ?>"><?= $revenueData['change'] ?>%</span>
                          <span class="dashboard-kpi-trend-label"><?= $revenueData['direction'] === 'up' ? 'increase' : 'decrease' ?></span>
                        </div>
                      <?php else: ?>
                        <span class="dashboard-kpi-note">No previous data</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            </div><!-- End Revenue Card -->
            <?php elseif ($isCashier): ?>
            <div class="col-xxl-4 col-md-6 dashboard-kpi-col">
              <div class="card info-card revenue-card dashboard-kpi-card dashboard-kpi-card--money dashboard-panel">
                <div class="filter">
                  <a class="icon" href="#" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></a>
                  <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                    <li class="dropdown-header text-start"><h6>Filter</h6></li>
                    <li><a class="dropdown-item <?= $revPeriod === 'today' ? 'active' : '' ?>" href="<?= filterUrl('rev_period', 'today') ?>">Today</a></li>
                    <li><a class="dropdown-item <?= $revPeriod === 'week' ? 'active' : '' ?>" href="<?= filterUrl('rev_period', 'week') ?>">This Week</a></li>
                    <li><a class="dropdown-item <?= $revPeriod === 'month' ? 'active' : '' ?>" href="<?= filterUrl('rev_period', 'month') ?>">This Month</a></li>
                    <li><a class="dropdown-item <?= $revPeriod === 'year' ? 'active' : '' ?>" href="<?= filterUrl('rev_period', 'year') ?>">This Year</a></li>
                  </ul>
                </div>
                <div class="card-body">
                  <h5 class="card-title dashboard-kpi-title">My Revenue <span>| <?= periodLabel($revPeriod) ?></span></h5>
                  <div class="dashboard-kpi-content d-flex align-items-center">
                    <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                      <i class="bi bi-currency-dollar"></i>
                    </div>
                    <div class="ps-3 dashboard-kpi-copy">
                      <h6 class="dashboard-kpi-value dashboard-kpi-value--money<?= $cashierRevenueKpiClass ?>" title="&#8369;<?= dashboardEscape($cashierRevenueKpiDisplay) ?>">&#8369;<?= dashboardEscape($cashierRevenueKpiDisplay) ?></h6>
                      <span class="dashboard-kpi-note">Processed by your account</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <?php else: ?>
            <!-- Out of Stock Card -->
            <div class="col-xxl-4 col-md-6 dashboard-kpi-col">
              <div class="card info-card revenue-card dashboard-kpi-card dashboard-panel">
                <div class="card-body">
                  <h5 class="card-title dashboard-kpi-title">Out of Stock <span>| Current</span></h5>
                  <div class="dashboard-kpi-content d-flex align-items-center">
                    <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                      <i class="bi bi-exclamation-octagon"></i>
                    </div>
                    <div class="ps-3 dashboard-kpi-copy">
                      <h6 class="dashboard-kpi-value" title="<?= dashboardEscape(number_format((int) $outOfStock)) ?>"><?= number_format((int) $outOfStock) ?></h6>
                      <span class="dashboard-kpi-note">Items needing replenishment</span>
                    </div>
                  </div>
                </div>
              </div>
            </div><!-- End Out of Stock Card -->
            <?php endif; ?>

            <!-- Quick Insight Card -->
            <div class="col-xxl-4 col-xl-12 dashboard-kpi-col">
              <div class="card info-card customers-card dashboard-kpi-card <?= $isCashier ? '' : 'dashboard-kpi-card--money' ?> dashboard-panel">
                <div class="card-body">
                  <h5 class="card-title dashboard-kpi-title">
                    <?= $isCashier ? 'Items Sold' : 'Average Sale' ?>
                    <span>| <?= $isCashier ? periodLabel($salesPeriod) : periodLabel($revPeriod) ?></span>
                  </h5>
                  <div class="dashboard-kpi-content d-flex align-items-center">
                    <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                      <i class="bi <?= $isCashier ? 'bi-bag-check' : 'bi-receipt-cutoff' ?>"></i>
                    </div>
                    <div class="ps-3 dashboard-kpi-copy">
                      <?php if ($isCashier): ?>
                        <h6 class="dashboard-kpi-value" title="<?= dashboardEscape(number_format((int) $cashierItemsSold)) ?>"><?= number_format((int) $cashierItemsSold) ?></h6>
                        <span class="dashboard-kpi-note">Piece-equivalent items sold by you</span>
                      <?php else: ?>
                        <h6 class="dashboard-kpi-value dashboard-kpi-value--money<?= $averageSaleKpiClass ?>" title="&#8369;<?= dashboardEscape($averageSaleKpiDisplay) ?>">&#8369;<?= dashboardEscape($averageSaleKpiDisplay) ?></h6>
                        <span class="dashboard-kpi-note">
                          <?= number_format((int) $revenueSalesCount) ?> sales in <?= strtolower(periodLabel($revPeriod)) ?>
                        </span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            </div><!-- End Quick Insight Card -->

            <?php if ($isAdmin) include __DIR__ . '/components/owner_command_center_v2.php'; ?>
            <?php if ($isAdmin || $isCashier): ?>
            <!-- Trend Snapshot -->
            <div class="col-12">
              <div class="card dashboard-panel dashboard-chart-panel">
                <div class="filter">
                  <a class="icon" href="#" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></a>
                  <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                    <li class="dropdown-header text-start"><h6>Filter</h6></li>
                    <li><a class="dropdown-item <?= $reportPeriod === 'today' ? 'active' : '' ?>" href="<?= filterUrl('report_period', 'today') ?>">Today</a></li>
                    <li><a class="dropdown-item <?= $reportPeriod === 'week' ? 'active' : '' ?>" href="<?= filterUrl('report_period', 'week') ?>">This Week</a></li>
                    <li><a class="dropdown-item <?= $reportPeriod === 'month' ? 'active' : '' ?>" href="<?= filterUrl('report_period', 'month') ?>">This Month</a></li>
                    <li><a class="dropdown-item <?= $reportPeriod === 'year'  ? 'active' : '' ?>" href="<?= filterUrl('report_period', 'year') ?>">This Year</a></li>
                  </ul>
                </div>
                <div class="card-body">
                  <h5 class="card-title dashboard-panel-title"><?= $isCashier ? 'My Sales Trend' : 'Quick Trend' ?> <span>| <?= periodLabel($reportPeriod) ?></span></h5>

                  <div id="reportsChart"></div>

                </div>
              </div>
            </div><!-- End Reports -->
            <?php endif; ?>

            <?php if ($isCashier): ?>
            <!-- My Recent Sales -->
            <div class="col-12">
              <div class="card recent-sales overflow-auto dashboard-panel">
                <div class="filter">
                  <a class="icon" href="#" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></a>
                  <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                    <li class="dropdown-header text-start"><h6>Filter</h6></li>
                    <li><a class="dropdown-item <?= $recentPeriod === 'today' ? 'active' : '' ?>" href="<?= filterUrl('recent_period', 'today') ?>">Today</a></li>
                    <li><a class="dropdown-item <?= $recentPeriod === 'week' ? 'active' : '' ?>" href="<?= filterUrl('recent_period', 'week') ?>">This Week</a></li>
                    <li><a class="dropdown-item <?= $recentPeriod === 'month' ? 'active' : '' ?>" href="<?= filterUrl('recent_period', 'month') ?>">This Month</a></li>
                    <li><a class="dropdown-item <?= $recentPeriod === 'year'  ? 'active' : '' ?>" href="<?= filterUrl('recent_period', 'year') ?>">This Year</a></li>
                  </ul>
                </div>
                <div class="card-body">
                  <h5 class="card-title dashboard-panel-title">My Recent Sales <span>| <?= periodLabel($recentPeriod) ?></span></h5>

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
                      <?php $visibleRecentSales = $cashierRecentSales; ?>
                      <?php if (empty($visibleRecentSales)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">No sales yet</td></tr>
                      <?php else: ?>
                        <?php foreach ($visibleRecentSales as $sale):
                          $cashier = $isCashier
                            ? 'You'
                            : (trim(($sale['first_name'] ?? '') . ' ' . ($sale['last_name'] ?? '')) ?: '-');
                          $badges  = ['cash' => 'bg-success', 'card' => 'bg-primary', 'gcash' => 'bg-info', 'other' => 'bg-secondary'];
                          $badge   = $badges[$sale['payment_method']] ?? 'bg-secondary';
                        ?>
                          <tr>
                            <th scope="row"><a href="#"><?= htmlspecialchars(formatTransactionNumber((int) $sale['sale_id'], (string) $sale['sale_date'])) ?></a></th>
                            <td><?= htmlspecialchars($cashier) ?></td>
                            <td><?= $sale['item_count'] ?> item<?= $sale['item_count'] != 1 ? 's' : '' ?></td>
                            <td>&#8369;<?= number_format((float)($sale['total_amount'] ?? 0), 2) ?></td>
                            <td><span class="badge <?= $badge ?>"><?= ucfirst($sale['payment_method']) ?></span></td>
                            <td class="text-muted small"><?= date('M d, Y h:i A', strtotime($sale['sale_date'])) ?></td>
                          </tr>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </tbody>
                  </table>

                </div>
              </div>
            </div><!-- End Recent Sales -->
            <?php endif; ?>

          </div>
        </div><!-- End Left side columns -->

        <!-- Right side columns -->
        <div class="col-lg-4 dashboard-side-column">

          <?php if ($isCashier): ?>
          <div class="card dashboard-panel dashboard-shift-panel">
            <div class="card-body">
              <h5 class="card-title dashboard-panel-title">Shift Snapshot <span>| Today</span></h5>
              <div class="d-flex flex-column gap-3">
                <div class="border rounded-4 p-3">
                  <small class="text-muted d-block mb-1">Transactions completed</small>
                  <div class="fw-semibold fs-5"><?= number_format((int) $cashierTodaySalesCount) ?></div>
                </div>
                <div class="border rounded-4 p-3">
                  <small class="text-muted d-block mb-1">Revenue processed</small>
                  <div class="fw-semibold fs-5">&#8369;<?= number_format((float) $cashierTodayRevenue, 2) ?></div>
                </div>
                <div class="border rounded-4 p-3">
                  <small class="text-muted d-block mb-1">Average ticket</small>
                  <div class="fw-semibold fs-5">&#8369;<?= number_format((float) $cashierAverageSale, 2) ?></div>
                </div>
                <div class="border rounded-4 p-3">
                  <small class="text-muted d-block mb-1">Last transaction</small>
                  <div class="fw-semibold"><?= $cashierLastSale ? htmlspecialchars(formatTransactionNumber((int) $cashierLastSale['sale_id'], (string) $cashierLastSale['sale_date'])) : 'No sale yet' ?></div>
                  <small class="text-muted"><?= $cashierLastSale ? htmlspecialchars(date('M d, Y h:i A', strtotime((string) $cashierLastSale['sale_date']))) : 'Start selling to generate activity' ?></small>
                </div>
                <div class="d-grid gap-2">
                  <a href="/inventory_system/product_management/pos.php" class="btn btn-primary btn-sm">Open POS</a>
                  <a href="/inventory_system/profile.php" class="btn btn-outline-secondary btn-sm">My Profile</a>
                </div>
              </div>
            </div>
          </div>
          <?php else: ?>
          <!-- Alert Stream -->
          <div class="card dashboard-panel dashboard-alert-card">
            <div class="filter">
              <a class="icon" href="#" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></a>
              <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                <li class="dropdown-header text-start"><h6>Filter</h6></li>
                <li><a class="dropdown-item" href="<?= filterUrl('activity_limit', '6') ?>">Last 6</a></li>
                <li><a class="dropdown-item" href="<?= filterUrl('activity_limit', '12') ?>">Last 12</a></li>
                <li><a class="dropdown-item" href="<?= filterUrl('activity_limit', '20') ?>">Last 20</a></li>
              </ul>
            </div>
            <div class="card-body">
              <h5 class="card-title dashboard-panel-title">Alert Stream <span>| Alerts</span></h5>

              <div class="dashboard-alert-stream">
                <?php if (empty($recentStock)): ?>
                  <p class="dashboard-alert-empty">No recent activity</p>
                <?php else: ?>
                  <?php
                  $actionMetaMap = [
                    'sale' => ['item' => 'is-sale', 'qty' => 'text-primary', 'label' => 'Sale'],
                    'stock_in' => ['item' => 'is-stock-in', 'qty' => 'text-success', 'label' => 'Stock In'],
                    'stock_out' => ['item' => 'is-stock-out', 'qty' => 'text-danger', 'label' => 'Stock Out'],
                    'manual_adjust' => ['item' => 'is-adjustment', 'qty' => 'text-warning', 'label' => 'Adjustment'],
                  ];
                  foreach ($recentStock as $log):
                    $meta = $actionMetaMap[$log['action']] ?? ['item' => 'is-neutral', 'qty' => 'text-muted', 'label' => ucwords(str_replace('_', ' ', (string) $log['action']))];
                    $qty = (int) ($log['change_qty'] ?? 0);
                    $qtyText = $qty > 0 ? '+' . $qty : (string) $qty;
                    $time = strtotime((string) $log['timestamp']);
                    $by = trim(((string) ($log['first_name'] ?? '')) . ' ' . ((string) ($log['last_name'] ?? '')));
                    if ($by === '') {
                      $by = 'System';
                    }
                  ?>
                    <article class="dashboard-alert-item <?= $meta['item'] ?>">
                      <div class="dashboard-alert-time">
                        <span><?= date('M d', $time) ?></span>
                        <strong><?= date('h:i A', $time) ?></strong>
                      </div>
                      <div class="dashboard-alert-body">
                        <div class="dashboard-alert-top">
                          <p class="dashboard-alert-product" title="<?= dashboardEscape((string) ($log['product_name'] ?? '')) ?>">
                            <?= dashboardEscape((string) ($log['product_name'] ?? '')) ?>
                          </p>
                          <span class="dashboard-alert-qty <?= $meta['qty'] ?>"><?= dashboardEscape($qtyText) ?></span>
                        </div>
                        <div class="dashboard-alert-meta">
                          <span class="dashboard-alert-chip"><?= dashboardEscape($meta['label']) ?></span>
                          <span>Current stock <?= number_format((int) ($log['current_qty'] ?? 0)) ?></span>
                          <span>by <?= dashboardEscape($by) ?></span>
                        </div>
                      </div>
                    </article>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>

            </div>
          </div><!-- End Recent Activity -->
          <?php endif; ?>

          <?php if ($isAdmin || $isCashier): ?>
          <!-- Payment Snapshot -->
          <div class="card dashboard-panel dashboard-payment-panel">
            <div class="filter">
              <a class="icon" href="#" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></a>
              <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                <li class="dropdown-header text-start"><h6>Filter</h6></li>
                    <li><a class="dropdown-item <?= $payPeriod === 'today' ? 'active' : '' ?>" href="<?= filterUrl('pay_period', 'today') ?>">Today</a></li>
                    <li><a class="dropdown-item <?= $payPeriod === 'week' ? 'active' : '' ?>" href="<?= filterUrl('pay_period', 'week') ?>">This Week</a></li>
                    <li><a class="dropdown-item <?= $payPeriod === 'month' ? 'active' : '' ?>" href="<?= filterUrl('pay_period', 'month') ?>">This Month</a></li>
                    <li><a class="dropdown-item <?= $payPeriod === 'year'  ? 'active' : '' ?>" href="<?= filterUrl('pay_period', 'year') ?>">This Year</a></li>
              </ul>
            </div>
            <div class="card-body pb-0">
              <h5 class="card-title dashboard-panel-title"><?= $isCashier ? 'My Payment Snapshot' : 'Payment Snapshot' ?> <span>| <?= periodLabel($payPeriod) ?></span></h5>

              <div id="paymentChart" style="min-height: 400px;" class="echart"></div>

              <?php if ($isCashier && $cashierPaymentLeader !== null): ?>
                <div class="pt-3 border-top mt-3">
                  <small class="text-muted d-block mb-1">Most used payment method</small>
                  <div class="fw-semibold">
                    <?= htmlspecialchars(ucfirst((string) ($cashierPaymentLeader['payment_method'] ?? 'Unknown'))) ?>
                    <span class="text-muted fw-normal">&middot; &#8369;<?= number_format((float) ($cashierPaymentLeader['total'] ?? 0), 2) ?></span>
                  </div>
                </div>
              <?php endif; ?>

            </div>
          </div><!-- End Payment Chart -->
          <?php endif; ?>

        </div><!-- End Right side columns -->

      </div>
    </section>

  </main><!-- End #main -->

  <?php require __DIR__ . '/components/footer.php'; ?>

  <a href="#" class="back-to-top d-flex align-items-center justify-content-center">
    <i class="bi bi-arrow-up-short"></i>
  </a>

  <?php if ($isAdmin || $isCashier): ?>
 
  <!-- Ã¢â€â‚¬Ã¢â€â‚¬ Chatbot launcher Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ -->
  <button
    type="button"
    class="dashboard-chatbot-launcher"
    id="dashboardChatbotLauncher"
    aria-label="Open store assistant"
    aria-expanded="false"
  >
    <i class="bi bi-robot"></i>
    <span class="dashboard-chatbot-launcher-dot" aria-hidden="true"></span>
  </button>
 
  <!-- Ã¢â€â‚¬Ã¢â€â‚¬ Chatbot popup Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ -->
  <div
    class="dashboard-chatbot-popup"
    id="dashboardChatbot"
    role="dialog"
    aria-label="Store assistant"
    aria-hidden="true"
    data-user-role="<?= htmlspecialchars($chatbotRole, ENT_QUOTES, 'UTF-8') ?>"
    data-csrf-token="<?= htmlspecialchars($chatbotCsrfToken, ENT_QUOTES, 'UTF-8') ?>"
  >
 
    <!-- Header -->
    <div class="dashboard-chatbot-topbar">
      <div class="dashboard-chatbot-title">
        <div class="dashboard-chatbot-title-icon">
          <i class="bi bi-robot"></i>
        </div>
        <div class="dashboard-chatbot-title-text">
          <div class="dashboard-chatbot-title-line">
            <strong>StockWise AI</strong>
            <div class="dashboard-chatbot-speaking" id="dashboardChatbotSpeaking" aria-live="polite" hidden>
              <span class="dashboard-chatbot-speaking-dot" aria-hidden="true"></span>
              <span class="dashboard-chatbot-speaking-text">Speaking</span>
            </div>
          </div>
          <span>Inventory & Sales Intelligence</span>
        </div>
      </div>
      <div class="dashboard-chatbot-topbar-actions">
        <button type="button" id="dashboardChatbotVoice" aria-label="Toggle voice replies" title="Toggle voice replies">
          <i class="bi bi-volume-up-fill"></i>
        </button>
        <button type="button" id="dashboardChatbotMinimize" aria-label="Minimize">
          <i class="bi bi-dash-lg"></i>
        </button>
        <button type="button" id="dashboardChatbotClose" aria-label="Close">
          <i class="bi bi-x-lg"></i>
        </button>
      </div>
    </div>
 
    <!-- Shell -->
    <div class="dashboard-chatbot-shell">
 
      <!-- Quick-action pills -->
      <div class="dashboard-chatbot-quick" role="toolbar" aria-label="Quick questions">
        <?php foreach ($chatbotQuickQuestions as $label => $question): ?>
          <button type="button" data-chat-question="<?= htmlspecialchars($question, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></button>
        <?php endforeach; ?>
      </div>
 
      <!-- Messages -->
      <div
        class="dashboard-chatbot-messages"
        id="dashboardChatbotMessages"
        role="log"
        aria-live="polite"
        aria-label="Conversation"
      >
        <div class="chatbot-message bot">
          <div class="chatbot-bubble chatbot-bubble-muted" id="dashboardChatbotGreeting">
            <?= htmlspecialchars($chatbotGreetingText, ENT_QUOTES, 'UTF-8') ?>
          </div>
        </div>
      </div>
 
      <!-- Input -->
      <div class="dashboard-chatbot-input-area">
        <form class="dashboard-chatbot-form" id="dashboardChatbotForm" autocomplete="off">
          <input
            type="text"
            class="form-control"
            id="dashboardChatbotInput"
            placeholder="Ask anything about your inventory..."
            aria-label="Ask the store assistant"
          >
          <button type="submit" class="btn btn-primary" aria-label="Send">
            <i class="bi bi-send-fill" style="font-size:.8rem;"></i>
            Send
          </button>
        </form>
      </div>
 
    </div><!-- /.dashboard-chatbot-shell -->
 
    <!-- Footer -->
    <div class="dashboard-chatbot-footer">
      Powered by <a href="#" tabindex="-1">StockWise AI</a>
    </div>
 
  </div><!-- /#dashboardChatbot -->
 
  <?php endif; ?>
 
  <?php require __DIR__ . '/components/js_script.php'; ?>
  <script src="/inventory_system/assets/js/dashboard-dynamic.js"></script>
  <?php if ($isAdmin || $isCashier): ?>
  <script src="/inventory_system/assets/js/dashboard-chatbot.js"></script>
  <?php endif; ?>

</body>

</html>
