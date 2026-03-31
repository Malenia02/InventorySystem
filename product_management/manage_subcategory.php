<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/CategoryController.php';
require_once __DIR__ . '/../controllers/SubcategoryController.php';

Middleware::auth()->role(['admin']);

$csrfToken = Middleware::generateCsrfToken();
$categories = CategoryController::all($conn);
$subcategories = SubcategoryController::all($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/../components/head.php'; ?>
    <title>Manage Subcategories</title>
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
                                    <?php $rowNumber = $index + 1; ?>
                                    <?php include __DIR__ . '/../templates/subcategory_row.php'; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="modal fade" id="addSubcategoryModal" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form id="addSubcategoryForm">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Add New Subcategory</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
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
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                        <button type="submit" class="btn btn-primary">Add Subcategory</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="modal fade" id="editSubcategoryModal" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form id="editSubcategoryForm">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="subcategory_id" id="editSubcategoryId">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Edit Subcategory</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
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
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                        <button type="submit" class="btn btn-success">Update Subcategory</button>
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
<script src="<?= HOSTURL ?>/assets/js/manage_subcategory.js"></script>
</body>
</html>
