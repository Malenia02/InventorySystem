<?php
/**
 * manage_product.php
 * Admin-only page for managing products.
 */
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/ProductController.php';
require_once __DIR__ . '/../controllers/CategoryController.php';
require_once __DIR__ . '/../controllers/SubcategoryController.php';
require_once __DIR__ . '/../controllers/SupplierController.php';
require_once __DIR__ . '/../controllers/StockAdjustmentController.php';

Middleware::auth()->role('admin');

$csrf_token = Middleware::generateCsrfToken();

$categories = CategoryController::all($conn);
$subcategories = SubcategoryController::all($conn);
$suppliers  = SupplierController::all($conn);
$pendingStockRequests = StockAdjustmentController::pendingCount($conn);

$productFilters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'status' => strtolower(trim((string) ($_GET['status'] ?? 'all'))),
    'category_id' => (int) ($_GET['category_id'] ?? 0),
    'supplier_id' => (int) ($_GET['supplier_id'] ?? 0),
    'page' => max(1, (int) ($_GET['page'] ?? 1)),
    'per_page' => (int) ($_GET['per_page'] ?? 25),
];
$productPage = ProductController::paginate($conn, $productFilters);
$products = $productPage['items'];
$productFilters = [
    'search' => (string) $productPage['search'],
    'status' => (string) $productPage['status'],
    'category_id' => (int) $productPage['category_id'],
    'supplier_id' => (int) $productPage['supplier_id'],
    'page' => (int) $productPage['page'],
    'per_page' => (int) $productPage['per_page'],
];
$productRowStart = $productPage['total'] > 0 ? (($productPage['page'] - 1) * $productPage['per_page']) + 1 : 0;

function productListUrl(array $filters, array $overrides = []): string
{
    $params = array_merge($filters, $overrides);

    if (($params['page'] ?? 1) <= 1) {
        unset($params['page']);
    }
    if (($params['status'] ?? 'all') === 'all') {
        unset($params['status']);
    }
    if (($params['category_id'] ?? 0) <= 0) {
        unset($params['category_id']);
    }
    if (($params['supplier_id'] ?? 0) <= 0) {
        unset($params['supplier_id']);
    }
    if (($params['search'] ?? '') === '') {
        unset($params['search']);
    }
    if (($params['per_page'] ?? 25) === 25) {
        unset($params['per_page']);
    }

    $query = http_build_query($params);
    return '/inventory_system/product_management/manage_product.php' . ($query !== '' ? '?' . $query : '');
}

function renderSubcategoryOptions(array $subcategories): string
{
    $html = '<option value="">No subcategory</option>';

    foreach ($subcategories as $subcategory) {
        $html .= sprintf(
            '<option value="%d" data-category-id="%d">%s</option>',
            (int) ($subcategory['subcategory_id'] ?? 0),
            (int) ($subcategory['category_id'] ?? 0),
            htmlspecialchars((string) ($subcategory['subcategory_name'] ?? '-'), ENT_QUOTES, 'UTF-8')
        );
    }

    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php require __DIR__ . '/../components/head.php'; ?>
    <title>Manage Products</title>
    <style>
        .modal-message-center {
            text-align: center;
            font-weight: 500;
            margin-bottom: 12px;
        }

        .modal-modern .modal-content {
            border: 0;
            border-radius: 1rem;
            overflow: hidden;
            box-shadow: 0 1rem 3rem rgba(0,0,0,.18);
        }

        .modal-modern .modal-header {
            border-bottom: 0;
            padding: 1rem 1.5rem;
        }

        .modal-modern .modal-body {
            padding: 1.5rem;
        }

        .modal-modern .modal-footer {
            border-top: 0;
            padding: 1rem 1.5rem 1.5rem;
        }

        .modal-modern .form-label {
            font-weight: 600;
            margin-bottom: .45rem;
            color: #495057;
        }

        .modal-modern .form-control,
        .modal-modern .form-select,
        .modal-modern .input-group-text {
            border-radius: .75rem;
        }

        .modal-modern .modal-section-title {
            font-size: .95rem;
            font-weight: 700;
            color: #6c757d;
            border-bottom: 1px solid #e9ecef;
            padding-bottom: .5rem;
            margin-bottom: .75rem;
        }

        .modal-modern .modal-side-card {
            border: 1px solid #e9ecef;
            background: #f8f9fa;
            border-radius: 1rem;
            padding: 1rem;
            height: 100%;
        }

        .modal-modern .preview-image {
            width: 100%;
            max-width: 260px;
            height: 260px;
            object-fit: cover;
            border-radius: 1rem;
            border: 1px solid #dee2e6;
            box-shadow: 0 .25rem .75rem rgba(0,0,0,.08);
        }

        .modal-modern .compact-preview {
            width: 100%;
            height: 140px;
            object-fit: cover;
            border-radius: .75rem;
            border: 1px solid #dee2e6;
        }

        .modal-modern .btn {
            border-radius: .75rem;
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
            <div class="row">
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">Product List</h5>

                            <div id="productMessages"></div>

                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <button type="button" class="btn btn-primary"
                                    data-bs-toggle="modal" data-bs-target="#addProductModal">
                                    <i class="bi bi-plus-circle"></i> Add New Product
                                </button>
                                <a href="/inventory_system/stock_adjustment_requests.php" class="btn btn-light border position-relative">
                                    <i class="bi bi-clipboard-check me-1"></i> Stock Requests
                                    <?php if ($pendingStockRequests > 0): ?>
                                        <span class="badge bg-warning text-dark ms-2"><?= (int) $pendingStockRequests ?></span>
                                    <?php endif; ?>
                                </a>
                                <a href="/inventory_system/product_management/bulk_upload_products.php" class="btn btn-outline-primary">
                                    <i class="bi bi-upload"></i> Bulk Create
                                </a>
                            </div>

                            <form method="get" class="row g-3 align-items-end mb-3">
                                <div class="col-lg-4">
                                    <label class="form-label">Search</label>
                                    <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($productFilters['search'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Product, SKU, category, supplier">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="all" <?= $productFilters['status'] === 'all' ? 'selected' : '' ?>>All</option>
                                        <option value="active" <?= $productFilters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                        <option value="inactive" <?= $productFilters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Category</label>
                                    <select name="category_id" class="form-select">
                                        <option value="0">All</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?= (int) $cat['category_id'] ?>" <?= $productFilters['category_id'] === (int) $cat['category_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars((string) $cat['category_name'], ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Supplier</label>
                                    <select name="supplier_id" class="form-select">
                                        <option value="0">All</option>
                                        <?php foreach ($suppliers as $sup): ?>
                                            <option value="<?= (int) $sup['supplier_id'] ?>" <?= $productFilters['supplier_id'] === (int) $sup['supplier_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars((string) $sup['supplier_name'], ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-1">
                                    <label class="form-label">Per page</label>
                                    <select name="per_page" class="form-select">
                                        <?php foreach ([10, 25, 50, 100] as $size): ?>
                                            <option value="<?= $size ?>" <?= $productFilters['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-1 d-flex gap-2">
                                    <button type="submit" class="btn btn-primary w-100">Apply</button>
                                </div>
                            </form>

                            <div class="table-responsive" style="max-height:500px; overflow-y:auto;">
                                <table id="productsTable" class="table table-striped table-bordered">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Photo</th>
                                            <th>Name</th>
                                            <th>Category</th>
                                            <th>Subcategory</th>
                                            <th>Supplier</th>
                                            <th>SKU</th>
                                            <th>Quantity</th>
                                            <th>Price</th>
                                            <th>Discounts</th>
                                            <th>Vatable</th>
                                            <th>Reorder Level</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($products as $index => $p):
                                            $photo    = !empty($p['photo']) ? htmlspecialchars($p['photo']) : '/inventory_system/assets/img/card.jpg';
                                            $status   = $p['status'] ?? 'inactive';
                                            $isActive = $status === 'active';
                                            $discountParts = [];
                                            if (!empty($p['sale_price'])) $discountParts[] = 'Piece: ' . rtrim(rtrim(number_format((float)$p['sale_price'], 2), '0'), '.') . '%';
                                            if (!empty($p['box_sale_price'])) $discountParts[] = 'Box: ' . rtrim(rtrim(number_format((float)$p['box_sale_price'], 2), '0'), '.') . '%';
                                            if (!empty($p['case_sale_price'])) $discountParts[] = 'Case: ' . rtrim(rtrim(number_format((float)$p['case_sale_price'], 2), '0'), '.') . '%';
                                        ?>
                                            <tr id="productRow<?= (int)$p['product_id'] ?>">
                                                <td><?= $productRowStart + $index ?></td>
                                                <td class="text-center">
                                                    <img src="<?= $photo ?>" alt="Photo"
                                                        style="width:50px;height:50px;object-fit:cover;">
                                                </td>
                                                <td><?= htmlspecialchars($p['product_name'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars($p['category_name'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars($p['subcategory_name'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars($p['supplier_name'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars($p['sku'] ?? '-') ?></td>
                                                <td class="product-quantity"><?= (int)($p['quantity'] ?? 0) ?></td>
                                                <td>₱<?= number_format((float)($p['price'] ?? 0), 2) ?></td>
                                                <td><?= $discountParts !== [] ? htmlspecialchars(implode(' | ', $discountParts)) : '-' ?></td>
                                                <td><?= !empty($p['vatable']) ? 'Yes' : 'No' ?></td>
                                                <td><?= (int)($p['reorder_level'] ?? 5) ?></td>
                                                <td>
                                                    <span class="badge <?= $isActive ? 'bg-success' : 'bg-secondary' ?>">
                                                        <?= ucfirst($status) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="d-flex gap-2 justify-content-center">

                                                        <button class="btn btn-sm btn-warning editProductBtn"
                                                            data-id="<?= (int)$p['product_id'] ?>"
                                                            data-name="<?= htmlspecialchars($p['product_name'] ?? '') ?>"
                                                            data-category="<?= (int)($p['category_id'] ?? 0) ?>"
                                                            data-subcategory="<?= (int)($p['subcategory_id'] ?? 0) ?>"
                                                            data-supplier="<?= (int)($p['supplier_id'] ?? 0) ?>"
                                                            data-sku="<?= htmlspecialchars($p['sku'] ?? '') ?>"
                                                            data-price="<?= (float)($p['price'] ?? 0) ?>"
                                                            data-box_price="<?= htmlspecialchars((string)($p['box_price'] ?? '')) ?>"
                                                            data-case_price="<?= htmlspecialchars((string)($p['case_price'] ?? '')) ?>"
                                                            data-sale_price="<?= htmlspecialchars((string)($p['sale_price'] ?? '')) ?>"
                                                            data-box_sale_price="<?= htmlspecialchars((string)($p['box_sale_price'] ?? '')) ?>"
                                                            data-case_sale_price="<?= htmlspecialchars((string)($p['case_sale_price'] ?? '')) ?>"
                                                            data-vatable="<?= (int)($p['vatable'] ?? 0) ?>"
                                                            data-pieces_per_box="<?= (int)($p['pieces_per_box'] ?? 1) ?>"
                                                            data-boxes_per_case="<?= (int)($p['boxes_per_case'] ?? 1) ?>"
                                                            data-reorder="<?= (int)($p['reorder_level'] ?? 5) ?>"
                                                            data-photo="<?= $photo ?>"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#editProductModal">
                                                            <i class="bi bi-pencil-square"></i>
                                                        </button>

                                                        <button class="btn btn-success btn-sm restock-btn"
                                                            data-id="<?= (int)$p['product_id'] ?>"
                                                            data-name="<?= htmlspecialchars($p['product_name'] ?? '') ?>">
                                                            <i class="bi bi-box-arrow-in-down"></i>
                                                        </button>

                                                        <button class="btn btn-secondary btn-sm stockout-btn"
                                                            data-id="<?= (int)$p['product_id'] ?>"
                                                            data-name="<?= htmlspecialchars($p['product_name'] ?? '') ?>">
                                                            <i class="bi bi-box-arrow-up"></i>
                                                        </button>

                                                        <button class="btn btn-sm <?= $isActive ? 'btn-danger' : 'btn-success' ?> toggleProductStatusBtn"
                                                            data-id="<?= (int)$p['product_id'] ?>"
                                                            data-name="<?= htmlspecialchars($p['product_name'] ?? '') ?>"
                                                            data-status="<?= $status ?>">
                                                            <i class="bi <?= $isActive ? 'bi-slash-circle' : 'bi-check-circle' ?>"></i>
                                                        </button>

                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mt-3">
                                <div class="text-muted small">
                                    <?php if ($productPage['total'] > 0): ?>
                                        Showing <?= $productRowStart ?> to <?= min($productRowStart + count($products) - 1, $productPage['total']) ?> of <?= $productPage['total'] ?> products
                                    <?php else: ?>
                                        No products found
                                    <?php endif; ?>
                                </div>

                                <nav aria-label="Products pagination">
                                    <ul class="pagination pagination-sm mb-0">
                                        <li class="page-item <?= $productPage['page'] <= 1 ? 'disabled' : '' ?>">
                                            <a class="page-link" href="<?= htmlspecialchars(productListUrl($productFilters, ['page' => $productPage['page'] - 1]), ENT_QUOTES, 'UTF-8') ?>">Previous</a>
                                        </li>
                                        <?php
                                        $productStartPage = max(1, $productPage['page'] - 2);
                                        $productEndPage = min($productPage['total_pages'], $productPage['page'] + 2);
                                        for ($pageNumber = $productStartPage; $pageNumber <= $productEndPage; $pageNumber++):
                                        ?>
                                            <li class="page-item <?= $pageNumber === $productPage['page'] ? 'active' : '' ?>">
                                                <a class="page-link" href="<?= htmlspecialchars(productListUrl($productFilters, ['page' => $pageNumber]), ENT_QUOTES, 'UTF-8') ?>"><?= $pageNumber ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        <li class="page-item <?= $productPage['page'] >= $productPage['total_pages'] ? 'disabled' : '' ?>">
                                            <a class="page-link" href="<?= htmlspecialchars(productListUrl($productFilters, ['page' => $productPage['page'] + 1]), ENT_QUOTES, 'UTF-8') ?>">Next</a>
                                        </li>
                                    </ul>
                                </nav>
                            </div>

                        </div>

                        <!-- ADD PRODUCT MODAL -->
                        <div class="modal fade modal-modern" id="addProductModal" tabindex="-1">
                            <div class="modal-dialog modal-xl modal-dialog-centered">
                                <div class="modal-content">
                                    <form id="addProductForm" enctype="multipart/form-data">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

                                        <div class="modal-header bg-primary-subtle">
                                            <div>
                                                <h5 class="modal-title fw-bold mb-1">
                                                    <i class="bi bi-plus-circle me-2"></i>Add New Product
                                                </h5>
                                                <small class="text-muted">Create a new product record and optionally set its initial stock.</small>
                                            </div>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>

                                        <div class="modal-body">
                                            <div class="row g-4">
                                                <div class="col-lg-4">
                                                    <div class="modal-side-card text-center">
                                                        <h6 class="modal-section-title text-start">Product Photo</h6>
                                                        <img id="addProductPhotoPreview"
                                                            src="/inventory_system/assets/img/card.jpg"
                                                            class="preview-image"
                                                            alt="Preview">
                                                        <div class="mt-3">
                                                            <label class="form-label">Upload Photo</label>
                                                            <input type="file" class="form-control" name="photo" accept="image/*">
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="col-lg-8">
                                                    <div class="row g-3">
                                                        <div class="col-12">
                                                            <div class="modal-section-title">Basic Information</div>
                                                        </div>

                                                        <div class="col-md-8">
                                                            <label class="form-label">Product Name</label>
                                                            <input type="text" class="form-control" name="product_name" required>
                                                        </div>

                                                        <div class="col-md-4">
                                                            <label class="form-label">SKU / Barcode</label>
                                                            <input type="text" class="form-control" name="sku" placeholder="Optional">
                                                        </div>

                                                        <div class="col-md-6">
                                                            <label class="form-label">Category</label>
                                                            <select class="form-select" name="category_id" id="addProductCategory" required>
                                                                <?php foreach ($categories as $cat): ?>
                                                                    <option value="<?= (int)$cat['category_id'] ?>">
                                                                        <?= htmlspecialchars($cat['category_name']) ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>

                                                        <div class="col-md-6">
                                                            <label class="form-label">Subcategory</label>
                                                            <select class="form-select" name="subcategory_id" id="addProductSubcategory">
                                                                <?= renderSubcategoryOptions($subcategories) ?>
                                                            </select>
                                                        </div>

                                                        <div class="col-md-6">
                                                            <label class="form-label">Supplier</label>
                                                            <div class="input-group">
                                                                <select class="form-select" name="supplier_id" id="addProductSupplierSelect" required>
                                                                    <?php foreach ($suppliers as $sup): ?>
                                                                        <option value="<?= (int)$sup['supplier_id'] ?>">
                                                                            <?= htmlspecialchars($sup['supplier_name']) ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                                <button type="button" class="btn btn-outline-primary"
                                                                    data-bs-toggle="modal"
                                                                    data-bs-target="#supplierModal"
                                                                    data-target-select="addProductSupplierSelect">
                                                                    + Add
                                                                </button>
                                                            </div>
                                                        </div>
                                                        <div class="col-12 mt-2">
                                                            <div class="modal-section-title">Pricing & Stock</div>
                                                        </div>

                                                        <div class="col-12 product-unit-note d-none" data-unit-note="beverage">
                                                            <div class="alert alert-warning border small mb-0">
                                                                Beverage items use <strong>Piece</strong> and <strong>Case</strong> in POS. Box selling fields are disabled for this category.
                                                            </div>
                                                        </div>

                                                        <div class="col-md-4">
                                                            <label class="form-label">Piece Price</label>
                                                            <div class="input-group">
                                                                <span class="input-group-text">₱</span>
                                                                <input type="number" class="form-control" name="price" step="0.01" min="0" required>
                                                            </div>
                                                        </div>

                                                        <div class="col-md-4">
                                                            <label class="form-label">Pieces per Box</label>
                                                            <input type="number" class="form-control" name="pieces_per_box" value="1" min="1" required>
                                                        </div>

                                                        <div class="col-md-4 product-box-field">
                                                            <label class="form-label">Box Price</label>
                                                            <div class="input-group">
                                                                <span class="input-group-text">₱</span>
                                                                <input type="number" class="form-control" name="box_price" step="0.01" min="0" placeholder="Optional">
                                                            </div>
                                                        </div>

                                                        <div class="col-md-4">
                                                            <label class="form-label">Boxes per Case</label>
                                                            <input type="number" class="form-control" name="boxes_per_case" value="1" min="1" required>
                                                        </div>

                                                        <div class="col-md-4">
                                                            <label class="form-label">Case Price</label>
                                                            <div class="input-group">
                                                                <span class="input-group-text">₱</span>
                                                                <input type="number" class="form-control" name="case_price" step="0.01" min="0" placeholder="Optional">
                                                            </div>
                                                        </div>

                                                        <div class="col-md-4">
                                                            <label class="form-label">Piece Discount %</label>
                                                            <div class="input-group">
                                                                <span class="input-group-text">%</span>
                                                                <input type="number" class="form-control" name="sale_price" step="0.01" min="0" max="100">
                                                            </div>
                                                        </div>

                                                        <div class="col-md-4 product-box-field">
                                                            <label class="form-label">Box Discount %</label>
                                                            <div class="input-group">
                                                                <span class="input-group-text">%</span>
                                                                <input type="number" class="form-control" name="box_sale_price" step="0.01" min="0" max="100">
                                                            </div>
                                                        </div>

                                                        <div class="col-md-4">
                                                            <label class="form-label">Case Discount %</label>
                                                            <div class="input-group">
                                                                <span class="input-group-text">%</span>
                                                                <input type="number" class="form-control" name="case_sale_price" step="0.01" min="0" max="100">
                                                            </div>
                                                        </div>

                                                        <div class="col-md-4">
                                                            <label class="form-label">Initial Quantity (Pieces)</label>
                                                            <input type="number" class="form-control" name="initial_quantity" value="0" min="0" required>
                                                        </div>

                                                        <div class="col-md-6">
                                                            <label class="form-label">Reorder Level</label>
                                                            <input type="number" class="form-control" name="reorder_level" value="5" min="0">
                                                        </div>


                                                        <div class="col-md-6">
                                                            <label class="form-label">Vatable?</label>
                                                            <select class="form-select" name="vatable" required>
                                                                <option value="1">Yes</option>
                                                                <option value="0">No</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-light border px-4" data-bs-dismiss="modal">Close</button>
                                            <button type="submit" class="btn btn-primary px-4">
                                                <i class="bi bi-save me-1"></i>Add Product
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- EDIT PRODUCT MODAL -->
                        <div class="modal fade modal-modern" id="editProductModal" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-xl modal-dialog-centered">
                                <div class="modal-content">
                                    <form id="editProductForm" enctype="multipart/form-data">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="product_id" id="editProductId">

                                        <div class="modal-header bg-warning-subtle">
                                            <div>
                                                <h5 class="modal-title fw-bold mb-1">
                                                    <i class="bi bi-pencil-square me-2"></i>Edit Product
                                                </h5>
                                                <small class="text-muted">Update product details, pricing, and supplier information.</small>
                                            </div>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>

                                        <div class="modal-body">
                                            <div class="row g-4">
                                                <div class="col-lg-4">
                                                    <div class="modal-side-card text-center">
                                                        <h6 class="modal-section-title text-start">Product Photo</h6>
                                                        <img id="editProductPhotoPreview"
                                                            src="/inventory_system/assets/img/card.jpg"
                                                            class="preview-image"
                                                            alt="Preview">
                                                        <div class="mt-3">
                                                            <label class="form-label">Upload New Photo</label>
                                                            <input type="file" class="form-control" name="photo" accept="image/*">
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="col-lg-8">
                                                    <div class="row g-3">
                                                        <div class="col-12">
                                                            <div class="modal-section-title">Basic Information</div>
                                                        </div>

                                                        <div class="col-md-8">
                                                            <label class="form-label">Product Name</label>
                                                            <input type="text" class="form-control" name="product_name" id="editProductName" required>
                                                        </div>

                                                        <div class="col-md-4">
                                                            <label class="form-label">SKU / Barcode</label>
                                                            <input type="text" class="form-control" name="sku" id="editProductSku">
                                                        </div>

                                                        <div class="col-md-6">
                                                            <label class="form-label">Category</label>
                                                            <select class="form-select" name="category_id" id="editProductCategory" required>
                                                                <?php foreach ($categories as $cat): ?>
                                                                    <option value="<?= (int)$cat['category_id'] ?>">
                                                                        <?= htmlspecialchars($cat['category_name']) ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>

                                                        <div class="col-md-6">
                                                            <label class="form-label">Subcategory</label>
                                                            <select class="form-select" name="subcategory_id" id="editProductSubcategory">
                                                                <?= renderSubcategoryOptions($subcategories) ?>
                                                            </select>
                                                        </div>

                                                        <div class="col-md-6">
                                                            <label class="form-label">Supplier</label>
                                                            <div class="input-group">
                                                                <select class="form-select" name="supplier_id" id="editProductSupplierSelect" required>
                                                                    <?php foreach ($suppliers as $sup): ?>
                                                                        <option value="<?= (int)$sup['supplier_id'] ?>">
                                                                            <?= htmlspecialchars($sup['supplier_name']) ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                                <button type="button" class="btn btn-outline-primary"
                                                                    data-bs-toggle="modal"
                                                                    data-bs-target="#supplierModal"
                                                                    data-target-select="editProductSupplierSelect">
                                                                    + Add
                                                                </button>
                                                            </div>
                                                        </div>

                                                        <div class="col-12 mt-2">
                                                            <div class="modal-section-title">Pricing & Stock Settings</div>
                                                        </div>

                                                        <div class="col-12 product-unit-note d-none" data-unit-note="beverage">
                                                            <div class="alert alert-warning border small mb-0">
                                                                Beverage items use <strong>Piece</strong> and <strong>Case</strong> in POS. Box selling fields are disabled for this category.
                                                            </div>
                                                        </div>

                                                        <div class="col-md-4">
                                                            <label class="form-label">Piece Price</label>
                                                            <div class="input-group">
                                                                <span class="input-group-text">₱</span>
                                                                <input type="number" class="form-control" name="price" id="editProductPrice" step="0.01" min="0" required>
                                                            </div>
                                                        </div>

                                                        <div class="col-md-4">
                                                            <label class="form-label">Pieces per Box</label>
                                                            <input type="number" class="form-control" name="pieces_per_box" id="editProductPiecesPerBox" min="1" required>
                                                        </div>

                                                        <div class="col-md-4 product-box-field">
                                                            <label class="form-label">Box Price</label>
                                                            <div class="input-group">
                                                                <span class="input-group-text">₱</span>
                                                                <input type="number" class="form-control" name="box_price" id="editProductBoxPrice" step="0.01" min="0">
                                                            </div>
                                                        </div>

                                                        <div class="col-md-4">
                                                            <label class="form-label">Boxes per Case</label>
                                                            <input type="number" class="form-control" name="boxes_per_case" id="editProductBoxesPerCase" min="1" required>
                                                        </div>

                                                        <div class="col-md-4">
                                                            <label class="form-label">Case Price</label>
                                                            <div class="input-group">
                                                                <span class="input-group-text">₱</span>
                                                                <input type="number" class="form-control" name="case_price" id="editProductCasePrice" step="0.01" min="0">
                                                            </div>
                                                        </div>

                                                        <div class="col-md-4">
                                                            <label class="form-label">Piece Discount %</label>
                                                            <div class="input-group">
                                                                <span class="input-group-text">%</span>
                                                                <input type="number" class="form-control" name="sale_price" id="editProductSalePrice" step="0.01" min="0" max="100">
                                                            </div>
                                                        </div>

                                                        <div class="col-md-4 product-box-field">
                                                            <label class="form-label">Box Discount %</label>
                                                            <div class="input-group">
                                                                <span class="input-group-text">%</span>
                                                                <input type="number" class="form-control" name="box_sale_price" id="editProductBoxSalePrice" step="0.01" min="0" max="100">
                                                            </div>
                                                        </div>

                                                        <div class="col-md-4">
                                                            <label class="form-label">Case Discount %</label>
                                                            <div class="input-group">
                                                                <span class="input-group-text">%</span>
                                                                <input type="number" class="form-control" name="case_sale_price" id="editProductCaseSalePrice" step="0.01" min="0" max="100">
                                                            </div>
                                                        </div>

                                                        <div class="col-md-4">
                                                            <label class="form-label">Reorder Level</label>
                                                            <input type="number" class="form-control" name="reorder_level" id="editProductReorderLevel" min="0" required>
                                                        </div>

                                                        <div class="col-md-6">
                                                            <label class="form-label">Vatable?</label>
                                                            <select class="form-select" name="vatable" id="editProductVatable" required>
                                                                <option value="1">Yes</option>
                                                                <option value="0">No</option>
                                                            </select>
                                                        </div>

                                                        <div class="col-md-6">
                                                            <label class="form-label">Status</label>
                                                            <input type="text" class="form-control bg-light" value="Managed via status button" readonly>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-light border px-4" data-bs-dismiss="modal">Close</button>
                                            <button type="submit" class="btn btn-success px-4">
                                                <i class="bi bi-save me-1"></i>Update Product
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- RESTOCK MODAL -->
                        <div class="modal fade modal-modern" id="restockModal" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <form id="restockForm">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" id="restockProductId" name="product_id">

                                        <div class="modal-header bg-success-subtle">
                                            <div>
                                                <h5 class="modal-title fw-bold mb-1">
                                                    <i class="bi bi-box-arrow-in-down me-2"></i>Restock Product
                                                </h5>
                                                <small class="text-muted">Add inventory to an existing active product.</small>
                                            </div>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>

                                        <div class="modal-body">
                                            <div class="modal-side-card">
                                                <div class="mb-3">
                                                    <label class="form-label">Product</label>
                                                    <input type="text" id="restockProductName" class="form-control" readonly>
                                                </div>

                                                <div class="mb-0">
                                                    <label class="form-label">Quantity to Add</label>
                                                    <input type="number" name="quantity" class="form-control" required min="1" placeholder="Enter quantity">
                                                </div>

                                                <div class="mt-3">
                                                    <label class="form-label">Adjustment Type</label>
                                                    <select name="adjustment_type" class="form-select" required>
                                                        <option value="delivery_received">Delivery Received</option>
                                                        <option value="manual_restock">Manual Restock</option>
                                                        <option value="count_correction">Count Correction</option>
                                                        <option value="customer_return">Customer Return</option>
                                                        <option value="purchase_receive">Purchase Order Receipt</option>
                                                    </select>
                                                </div>

                                                <div class="mt-3">
                                                    <label class="form-label">Supplier (optional)</label>
                                                    <select name="supplier_id" class="form-select">
                                                        <option value="">No supplier link</option>
                                                        <?php foreach ($suppliers as $sup): ?>
                                                            <option value="<?= (int) ($sup['supplier_id'] ?? 0) ?>">
                                                                <?= htmlspecialchars((string) ($sup['supplier_name'] ?? 'Supplier'), ENT_QUOTES, 'UTF-8') ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div class="mt-3 mb-0">
                                                    <label class="form-label">Restock Note (optional)</label>
                                                    <textarea
                                                        name="notes"
                                                        class="form-control"
                                                        rows="3"
                                                        maxlength="500"
                                                        placeholder="Example: Delivery received from supplier, emergency refill, counted adjustment"
                                                    ></textarea>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-light border px-4" data-bs-dismiss="modal">Close</button>
                                            <button type="submit" class="btn btn-success px-4">
                                                <i class="bi bi-plus-lg me-1"></i>Restock
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- STOCK OUT MODAL -->
                        <div class="modal fade modal-modern" id="stockOutModal" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <form id="stockOutForm">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" id="stockOutProductId" name="product_id">

                                        <div class="modal-header bg-dark text-white">
                                            <div>
                                                <h5 class="modal-title fw-bold mb-1">
                                                    <i class="bi bi-box-arrow-up me-2"></i>Stock Out Product
                                                </h5>
                                                <small class="text-white-50">Remove inventory due to damage, expiry, loss, or other reasons.</small>
                                            </div>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>

                                        <div class="modal-body">
                                            <div class="modal-side-card">
                                                <div class="mb-3">
                                                    <label class="form-label">Product</label>
                                                    <input type="text" id="stockOutProductName" class="form-control" readonly>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label">Quantity to Remove</label>
                                                    <input type="number" name="quantity" class="form-control" required min="1" placeholder="Enter quantity">
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label">Adjustment Type</label>
                                                    <select name="adjustment_type" class="form-select" required>
                                                        <option value="">Select adjustment type</option>
                                                        <option value="Damaged">Damaged</option>
                                                        <option value="Expired">Expired</option>
                                                        <option value="Lost">Lost</option>
                                                        <option value="Returned to supplier">Returned to supplier</option>
                                                        <option value="Broken packaging">Broken packaging</option>
                                                        <option value="Count correction">Count correction</option>
                                                        <option value="Other">Other</option>
                                                    </select>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label">Specific Reason (optional)</label>
                                                    <input type="text" id="customStockOutReason" class="form-control" maxlength="500" placeholder="Enter a specific reason if needed">
                                                </div>

                                                <div class="mb-0">
                                                    <label class="form-label">Notes (optional)</label>
                                                    <textarea name="notes" class="form-control" rows="3" maxlength="500" placeholder="Add more details for the stock-out record"></textarea>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-light border px-4" data-bs-dismiss="modal">Close</button>
                                            <button type="submit" class="btn btn-dark px-4">
                                                <i class="bi bi-check2-circle me-1"></i>Confirm Stock Out
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                    <!-- SUPPLIER MODAL -->
                    <div class="modal fade modal-modern" id="supplierModal" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <form id="supplierForm">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                                    <div class="modal-header bg-info-subtle">
                                        <div>
                                            <h5 class="modal-title fw-bold mb-1">
                                                <i class="bi bi-truck me-2"></i>Add New Supplier
                                            </h5>
                                            <small class="text-muted">Create a supplier record for product assignment.</small>
                                        </div>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>

                                    <div class="modal-body">
                                        <div id="supplierMessage" class="modal-message-center"></div>

                                        <div class="row g-3">
                                            <div class="col-12">
                                                <label class="form-label">Supplier Name</label>
                                                <input type="text" class="form-control" id="supplier_name" name="supplier_name" required>
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Contact Person</label>
                                                <input type="text" class="form-control" id="contact_person" name="contact_person">
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Phone</label>
                                                <input type="text" class="form-control" id="phone" name="phone">
                                            </div>

                                            <div class="col-12">
                                                <label class="form-label">Email</label>
                                                <input type="email" class="form-control" id="email" name="email">
                                            </div>

                                            <div class="col-12">
                                                <label class="form-label">Address</label>
                                                <textarea class="form-control" id="address" name="address" rows="3"></textarea>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-light border px-4" data-bs-dismiss="modal">Close</button>
                                        <button type="submit" class="btn btn-info px-4 text-white" id="saveSupplierBtn">
                                            <i class="bi bi-save me-1"></i>Add Supplier
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <?php require __DIR__ . '/../components/js_script.php'; ?>
    <script src="/inventory_system/assets/js/manage_product.js"></script>

</body>
</html>
