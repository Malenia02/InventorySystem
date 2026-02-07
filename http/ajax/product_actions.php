<?php
header('Content-Type: application/json');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once $_SERVER['DOCUMENT_ROOT'].'/inventory_system/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/inventory_system/middleware/csrf.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/inventory_system/controllers/ProductController.php';

$response = ['success' => false, 'error' => 'Invalid action'];

// ============================
// CSRF PROTECTION
// ============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['error' => 'Invalid CSRF token.']);
        exit;
    }
}

try {

    // ==========================
    // ADD PRODUCT
    // ==========================
    if(isset($_POST['add_product'])) {
        $name       = trim($_POST['product_name']);
        $categoryId = (int)$_POST['category_id'];
        $supplier   = (int)$_POST['supplier_id'];
        $sku        = trim($_POST['sku'] ?? '');
        $price      = (float)$_POST['price'];
        $sale_price = isset($_POST['sale_price']) && $_POST['sale_price'] !== '' ? (float)$_POST['sale_price'] : null;
        $vatable    = (int)$_POST['vatable'];
        $quantity   = isset($_POST['initial_quantity']) ? (int)$_POST['initial_quantity'] : 0;
        $reorder    = isset($_POST['reorder_level']) ? (int)$_POST['reorder_level'] : 5;

        // Handle photo upload
        $photoPath = ProductController::handlePhotoUpload('photo', $name);

        $id = ProductController::addProduct(
            $conn,
            'products',
            $name,
            $categoryId,
            $supplier,
            $price,
            $sale_price,
            $vatable,
            $photoPath,
            $sku
        );

        // Fetch full product info with category & supplier names
        $stmt = $conn->prepare("SELECT p.*, c.category_name, s.supplier_name 
                                FROM products p 
                                LEFT JOIN categories c ON p.category_id = c.category_id
                                LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
                                WHERE p.product_id = :id");
        $stmt->execute(['id' => $id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        // Build new row HTML
        ob_start(); ?>
<tr id="productRow<?= $product['product_id'] ?>">
    <td>#</td>
    <td class="text-center">
        <img src="<?= $product['photo'] ?: '/inventory_system/assets/uploads/products/images.jpeg' ?>" style="width:50px;height:50px;object-fit:cover;">
    </td>
    <td><?= htmlspecialchars($product['product_name'] ?? '') ?></td>
    <td><?= htmlspecialchars($product['category_name'] ?? '') ?></td>
    <td><?= htmlspecialchars($product['supplier_name'] ?? '') ?></td>
    <td><?= htmlspecialchars($product['sku'] ?? '') ?></td>
    <td><?= $product['quantity'] ?? 0 ?></td>
    <td>₱<?= number_format($product['price'] ?? 0, 2) ?></td>
    <td><?= isset($product['sale_price']) ? '₱'.number_format($product['sale_price'], 2) : '-' ?></td>
    <td><?= !empty($product['vatable']) ? 'Yes' : 'No' ?></td>
    <td><?= $product['reorder_level'] ?? 5 ?></td>
    <td>
        <span class="badge <?= ($product['status'] ?? 'inactive') === 'active' ? 'bg-success' : 'bg-secondary' ?>">
            <?= ucfirst($product['status'] ?? 'inactive') ?>
        </span>
    </td>
    <td>
        <button class="btn btn-sm btn-warning editProductBtn" 
                data-id="<?= $product['product_id'] ?>"
                data-name="<?= htmlspecialchars($product['product_name'] ?? '') ?>"
                data-category="<?= $product['category_id'] ?? 0 ?>"
                data-supplier="<?= $product['supplier_id'] ?? 0 ?>"
                data-sku="<?= htmlspecialchars($product['sku'] ?? '') ?>"
                data-quantity="<?= $product['quantity'] ?? 0 ?>"
                data-price="<?= $product['price'] ?? 0 ?>"
                data-sale_price="<?= $product['sale_price'] ?? '' ?>"
                data-vatable="<?= $product['vatable'] ?? 0 ?>"
                data-reorder="<?= $product['reorder_level'] ?? 5 ?>"
                data-photo="<?= $product['photo'] ?? '' ?>"
                data-bs-toggle="modal" data-bs-target="#editProductModal">
            <i class="bi bi-pencil-square"></i>
        </button>
        <button class="btn btn-sm btn-danger toggleProductStatusBtn"
                data-id="<?= $product['product_id'] ?>" data-status="deactivate">
            <i class="bi bi-slash-circle"></i>
        </button>
    </td>
</tr>
<?php
        $response = ['success' => 'Product added successfully!', 'newProductRow' => ob_get_clean()];
    }

    // ==========================
    // EDIT PRODUCT
    // ==========================
    if (isset($_POST['edit_product'])) {

        $id         = (int)$_POST['product_id'];
        $name       = trim($_POST['product_name']);
        $category   = (int)$_POST['category_id'];
        $supplier   = (!empty($_POST['supplier_id'])) ? (int)$_POST['supplier_id'] : null;
        $sku        = trim($_POST['sku'] ?? '');
        $price      = (float)$_POST['price'];
        $sale_price = ($_POST['sale_price'] !== '') ? (float)$_POST['sale_price'] : null;
        $vatable    = (int)$_POST['vatable'];
        $reorder    = isset($_POST['reorder_level']) ? (int)$_POST['reorder_level'] : 5;

        // Validate supplier exists
        if ($supplier !== null) {
            $checkSupplier = $conn->prepare("SELECT supplier_id FROM suppliers WHERE supplier_id = :supplier_id LIMIT 1");
            $checkSupplier->execute([':supplier_id' => $supplier]);
            if (!$checkSupplier->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Selected supplier does not exist.']);
                exit;
            }
        }

        // Handle photo upload
        $photoPath = !empty($_FILES['photo']['name']) ? ProductController::handlePhotoUpload('photo', $name) : null;

        // UPDATE PRODUCT including SKU
        $sql = "
            UPDATE products SET
                product_name   = :name,
                category_id    = :category,
                supplier_id    = :supplier,
                sku            = :sku,
                price          = :price,
                sale_price     = :sale_price,
                vatable        = :vatable,
                reorder_level  = :reorder
                " . ($photoPath ? ", photo = :photo" : "") . "
            WHERE product_id = :id
        ";

        $stmt = $conn->prepare($sql);
        $params = [
            ':name'       => $name,
            ':category'   => $category,
            ':supplier'   => $supplier,
            ':sku'        => $sku,
            ':price'      => $price,
            ':sale_price' => $sale_price,
            ':vatable'    => $vatable,
            ':reorder'    => $reorder,
            ':id'         => $id
        ];
        if ($photoPath) $params[':photo'] = $photoPath;
        $stmt->execute($params);

        // Fetch updated product and return row HTML
        $stmt = $conn->prepare("
            SELECT p.*, c.category_name, s.supplier_name
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
            WHERE p.product_id = :id
        ");
        $stmt->execute([':id' => $id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        ob_start(); ?>
<tr id="productRow<?= $product['product_id'] ?>">
    <td>#</td>
    <td class="text-center">
        <img src="<?= $product['photo'] ?: '/inventory_system/assets/uploads/products/images.jpeg' ?>" style="width:50px;height:50px;object-fit:cover;">
    </td>
    <td><?= htmlspecialchars($product['product_name'] ?? '') ?></td>
    <td><?= htmlspecialchars($product['category_name'] ?? '') ?></td>
    <td><?= htmlspecialchars(string: $product['supplier_name'] ?? '') ?></td>
    <td><?= htmlspecialchars($product['sku'] ?? '') ?></td>
    <td><?= $product['quantity'] ?? 0 ?></td>
    <td>₱<?= number_format($product['price'] ?? 0, 2) ?></td>
    <td><?= isset($product['sale_price']) ? '₱'.number_format($product['sale_price'], 2) : '-' ?></td>
    <td><?= !empty($product['vatable']) ? 'Yes' : 'No' ?></td>
    <td><?= $product['reorder_level'] ?? 5 ?></td>
    <td>
        <span class="badge <?= ($product['status'] ?? 'inactive') === 'active' ? 'bg-success' : 'bg-secondary' ?>">
            <?= ucfirst($product['status'] ?? 'inactive') ?>
        </span>
    </td>
    <td>
        <button class="btn btn-sm btn-warning editProductBtn"
                data-id="<?= $product['product_id'] ?>"
                data-name="<?= htmlspecialchars($product['product_name'] ?? '') ?>"
                data-category="<?= $product['category_id'] ?? 0 ?>"
                data-supplier="<?= $product['supplier_id'] ?? 0 ?>"
                data-sku="<?= htmlspecialchars($product['sku'] ?? '') ?>"
                data-quantity="<?= $product['quantity'] ?? 0 ?>"
                data-price="<?= $product['price'] ?? 0 ?>"
                data-sale_price="<?= $product['sale_price'] ?? '' ?>"
                data-vatable="<?= $product['vatable'] ?? 0 ?>"
                data-reorder="<?= $product['reorder_level'] ?? 5 ?>"
                data-photo="<?= $product['photo'] ?? '' ?>"
                data-bs-toggle="modal" data-bs-target="#editProductModal">
            <i class="bi bi-pencil-square"></i>
        </button>
        <button class="btn btn-sm <?= ($product['status'] ?? 'inactive') === 'active' ? 'btn-danger' : 'btn-success' ?> toggleProductStatusBtn"
                data-id="<?= $product['product_id'] ?>"
                data-status="<?= ($product['status'] ?? 'inactive') === 'active' ? 'deactivate' : 'activate' ?>">
            <?= ($product['status'] ?? 'inactive') === 'active' ? '<i class="bi bi-slash-circle"></i>' : '<i class="bi bi-check-circle"></i>' ?>
        </button>
    </td>
</tr>
<?php
        $response = ['success' => 'Product updated successfully!', 'updatedRowHtml' => ob_get_clean()];
    }

    // ==========================
    // TOGGLE PRODUCT STATUS
    // ==========================
    if(isset($_POST['toggle_id'])) {
        $id = (int)$_POST['toggle_id'];
        $newStatus = ProductController::toggleStatus($conn, 'products', $id);

        if(!$newStatus) {
            $response = ['error' => 'Product not found'];
        } else {
            $response = ['success' => "Status updated to $newStatus", 'new_status' => $newStatus];
        }
    }

} catch(Exception $e) {
    $response = ['error' => $e->getMessage()];
}

echo json_encode($response);
