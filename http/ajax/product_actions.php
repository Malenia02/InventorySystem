<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/middleware/csrf.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/controllers/ProductController.php';

$response = ['success' => false, 'error' => 'Invalid action'];

try {
    // ============================
    // CSRF PROTECTION
    // ============================
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
            exit;
        }
    }

    // ============================
    // ADD PRODUCT
    // ============================
    if (isset($_POST['add_product'])) {

        $userID = $_SESSION['user_id'] ?? null;
        if (!$userID) {
            echo json_encode([
                'success' => false,
                'error' => 'You must be logged in to add a product.'
            ]);
            exit;
        }

        // Get form data
        $name = trim($_POST['product_name']);
        $categoryId = (int) $_POST['category_id'];
        $supplierId = (int) $_POST['supplier_id'];
        $sku = trim($_POST['sku'] ?? '');
        $price = (float) $_POST['price'];
        $sale_price = isset($_POST['sale_price']) && $_POST['sale_price'] !== '' ? (float) $_POST['sale_price'] : null;
        $vatable = (int) $_POST['vatable'];
        $quantity = isset($_POST['initial_quantity']) ? (int) $_POST['initial_quantity'] : 0;

        // Handle photo
        try {
            $photoPath = ProductController::handlePhotoUpload('photo', $name);
        } catch (Exception $e) {
            $photoPath = null;
        }

        // Add product
        $productId = ProductController::addProduct(
            $conn,
            'products',
            $name,
            $categoryId,
            $supplierId,
            $price,
            $sale_price,
            $vatable,
            $quantity,
            $photoPath,
            $sku,
            $userID
        );

        // Fetch newly added product
        $stmt = $conn->prepare("
            SELECT p.*, c.category_name, s.supplier_name 
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
            WHERE p.product_id = :id
        ");
        $stmt->execute(['id' => $productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        // Render HTML row for DataTable
        ob_start();
        include $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/templates/product_row.php';
        $newRowHtml = ob_get_clean();

        echo json_encode([
            'success' => true,
            'message' => "$name added successfully!",
            'newRowHtml' => $newRowHtml
        ]);
        exit;
    }

    // ============================
    // EDIT PRODUCT (keep detect changes)
    // ============================
    if (isset($_POST['edit_product'])) {

        $id = (int) $_POST['product_id'];
        $name = trim($_POST['product_name']);
        $category = (int) $_POST['category_id'];
        $supplier = !empty($_POST['supplier_id']) ? (int) $_POST['supplier_id'] : null;
        $sku = trim($_POST['sku'] ?? '');
        $price = (float) $_POST['price'];
        $sale_price = ($_POST['sale_price'] !== '') ? (float) $_POST['sale_price'] : null;
        $vatable = (int) $_POST['vatable'];
        $reorder = isset($_POST['reorder_level']) ? (int) $_POST['reorder_level'] : 5;

        // Fetch old product
        $stmt = $conn->prepare("
            SELECT p.*, c.category_name, s.supplier_name
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
            WHERE p.product_id = :id
        ");
        $stmt->execute([':id' => $id]);
        $old = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$old) throw new Exception("Product not found");

        // Handle photo
        $photoPath = !empty($_FILES['photo']['name'])
            ? ProductController::handlePhotoUpload('photo', $name)
            : null;

        // Update product
        $sql = "UPDATE products SET
                product_name = :name,
                category_id = :category,
                supplier_id = :supplier,
                sku = :sku,
                price = :price,
                sale_price = :sale_price,
                vatable = :vatable,
                reorder_level = :reorder";
        if ($photoPath) $sql .= ", photo = :photo";
        $sql .= " WHERE product_id = :id";

        $params = [
            ':name' => $name,
            ':category' => $category,
            ':supplier' => $supplier,
            ':sku' => $sku,
            ':price' => $price,
            ':sale_price' => $sale_price,
            ':vatable' => $vatable,
            ':reorder' => $reorder,
            ':id' => $id
        ];
        if ($photoPath) $params[':photo'] = $photoPath;

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        // Fetch updated product
        $stmt = $conn->prepare("
            SELECT p.*, c.category_name, s.supplier_name 
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
            WHERE p.product_id = :id
        ");
        $stmt->execute([':id' => $id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        // Detect changes
        $changes = [];
        if ($old['product_name'] !== $name) $changes[] = "Name: {$old['product_name']} → $name";
        if ($old['sku'] !== $sku) $changes[] = "SKU: {$old['sku']} → $sku";
        if ((float)$old['price'] !== $price) $changes[] = "Price: {$old['price']} → $price";
        if ((float)$old['sale_price'] !== (float)$sale_price) $changes[] = "Sale Price: {$old['sale_price']} → $sale_price";
        if ((int)$old['vatable'] !== $vatable) $changes[] = "Vatable: {$old['vatable']} → $vatable";
        if ((int)$old['reorder_level'] !== $reorder) $changes[] = "Reorder Level: {$old['reorder_level']} → $reorder";
        if ($old['category_id'] != $category) $changes[] = "Category: {$old['category_name']} → {$product['category_name']}";
        if ($old['supplier_id'] != $supplier) $changes[] = "Supplier: {$old['supplier_name']} → {$product['supplier_name']}";

        // Render new row HTML
        ob_start();
        include $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/templates/product_row.php';
        $newRowHtml = ob_get_clean();

        $message = !empty($changes) ? ("<b>{$old['product_name']}</b> was updated:<br>" . implode("<br>", $changes)) : "No changes were made.";

        echo json_encode([
            'success' => true,
            'message' => $message,
            'newRowHtml' => $newRowHtml
        ]);
        exit;
    }

    // ============================
    // TOGGLE STATUS (return newRowHtml)
    // ============================
    if (isset($_POST['toggle_id'])) {
        $id = (int) $_POST['toggle_id'];
        $stmt = $conn->prepare("
            SELECT p.*, c.category_name, s.supplier_name 
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
            WHERE p.product_id = :id
        ");
        $stmt->execute([':id' => $id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) throw new Exception("Product not found");

        $newStatus = ProductController::toggleStatus($conn, 'products', $id);

        // Fetch updated product row
        $stmt->execute([':id' => $id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        ob_start();
        include $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/templates/product_row.php';
        $newRowHtml = ob_get_clean();

        echo json_encode([
            'success' => true,
            'message' => $product['product_name'] . " status changed to " . ucfirst($newStatus),
            'new_status' => $newStatus,
            'newRowHtml' => $newRowHtml
        ]);
        exit;
    }

    // ============================
    // RESTOCK (unchanged)
    // ============================
    if (isset($_POST['restock_product'])) {
        $productId = (int) $_POST['product_id'];
        $quantity = (int) $_POST['quantity'];
        $userId = $_SESSION['user_id'] ?? null;

        ProductController::restockProduct($conn, $productId, $quantity, $userId);

        $stmt = $conn->prepare("SELECT quantity, product_name FROM products WHERE product_id = :id");
        $stmt->execute([':id' => $productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'message' => $product['product_name'] . " restocked successfully!",
            'new_quantity' => $product['quantity'],
            'product_id' => $productId
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}