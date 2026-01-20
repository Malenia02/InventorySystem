<?php
header('Content-Type: application/json');
require_once $_SERVER['DOCUMENT_ROOT'].'/inventory_system/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/inventory_system/controllers/ProductController.php';

$response = ['success' => false, 'error' => 'Invalid action'];



try {

    // ==========================
    // ADD PRODUCT
    // ==========================
    if(isset($_POST['add_product'])) {
        $name = $_POST['product_name'];
        $category = $_POST['category_id'];
        $price = $_POST['price'];
        $sale_price = $_POST['sale_price'] ?? null;
        $vatable = $_POST['vatable'];
        $photoPath = ProductController::handlePhotoUpload('photo', $name);

        $id = ProductController::addProduct($conn, 'products', $name, $category, $price, $sale_price, $vatable, $photoPath);

        $product = ProductController::getProductById($conn, 'products', $id);
        $categoryName = $product['category_id']; 

        ob_start();
        ?>
        <tr id="productRow<?= $product['product_id'] ?>">
            <td>#</td>
            <td class="text-center">
                <img src="<?= $product['photo'] ?: '/inventory_system/assets/uploads/products/images.jpeg' ?>" style="width:50px;height:50px;object-fit:cover;">
            </td>
            <td><?= htmlspecialchars($product['product_name']) ?></td>
            <td><?= htmlspecialchars($product['category_id']) ?></td>
            <td>₱<?= number_format($product['price'],2) ?></td>
            <td><?= $product['sale_price'] ? '₱'.number_format($product['sale_price'],2) : '-' ?></td>
            <td><?= $product['vatable'] ? 'Yes' : 'No' ?></td>
            <td>
                <span class="badge bg-success">Active</span>
            </td>
            <td>
                <button class="btn btn-sm btn-warning editProductBtn" 
                        data-id="<?= $product['product_id'] ?>"
                        data-name="<?= htmlspecialchars($product['product_name']) ?>"
                        data-category="<?= $product['category_id'] ?>"
                        data-price="<?= $product['price'] ?>"
                        data-sale_price="<?= $product['sale_price'] ?>"
                        data-vatable="<?= $product['vatable'] ?>"
                        data-photo="<?= $product['photo'] ?>"
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
    if(isset($_POST['edit_product'])) {
        $id = $_POST['product_id'];
        $name = $_POST['product_name'];
        $category = $_POST['category_id'];
        $price = $_POST['price'];
        $sale_price = $_POST['sale_price'] ?? null;
        $vatable = $_POST['vatable'];

        $photoPath = null;
        if(!empty($_FILES['photo']['name'])){
            $photoPath = ProductController::handlePhotoUpload('photo', $name);
        }

        ProductController::updateProduct($conn, 'products', $id, $name, $category, $price, $sale_price, $vatable, $photoPath);

        $product = ProductController::getProductById($conn, 'products', $id);

        ob_start();
        ?>
        <tr id="productRow<?= $product['product_id'] ?>">
            <td>#</td>
            <td class="text-center">
                <img src="<?= $product['photo'] ?: '/inventory_system/assets/uploads/products/images.jpeg' ?>" style="width:50px;height:50px;object-fit:cover;">
            </td>
            <td><?= htmlspecialchars($product['product_name']) ?></td>
            <td><?= htmlspecialchars($product['category_id']) ?></td>
            <td>₱<?= number_format($product['price'],2) ?></td>
            <td><?= $product['sale_price'] ? '₱'.number_format($product['sale_price'],2) : '-' ?></td>
            <td><?= $product['vatable'] ? 'Yes' : 'No' ?></td>
            <td>
                <span class="badge <?= $product['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                    <?= ucfirst($product['status']) ?>
                </span>
            </td>
            <td>
                <button class="btn btn-sm btn-warning editProductBtn" 
                        data-id="<?= $product['product_id'] ?>"
                        data-name="<?= htmlspecialchars($product['product_name']) ?>"
                        data-category="<?= $product['category_id'] ?>"
                        data-price="<?= $product['price'] ?>"
                        data-sale_price="<?= $product['sale_price'] ?>"
                        data-vatable="<?= $product['vatable'] ?>"
                        data-photo="<?= $product['photo'] ?>"
                        data-bs-toggle="modal" data-bs-target="#editProductModal">
                    <i class="bi bi-pencil-square"></i>
                </button>
                <button class="btn btn-sm <?= $product['status'] === 'active' ? 'btn-danger' : 'btn-success' ?> toggleProductStatusBtn"
                        data-id="<?= $product['product_id'] ?>"
                        data-status="<?= $product['status'] === 'active' ? 'deactivate' : 'activate' ?>">
                    <?= $product['status'] === 'active' ? '<i class="bi bi-slash-circle"></i>' : '<i class="bi bi-check-circle"></i>' ?>
                </button>
            </td>
        </tr>
        <?php
        $response = ['success' => 'Product updated successfully!', 'updatedRowHtml' => ob_get_clean()];
    }

    // ==========================
    // TOGGLE STATUS
    // ==========================
    if(isset($_POST['toggle_id'])) {
        $id = $_POST['toggle_id'];
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
