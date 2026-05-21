<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/ProductController.php';

Middleware::auth()->role(['admin']);

$products = ProductController::barcodeLabelProducts($conn);
$skuCounts = [];
foreach ($products as $product) {
    $sku = trim((string) ($product['sku'] ?? ''));
    if ($sku !== '') {
        $skuCounts[strtolower($sku)] = ($skuCounts[strtolower($sku)] ?? 0) + 1;
    }
}

$duplicateSkuRows = array_values(array_filter($products, static function (array $product) use ($skuCounts): bool {
    $sku = strtolower(trim((string) ($product['sku'] ?? '')));
    return $sku !== '' && ($skuCounts[$sku] ?? 0) > 1;
}));

$missingSkuRows = array_values(array_filter($products, static fn(array $product): bool => trim((string) ($product['sku'] ?? '')) === ''));

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/../components/head.php'; ?>
    <title>Barcode Labels</title>
    <link rel="stylesheet" href="/inventory_system/assets/css/ops-suite.css">
</head>
<body>
<?php require __DIR__ . '/../components/header.php'; ?>
<?php require __DIR__ . '/../components/sidebar.php'; ?>

<main id="main" class="main ops-page">
    <section class="ops-hero">
        <div class="ops-hero__body">
            <div>
                <span class="ops-eyebrow">Barcode Workflow</span>
                <h1 class="ops-title">Barcode Labels</h1>
                <p class="ops-copy">Check SKU readiness, catch duplicate barcode values, and print simple product labels for POS scanning.</p>
            </div>
            <div class="ops-hero__stats">
                <div class="ops-stat">
                    <span>Products loaded</span>
                    <strong id="barcodeVisibleCount"><?= number_format(count($products)) ?></strong>
                    <small>catalog rows in this tool</small>
                </div>
                <div class="ops-stat">
                    <span>Duplicate SKUs</span>
                    <strong id="barcodeDuplicateCount"><?= number_format(count($duplicateSkuRows)) ?></strong>
                    <small>should be fixed before printing</small>
                </div>
                <div class="ops-stat">
                    <span>Missing SKUs</span>
                    <strong id="barcodeMissingCount"><?= number_format(count($missingSkuRows)) ?></strong>
                    <small>cannot scan without SKU/barcode</small>
                </div>
                <div class="ops-stat">
                    <span>Selected labels</span>
                    <strong id="barcodeSelectedCount">0</strong>
                    <small>ready for print preview</small>
                </div>
            </div>
        </div>
    </section>

    <section class="ops-filter-card mb-4">
        <div class="ops-filter-grid">
            <div class="is-wide">
                <label>Search product, SKU, category, or supplier</label>
                <input type="search" id="barcodeSearch" class="form-control" placeholder="Search labels">
            </div>
            <div>
                <label>Status</label>
                <select id="barcodeStatus" class="form-select">
                    <option value="all">All products</option>
                    <option value="ready">Ready to print</option>
                    <option value="duplicate">Duplicate SKU</option>
                    <option value="missing">Missing SKU</option>
                </select>
            </div>
            <div>
                <label>Labels per product</label>
                <input type="number" id="barcodeCopies" class="form-control" min="1" max="24" value="1">
            </div>
            <div class="d-flex align-items-end gap-2">
                <button type="button" class="btn btn-outline-primary w-100" id="barcodeSelectVisible">Select Visible</button>
                <button type="button" class="btn btn-primary w-100" id="barcodePrintSelected">Print Labels</button>
            </div>
        </div>
    </section>

    <section class="ops-table-card">
        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
            <div>
                <span class="ops-eyebrow">Label Queue</span>
                <h2 class="h4 mb-1" style="color:#012970;font-weight:800;">SKU and barcode readiness</h2>
                <p class="ops-muted mb-0">Use the checkbox column to choose labels, then print. Duplicate or missing SKU rows are highlighted.</p>
            </div>
            <div id="barcodeMeta" class="ops-muted small">Showing all products.</div>
        </div>

        <div class="table-responsive">
            <table class="table align-middle" id="barcodeLabelTable">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="barcodeToggleAll" aria-label="Select all visible labels"></th>
                        <th>Product</th>
                        <th>SKU / Barcode</th>
                        <th>Category</th>
                        <th>Supplier</th>
                        <th class="text-end">Stock</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <?php
                        $sku = trim((string) ($product['sku'] ?? ''));
                        $skuKey = strtolower($sku);
                        $status = $sku === '' ? 'missing' : (($skuCounts[$skuKey] ?? 0) > 1 ? 'duplicate' : 'ready');
                        $search = strtolower(implode(' ', [
                            (string) ($product['product_name'] ?? ''),
                            $sku,
                            (string) ($product['category_name'] ?? ''),
                            (string) ($product['supplier_name'] ?? ''),
                        ]));
                        ?>
                        <tr
                            data-search="<?= e($search) ?>"
                            data-status="<?= e($status) ?>"
                            data-product-name="<?= e((string) ($product['product_name'] ?? 'Product')) ?>"
                            data-sku="<?= e($sku) ?>"
                            data-price="<?= e(number_format((float) ($product['price'] ?? 0), 2)) ?>"
                        >
                            <td>
                                <input type="checkbox" class="barcode-label-check" <?= $status === 'ready' ? '' : 'disabled' ?> aria-label="Select <?= e((string) ($product['product_name'] ?? 'Product')) ?>">
                            </td>
                            <td>
                                <strong><?= e((string) ($product['product_name'] ?? 'Product')) ?></strong>
                                <div class="small text-muted"><?= e((string) ($product['status'] ?? 'active')) ?></div>
                            </td>
                            <td><code><?= $sku !== '' ? e($sku) : 'No SKU' ?></code></td>
                            <td><?= e((string) ($product['category_name'] ?? 'Uncategorized')) ?></td>
                            <td><?= e((string) ($product['supplier_name'] ?? '-')) ?></td>
                            <td class="text-end"><?= number_format((int) ($product['quantity'] ?? 0)) ?></td>
                            <td>
                                <span class="ops-status <?= $status === 'ready' ? 'is-success' : ($status === 'duplicate' ? 'is-danger' : 'is-warning') ?>">
                                    <?= e(ucfirst($status)) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="barcodeEmpty" class="ops-empty d-none mt-3">No products match the current barcode filters.</div>
    </section>
</main>

<?php require __DIR__ . '/../components/js_script.php'; ?>
<script src="/inventory_system/assets/js/barcode-labels.js"></script>
</body>
</html>
