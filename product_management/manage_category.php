<?php
require $_SERVER['DOCUMENT_ROOT'].'/inventory_system/controllers/CategoryController.php';

$categories = CategoryController::all();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require $_SERVER['DOCUMENT_ROOT'].'/inventory_system/components/head.php'; ?>
</head>
<body>
<?php
require $_SERVER['DOCUMENT_ROOT'].'/inventory_system/components/header.php';
require $_SERVER['DOCUMENT_ROOT'].'/inventory_system/components/sidebar.php';
require $_SERVER['DOCUMENT_ROOT'].'/inventory_system/components/breadcrumb.php';
?>



    <section class="section">
        <div class="row">
            <div class="col-lg-12">

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Category List</h5>

                        <!-- Add Category Button -->
                        <button type="button" class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                            <i class="bi bi-plus-circle"></i> Add New Category
                        </button>

                        <!-- Category Table -->
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Category Name</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($categories as $index => $cat): ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td><?= htmlspecialchars($cat['category_name']) ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-warning editCategoryBtn" 
                                                data-id="<?= $cat['category_id'] ?>" 
                                                data-name="<?= htmlspecialchars($cat['category_name']) ?>" 
                                                data-bs-toggle="modal" data-bs-target="#editCategoryModal">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger deleteCategoryBtn" data-id="<?= $cat['category_id'] ?>">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- ADD CATEGORY MODAL -->
                        <div class="modal fade" id="addCategoryModal" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form id="addCategoryForm">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Add New Category</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="mb-3">
                                                <label class="form-label">Category Name</label>
                                                <input type="text" class="form-control" name="category_name" required>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                            <button type="submit" class="btn btn-primary">Add Category</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- EDIT CATEGORY MODAL -->
                        <div class="modal fade" id="editCategoryModal" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form id="editCategoryForm">
                                        <input type="hidden" name="category_id" id="editCategoryId">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Edit Category</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="mb-3">
                                                <label class="form-label">Category Name</label>
                                                <input type="text" class="form-control" name="category_name" id="editCategoryName" required>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                            <button type="submit" class="btn btn-success">Update Category</button>
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
</main>

<?php require $_SERVER['DOCUMENT_ROOT'].'/inventory_system/components/js_script.php'; ?>
<script src="<?= HOSTURL ?>/assets/js/manage_category.js"></script>
</body>
</html>
