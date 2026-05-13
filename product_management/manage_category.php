<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/CategoryController.php';

Middleware::auth()->role(['admin']);

$categoryFilters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'status' => strtolower(trim((string) ($_GET['status'] ?? 'all'))),
    'page' => max(1, (int) ($_GET['page'] ?? 1)),
    'per_page' => (int) ($_GET['per_page'] ?? 25),
];
$categoryPage = CategoryController::paginate($conn, $categoryFilters);
$categories = $categoryPage['items'];
$categoryFilters = [
    'search' => (string) $categoryPage['search'],
    'status' => (string) $categoryPage['status'],
    'page' => (int) $categoryPage['page'],
    'per_page' => (int) $categoryPage['per_page'],
];
$categoryRowStart = $categoryPage['total'] > 0 ? (($categoryPage['page'] - 1) * $categoryPage['per_page']) + 1 : 0;

function categoryListUrl(array $filters, array $overrides = []): string
{
    $params = array_merge($filters, $overrides);
    if (($params['page'] ?? 1) <= 1) unset($params['page']);
    if (($params['search'] ?? '') === '') unset($params['search']);
    if (($params['status'] ?? 'all') === 'all') unset($params['status']);
    if (($params['per_page'] ?? 25) === 25) unset($params['per_page']);
    $query = http_build_query($params);
    return '/inventory_system/product_management/manage_category.php' . ($query !== '' ? '?' . $query : '');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/../components/head.php'; ?>
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
                    <h5 class="card-title">Category List</h5>
<div id="categoryMessageContainer"></div>
                    <!-- Add Category Button -->
                    <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                        <i class="bi bi-plus-circle"></i> Add New Category
                    </button>

                    <form method="get" class="row g-3 align-items-end mb-3">
                        <div class="col-lg-6">
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($categoryFilters['search'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Category name or description">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="all" <?= $categoryFilters['status'] === 'all' ? 'selected' : '' ?>>All</option>
                                <option value="active" <?= $categoryFilters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $categoryFilters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Per page</label>
                            <select name="per_page" class="form-select">
                                <?php foreach ([10, 25, 50, 100] as $size): ?>
                                    <option value="<?= $size ?>" <?= $categoryFilters['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">Apply</button>
                        </div>
                    </form>

                    <!-- Category Table -->
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered" id="categoryTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Category Name</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($categories as $index => $cat): ?>
                                <tr id="categoryRow<?= $cat['category_id'] ?>">
                                    <td><?= $categoryRowStart + $index ?></td>
                                    <td><?= htmlspecialchars($cat['category_name']) ?></td>
                                    <td>
                                        <span class="badge <?= $cat['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                                            <?= ucfirst($cat['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-2 justify-content-center">
                                        <button class="btn btn-sm btn-warning editCategoryBtn"
                                                data-id="<?= $cat['category_id'] ?>"
                                                data-name="<?= htmlspecialchars($cat['category_name']) ?>"
                                                data-bs-toggle="modal" data-bs-target="#editCategoryModal">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>

                                        <button class="btn btn-sm <?= $cat['status'] === 'active' ? 'btn-danger' : 'btn-success' ?> toggleCategoryStatusBtn"
                                                data-id="<?= $cat['category_id'] ?>"
                                                data-name="<?= htmlspecialchars($cat['category_name']) ?>"
                                                data-status="<?= $cat['status'] ?>">
                                            <?= $cat['status'] === 'active'
                                                ? '<i class="bi bi-slash-circle"></i>'
                                                : '<i class="bi bi-check-circle"></i>' ?>
                                        </button>
                                    </td>
                                    </div>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mt-3">
                        <div class="text-muted small">
                            <?php if ($categoryPage['total'] > 0): ?>
                                Showing <?= $categoryRowStart ?> to <?= min($categoryRowStart + count($categories) - 1, $categoryPage['total']) ?> of <?= $categoryPage['total'] ?> categories
                            <?php else: ?>
                                No categories found
                            <?php endif; ?>
                        </div>
                        <nav aria-label="Category pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?= $categoryPage['page'] <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= htmlspecialchars(categoryListUrl($categoryFilters, ['page' => $categoryPage['page'] - 1]), ENT_QUOTES, 'UTF-8') ?>">Previous</a>
                                </li>
                                <?php
                                $categoryStartPage = max(1, $categoryPage['page'] - 2);
                                $categoryEndPage = min($categoryPage['total_pages'], $categoryPage['page'] + 2);
                                for ($pageNumber = $categoryStartPage; $pageNumber <= $categoryEndPage; $pageNumber++):
                                ?>
                                    <li class="page-item <?= $pageNumber === $categoryPage['page'] ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= htmlspecialchars(categoryListUrl($categoryFilters, ['page' => $pageNumber]), ENT_QUOTES, 'UTF-8') ?>"><?= $pageNumber ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= $categoryPage['page'] >= $categoryPage['total_pages'] ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= htmlspecialchars(categoryListUrl($categoryFilters, ['page' => $categoryPage['page'] + 1]), ENT_QUOTES, 'UTF-8') ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    </div>

                    <!-- ADD CATEGORY MODAL -->
                    <div class="modal fade modal-modern" id="addCategoryModal" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <form id="addCategoryForm">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                                    <div class="modal-header bg-primary-subtle">
                                        <div>
                                            <h5 class="modal-title fw-bold mb-1">
                                                <i class="bi bi-tags me-2"></i>Add New Category
                                            </h5>
                                            <small class="text-muted">Create a new category for product organization.</small>
                                        </div>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="modal-side-card">
                                            <div class="modal-section-title">Category Details</div>
                                            <div class="mb-0">
                                                <label class="form-label">Category Name</label>
                                                <input type="text" class="form-control" name="category_name" required>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-light border px-4" data-bs-dismiss="modal">Close</button>
                                        <button type="submit" class="btn btn-primary px-4">Add Category</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- EDIT CATEGORY MODAL -->
                    <div class="modal fade modal-modern" id="editCategoryModal" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <form id="editCategoryForm">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                                    <input type="hidden" name="category_id" id="editCategoryId">
                                    <div class="modal-header bg-warning-subtle">
                                        <div>
                                            <h5 class="modal-title fw-bold mb-1">
                                                <i class="bi bi-pencil-square me-2"></i>Edit Category
                                            </h5>
                                            <small class="text-muted">Update the selected category name.</small>
                                        </div>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="modal-side-card">
                                            <div class="modal-section-title">Category Details</div>
                                            <div class="mb-0">
                                                <label class="form-label">Category Name</label>
                                                <input type="text" class="form-control" name="category_name" id="editCategoryName" required>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-light border px-4" data-bs-dismiss="modal">Close</button>
                                        <button type="submit" class="btn btn-success px-4">Update Category</button>
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
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<script src="/inventory_system/assets/js/manage_category.js"></script>
</body>
</html>
