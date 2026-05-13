<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/CategoryController.php';
require_once __DIR__ . '/../controllers/SubcategoryController.php';

Middleware::auth()->role(['admin']);

$csrfToken = Middleware::generateCsrfToken();
$categories = CategoryController::all($conn);
$subcategoryFilters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'status' => strtolower(trim((string) ($_GET['status'] ?? 'all'))),
    'category_id' => (int) ($_GET['category_id'] ?? 0),
    'page' => max(1, (int) ($_GET['page'] ?? 1)),
    'per_page' => (int) ($_GET['per_page'] ?? 25),
];
$subcategoryPage = SubcategoryController::paginate($conn, $subcategoryFilters);
$subcategories = $subcategoryPage['items'];
$subcategoryFilters = [
    'search' => (string) $subcategoryPage['search'],
    'status' => (string) $subcategoryPage['status'],
    'category_id' => (int) $subcategoryPage['category_id'],
    'page' => (int) $subcategoryPage['page'],
    'per_page' => (int) $subcategoryPage['per_page'],
];
$subcategoryRowStart = $subcategoryPage['total'] > 0 ? (($subcategoryPage['page'] - 1) * $subcategoryPage['per_page']) + 1 : 0;

function subcategoryListUrl(array $filters, array $overrides = []): string
{
    $params = array_merge($filters, $overrides);
    if (($params['page'] ?? 1) <= 1) unset($params['page']);
    if (($params['search'] ?? '') === '') unset($params['search']);
    if (($params['status'] ?? 'all') === 'all') unset($params['status']);
    if (($params['category_id'] ?? 0) <= 0) unset($params['category_id']);
    if (($params['per_page'] ?? 25) === 25) unset($params['per_page']);
    $query = http_build_query($params);
    return '/inventory_system/product_management/manage_subcategory.php' . ($query !== '' ? '?' . $query : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/../components/head.php'; ?>
    <title>Manage Subcategories</title>
    <style>
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

<section class="section">
    <div class="row">
        <div class="col-lg-12">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Subcategory List</h5>

                    <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#addSubcategoryModal">
                        <i class="bi bi-plus-circle"></i> Add New Subcategory
                    </button>

                    <form method="get" class="row g-3 align-items-end mb-3">
                        <div class="col-lg-4">
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($subcategoryFilters['search'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Subcategory, category, description">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Category</label>
                            <select name="category_id" class="form-select">
                                <option value="0">All</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= (int) ($category['category_id'] ?? 0) ?>" <?= $subcategoryFilters['category_id'] === (int) ($category['category_id'] ?? 0) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string) ($category['category_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="all" <?= $subcategoryFilters['status'] === 'all' ? 'selected' : '' ?>>All</option>
                                <option value="active" <?= $subcategoryFilters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $subcategoryFilters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Per page</label>
                            <select name="per_page" class="form-select">
                                <?php foreach ([10, 25, 50, 100] as $size): ?>
                                    <option value="<?= $size ?>" <?= $subcategoryFilters['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-1">
                            <button type="submit" class="btn btn-primary w-100">Apply</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-striped table-bordered" id="subcategoryTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Category</th>
                                    <th>Subcategory</th>
                                    <th>Description</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($subcategories as $index => $subcategory): ?>
                                    <?php $rowNumber = $subcategoryRowStart + $index; ?>
                                    <?php include __DIR__ . '/../templates/subcategory_row.php'; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mt-3">
                        <div class="text-muted small">
                            <?php if ($subcategoryPage['total'] > 0): ?>
                                Showing <?= $subcategoryRowStart ?> to <?= min($subcategoryRowStart + count($subcategories) - 1, $subcategoryPage['total']) ?> of <?= $subcategoryPage['total'] ?> subcategories
                            <?php else: ?>
                                No subcategories found
                            <?php endif; ?>
                        </div>
                        <nav aria-label="Subcategory pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?= $subcategoryPage['page'] <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= htmlspecialchars(subcategoryListUrl($subcategoryFilters, ['page' => $subcategoryPage['page'] - 1]), ENT_QUOTES, 'UTF-8') ?>">Previous</a>
                                </li>
                                <?php
                                $subcategoryStartPage = max(1, $subcategoryPage['page'] - 2);
                                $subcategoryEndPage = min($subcategoryPage['total_pages'], $subcategoryPage['page'] + 2);
                                for ($pageNumber = $subcategoryStartPage; $pageNumber <= $subcategoryEndPage; $pageNumber++):
                                ?>
                                    <li class="page-item <?= $pageNumber === $subcategoryPage['page'] ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= htmlspecialchars(subcategoryListUrl($subcategoryFilters, ['page' => $pageNumber]), ENT_QUOTES, 'UTF-8') ?>"><?= $pageNumber ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= $subcategoryPage['page'] >= $subcategoryPage['total_pages'] ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= htmlspecialchars(subcategoryListUrl($subcategoryFilters, ['page' => $subcategoryPage['page'] + 1]), ENT_QUOTES, 'UTF-8') ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    </div>

                    <div class="modal fade modal-modern" id="addSubcategoryModal" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <form id="addSubcategoryForm">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="modal-header bg-primary-subtle">
                                        <div>
                                            <h5 class="modal-title fw-bold mb-1">
                                                <i class="bi bi-diagram-3 me-2"></i>Add New Subcategory
                                            </h5>
                                            <small class="text-muted">Create a subcategory under one of your existing categories.</small>
                                        </div>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="modal-side-card">
                                            <div class="modal-section-title">Subcategory Details</div>

                                            <div class="mb-3">
                                                <label class="form-label">Category</label>
                                                <select class="form-select" name="category_id" required>
                                                    <?php foreach ($categories as $category): ?>
                                                        <option value="<?= (int) ($category['category_id'] ?? 0) ?>"><?= htmlspecialchars((string) ($category['category_name'] ?? '-')) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Subcategory Name</label>
                                                <input type="text" class="form-control" name="subcategory_name" required>
                                            </div>
                                            <div class="mb-0">
                                                <label class="form-label">Description</label>
                                                <textarea class="form-control" name="description" rows="3"></textarea>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-light border px-4" data-bs-dismiss="modal">Close</button>
                                        <button type="submit" class="btn btn-primary px-4">Add Subcategory</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="modal fade modal-modern" id="editSubcategoryModal" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <form id="editSubcategoryForm">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="subcategory_id" id="editSubcategoryId">
                                    <div class="modal-header bg-warning-subtle">
                                        <div>
                                            <h5 class="modal-title fw-bold mb-1">
                                                <i class="bi bi-pencil-square me-2"></i>Edit Subcategory
                                            </h5>
                                            <small class="text-muted">Update the selected subcategory details.</small>
                                        </div>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="modal-side-card">
                                            <div class="modal-section-title">Subcategory Details</div>

                                            <div class="mb-3">
                                                <label class="form-label">Category</label>
                                                <select class="form-select" name="category_id" id="editSubcategoryCategory" required>
                                                    <?php foreach ($categories as $category): ?>
                                                        <option value="<?= (int) ($category['category_id'] ?? 0) ?>"><?= htmlspecialchars((string) ($category['category_name'] ?? '-')) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Subcategory Name</label>
                                                <input type="text" class="form-control" name="subcategory_name" id="editSubcategoryName" required>
                                            </div>
                                            <div class="mb-0">
                                                <label class="form-label">Description</label>
                                                <textarea class="form-control" name="description" id="editSubcategoryDescription" rows="3"></textarea>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-light border px-4" data-bs-dismiss="modal">Close</button>
                                        <button type="submit" class="btn btn-success px-4">Update Subcategory</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/../components/js_script.php'; ?>
<script src="/inventory_system/assets/js/manage_subcategory.js"></script>
</body>
</html>
