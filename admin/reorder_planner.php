<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/DashboardController.php';

Middleware::auth()->role(['admin']);

$reorderRows = DashboardController::chatbotReorderSuggestions($conn, 50);
$lowStockCount = DashboardController::lowStockCount($conn);
$reorderPieces = array_sum(array_map(static fn(array $row): int => (int) ($row['recommended_pieces'] ?? 0), $reorderRows));
$soonestCover = null;

foreach ($reorderRows as &$row) {
    $cover = $row['cover_days'] ?? null;
    if ($cover !== null) {
        $cover = (float) $cover;
        $soonestCover = $soonestCover === null ? $cover : min($soonestCover, $cover);
    }

    $qty = (int) ($row['quantity'] ?? 0);
    $reorder = max(1, (int) ($row['reorder_level'] ?? 1));
    $coverageValue = $cover === null ? 0.0 : $cover;
    $gap = max(0, $reorder - $qty);
    $urgencyScore = $qty <= 0 ? 100 : max(5, min(95, (int) round(($gap / $reorder) * 65 + max(0, (7 - $coverageValue)) * 5)));
    $row['urgency_score'] = $urgencyScore;
    $row['urgency_band'] = $urgencyScore >= 70 ? 'critical' : ($urgencyScore >= 40 ? 'watch' : 'stable');
}
unset($row);

usort($reorderRows, static fn(array $a, array $b): int => ((int) ($b['urgency_score'] ?? 0)) <=> ((int) ($a['urgency_score'] ?? 0)));

$criticalCount = count(array_filter($reorderRows, static fn(array $row): bool => (string) ($row['urgency_band'] ?? '') === 'critical'));

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/../components/head.php'; ?>
    <title>Reorder Intelligence</title>
    <link rel="stylesheet" href="/inventory_system/assets/css/ops-suite.css">
</head>
<body>
<?php require __DIR__ . '/../components/header.php'; ?>
<?php require __DIR__ . '/../components/sidebar.php'; ?>

<main id="main" class="main ops-page">
    <section class="ops-hero">
        <div class="ops-hero__body">
            <div>
                <span class="ops-eyebrow">Stock Forecast</span>
                <h1 class="ops-title">Reorder Intelligence</h1>
                <p class="ops-copy">Prioritize low-stock products using urgency score, stock cover, and recommended replenishment pieces instead of relying on raw stock count alone.</p>
            </div>
            <div class="ops-hero__stats">
                <div class="ops-stat"><span>Products to replenish</span><strong><?= number_format(count($reorderRows)) ?></strong><small>active low-stock items</small></div>
                <div class="ops-stat"><span>Suggested pieces</span><strong id="reorderPiecesTotal"><?= number_format($reorderPieces) ?></strong><small>recommended total intake</small></div>
                <div class="ops-stat"><span>Soonest coverage</span><strong><?= $soonestCover !== null ? number_format($soonestCover, 1) . 'd' : 'N/A' ?></strong><small>most urgent item cover</small></div>
                <div class="ops-stat"><span>Critical now</span><strong id="reorderCriticalCount"><?= number_format($criticalCount) ?></strong><small>items with the highest urgency</small></div>
            </div>
        </div>
    </section>

    <section class="ops-filter-card mb-4">
        <div class="ops-filter-grid">
            <div class="is-wide">
                <label>Search product or category</label>
                <input type="search" id="reorderSearch" class="form-control" placeholder="Search reorder list">
            </div>
            <div>
                <label>Urgency</label>
                <select id="reorderUrgency" class="form-select">
                    <option value="all">All urgency levels</option>
                    <option value="critical">Critical</option>
                    <option value="watch">Watch list</option>
                    <option value="stable">Stable low stock</option>
                </select>
            </div>
            <div>
                <label>Coverage</label>
                <select id="reorderCoverage" class="form-select">
                    <option value="all">All coverage</option>
                    <option value="none">No recent sales</option>
                    <option value="lt2">Under 2 days</option>
                    <option value="lt7">Under 7 days</option>
                </select>
            </div>
        </div>
    </section>

    <section class="ops-table-card">
        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
            <div>
                <span class="ops-eyebrow">Priority List</span>
                <h2 class="h4 mb-1" style="color:#012970;font-weight:800;">Inventory Replenishment Queue</h2>
                <p class="ops-muted mb-0">Use the urgency score to decide which products should be purchased first.</p>
            </div>
            <div class="text-end">
                <div id="reorderMeta" class="ops-muted small">Showing all loaded suggestions.</div>
                <div id="reorderVisibleSummary" class="fw-bold" style="color:#012970;">Visible suggested intake: <?= number_format($reorderPieces) ?> pcs</div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table align-middle" id="reorderPlannerTable">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th class="text-end">Stock</th>
                        <th class="text-end">Reorder</th>
                        <th class="text-end">Cover Days</th>
                        <th class="text-end">Suggested</th>
                        <th class="text-end">Urgency</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reorderRows as $row): ?>
                        <?php $band = (string) ($row['urgency_band'] ?? 'watch'); ?>
                        <tr
                            data-search="<?= e(strtolower((string) ($row['product_name'] ?? '') . ' ' . (string) ($row['category_name'] ?? ''))) ?>"
                            data-urgency="<?= e($band) ?>"
                            data-cover="<?= e($row['cover_days'] === null ? 'none' : (string) $row['cover_days']) ?>"
                            data-score="<?= (int) ($row['urgency_score'] ?? 0) ?>"
                            data-suggested="<?= (int) ($row['recommended_pieces'] ?? 0) ?>"
                        >
                            <td><strong><?= e((string) ($row['product_name'] ?? '')) ?></strong></td>
                            <td><?= e((string) ($row['category_name'] ?? 'Uncategorized')) ?></td>
                            <td class="text-end"><?= number_format((int) ($row['quantity'] ?? 0)) ?></td>
                            <td class="text-end"><?= number_format((int) ($row['reorder_level'] ?? 0)) ?></td>
                            <td class="text-end"><?= $row['cover_days'] !== null ? number_format((float) $row['cover_days'], 1) . 'd' : 'No sales' ?></td>
                            <td class="text-end fw-bold"><?= number_format((int) ($row['recommended_pieces'] ?? 0)) ?></td>
                            <td class="text-end">
                                <div class="ops-priority">
                                    <span class="ops-status <?= e(match ($band) {
                                    'critical' => 'is-danger',
                                    'stable' => 'is-success',
                                    default => 'is-warning',
                                    }) ?>">
                                        <?= e(ucfirst($band)) ?> · <?= (int) ($row['urgency_score'] ?? 0) ?>
                                    </span>
                                    <div class="ops-progress mt-2">
                                        <span style="width: <?= max(6, min(100, (int) ($row['urgency_score'] ?? 0))) ?>%"></span>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="reorderEmpty" class="ops-empty d-none mt-3">No reorder suggestions match the current filters.</div>

        <div class="d-flex flex-wrap gap-2 mt-4">
            <a href="/inventory_system/admin/purchase_orders.php" class="btn btn-success">Create Purchase Orders</a>
            <a href="/inventory_system/product_management/manage_product.php" class="btn btn-outline-primary">Review Product Stock</a>
            <a href="/inventory_system/admin/purchase_receiving_history.php" class="btn btn-light border">Receiving History</a>
        </div>
    </section>
</main>

<?php require __DIR__ . '/../components/js_script.php'; ?>
<script src="/inventory_system/assets/js/reorder-planner.js"></script>
</body>
</html>
