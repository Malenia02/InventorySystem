<!DOCTYPE html>
<html lang="en">

<?php
require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/controllers/DashboardController.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /inventory_system/login.php');
    exit;
}

// ── Allowed periods ───────────────────────────────────────────
$allowedPeriods = ['today', 'month', 'year'];

function sanitizePeriod(string $key, string $default = 'today'): string {
    global $allowedPeriods;
    $val = $_GET[$key] ?? $default;
    return in_array($val, $allowedPeriods, true) ? $val : $default;
}

function periodLabel(string $period): string {
    return match($period) {
        'today' => 'Today',
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

// ── Per-card filter periods ───────────────────────────────────
$salesPeriod   = sanitizePeriod('sales_period',   'today');
$revPeriod     = sanitizePeriod('rev_period',     'month');
$reportPeriod  = sanitizePeriod('report_period',  'month');
$topPeriod     = sanitizePeriod('top_period',     'month');
$recentPeriod  = sanitizePeriod('recent_period',  'month');
$payPeriod     = sanitizePeriod('pay_period',     'month');

// ── Fetch all dashboard data ──────────────────────────────────
$salesData    = DashboardController::salesChange($conn, $salesPeriod);
$revenueData  = DashboardController::revenueChange($conn, $revPeriod);
$totalProds   = DashboardController::totalProducts($conn);
$outOfStock   = DashboardController::outOfStockCount($conn);
$lowStock     = DashboardController::lowStockProducts($conn, 5);
$topSelling   = DashboardController::topSellingProducts($conn, $topPeriod, 5);
$recentSales  = DashboardController::recentSales($conn, 10, $recentPeriod);
$chartData    = DashboardController::salesChartData($conn, $reportPeriod);
$activityLimit = min(20, max(6, (int)($_GET['activity_limit'] ?? 6)));
$recentStock  = DashboardController::recentStockActivity($conn, $activityLimit);
$payBreakdown = DashboardController::paymentBreakdown($conn, $payPeriod);

// Chart arrays for JS
$chartDates   = json_encode(array_column($chartData, 'day'));
$chartSales   = json_encode(array_map('intval',   array_column($chartData, 'sales_count')));
$chartRevenue = json_encode(array_map('floatval', array_column($chartData, 'revenue')));
$payLabels    = json_encode(array_map('ucfirst',  array_column($payBreakdown, 'payment_method')));
$payTotals    = json_encode(array_map('floatval', array_column($payBreakdown, 'total')));

require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/components/head.php';
?>

<body>

  <?php
    require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/components/header.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/components/sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/components/breadcrumb.php';
  ?>

    <section class="section dashboard">
      <div class="row">

        <!-- Left side columns -->
        <div class="col-lg-8">
          <div class="row">

            <!-- Sales Card -->
            <div class="col-xxl-4 col-md-6">
              <div class="card info-card sales-card">
                <div class="filter">
                  <a class="icon" href="#" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></a>
                  <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                    <li class="dropdown-header text-start"><h6>Filter</h6></li>
                    <li><a class="dropdown-item <?= $salesPeriod === 'today' ? 'active' : '' ?>" href="<?= filterUrl('sales_period', 'today') ?>">Today</a></li>
                    <li><a class="dropdown-item <?= $salesPeriod === 'month' ? 'active' : '' ?>" href="<?= filterUrl('sales_period', 'month') ?>">This Month</a></li>
                    <li><a class="dropdown-item <?= $salesPeriod === 'year'  ? 'active' : '' ?>" href="<?= filterUrl('sales_period', 'year') ?>">This Year</a></li>
                  </ul>
                </div>
                <div class="card-body">
                  <h5 class="card-title">Sales <span>| <?= periodLabel($salesPeriod) ?></span></h5>
                  <div class="d-flex align-items-center">
                    <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                      <i class="bi bi-cart"></i>
                    </div>
                    <div class="ps-3">
                      <h6><?= number_format($salesData['current']) ?></h6>
                      <?php if ($salesData['change'] > 0): ?>
                        <span class="<?= $salesData['direction'] === 'up' ? 'text-success' : 'text-danger' ?> small pt-1 fw-bold"><?= $salesData['change'] ?>%</span>
                        <span class="text-muted small pt-2 ps-1"><?= $salesData['direction'] === 'up' ? 'increase' : 'decrease' ?></span>
                      <?php else: ?>
                        <span class="text-muted small pt-2">No previous data</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            </div><!-- End Sales Card -->

            <!-- Revenue Card -->
            <div class="col-xxl-4 col-md-6">
              <div class="card info-card revenue-card">
                <div class="filter">
                  <a class="icon" href="#" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></a>
                  <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                    <li class="dropdown-header text-start"><h6>Filter</h6></li>
                    <li><a class="dropdown-item <?= $revPeriod === 'today' ? 'active' : '' ?>" href="<?= filterUrl('rev_period', 'today') ?>">Today</a></li>
                    <li><a class="dropdown-item <?= $revPeriod === 'month' ? 'active' : '' ?>" href="<?= filterUrl('rev_period', 'month') ?>">This Month</a></li>
                    <li><a class="dropdown-item <?= $revPeriod === 'year'  ? 'active' : '' ?>" href="<?= filterUrl('rev_period', 'year') ?>">This Year</a></li>
                  </ul>
                </div>
                <div class="card-body">
                  <h5 class="card-title">Revenue <span>| <?= periodLabel($revPeriod) ?></span></h5>
                  <div class="d-flex align-items-center">
                    <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                      <i class="bi bi-currency-dollar"></i>
                    </div>
                    <div class="ps-3">
                      <h6>₱<?= number_format($revenueData['current'], 2) ?></h6>
                      <?php if ($revenueData['change'] > 0): ?>
                        <span class="<?= $revenueData['direction'] === 'up' ? 'text-success' : 'text-danger' ?> small pt-1 fw-bold"><?= $revenueData['change'] ?>%</span>
                        <span class="text-muted small pt-2 ps-1"><?= $revenueData['direction'] === 'up' ? 'increase' : 'decrease' ?></span>
                      <?php else: ?>
                        <span class="text-muted small pt-2">No previous data</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            </div><!-- End Revenue Card -->

            <!-- Products Card -->
            <div class="col-xxl-4 col-xl-12">
              <div class="card info-card customers-card">
                <div class="card-body">
                  <h5 class="card-title">Products <span>| Active</span></h5>
                  <div class="d-flex align-items-center">
                    <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                      <i class="bi bi-box-seam"></i>
                    </div>
                    <div class="ps-3">
                      <h6><?= number_format($totalProds) ?></h6>
                      <?php if ($outOfStock > 0): ?>
                        <span class="text-danger small pt-1 fw-bold"><?= $outOfStock ?></span>
                        <span class="text-muted small pt-2 ps-1">out of stock</span>
                      <?php else: ?>
                        <span class="text-success small pt-2">All in stock</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            </div><!-- End Products Card -->

            <!-- Reports Chart -->
            <div class="col-12">
              <div class="card">
                <div class="filter">
                  <a class="icon" href="#" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></a>
                  <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                    <li class="dropdown-header text-start"><h6>Filter</h6></li>
                    <li><a class="dropdown-item <?= $reportPeriod === 'today' ? 'active' : '' ?>" href="<?= filterUrl('report_period', 'today') ?>">Today</a></li>
                    <li><a class="dropdown-item <?= $reportPeriod === 'month' ? 'active' : '' ?>" href="<?= filterUrl('report_period', 'month') ?>">This Month</a></li>
                    <li><a class="dropdown-item <?= $reportPeriod === 'year'  ? 'active' : '' ?>" href="<?= filterUrl('report_period', 'year') ?>">This Year</a></li>
                  </ul>
                </div>
                <div class="card-body">
                  <h5 class="card-title">Reports <span>| <?= periodLabel($reportPeriod) ?></span></h5>

                  <!-- Line Chart -->
                  <div id="reportsChart"></div>

                  <script>
                    document.addEventListener("DOMContentLoaded", () => {
                      new ApexCharts(document.querySelector("#reportsChart"), {
                        series: [
                          { name: 'Sales',   data: <?= $chartSales ?> },
                          { name: 'Revenue', data: <?= $chartRevenue ?> },
                        ],
                        chart: {
                          height: 350,
                          type: 'area',
                          toolbar: { show: false },
                        },
                        markers: { size: 4 },
                        colors: ['#4154f1', '#2eca6a'],
                        fill: {
                          type: "gradient",
                          gradient: { shadeIntensity: 1, opacityFrom: 0.3, opacityTo: 0.4, stops: [0, 90, 100] }
                        },
                        dataLabels: { enabled: false },
                        stroke: { curve: 'smooth', width: 2 },
                        xaxis: {
                          type: 'datetime',
                          categories: <?= $chartDates ?>,
                        },
                        tooltip: { x: { format: 'MMM dd, yyyy' } },
                      }).render();
                    });
                  </script>
                  <!-- End Line Chart -->

                </div>
              </div>
            </div><!-- End Reports -->

            <!-- Recent Sales -->
            <div class="col-12">
              <div class="card recent-sales overflow-auto">
                <div class="filter">
                  <a class="icon" href="#" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></a>
                  <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                    <li class="dropdown-header text-start"><h6>Filter</h6></li>
                    <li><a class="dropdown-item <?= $recentPeriod === 'today' ? 'active' : '' ?>" href="<?= filterUrl('recent_period', 'today') ?>">Today</a></li>
                    <li><a class="dropdown-item <?= $recentPeriod === 'month' ? 'active' : '' ?>" href="<?= filterUrl('recent_period', 'month') ?>">This Month</a></li>
                    <li><a class="dropdown-item <?= $recentPeriod === 'year'  ? 'active' : '' ?>" href="<?= filterUrl('recent_period', 'year') ?>">This Year</a></li>
                  </ul>
                </div>
                <div class="card-body">
                  <h5 class="card-title">Recent Sales <span>| <?= periodLabel($recentPeriod) ?></span></h5>

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
                      <?php if (empty($recentSales)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">No sales yet</td></tr>
                      <?php else: ?>
                        <?php foreach ($recentSales as $sale):
                          $cashier = trim(($sale['first_name'] ?? '') . ' ' . ($sale['last_name'] ?? '')) ?: '—';
                          $badges  = ['cash' => 'bg-success', 'card' => 'bg-primary', 'gcash' => 'bg-info', 'other' => 'bg-secondary'];
                          $badge   = $badges[$sale['payment_method']] ?? 'bg-secondary';
                        ?>
                          <tr>
                            <th scope="row"><a href="#">#<?= $sale['sale_id'] ?></a></th>
                            <td><?= htmlspecialchars($cashier) ?></td>
                            <td><?= $sale['item_count'] ?> item<?= $sale['item_count'] != 1 ? 's' : '' ?></td>
                            <td>₱<?= number_format($sale['total_amount'], 2) ?></td>
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

            <!-- Top Selling -->
            <div class="col-12">
              <div class="card top-selling overflow-auto">
                <div class="filter">
                  <a class="icon" href="#" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></a>
                  <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                    <li class="dropdown-header text-start"><h6>Filter</h6></li>
                    <li><a class="dropdown-item <?= $topPeriod === 'today' ? 'active' : '' ?>" href="<?= filterUrl('top_period', 'today') ?>">Today</a></li>
                    <li><a class="dropdown-item <?= $topPeriod === 'month' ? 'active' : '' ?>" href="<?= filterUrl('top_period', 'month') ?>">This Month</a></li>
                    <li><a class="dropdown-item <?= $topPeriod === 'year'  ? 'active' : '' ?>" href="<?= filterUrl('top_period', 'year') ?>">This Year</a></li>
                  </ul>
                </div>
                <div class="card-body pb-0">
                  <h5 class="card-title">Top Selling <span>| <?= periodLabel($topPeriod) ?></span></h5>

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
                      <?php if (empty($topSelling)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-3">No sales this month</td></tr>
                      <?php else: ?>
                        <?php foreach ($topSelling as $p): ?>
                          <tr>
                            <th scope="row">
                              <a href="#">
                                <img src="<?= htmlspecialchars($p['photo'] ?: '/inventory_system/assets/uploads/products/images.jpeg') ?>"
                                     alt="" style="width:50px; height:50px; object-fit:cover; border-radius:6px;">
                              </a>
                            </th>
                            <td><a href="#" class="text-primary fw-bold"><?= htmlspecialchars($p['product_name']) ?></a></td>
                            <td>₱<?= number_format($p['price'], 2) ?></td>
                            <td class="fw-bold"><?= number_format($p['total_sold']) ?></td>
                            <td>₱<?= number_format($p['total_revenue'], 2) ?></td>
                          </tr>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </tbody>
                  </table>

                </div>
              </div>
            </div><!-- End Top Selling -->

          </div>
        </div><!-- End Left side columns -->

        <!-- Right side columns -->
        <div class="col-lg-4">

          <!-- Recent Activity -->
          <div class="card">
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
              <h5 class="card-title">Recent Activity <span>| Stock</span></h5>

              <div class="activity">
                <?php if (empty($recentStock)): ?>
                  <p class="text-muted text-center">No recent activity</p>
                <?php else: ?>
                  <?php
                  $actionColors = ['sale' => 'text-primary', 'stock_in' => 'text-success', 'stock_out' => 'text-danger', 'manual_adjust' => 'text-warning'];
                  foreach ($recentStock as $log):
                    $color = $actionColors[$log['action']] ?? 'text-muted';
                    $qty   = $log['change_qty'] > 0 ? '+' . $log['change_qty'] : $log['change_qty'];
                    $time  = date('M d, h:i A', strtotime($log['timestamp']));
                    $by    = trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? '')) ?: 'System';
                  ?>
                    <div class="activity-item d-flex">
                      <div class="activite-label"><?= $time ?></div>
                      <i class="bi bi-circle-fill activity-badge <?= $color ?> align-self-start"></i>
                      <div class="activity-content">
                        <span class="fw-bold"><?= htmlspecialchars($log['product_name']) ?></span>
                        <span class="<?= $color ?>"><?= $qty ?></span>
                        <span class="text-muted">(<?= str_replace('_', ' ', $log['action']) ?>)</span><br>
                        <small class="text-muted">by <?= htmlspecialchars($by) ?></small>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>

            </div>
          </div><!-- End Recent Activity -->

          <!-- Payment Breakdown (replaces Budget Report) -->
          <div class="card">
            <div class="filter">
              <a class="icon" href="#" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></a>
              <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                <li class="dropdown-header text-start"><h6>Filter</h6></li>
                <li><a class="dropdown-item <?= $payPeriod === 'today' ? 'active' : '' ?>" href="<?= filterUrl('pay_period', 'today') ?>">Today</a></li>
                <li><a class="dropdown-item <?= $payPeriod === 'month' ? 'active' : '' ?>" href="<?= filterUrl('pay_period', 'month') ?>">This Month</a></li>
                <li><a class="dropdown-item <?= $payPeriod === 'year'  ? 'active' : '' ?>" href="<?= filterUrl('pay_period', 'year') ?>">This Year</a></li>
              </ul>
            </div>
            <div class="card-body pb-0">
              <h5 class="card-title">Payment Methods <span>| <?= periodLabel($payPeriod) ?></span></h5>

              <div id="paymentChart" style="min-height: 400px;" class="echart"></div>

              <script>
                document.addEventListener("DOMContentLoaded", () => {
                  const labels = <?= $payLabels ?>;
                  const values = <?= $payTotals ?>;

                  if (!labels.length) {
                    document.getElementById('paymentChart').innerHTML =
                      '<p class="text-center text-muted py-5">No sales this month</p>';
                    return;
                  }

                  echarts.init(document.querySelector("#paymentChart")).setOption({
                    tooltip: { trigger: 'item', formatter: '{b}: ₱{c} ({d}%)' },
                    legend: { top: '5%', left: 'center' },
                    series: [{
                      name: 'Payment',
                      type: 'pie',
                      radius: ['40%', '70%'],
                      avoidLabelOverlap: false,
                      label: { show: false, position: 'center' },
                      emphasis: { label: { show: true, fontSize: '18', fontWeight: 'bold' } },
                      labelLine: { show: false },
                      data: labels.map((l, i) => ({ name: l, value: values[i] }))
                    }]
                  });
                });
              </script>

            </div>
          </div><!-- End Payment Chart -->

          <!-- Low Stock Alert (replaces Website Traffic) -->
          <div class="card">
            <div class="card-body pb-0">
              <h5 class="card-title">
                Low Stock Alert
                <?php if ($outOfStock > 0): ?>
                  <span class="badge bg-danger ms-1"><?= $outOfStock ?> out</span>
                <?php endif; ?>
              </h5>

              <?php if (empty($lowStock)): ?>
                <p class="text-center text-muted py-3">
                  <i class="bi bi-check-circle text-success fs-4 d-block mb-2"></i>
                  All products are well stocked!
                </p>
              <?php else: ?>
                <table class="table table-sm table-borderless">
                  <thead>
                    <tr>
                      <th>Product</th>
                      <th class="text-center">Stock</th>
                      <th class="text-center">Min</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($lowStock as $p): ?>
                      <tr>
                        <td>
                          <small class="fw-bold d-block"><?= htmlspecialchars($p['product_name']) ?></small>
                          <small class="text-muted"><?= htmlspecialchars($p['category_name'] ?? '—') ?></small>
                        </td>
                        <td class="text-center">
                          <?php if ($p['quantity'] == 0): ?>
                            <span class="badge bg-danger">Out</span>
                          <?php else: ?>
                            <span class="badge bg-warning text-dark"><?= $p['quantity'] ?></span>
                          <?php endif; ?>
                        </td>
                        <td class="text-center text-muted small"><?= $p['reorder_level'] ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
                <a href="/inventory_system/products.php" class="btn btn-sm btn-outline-primary w-100 mb-3">
                  View All Products
                </a>
              <?php endif; ?>
            </div>
          </div><!-- End Low Stock Alert -->

        </div><!-- End Right side columns -->

      </div>
    </section>

  </main><!-- End #main -->

  <?php require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/components/footer.php'; ?>

  <a href="#" class="back-to-top d-flex align-items-center justify-content-center">
    <i class="bi bi-arrow-up-short"></i>
  </a>

  <!-- Vendor JS Files -->
  <script src="assets/vendor/apexcharts/apexcharts.min.js"></script>
  <script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="assets/vendor/chart.js/chart.umd.js"></script>
  <script src="assets/vendor/echarts/echarts.min.js"></script>
  <script src="assets/vendor/quill/quill.js"></script>
  <script src="assets/vendor/simple-datatables/simple-datatables.js"></script>
  <script src="assets/vendor/tinymce/tinymce.min.js"></script>
  <script src="assets/vendor/php-email-form/validate.js"></script>

  <!-- Template Main JS File -->
  <script src="assets/js/main.js"></script>

</body>

</html>