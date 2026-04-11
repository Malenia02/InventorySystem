<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/CategoryController.php';
require_once __DIR__ . '/../controllers/SubcategoryController.php';
require_once __DIR__ . '/../controllers/SupplierController.php';

Middleware::auth()->role(['admin']);

$csrfToken = Middleware::generateCsrfToken();
$categories = CategoryController::all($conn);
$subcategories = SubcategoryController::all($conn);
$suppliers = SupplierController::all($conn);

ob_start();
foreach ($categories as $category) {
    ?>
    <option value="<?= (int) ($category['category_id'] ?? 0) ?>">
        <?= htmlspecialchars((string) ($category['category_name'] ?? '-')) ?>
    </option>
    <?php
}
$categoryOptions = trim((string) ob_get_clean());

ob_start();
foreach ($suppliers as $supplier) {
    ?>
    <option value="<?= (int) ($supplier['supplier_id'] ?? 0) ?>">
        <?= htmlspecialchars((string) ($supplier['supplier_name'] ?? '-')) ?>
    </option>
    <?php
}
$supplierOptions = trim((string) ob_get_clean());

ob_start();
?>
<option value="">No subcategory</option>
<?php
foreach ($subcategories as $subcategory) {
    ?>
    <option
        value="<?= (int) ($subcategory['subcategory_id'] ?? 0) ?>"
        data-category-id="<?= (int) ($subcategory['category_id'] ?? 0) ?>"
    >
        <?= htmlspecialchars((string) ($subcategory['subcategory_name'] ?? '-')) ?>
    </option>
    <?php
}
$subcategoryOptions = trim((string) ob_get_clean());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/../components/head.php'; ?>
    <title>Bulk Create Products</title>
    <style>
        .bulk-builder-hero {
            border: 1px solid #dde7f5;
            border-radius: 1.2rem;
            background: linear-gradient(135deg, #f6faff 0%, #eef3ff 100%);
            padding: 1.5rem;
        }

        .bulk-builder-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.8fr) minmax(280px, .9fr);
            gap: 1.25rem;
        }

        .bulk-builder-stack {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .bulk-product-card {
            border: 1px solid #e8edf4;
            border-radius: 1.1rem;
            overflow: hidden;
            box-shadow: 0 .45rem 1.2rem rgba(31, 48, 78, .06);
        }

        .bulk-product-card .card-header {
            background: #f8fbff;
            border-bottom: 1px solid #e8edf4;
            padding: 1rem 1.25rem;
        }

        .bulk-product-card .card-body {
            padding: 1.25rem;
        }

        .bulk-product-preview {
            width: 100%;
            max-width: 220px;
            height: 220px;
            object-fit: cover;
            border-radius: 1rem;
            border: 1px solid #dde3ec;
            background: #fff;
        }

        .bulk-side-note {
            border: 1px solid #e8edf4;
            border-radius: 1rem;
            background: #fff;
            padding: 1rem 1.1rem;
        }

        .bulk-side-note h6 {
            font-weight: 700;
            margin-bottom: .65rem;
        }

        .bulk-action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .4rem;
            min-height: 40px;
            padding: .5rem .9rem;
            border-radius: .8rem;
            font-size: .92rem;
            font-weight: 600;
            line-height: 1;
            box-shadow: none !important;
        }

        .bulk-action-btn i {
            font-size: .95rem;
        }

        .bulk-primary-btn {
            background: #2563eb;
            border-color: #2563eb;
        }

        .bulk-primary-btn:hover,
        .bulk-primary-btn:focus {
            background: #1d4ed8;
            border-color: #1d4ed8;
        }

        .bulk-success-btn {
            background: #198754;
            border-color: #198754;
        }

        .bulk-success-btn:hover,
        .bulk-success-btn:focus {
            background: #157347;
            border-color: #157347;
        }

        .bulk-soft-btn {
            background: #fff;
            border: 1px solid #d8e1ee;
            color: #334155;
        }

        .bulk-soft-btn:hover,
        .bulk-soft-btn:focus {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #1e293b;
        }

        .bulk-outline-btn {
            border-width: 1px;
            color: #2563eb;
            border-color: #bfd2ff;
            background: #fff;
        }

        .bulk-outline-btn:hover,
        .bulk-outline-btn:focus {
            background: #eff6ff;
            color: #1d4ed8;
            border-color: #93c5fd;
        }

        .bulk-remove-btn {
            min-height: 34px;
            padding: .42rem .75rem;
            border-radius: .7rem;
            font-size: .84rem;
            font-weight: 600;
        }

        .bulk-result-table td,
        .bulk-result-table th {
            vertical-align: top;
        }

        @media (max-width: 991.98px) {
            .bulk-builder-layout {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php
    require __DIR__ . '/../components/header.php';
    require __DIR__ . '/../components/sidebar.php';
    require __DIR__ . '/../components/breadcrumb.php';
    ?>

    <main class="main">
        <section class="section">
            <div class="card">
                <div class="card-body">
                    <div class="bulk-builder-hero mt-4 mb-4">
                        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                            <div>
                                <h5 class="card-title mb-2">Bulk Create Products</h5>
                                <p class="text-muted mb-0">Add multiple product forms on one page, fill them out like the normal product modal, then save everything in one submit.</p>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <a href="/inventory_system/product_management/manage_product.php" class="btn bulk-action-btn bulk-soft-btn">
                                    <i class="bi bi-arrow-left me-1"></i>Back to Products
                                </a>
                            </div>
                        </div>
                    </div>

                    <form id="bulkUploadProductsForm" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                        <div class="bulk-builder-layout">
                            <div>
                                <div id="bulkProductCards" class="bulk-builder-stack"></div>

                                <div class="d-flex flex-wrap gap-2 mt-3">
                                    <button type="button" class="btn bulk-action-btn bulk-outline-btn" id="addBulkProductCardBtnBottom">
                                        <i class="bi bi-plus-lg me-1"></i>Add Another Product
                                    </button>
                                    <button type="submit" class="btn bulk-action-btn bulk-success-btn" id="bulkUploadSubmitBtn">
                                        <i class="bi bi-check2-circle me-1"></i>Save All Products
                                    </button>
                                </div>

                                <div id="bulkUploadResults" class="mt-4"></div>
                            </div>

                            <div class="bulk-builder-stack">
                                <div class="bulk-side-note">
                                    <h6>How this page works</h6>
                                    <p class="text-muted mb-2">Each card is one product. Fill out as many as you need, then submit them together.</p>
                                    <p class="text-muted mb-2">If one product has an issue, the other valid products can still be saved.</p>
                                    <p class="text-muted mb-0">Photos are optional and can be uploaded per product card.</p>
                                </div>

                                <div class="bulk-side-note">
                                    <h6>Helpful Tips</h6>
                                    <p class="text-muted mb-2">Use unique SKUs to avoid duplicate errors.</p>
                                    <p class="text-muted mb-2">Leave sale price blank if the product is not on sale.</p>
                                    <p class="text-muted mb-0">Status defaults to inactive, or choose active before saving.</p>
                                </div>

                                <div class="bulk-side-note">
                                    <h6>Piece, Box, and Case Guide</h6>
                                    <p class="text-muted mb-2"><strong>Piece</strong> is the smallest selling unit, like one bottle, one sachet, or one pack.</p>
                                    <p class="text-muted mb-2"><strong>Box</strong> is a grouped pack of pieces. Example: if 1 box contains 12 bottles, set <strong>Pieces per Box</strong> to <strong>12</strong>.</p>
                                    <p class="text-muted mb-2"><strong>Case</strong> is a larger grouped pack. Example: if 1 case contains 24 pieces, set the case quantity using your product's packaging values and add the <strong>Case Price</strong>.</p>
                                    <p class="text-muted mb-2 mb-lg-2">Example setup: Piece = 1 bottle, Box = 12 bottles, Case = 24 bottles.</p>
                                    <p class="text-muted mb-0">If a product does not use boxes, leave <strong>Box Price</strong> blank. For beverage categories, the system uses <strong>Piece</strong> and <strong>Case</strong> in POS.</p>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </section>
    </main>

    <template id="bulkProductCardTemplate">
        <div class="card bulk-product-card" data-card-index="__INDEX__">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h6 class="mb-1 fw-bold">Product <span class="bulk-product-number">__NUMBER__</span></h6>
                    <small class="text-muted">Complete this form just like the normal add-product modal.</small>
                </div>
                <button type="button" class="btn btn-outline-danger bulk-remove-btn remove-bulk-card-btn">
                    <i class="bi bi-trash"></i> Remove
                </button>
            </div>
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-lg-4">
                        <div class="text-center border rounded-4 bg-light p-3 h-100">
                            <h6 class="fw-semibold text-start mb-3">Product Photo</h6>
                            <img src="/inventory_system/assets/img/card.jpg" alt="Preview" class="bulk-product-preview bulk-photo-preview">
                            <div class="mt-3">
                                <input type="file" class="form-control bulk-photo-input" name="photo___INDEX__" accept="image/*">
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-8">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label fw-semibold">Product Name</label>
                                <input type="text" class="form-control" name="products[__INDEX__][product_name]" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">SKU / Barcode</label>
                                <input type="text" class="form-control" name="products[__INDEX__][sku]" placeholder="Optional">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Category</label>
                                <select class="form-select bulk-category-select" name="products[__INDEX__][category_id]" required>
                                    <?= $categoryOptions ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Subcategory</label>
                                <select class="form-select bulk-subcategory-select" name="products[__INDEX__][subcategory_id]">
                                    <?= $subcategoryOptions ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Supplier</label>
                                <select class="form-select" name="products[__INDEX__][supplier_id]">
                                    <option value="">Select supplier</option>
                                    <?= $supplierOptions ?>
                                </select>
                            </div>
                            <div class="col-12 bulk-unit-note d-none" data-unit-note="beverage">
                                <div class="alert alert-warning border small mb-0">
                                    Beverage items use <strong>Piece</strong> and <strong>Case</strong> in POS. Box selling fields are disabled for this category.
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Piece Price</label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" class="form-control" name="products[__INDEX__][price]" step="0.01" min="0" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Pieces per Box</label>
                                <input type="number" class="form-control" name="products[__INDEX__][pieces_per_box]" value="1" min="1" required>
                            </div>
                            <div class="col-md-4 bulk-box-field">
                                <label class="form-label fw-semibold">Box Price</label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" class="form-control" name="products[__INDEX__][box_price]" step="0.01" min="0">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Boxes per Case</label>
                                <input type="number" class="form-control" name="products[__INDEX__][boxes_per_case]" value="1" min="1" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Case Price</label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" class="form-control" name="products[__INDEX__][case_price]" step="0.01" min="0">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Piece Discount %</label>
                                <div class="input-group">
                                    <span class="input-group-text">%</span>
                                    <input type="number" class="form-control" name="products[__INDEX__][sale_price]" step="0.01" min="0" max="100">
                                </div>
                            </div>
                            <div class="col-md-4 bulk-box-field">
                                <label class="form-label fw-semibold">Box Discount %</label>
                                <div class="input-group">
                                    <span class="input-group-text">%</span>
                                    <input type="number" class="form-control" name="products[__INDEX__][box_sale_price]" step="0.01" min="0" max="100">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Case Discount %</label>
                                <div class="input-group">
                                    <span class="input-group-text">%</span>
                                    <input type="number" class="form-control" name="products[__INDEX__][case_sale_price]" step="0.01" min="0" max="100">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Initial Quantity (Pieces)</label>
                                <input type="number" class="form-control" name="products[__INDEX__][initial_quantity]" value="0" min="0" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Reorder Level</label>
                                <input type="number" class="form-control" name="products[__INDEX__][reorder_level]" value="5" min="0" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Vatable?</label>
                                <select class="form-select" name="products[__INDEX__][vatable]" required>
                                    <option value="1">Yes</option>
                                    <option value="0">No</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Status</label>
                                <select class="form-select" name="products[__INDEX__][status]" required>
                                    <option value="inactive">Inactive</option>
                                    <option value="active">Active</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>

    <?php require __DIR__ . '/../components/js_script.php'; ?>
    <script src="/inventory_system/assets/js/bulk_upload_products.js"></script>
</body>
</html>
