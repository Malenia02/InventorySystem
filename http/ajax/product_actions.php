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
        $supplierId = (int)$_POST['supplier_id'];
        $sku        = trim($_POST['sku'] ?? '');
        $price      = (float)$_POST['price'];
        $sale_price = isset($_POST['sale_price']) && $_POST['sale_price'] !== '' ? (float)$_POST['sale_price'] : null;
        $vatable    = (int)$_POST['vatable'];
        $quantity   = isset($_POST['initial_quantity']) ? (int)$_POST['initial_quantity'] : 0;
        $reorder    = isset($_POST['reorder_level']) ? (int)$_POST['reorder_level'] : 5;

        $photoPath = ProductController::handlePhotoUpload('photo', $name);

        $id = ProductController::addProduct(
            $conn,
            'products',
            $name,
            $categoryId,
            $supplierId,
            $price,
            $sale_price,
            $vatable,
            $photoPath,
            $sku
        );

        // Fetch full product info with category & supplier names
        $stmt = $conn->prepare("
            SELECT p.*, c.category_name, s.supplier_name 
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
            WHERE p.product_id = :id
        ");
        $stmt->execute(['id' => $id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        ob_start(); ?>
<tr id="productRow<?= $product['product_id'] ?>" data-id="<?= $product['product_id'] ?>">
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
                data-category_name="<?= htmlspecialchars($product['category_name'] ?? '') ?>"
                data-supplier="<?= $product['supplier_id'] ?? 0 ?>"
                data-supplier_name="<?= htmlspecialchars($product['supplier_name'] ?? '') ?>"
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
                data-status="<?= ($product['status'] ?? 'inactive') === 'active' ? 'active' : 'inactive' ?>">
            <?= ($product['status'] ?? 'inactive') === 'active' ? '<i class="bi bi-slash-circle"></i>' : '<i class="bi bi-check-circle"></i>' ?>
        </button>
    </td>
</tr>
<?php
        $response = [
            'success' => true,
            'message' => $product['product_name'] . ' added successfully!',
            'newRowHtml' => ob_get_clean()
        ];
    }

    // ==========================
    // EDIT PRODUCT
    // ==========================
    if(isset($_POST['edit_product'])) {
        $id         = (int)$_POST['product_id'];
        $name       = trim($_POST['product_name']);
        $category   = (int)$_POST['category_id'];
        $supplier   = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
        $sku        = trim($_POST['sku'] ?? '');
        $price      = (float)$_POST['price'];
        $sale_price = ($_POST['sale_price'] !== '') ? (float)$_POST['sale_price'] : null;
        $vatable    = (int)$_POST['vatable'];
        $reorder    = isset($_POST['reorder_level']) ? (int)$_POST['reorder_level'] : 5;

        // Fetch old product for comparison
        $stmt = $conn->prepare("SELECT * FROM products WHERE product_id = :id");
        $stmt->execute([':id'=>$id]);
        $old = $stmt->fetch(PDO::FETCH_ASSOC);

        // Handle photo
        $photoPath = !empty($_FILES['photo']['name']) ? ProductController::handlePhotoUpload('photo', $name) : null;

        // UPDATE PRODUCT
        $sql = "UPDATE products SET
                    product_name = :name,
                    category_id  = :category,
                    supplier_id  = :supplier,
                    sku          = :sku,
                    price        = :price,
                    sale_price   = :sale_price,
                    vatable      = :vatable,
                    reorder_level= :reorder";
        if($photoPath) $sql .= ", photo = :photo";
        $sql .= " WHERE product_id = :id";

        $params = [
            ':name'=>$name,
            ':category'=>$category,
            ':supplier'=>$supplier,
            ':sku'=>$sku,
            ':price'=>$price,
            ':sale_price'=>$sale_price,
            ':vatable'=>$vatable,
            ':reorder'=>$reorder,
            ':id'=>$id
        ];
        if($photoPath) $params[':photo']=$photoPath;

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        // Fetch updated product with category and supplier names
        $stmt = $conn->prepare("
            SELECT p.*, c.category_name, s.supplier_name 
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
            WHERE p.product_id = :id
        ");
        $stmt->execute([':id'=>$id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        // Generate field changes
        $changes = [];
        if($old['sku'] !== $sku) $changes[] = "SKU to $sku";
        if($old['price'] != $price) $changes[] = "Price to $price";
        if($old['sale_price'] != $sale_price) $changes[] = "Sale Price to $sale_price";
        if($old['category_id'] != $category) $changes[] = "Category to ".$product['category_name'];
        if($old['supplier_id'] != $supplier) $changes[] = "Supplier to ".$product['supplier_name'];
        if($old['vatable'] != $vatable) $changes[] = "Vatable to ".($vatable?'Yes':'No');
        if($old['reorder_level'] != $reorder) $changes[] = "Reorder Level to $reorder";

        $changesText = !empty($changes) ? implode(', ', $changes) : 'No changes made';

        ob_start(); ?>
<tr id="productRow<?= $product['product_id'] ?>" data-id="<?= $product['product_id'] ?>">
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
                data-category_name="<?= htmlspecialchars($product['category_name'] ?? '') ?>"
                data-supplier="<?= $product['supplier_id'] ?? 0 ?>"
                data-supplier_name="<?= htmlspecialchars($product['supplier_name'] ?? '') ?>"
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
                data-status="<?= ($product['status'] ?? 'inactive') === 'active' ? 'active' : 'inactive' ?>">
            <?= ($product['status'] ?? 'inactive') === 'active' ? '<i class="bi bi-slash-circle"></i>' : '<i class="bi bi-check-circle"></i>' ?>
        </button>
    </td>
</tr>
<?php
        $response = [
            'success' => true,
            'message' => $product['product_name'].' updated: '.$changesText,
            'newRowHtml' => ob_get_clean()
        ];
    }

    // ==========================
    // TOGGLE PRODUCT STATUS
    // ==========================
    if(isset($_POST['toggle_id'])) {
        $id = (int)$_POST['toggle_id'];

        $stmt = $conn->prepare("SELECT product_name FROM products WHERE product_id = :id LIMIT 1");
        $stmt->execute([':id'=>$id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if(!$product) {
            echo json_encode(['success'=>false, 'message'=>'Product not found']);
            exit;
        }

        $newStatus = ProductController::toggleStatus($conn, 'products', $id);

        $response = [
            'success'=>true,
            'message'=>$product['product_name']." status changed to ".$newStatus,
            'new_status'=>$newStatus,
            'product_name'=>$product['product_name']
        ];
    }

} catch(Exception $e){
    $response = ['success'=>false,'error'=>$e->getMessage()];
}

echo json_encode($response);