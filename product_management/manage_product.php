<?php
session_start();
// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Check admin access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: /inventory_system/index.php");
    exit;
}

require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/config/config.php';
require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/controllers/ProductController.php';
require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/controllers/CategoryController.php';
require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/controllers/SupplierController.php';


// Fetch all categories,products, and suppliers
$categories = CategoryController::all($conn, table: 'categories');
$products = ProductController::allProducts($conn);
$suppliers = SupplierController::all($conn, $table_suppliers);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <?php require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/components/head.php'; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/simple-datatables@latest/dist/style.css">
    <title>Manage Products</title>
    <style>
        .modal-message-center {
            text-align: center;
            font-weight: 500;
            margin-bottom: 12px;
        }
    </style>
</head>

<body>
    <?php
    require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/components/header.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/components/sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/components/breadcrumb.php';
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
                            <button type="button" class="btn btn-primary mb-3" data-bs-toggle="modal"
                                data-bs-target="#addProductModal">
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
                                            <th>Supplier</th>
                                            <th>SKU</th>
                                            <th>Quantity</th>
                                            <th>Price</th>
                                            <th>Sale Price</th>
                                            <th>Vatable</th>
                                            <th>Re-Order Level</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($products as $index => $p): ?>
                                            <tr id="productRow<?= $p['product_id'] ?>">
                                                <td><?= $index + 1 ?></td>
                                                <td class="text-center">
                                                    <img src="<?= !empty($p['photo']) ? htmlspecialchars($p['photo']) : '/inventory_system/assets/uploads/products/images.jpeg' ?>"
                                                        alt="Photo" style="width:50px;height:50px;object-fit:cover;">
                                                </td>
                                                <td><?= htmlspecialchars($p['product_name'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars($p['category_name'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars($p['supplier_name'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars($p['sku'] ?? '-') ?></td>
                                                <td class="product-quantity"><?= (int) ($p['quantity'] ?? 0) ?></td>
                                                <td>₱<?= number_format($p['price'] ?? 0, 2) ?></td>
                                                <td><?= !empty($p['sale_price']) ? '₱' . number_format($p['sale_price'], 2) : '-' ?>
                                                </td>
                                                <td><?= !empty($p['vatable']) ? 'Yes' : 'No' ?></td>
                                                <td><?= (int) ($p['reorder_level'] ?? 5) ?></td>
                                                <td>
                                                    <span
                                                        class="badge <?= ($p['status'] ?? 'inactive') === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                                                        <?= ucfirst($p['status'] ?? 'inactive') ?>
                                                    </span>
                                                </td>
                                                <td>
    <div class="d-flex gap-2 justify-content-center">

        <button class="btn btn-sm btn-warning editProductBtn"
                data-id="<?= $p['product_id'] ?>"
                data-name="<?= htmlspecialchars($p['product_name'] ?? '') ?>"
                data-category="<?= $p['category_id'] ?? 0 ?>"
                data-supplier="<?= $p['supplier_id'] ?? 0 ?>"
                data-sku="<?= htmlspecialchars($p['sku'] ?? '') ?>"
                data-price="<?= $p['price'] ?? 0 ?>"
                data-sale_price="<?= $p['sale_price'] ?? '' ?>"
                data-vatable="<?= $p['vatable'] ?? 0 ?>"
                data-reorder="<?= $p['reorder_level'] ?? 5 ?>"
                data-photo="<?= !empty($p['photo']) ? htmlspecialchars($p['photo']) : '/inventory_system/assets/uploads/products/images.jpeg' ?>"
                data-bs-toggle="modal"
                data-bs-target="#editProductModal">
            <i class="bi bi-pencil-square"></i>
        </button>

        <button class="btn btn-sm <?= ($p['status'] ?? 'inactive') === 'active' ? 'btn-danger' : 'btn-success' ?> toggleProductStatusBtn"
                data-id="<?= $p['product_id'] ?>"
                data-status="<?= ($p['status'] ?? 'inactive') === 'active' ? 'deactivate' : 'activate' ?>">
            <?= ($p['status'] ?? 'inactive') === 'active' ? '<i class="bi bi-slash-circle"></i>' : '<i class="bi bi-check-circle"></i>' ?>
        </button>

        <button class="btn btn-success btn-sm restock-btn"
                data-id="<?= $p['product_id']; ?>"
                data-name="<?= htmlspecialchars($p['product_name']); ?>">
            <i class="bi bi-box-arrow-in-down"></i>
        </button>

    </div>
</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                        </div>

                        <!-- ==========================
                            ADD PRODUCT MODAL
                            ========================== -->
                        <div class="modal fade" id="addProductModal" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">

                                    <form id="addProductForm" enctype="multipart/form-data">
                                        <input type="hidden" name="csrf_token"
                                            value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Add New Product</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>

                                        <div class="modal-body">
                                            <div class="row">

                                                <!-- LEFT : PHOTO -->
                                                <div class="col-md-4 text-center">
                                                    <label class="form-label">Photo</label>
                                                    <img id="addProductPhotoPreview"
                                                        src="/inventory_system/assets/uploads/products/images.jpeg"
                                                        style="width:150px;height:150px;object-fit:cover;border-radius:8px;">
                                                    <input type="file" class="form-control mt-2" name="photo"
                                                        accept="image/*">
                                                </div>

                                                <!-- RIGHT : DETAILS -->
                                                <div class="col-md-8">

                                                    <!-- Product Name -->
                                                    <div class="mb-3">
                                                        <label class="form-label">Product Name</label>
                                                        <input type="text" class="form-control" name="product_name"
                                                            required>
                                                    </div>

                                                    <!-- Category -->
                                                    <div class="mb-3">
                                                        <label class="form-label">Category</label>
                                                        <select class="form-select" name="category_id" required>
                                                            <?php foreach ($categories as $cat): ?>
                                                                <option value="<?= $cat['category_id'] ?>">
                                                                    <?= htmlspecialchars($cat['category_name']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <!-- Supplier -->
                                                    <div class="mb-3">
                                                        <label class="form-label">Supplier</label>
                                                        <div class="input-group">
                                                            <select class="form-select" name="supplier_id"
                                                                id="addProductSupplierSelect" required>
                                                                <?php foreach ($suppliers as $sup): ?>
                                                                    <option value="<?= $sup['supplier_id'] ?>">
                                                                        <?= htmlspecialchars($sup['supplier_name']) ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                            <button type="button" class="btn btn-outline-primary"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#addSupplierModal">
                                                                + Add Supplier
                                                            </button>
                                                        </div>
                                                    </div>

                                                    <!-- SKU -->
                                                    <div class="mb-3">
                                                        <label class="form-label">SKU / Barcode</label>
                                                        <input type="text" class="form-control" name="sku"
                                                            placeholder="Optional but recommended">
                                                    </div>

                                                    <div class="row">
                                                        <!-- Price -->
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Price</label>
                                                            <input type="number" class="form-control" name="price"
                                                                step="0.01" required>
                                                        </div>

                                                        <!-- Sale Price -->
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Sale Price (optional)</label>
                                                            <input type="number" class="form-control" name="sale_price"
                                                                step="0.01">
                                                        </div>
                                                    </div>

                                                    <div class="row">
                                                        <!-- Initial Quantity -->
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Initial Quantity</label>
                                                            <input type="number" class="form-control"
                                                                name="initial_quantity" value="0" min="0" required>
                                                        </div>

                                                        <!-- Reorder Level -->
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">Reorder Level</label>
                                                            <input type="number" class="form-control"
                                                                name="reorder_level" value="5" min="0">
                                                        </div>
                                                    </div>

                                                    <!-- VATABLE -->
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
                                            <button type="button" class="btn btn-secondary"
                                                data-bs-dismiss="modal">Close</button>
                                            <button type="submit" class="btn btn-primary">Add Product</button>
                                        </div>

                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- ==========================
                                EDIT PRODUCT MODAL
                            ========================== -->
                        <div class="modal fade" id="editProductModal" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <form id="editProductForm" enctype="multipart/form-data">
                                        <!-- Hidden ID -->
                                        <input type="hidden" name="csrf_token"
                                            value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                                        <input type="hidden" name="product_id" id="editProductId">

                                        <div class="modal-header">
                                            <h5 class="modal-title">Edit Product</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                                aria-label="Close"></button>
                                        </div>

                                        <div class="modal-body">
                                            <div class="row">

                                                <!-- LEFT: Current Photo -->
                                                <div class="col-md-4 text-center">
                                                    <label class="form-label">Current Photo</label>
                                                    <img id="editProductPhotoPreview"
                                                        src="/inventory_system/assets/uploads/products/images.jpeg"
                                                        alt="Product Photo"
                                                        style="width:150px;height:150px;object-fit:cover;border-radius:8px;">
                                                    <input type="file" class="form-control mt-2" name="photo"
                                                        accept="image/*">
                                                </div>

                                                <!-- RIGHT: Product Details -->
                                                <div class="col-md-8">

                                                    <!-- Product Name -->
                                                    <div class="mb-3">
                                                        <label class="form-label">Product Name</label>
                                                        <input type="text" class="form-control" name="product_name"
                                                            id="editProductName" required>
                                                    </div>

                                                    <div class="mb-3">
                                                        <label class="form-label">SKU / Barcode</label>
                                                        <input type="text" class="form-control" name="sku"
                                                            id="editProductSku">
                                                    </div>


                                                    <!-- Category -->
                                                    <div class="mb-3">
                                                        <label class="form-label">Category</label>
                                                        <select class="form-select" name="category_id"
                                                            id="editProductCategory" required>
                                                            <?php foreach ($categories as $cat): ?>
                                                                <option value="<?= $cat['category_id'] ?>">
                                                                    <?= htmlspecialchars($cat['category_name']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>

                                                    <!-- Supplier -->
                                                    <div class="mb-3">
                                                        <label class="form-label">Supplier</label>
                                                        <div class="input-group">
                                                            <select class="form-select" name="supplier_id"
                                                                id="editProductSupplierSelect" required>
                                                                <?php foreach ($suppliers as $sup): ?>
                                                                    <option value="<?= $sup['supplier_id'] ?>">
                                                                        <?= htmlspecialchars($sup['supplier_name']) ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                            <button type="button" class="btn btn-outline-primary"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#editAddSupplierModal">
                                                                + Add Supplier
                                                            </button>
                                                        </div>
                                                    </div>


                                                    <!-- Price -->
                                                    <div class="mb-3">
                                                        <label class="form-label">Price</label>
                                                        <input type="number" class="form-control" name="price"
                                                            id="editProductPrice" step="0.01" required>
                                                    </div>

                                                    <!-- Sale Price (Optional) -->
                                                    <div class="mb-3">
                                                        <label class="form-label">Sale Price (Optional)</label>
                                                        <input type="number" class="form-control" name="sale_price"
                                                            id="editProductSalePrice" step="0.01">
                                                    </div>

                                                    <!-- Reorder Level -->
                                                    <div class="mb-3">
                                                        <label class="form-label">Reorder Level</label>
                                                        <input type="number" class="form-control" name="reorder_level"
                                                            id="editProductReorderLevel" value="5" min="0" required>
                                                    </div>

                                                    <!-- VATABLE -->
                                                    <div class="mb-3">
                                                        <label class="form-label">Vatable?</label>
                                                        <select class="form-select" name="vatable"
                                                            id="editProductVatable" required>
                                                            <option value="1">Yes</option>
                                                            <option value="0">No</option>
                                                        </select>
                                                    </div>

                                                </div>
                                            </div>
                                        </div>

                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary"
                                                data-bs-dismiss="modal">Close</button>
                                            <button type="submit" class="btn btn-success">Update Product</button>
                                        </div>

                                    </form>
                                </div>
                            </div>
                        </div>
                        <!-- ==========================
                                ADD SUPPLIER MODAL
                            ========================== -->
                        <!-- Add Supplier Modal -->
                        <div class="modal fade" id="addSupplierModal" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form id="addSupplierForm">
                                        <input type="hidden" name="csrf_token"
                                            value="<?= $_SESSION['csrf_token'] ?? '' ?>">

                                        <div class="modal-header">
                                            <h5 class="modal-title">Add New Supplier</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>

                                        <div class="modal-body">
                                            <div id="supplierMessage" class="modal-message-center"></div>

                                            <div class="mb-3">
                                                <label class="form-label">Supplier Name</label>
                                                <input type="text" class="form-control" name="supplier_name" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Contact Person</label>
                                                <input type="text" class="form-control" name="contact_person">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Phone</label>
                                                <input type="text" class="form-control" name="phone">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Email</label>
                                                <input type="email" class="form-control" name="email">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Address</label>
                                                <textarea class="form-control" name="address" rows="2"></textarea>
                                            </div>
                                        </div>

                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary"
                                                data-bs-dismiss="modal">Close</button>
                                            <button type="submit" class="btn btn-primary">Add Supplier</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <!-- Add Supplier Modal for Edit Product -->
                        <div class="modal fade" id="editAddSupplierModal" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form id="editAddSupplierForm">
                                        <input type="hidden" name="csrf_token"
                                            value="<?= $_SESSION['csrf_token'] ?? '' ?>">

                                        <div class="modal-header">
                                            <h5 class="modal-title">Add New Supplier</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>

                                        <div class="modal-body">
                                            <div id="editSupplierMessage" class="modal-message-center"></div>

                                            <div class="mb-3">
                                                <label class="form-label">Supplier Name</label>
                                                <input type="text" class="form-control" name="supplier_name" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Contact Person</label>
                                                <input type="text" class="form-control" name="contact_person">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Phone</label>
                                                <input type="text" class="form-control" name="phone">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Email</label>
                                                <input type="email" class="form-control" name="email">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Address</label>
                                                <textarea class="form-control" name="address" rows="2"></textarea>
                                            </div>
                                        </div>

                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary"
                                                data-bs-dismiss="modal">Close</button>
                                            <button type="submit" class="btn btn-primary">Add Supplier</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>


                        <!-- Restock MODAL -->

<div class="modal fade" id="restockModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="restockForm">
        <div class="modal-header">
          <h5 class="modal-title">Restock Product</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" id="restockProductId" name="product_id">
          <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">

          <div class="mb-3">
            <label class="form-label">Product</label>
            <input type="text" id="restockProductName" class="form-control" readonly>
          </div>

          <div class="mb-3">
            <label class="form-label">Quantity to Add</label>
            <input type="number" name="quantity" class="form-control" required min="1">
          </div>
        </div>

        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Restock</button>
        </div>
      </form>
    </div>
  </div>
</div>








            </div>
            </div>
        </section>
    </main>

    <?php require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/components/js_script.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/simple-datatables@latest" type="text/javascript"></script>
    <script>
        const CSRF_TOKEN = "<?= $csrf_token ?>";
    </script>
    <script src="<?= HOSTURL ?>/assets/js/manage_product.js"></script>
    <script>
        // Initialize Simple-DataTables
        const table = document.querySelector("#productsTable");
        if (table) {
            new simpleDatatables.DataTable(table, {
                searchable: true,
                fixedHeight: true,
                perPage: 10
            });
        }
    </script>

</body>

</html>