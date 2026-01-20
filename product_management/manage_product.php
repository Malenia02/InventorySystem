<?php
session_start();

// Check admin access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: /inventory_system/index.php");
    exit;
}

require $_SERVER['DOCUMENT_ROOT'].'/inventory_system/config/config.php';
require $_SERVER['DOCUMENT_ROOT'].'/inventory_system/controllers/ProductController.php';
require $_SERVER['DOCUMENT_ROOT'].'/inventory_system/controllers/CategoryController.php';

// Fetch all categories and products
$categories = CategoryController::all($conn, table: 'categories');
$products = ProductController::allProducts($conn); // Make sure $conn is passed
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php require $_SERVER['DOCUMENT_ROOT'].'/inventory_system/components/head.php'; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/simple-datatables@latest/dist/style.css">
    <title>Manage Products</title>
</head>
<body>
    <?php
    require $_SERVER['DOCUMENT_ROOT'].'/inventory_system/components/header.php';
    require $_SERVER['DOCUMENT_ROOT'].'/inventory_system/components/sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'].'/inventory_system/components/breadcrumb.php';
    ?>

    <main class="main">
        <section class="section">
            <div class="row">
                <div class="col-lg-12">

                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">Product List</h5>

                            <!-- Messages -->
                            <div id="productMessages"></div>

                            <!-- Add Product Button -->
                            <button type="button" class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#addProductModal">
                                <i class="bi bi-plus-circle"></i> Add New Product
                            </button>

                            <!-- Products Table -->
                            <div class="table-responsive">
                                <table id="productsTable" class="table table-striped table-bordered">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Photo</th>
                                            <th>Name</th>
                                            <th>Category</th>
                                            <th>Price</th>
                                            <th>Sale Price</th>
                                            <th>Vatable</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($products as $index => $p): ?>
                                            <tr id="productRow<?= $p['product_id'] ?>">
                                                <td><?= $index + 1 ?></td>
                                                <td class="text-center">
                                                    <img src="<?= !empty($p['photo']) ? $p['photo'] : '/inventory_system/assets/uploads/products/images.jpeg' ?>" 
                                                        alt="Photo" style="width:50px;height:50px;object-fit:cover;">
                                                </td>
                                                <td><?= htmlspecialchars($p['product_name']) ?></td>
                                                <td><?= htmlspecialchars($p['category_name'] ?? '-') ?></td>
                                                <td>₱<?= number_format($p['price'],2) ?></td>
                                                <td><?= !empty($p['sale_price']) ? '₱'.number_format($p['sale_price'],2) : '-' ?></td>
                                                <td><?= $p['vatable'] ? 'Yes' : 'No' ?></td>
                                                <td>
                                                    <span class="badge <?= $p['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                                                        <?= ucfirst($p['status'] ?? 'inactive') ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-warning editProductBtn"
                                                        data-id="<?= $p['product_id'] ?>"
                                                        data-name="<?= htmlspecialchars($p['product_name']) ?>"
                                                        data-category="<?= $p['category_id'] ?>"
                                                        data-price="<?= $p['price'] ?>"
                                                        data-sale_price="<?= $p['sale_price'] ?>"
                                                        data-vatable="<?= $p['vatable'] ?>"
                                                        data-photo="<?= !empty($p['photo']) ? $p['photo'] : '/inventory_system/assets/uploads/products/images.jpeg' ?>"
                                                        data-bs-toggle="modal" data-bs-target="#editProductModal">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </button>
                                                    <button class="btn btn-sm <?= $p['status'] === 'active' ? 'btn-danger' : 'btn-success' ?> toggleProductStatusBtn"
                                                        data-id="<?= $p['product_id'] ?>"
                                                        data-status="<?= $p['status'] === 'active' ? 'deactivate' : 'activate' ?>">
                                                        <?= $p['status'] === 'active' ? '<i class="bi bi-slash-circle"></i>' : '<i class="bi bi-check-circle"></i>' ?>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- ==========================
                                 ADD PRODUCT MODAL
                                 ========================== -->
                            <div class="modal fade" id="addProductModal" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <form id="addProductForm" enctype="multipart/form-data">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Add New Product</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="row">
                                                    <div class="col-md-4 text-center">
                                                        <label class="form-label">Photo</label>
                                                        <img id="addProductPhotoPreview" src="/inventory_system/assets/uploads/products/images.jpeg" style="width:150px;height:150px;object-fit:cover;">
                                                        <input type="file" class="form-control mt-2" name="photo" accept="image/*">
                                                    </div>
                                                    <div class="col-md-8">
                                                        <div class="mb-3">
                                                            <label class="form-label">Product Name</label>
                                                            <input type="text" class="form-control" name="product_name" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Category</label>
                                                            <select class="form-select" name="category_id" required>
                                                                <?php foreach($categories as $cat): ?>
                                                                    <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Price</label>
                                                            <input type="number" class="form-control" name="price" step="0.01" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Sale Price (optional)</label>
                                                            <input type="number" class="form-control" name="sale_price" step="0.01">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Vatable?</label>
                                                            <select class="form-select" name="vatable" required>
                                                                <option value="1">Yes</option>
                                                                <option value="0">No</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                <button type="submit" class="btn btn-primary">Add Product</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- ==========================
                                 EDIT PRODUCT MODAL
                                 ========================== -->
                            <div class="modal fade" id="editProductModal" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <form id="editProductForm" enctype="multipart/form-data">
                                            <input type="hidden" name="product_id" id="editProductId">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Edit Product</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="row">
                                                    <div class="col-md-4 text-center">
                                                        <label class="form-label">Current Photo</label>
                                                        <img id="editProductPhotoPreview" src="/inventory_system/assets/uploads/products/images.jpeg" style="width:150px;height:150px;object-fit:cover;">
                                                        <input type="file" class="form-control mt-2" name="photo" accept="image/*">
                                                    </div>
                                                    <div class="col-md-8">
                                                        <div class="mb-3">
                                                            <label class="form-label">Product Name</label>
                                                            <input type="text" class="form-control" name="product_name" id="editProductName" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Category</label>
                                                            <select class="form-select" name="category_id" id="editProductCategory" required>
                                                                <?php foreach($categories as $cat): ?>
                                                                    <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Price</label>
                                                            <input type="number" class="form-control" name="price" id="editProductPrice" step="0.01" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Sale Price (optional)</label>
                                                            <input type="number" class="form-control" name="sale_price" id="editProductSalePrice" step="0.01">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Vatable?</label>
                                                            <select class="form-select" name="vatable" id="editProductVatable" required>
                                                                <option value="1">Yes</option>
                                                                <option value="0">No</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                <button type="submit" class="btn btn-success">Update Product</button>
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

    <script src="https://cdn.jsdelivr.net/npm/simple-datatables@latest" type="text/javascript"></script>
    <script src="<?= HOSTURL ?>/assets/js/manage_product.js"></script>
    <script>
        // Initialize Simple-DataTables
        const table = document.querySelector("#productsTable");
        if(table){
            new simpleDatatables.DataTable(table, {
                searchable: true,
                fixedHeight: true,
                perPage: 10
            });
        }
    </script>

</body>
</html>
