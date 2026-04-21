<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/DashboardController.php';

Middleware::auth()->role(['admin']);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

$reorderRows = DashboardController::chatbotReorderSuggestions($conn, 25);
$lowStockCount = DashboardController::lowStockCount($conn);
$reorderPieces = array_sum(array_map(static fn(array $row): int => (int) ($row['recommended_pieces'] ?? 0), $reorderRows));
$soonestCover = null;
foreach ($reorderRows as $row) {
    $cover = $row['cover_days'] ?? null;
    if ($cover === null) {
        continue;
    }
    $soonestCover = $soonestCover === null ? (float) $cover : min((float) $soonestCover, (float) $cover);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/../components/head.php'; ?>
    <title>Reorder Planner</title>
</head>
<body>
<?php
require __DIR__ . '/../components/header.php';
require __DIR__ . '/../components/sidebar.php';
?>

<main id="main" class="main">
    <div class="pagetitle">
        <h1>Reorder Planner</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/inventory_system/index.php">Home</a></li>
                <li class="breadcrumb-item active">Reorder Planner</li>
            </ol>
        </nav>
    </div>

    <section class="section dashboard">
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card info-card sales-card">
                    <div class="card-body">
                        <h5 class="card-title">Products to Replenish</h5>
                        <div class="d-flex align-items-center">
                            <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                                <i class="bi bi-box-seam"></i>
                            </div>
                            <div class="ps-3">
                                <h6><?= number_format((int) count($reorderRows)) ?></h6>
                                <span class="text-muted small pt-2">Low-stock and out-of-stock items</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card info-card revenue-card">
                    <div class="card-body">
                        <h5 class="card-title">Suggested Pieces</h5>
                        <div class="d-flex align-items-center">
                            <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                                <i class="bi bi-arrow-repeat"></i>
                            </div>
                            <div class="ps-3">
                                <h6><?= number_format($reorderPieces) ?></h6>
                                <span class="text-muted small pt-2">Pieces recommended across the list</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card info-card customers-card">
                    <div class="card-body">
                        <h5 class="card-title">Soonest Coverage</h5>
                        <div class="d-flex align-items-center">
                            <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                                <i class="bi bi-hourglass-split"></i>
                            </div>
                            <div class="ps-3">
                                <h6><?= $soonestCover !== null ? number_format((float) $soonestCover, 1) . 'd' : 'N/A' ?></h6>
                                <span class="text-muted small pt-2">Estimated days left for the most urgent item</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body">
                <h5 class="card-title">Reorder List</h5>

                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Category</th>
                                <th class="text-end">Current Stock</th>
                                <th class="text-end">Reorder Level</th>
                                <th class="text-end">Cover Days</th>
                                <th class="text-end">Suggested Pieces</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($reorderRows)): ?>
                                <?php foreach ($reorderRows as $row): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?= e((string) ($row['product_name'] ?? '')) ?></div>
                                            <div class="text-muted small"><?= e((string) ($row['category_name'] ?? 'Uncategorized')) ?></div>
                                        </td>
                                        <td><?= e((string) ($row['category_name'] ?? 'Uncategorized')) ?></td>
                                        <td class="text-end"><?= number_format((int) ($row['quantity'] ?? 0)) ?></td>
                                        <td class="text-end"><?= number_format((int) ($row['reorder_level'] ?? 0)) ?></td>
                                        <td class="text-end"><?= isset($row['cover_days']) && $row['cover_days'] !== null ? number_format((float) $row['cover_days'], 1) . 'd' : 'No sales' ?></td>
                                        <td class="text-end"><?= number_format((int) ($row['recommended_pieces'] ?? 0)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        No items currently need replenishment.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="row g-4 mt-1">
            <div class="col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title">What This Helps With</h5>
                        <ul class="small mb-0 ps-3">
                            <li>Prioritize products that are almost out of stock.</li>
                            <li>See suggested pieces instead of just raw stock counts.</li>
                            <li>Estimate how long the current stock will last.</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title">Quick Action</h5>
                        <div class="d-flex gap-2 flex-wrap">
                            <a href="/inventory_system/product_management/manage_product.php" class="btn btn-primary">Manage Products</a>
                            <a href="/inventory_system/admin/activity_log.php" class="btn btn-outline-secondary">Activity Log</a>
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
