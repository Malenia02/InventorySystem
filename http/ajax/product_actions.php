<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/middleware/Middleware.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/controllers/ProductController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/controllers/NotificationController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/controllers/AuthController.php';

Middleware::auth()
    ->role('admin')
    ->ajax()
    ->methods(['POST'])
    ->csrf();


try {
    $sessionUserId = (int)($_SESSION['user_id'] ?? 0);
    $sessionRole   = $_SESSION['role'] ?? 'admin';

    $logConfig = [
        'table'       => $table_activity_logs,
        'col_user_id' => $activity_log_user_id,
        'col_action'  => $activity_log_action,
        'col_desc'    => $activity_log_desc,
        'col_ip'      => $activity_log_ip,
        'col_created' => $activity_log_created,
    ];

    if ($sessionUserId <= 0) {
        echo json_encode([
            'success' => false,
            'error'   => 'Unauthorized'
        ]);
        exit;
    }

    // ============================
    // ADD PRODUCT
    // ============================
    if (isset($_POST['add_product'])) {
        $name        = trim($_POST['product_name'] ?? '');
        $categoryId  = (int)($_POST['category_id'] ?? 0);
        $supplierId  = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
        $sku         = trim($_POST['sku'] ?? '');
        $price       = (float)($_POST['price'] ?? 0);
        $salePrice   = (isset($_POST['sale_price']) && $_POST['sale_price'] !== '') ? (float)$_POST['sale_price'] : null;
        $vatable     = (int)($_POST['vatable'] ?? 0);
        $quantity    = (int)($_POST['initial_quantity'] ?? 0);
        $reorder     = (int)($_POST['reorder_level'] ?? 5);

        try {
            $photoPath = ProductController::handlePhotoUpload('photo', $name);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error'   => $e->getMessage()
            ]);
            exit;
        }

        $productId = ProductController::addProduct($conn, [
            'name'             => $name,
            'category_id'      => $categoryId,
            'supplier_id'      => $supplierId,
            'price'            => $price,
            'sale_price'       => $salePrice,
            'vatable'          => $vatable,
            'initial_quantity' => $quantity,
            'photo'            => $photoPath,
            'sku'              => $sku,
            'user_id'          => $sessionUserId,
            'reorder_level'    => $reorder
        ]);

        $product = ProductController::getProductById($conn, $productId);

        ob_start();
        include $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/templates/product_row.php';
        $newRowHtml = ob_get_clean();

        NotificationController::create(
            $conn,
            $sessionUserId,
            'admin',
            'product',
            'Product Added',
            "{$name} was added to inventory.",
            'bi-box-seam',
            'text-success',
            '/inventory_system/product_management/manage_product.php'
        );

        AuthController::logActivity(
            $conn,
            $logConfig,
            $sessionUserId,
            'product_add',
            "Added product: {$name}" . ($sku !== '' ? " (SKU: {$sku})" : '')
        );

        echo json_encode([
            'success'    => true,
            'message'    => "{$name} added successfully!",
            'event'      => 'notification_update',
            'type'       => 'product',
            'newRowHtml' => $newRowHtml
        ]);
        exit;
    }

    // ============================
    // EDIT PRODUCT
    // ============================
    if (isset($_POST['edit_product'])) {
        $id        = (int)($_POST['product_id'] ?? 0);
        $name      = trim($_POST['product_name'] ?? '');
        $category  = (int)($_POST['category_id'] ?? 0);
        $supplier  = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
        $sku       = trim($_POST['sku'] ?? '');
        $price     = (float)($_POST['price'] ?? 0);
        $salePrice = (isset($_POST['sale_price']) && $_POST['sale_price'] !== '') ? (float)$_POST['sale_price'] : null;
        $vatable   = (int)($_POST['vatable'] ?? 0);
        $reorder   = (int)($_POST['reorder_level'] ?? 5);

        $old = ProductController::getProductById($conn, $id);
        if (!$old) {
            throw new Exception('Product not found.');
        }

        $photoPath = null;
        if (!empty($_FILES['photo']['name'])) {
            $photoPath = ProductController::handlePhotoUpload('photo', $name);
        }

        ProductController::updateProduct($conn, $id, [
            'name'          => $name,
            'category_id'   => $category,
            'supplier_id'   => $supplier,
            'sku'           => $sku,
            'price'         => $price,
            'sale_price'    => $salePrice,
            'vatable'       => $vatable,
            'reorder_level' => $reorder,
            'photo'         => $photoPath
        ]);

        $product = ProductController::getProductById($conn, $id);

        $changes = [];
        if ($old['product_name'] !== $name) $changes[] = "Name: {$old['product_name']} → {$name}";
        if ((string)$old['sku'] !== (string)$sku) $changes[] = "SKU: {$old['sku']} → {$sku}";
        if ((float)$old['price'] !== $price) $changes[] = "Price: {$old['price']} → {$price}";
        if ((string)$old['sale_price'] !== (string)$salePrice) $changes[] = "Sale Price: {$old['sale_price']} → {$salePrice}";
        if ((int)$old['vatable'] !== $vatable) $changes[] = "Vatable: {$old['vatable']} → {$vatable}";
        if ((int)$old['reorder_level'] !== $reorder) $changes[] = "Reorder Level: {$old['reorder_level']} → {$reorder}";
        if ((int)$old['category_id'] !== $category) $changes[] = "Category: {$old['category_name']} → {$product['category_name']}";
        if ((int)$old['supplier_id'] !== (int)$supplier) $changes[] = "Supplier: {$old['supplier_name']} → {$product['supplier_name']}";

        ob_start();
        include $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/templates/product_row.php';
        $newRowHtml = ob_get_clean();

        $message = !empty($changes)
            ? "<b>{$old['product_name']}</b> was updated:<br>" . implode("<br>", $changes)
            : "No changes were made.";

        NotificationController::create(
            $conn,
            $sessionUserId,
            'admin',
            'product',
            'Product Updated',
            "{$name} was updated.",
            'bi-pencil-square',
            'text-warning',
            '/inventory_system/product_management/manage_product.php'
        );

        AuthController::logActivity(
            $conn,
            $logConfig,
            $sessionUserId,
            'product_update',
            !empty($changes)
                ? "Updated product {$old['product_name']}: " . implode(' | ', $changes)
                : "Opened update on {$old['product_name']} but no changes were made"
        );

        echo json_encode([
            'success'    => true,
            'message'    => $message,
            'event'      => 'notification_update',
            'type'       => 'product',
            'newRowHtml' => $newRowHtml
        ]);
        exit;
    }

    // ============================
    // TOGGLE STATUS
    // ============================
    if (isset($_POST['toggle_id'])) {
        $id = (int)($_POST['toggle_id'] ?? 0);

        $product = ProductController::getProductById($conn, $id);
        if (!$product) {
            throw new Exception('Product not found.');
        }

        $newStatus = ProductController::toggleStatus($conn, $id);
        $product   = ProductController::getProductById($conn, $id);

        ob_start();
        include $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/templates/product_row.php';
        $newRowHtml = ob_get_clean();

        NotificationController::create(
            $conn,
            $sessionUserId,
            'admin',
            'product_status',
            'Product Status Changed',
            $product['product_name'] . ' status changed to ' . ucfirst($newStatus),
            'bi-arrow-repeat',
            'text-info',
            '/inventory_system/product_management/manage_product.php'
        );

        AuthController::logActivity(
            $conn,
            $logConfig,
            $sessionUserId,
            'product_status_update',
            $product['product_name'] . ' status changed to ' . ucfirst($newStatus)
        );

        echo json_encode([
            'success'    => true,
            'message'    => $product['product_name'] . ' status changed to ' . ucfirst($newStatus),
            'event'      => 'notification_update',
            'type'       => 'product_status',
            'new_status' => $newStatus,
            'newRowHtml' => $newRowHtml
        ]);
        exit;
    }

    // ============================
    // RESTOCK
    // ============================
    if (isset($_POST['restock_product'])) {
        $productId = (int)($_POST['product_id'] ?? 0);
        $quantity  = (int)($_POST['quantity'] ?? 0);

        if ($productId <= 0 || $quantity <= 0) {
            echo json_encode([
                'success' => false,
                'error'   => 'Invalid product or quantity.'
            ]);
            exit;
        }

        $product = ProductController::getProductById($conn, $productId);
        if (!$product || $product['status'] !== 'active') {
            echo json_encode([
                'success' => false,
                'error'   => 'Product not found or inactive. Refresh page.'
            ]);
            exit;
        }

        ProductController::restockProduct($conn, $productId, $quantity, $sessionUserId);
        $updated = ProductController::getProductById($conn, $productId);

        NotificationController::create(
            $conn,
            $sessionUserId,
            'admin',
            'stock_in',
            'Product Restocked',
            $product['product_name'] . " (+{$quantity}) = {$updated['quantity']} units",
            'bi-box-arrow-in-down',
            'text-success',
            '/inventory_system/product_management/manage_product.php'
        );

        AuthController::logActivity(
            $conn,
            $logConfig,
            $sessionUserId,
            'product_restock',
            $product['product_name'] . " restocked by {$quantity} unit(s). New quantity: {$updated['quantity']}"
        );

        echo json_encode([
            'success'      => true,
            'message'      => $product['product_name'] . " (+{$quantity}) = {$updated['quantity']} units",
            'event'        => 'notification_update',
            'type'         => 'stock_in',
            'new_quantity' => $updated['quantity'],
            'product_id'   => $productId
        ]);
        exit;
    }

    // ============================
    // STOCK OUT
    // ============================
    if (isset($_POST['stockout_product'])) {
        $productId = (int)($_POST['product_id'] ?? 0);
        $quantity  = (int)($_POST['quantity'] ?? 0);
        $reason    = trim($_POST['reason'] ?? '');

        if ($productId <= 0 || $quantity <= 0) {
            echo json_encode([
                'success' => false,
                'error'   => 'Invalid product or quantity.'
            ]);
            exit;
        }

        $product = ProductController::getProductById($conn, $productId);
        if (!$product || $product['status'] !== 'active') {
            echo json_encode([
                'success' => false,
                'error'   => 'Product not found or inactive.'
            ]);
            exit;
        }

        ProductController::stockOutProduct($conn, $productId, $quantity, $reason, $sessionUserId);
        $updated = ProductController::getProductById($conn, $productId);

        NotificationController::create(
            $conn,
            $sessionUserId,
            'admin',
            'stock_out',
            'Stock Out',
            $product['product_name'] . " (-{$quantity}) = {$updated['quantity']} units",
            'bi-box-arrow-up',
            'text-danger',
            '/inventory_system/product_management/manage_product.php'
        );

        AuthController::logActivity(
            $conn,
            $logConfig,
            $sessionUserId,
            'product_stock_out',
            $product['product_name'] . " stock-out by {$quantity} unit(s)" . ($reason !== '' ? " | Reason: {$reason}" : '') . ". New quantity: {$updated['quantity']}"
        );

        echo json_encode([
            'success'      => true,
            'message'      => $product['product_name'] . " (-{$quantity}) = {$updated['quantity']} units",
            'event'        => 'notification_update',
            'type'         => 'stock_out',
            'new_quantity' => $updated['quantity'],
            'product_id'   => $productId
        ]);
        exit;
    }

    echo json_encode([
        'success' => false,
        'error'   => 'Invalid action'
    ]);
    exit;

} catch (\Throwable $e) {
    error_log('[product_actions ERROR] ' . $e->getMessage());

    echo json_encode([
        'success' => false,
        'error'   => 'Internal server error'
    ]);
    exit;
}