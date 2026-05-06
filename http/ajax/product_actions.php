<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap/app.php';
require_once __DIR__ . '/../../middleware/Middleware.php';
require_once __DIR__ . '/../../controllers/ProductController.php';
require_once __DIR__ . '/../../controllers/NotificationController.php';
require_once __DIR__ . '/../../controllers/AuthController.php';

header('Content-Type: application/json; charset=UTF-8');

Middleware::auth()
    ->role(['admin'])
    ->ajax()
    ->methods(['POST'])
    ->csrf()
    ->throttle('product_actions', 40, 60, 'Too many product changes. Please slow down and try again.');

function jsonResponse(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function safeCreateProductNotification(
    PDO $conn,
    ?int $userId,
    string $roleTarget,
    string $type,
    string $title,
    string $message,
    string $icon = 'bi-bell',
    string $color = 'text-primary',
    ?string $link = null
): void {
    try {
        NotificationController::create(
            $conn,
            $userId,
            $roleTarget,
            $type,
            $title,
            $message,
            $icon,
            $color,
            $link
        );
    } catch (Throwable $e) {
        error_log('[product_actions notification] ' . $e->getMessage());
    }
}

try {
    $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);

    if ($sessionUserId <= 0) {
        jsonResponse([
            'success' => false,
            'error'   => 'Unauthorized.'
        ], 401);
    }

    $logConfig = [
        'table'       => $table_activity_logs,
        'col_user_id' => $activity_log_user_id,
        'col_action'  => $activity_log_action,
        'col_desc'    => $activity_log_desc,
        'col_ip'      => $activity_log_ip,
        'col_created' => $activity_log_created,
    ];

    if (isset($_POST['add_product'])) {
        $name = trim((string) ($_POST['product_name'] ?? ''));
        $photoPath = null;

        try {
            $photoPath = ProductController::handlePhotoUpload('photo', $name);

            $productId = ProductController::addProduct($conn, [
                'name'             => $name,
                'category_id'      => (int) ($_POST['category_id'] ?? 0),
                'subcategory_id'   => !empty($_POST['subcategory_id']) ? (int) $_POST['subcategory_id'] : null,
                'supplier_id'      => !empty($_POST['supplier_id']) ? (int) $_POST['supplier_id'] : null,
                'sku'              => trim((string) ($_POST['sku'] ?? '')),
                'price'            => (float) ($_POST['price'] ?? 0),
                'box_price'        => (isset($_POST['box_price']) && $_POST['box_price'] !== '') ? (float) $_POST['box_price'] : null,
                'case_price'       => (isset($_POST['case_price']) && $_POST['case_price'] !== '') ? (float) $_POST['case_price'] : null,
                'sale_price'       => (isset($_POST['sale_price']) && $_POST['sale_price'] !== '') ? (float) $_POST['sale_price'] : null,
                'box_sale_price'   => (isset($_POST['box_sale_price']) && $_POST['box_sale_price'] !== '') ? (float) $_POST['box_sale_price'] : null,
                'case_sale_price'  => (isset($_POST['case_sale_price']) && $_POST['case_sale_price'] !== '') ? (float) $_POST['case_sale_price'] : null,
                'vatable'          => !empty($_POST['vatable']) ? 1 : 0,
                'pieces_per_box'   => (int) ($_POST['pieces_per_box'] ?? 1),
                'boxes_per_case'   => (int) ($_POST['boxes_per_case'] ?? 1),
                'initial_quantity' => (int) ($_POST['initial_quantity'] ?? 0),
                'reorder_level'    => (int) ($_POST['reorder_level'] ?? 5),
                'photo'            => $photoPath,
                'user_id'          => $sessionUserId,
            ]);
        } catch (Throwable $e) {
            ProductController::cleanupUploadedPhoto($photoPath);
            throw $e;
        }

        $product = ProductController::getProductById($conn, $productId);

        ob_start();
        include __DIR__ . '/../../templates/product_row.php';
        $newRowHtml = ob_get_clean();

        safeCreateProductNotification(
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
            "Added product: {$name}" . (!empty($_POST['sku']) ? " (SKU: " . trim((string) $_POST['sku']) . ')' : ''),
            'product',
            $productId
        );

        jsonResponse([
            'success'    => true,
            'message'    => "{$name} added successfully.",
            'event'      => 'notification_update',
            'type'       => 'product',
            'newRowHtml' => $newRowHtml
        ], 201);
    }

    if (isset($_POST['bulk_create_products'])) {
        $products = $_POST['products'] ?? [];

        if (!is_array($products) || $products === []) {
            jsonResponse([
                'success' => false,
                'error'   => 'Please add at least one product form.'
            ], 422);
        }

        $createdIds = [];
        $errors = [];

        foreach ($products as $index => $productData) {
            if (!is_array($productData)) {
                continue;
            }

            $rowNumber = (int) $index + 1;
            $name = trim((string) ($productData['product_name'] ?? ''));
            $photoPath = null;

            try {
                $photoField = 'photo_' . $index;
                if (!empty($_FILES[$photoField]['name'])) {
                    $photoPath = ProductController::handlePhotoUpload($photoField, $name);
                }

                $productId = ProductController::addProduct($conn, [
                    'name'             => $name,
                    'category_id'      => (int) ($productData['category_id'] ?? 0),
                    'subcategory_id'   => !empty($productData['subcategory_id']) ? (int) $productData['subcategory_id'] : null,
                    'supplier_id'      => !empty($productData['supplier_id']) ? (int) $productData['supplier_id'] : null,
                    'sku'              => trim((string) ($productData['sku'] ?? '')),
                    'price'            => (float) ($productData['price'] ?? 0),
                    'box_price'        => (isset($productData['box_price']) && $productData['box_price'] !== '') ? (float) $productData['box_price'] : null,
                    'case_price'       => (isset($productData['case_price']) && $productData['case_price'] !== '') ? (float) $productData['case_price'] : null,
                    'sale_price'       => (isset($productData['sale_price']) && $productData['sale_price'] !== '') ? (float) $productData['sale_price'] : null,
                    'box_sale_price'   => (isset($productData['box_sale_price']) && $productData['box_sale_price'] !== '') ? (float) $productData['box_sale_price'] : null,
                    'case_sale_price'  => (isset($productData['case_sale_price']) && $productData['case_sale_price'] !== '') ? (float) $productData['case_sale_price'] : null,
                    'vatable'          => !empty($productData['vatable']) ? 1 : 0,
                    'pieces_per_box'   => (int) ($productData['pieces_per_box'] ?? 1),
                    'boxes_per_case'   => (int) ($productData['boxes_per_case'] ?? 1),
                    'initial_quantity' => (int) ($productData['initial_quantity'] ?? 0),
                    'reorder_level'    => (int) ($productData['reorder_level'] ?? 5),
                    'status'           => trim((string) ($productData['status'] ?? 'inactive')),
                    'photo'            => $photoPath,
                    'user_id'          => $sessionUserId,
                ]);

                $createdIds[] = $productId;
            } catch (InvalidArgumentException | RuntimeException $e) {
                ProductController::cleanupUploadedPhoto($photoPath);
                $errors[] = [
                    'row'     => $rowNumber,
                    'product' => $name,
                    'error'   => $e->getMessage(),
                ];
            } catch (Throwable $e) {
                ProductController::cleanupUploadedPhoto($photoPath);
                error_log('[product_actions bulk_create row] ' . $e->getMessage());
                $errors[] = [
                    'row'     => $rowNumber,
                    'product' => $name,
                    'error'   => 'Unable to create this product right now.',
                ];
            }
        }

        $createdCount = count($createdIds);
        $errorCount = count($errors);

        if ($createdCount > 0) {
            $summaryText = "{$createdCount} product(s) created";
            if ($errorCount > 0) {
                $summaryText .= ", {$errorCount} form(s) skipped";
            }

        safeCreateProductNotification(
            $conn,
            $sessionUserId,
            'admin',
            'product',
            'Bulk Product Upload',
                $summaryText . '.',
                'bi-upload',
                'text-primary',
                '/inventory_system/product_management/bulk_upload_products.php'
            );

            AuthController::logActivity(
                $conn,
                $logConfig,
                $sessionUserId,
                'product_bulk_create',
                $summaryText . '.',
                'product'
            );
        }

        jsonResponse([
            'success'       => $createdCount > 0,
            'message'       => $createdCount > 0
                ? "{$createdCount} product(s) created successfully."
                : 'No products were created.',
            'event'         => 'notification_update',
            'type'          => 'product',
            'created_count' => $createdCount,
            'error_count'   => $errorCount,
            'errors'        => $errors,
        ], $createdCount > 0 ? 200 : 422);
    }

    if (isset($_POST['edit_product'])) {
        $id = (int) ($_POST['product_id'] ?? 0);
        $name = trim((string) ($_POST['product_name'] ?? ''));

        $old = ProductController::getProductById($conn, $id);
        if (!$old) {
            jsonResponse([
                'success' => false,
                'error'   => 'Product not found.'
            ], 404);
        }

        $photoPath = null;

        try {
            if (!empty($_FILES['photo']['name'])) {
                $photoPath = ProductController::handlePhotoUpload('photo', $name);
            }

            ProductController::updateProduct($conn, $id, [
                'name'          => $name,
                'category_id'   => (int) ($_POST['category_id'] ?? 0),
                'subcategory_id'=> !empty($_POST['subcategory_id']) ? (int) $_POST['subcategory_id'] : null,
                'supplier_id'   => !empty($_POST['supplier_id']) ? (int) $_POST['supplier_id'] : null,
                'sku'           => trim((string) ($_POST['sku'] ?? '')),
                'price'         => (float) ($_POST['price'] ?? 0),
                'box_price'     => (isset($_POST['box_price']) && $_POST['box_price'] !== '') ? (float) $_POST['box_price'] : null,
                'case_price'    => (isset($_POST['case_price']) && $_POST['case_price'] !== '') ? (float) $_POST['case_price'] : null,
                'sale_price'    => (isset($_POST['sale_price']) && $_POST['sale_price'] !== '') ? (float) $_POST['sale_price'] : null,
                'box_sale_price'=> (isset($_POST['box_sale_price']) && $_POST['box_sale_price'] !== '') ? (float) $_POST['box_sale_price'] : null,
                'case_sale_price'=> (isset($_POST['case_sale_price']) && $_POST['case_sale_price'] !== '') ? (float) $_POST['case_sale_price'] : null,
                'vatable'       => !empty($_POST['vatable']) ? 1 : 0,
                'pieces_per_box'=> (int) ($_POST['pieces_per_box'] ?? 1),
                'boxes_per_case'=> (int) ($_POST['boxes_per_case'] ?? 1),
                'reorder_level' => (int) ($_POST['reorder_level'] ?? 5),
                'photo'         => $photoPath,
            ]);
        } catch (Throwable $e) {
            ProductController::cleanupUploadedPhoto($photoPath);
            throw $e;
        }

        $product = ProductController::getProductById($conn, $id);

        ob_start();
        include __DIR__ . '/../../templates/product_row.php';
        $newRowHtml = ob_get_clean();

        safeCreateProductNotification(
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
            "Updated product: {$name}",
            'product',
            $id
        );

        jsonResponse([
            'success'    => true,
            'message'    => "{$name} updated successfully.",
            'event'      => 'notification_update',
            'type'       => 'product',
            'newRowHtml' => $newRowHtml
        ]);
    }

    if (isset($_POST['toggle_id'])) {
        $id = (int) ($_POST['toggle_id'] ?? 0);

        $product = ProductController::getProductById($conn, $id);
        if (!$product) {
            jsonResponse([
                'success' => false,
                'error'   => 'Product not found.'
            ], 404);
        }

        $newStatus = ProductController::toggleStatus($conn, $id);
        $product = ProductController::getProductById($conn, $id);

        ob_start();
        include __DIR__ . '/../../templates/product_row.php';
        $newRowHtml = ob_get_clean();

        safeCreateProductNotification(
            $conn,
            $sessionUserId,
            'admin',
            'product_status',
            'Product Status Changed',
            $product['product_name'] . ' status changed to ' . ucfirst((string) $newStatus),
            'bi-arrow-repeat',
            'text-info',
            '/inventory_system/product_management/manage_product.php'
        );

        AuthController::logActivity(
            $conn,
            $logConfig,
            $sessionUserId,
            'product_status_update',
            $product['product_name'] . ' status changed to ' . ucfirst((string) $newStatus),
            'product',
            $id
        );

        jsonResponse([
            'success'    => true,
            'message'    => $product['product_name'] . ' status changed to ' . ucfirst((string) $newStatus),
            'event'      => 'notification_update',
            'type'       => 'product_status',
            'new_status' => $newStatus,
            'newRowHtml' => $newRowHtml
        ]);
    }

    if (isset($_POST['restock_product'])) {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $quantity  = (int) ($_POST['quantity'] ?? 0);
        $adjustmentType = trim((string) ($_POST['adjustment_type'] ?? 'manual_restock'));
        $supplierId = !empty($_POST['supplier_id']) ? (int) $_POST['supplier_id'] : null;
        $notes = trim((string) ($_POST['notes'] ?? ''));

        ProductController::restockProduct($conn, $productId, $quantity, $sessionUserId, $notes, [
            'adjustment_type' => $adjustmentType,
            'supplier_id' => $supplierId,
        ]);
        $updated = ProductController::getProductById($conn, $productId);

        safeCreateProductNotification(
            $conn,
            $sessionUserId,
            'admin',
            'stock_in',
            'Product Restocked',
            $updated['product_name'] . " (+{$quantity}) = {$updated['quantity']} units",
            'bi-box-arrow-in-down',
            'text-success',
            '/inventory_system/product_management/manage_product.php'
        );

        AuthController::logActivity(
            $conn,
            $logConfig,
            $sessionUserId,
            'product_restock',
            $updated['product_name'] . " restocked by {$quantity} unit(s)"
                . " | Type: " . str_replace('_', ' ', $adjustmentType)
                . ($notes !== '' ? " | Note: {$notes}" : '')
                . ". New quantity: {$updated['quantity']}",
            'product',
            $productId
        );

        jsonResponse([
            'success'      => true,
            'message'      => $updated['product_name'] . " (+{$quantity}) = {$updated['quantity']} units",
            'event'        => 'notification_update',
            'type'         => 'stock_in',
            'new_quantity' => $updated['quantity'],
            'product_id'   => $productId
        ]);
    }

    if (isset($_POST['stockout_product'])) {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $quantity  = (int) ($_POST['quantity'] ?? 0);
        $reason    = trim((string) ($_POST['reason'] ?? ''));
        $adjustmentType = trim((string) ($_POST['adjustment_type'] ?? $reason));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        ProductController::stockOutProduct($conn, $productId, $quantity, $reason, $sessionUserId, [
            'adjustment_type' => $adjustmentType,
            'notes' => $notes,
        ]);
        $updated = ProductController::getProductById($conn, $productId);

        safeCreateProductNotification(
            $conn,
            $sessionUserId,
            'admin',
            'stock_out',
            'Stock Out',
            $updated['product_name'] . " (-{$quantity}) = {$updated['quantity']} units",
            'bi-box-arrow-up',
            'text-danger',
            '/inventory_system/product_management/manage_product.php'
        );

        AuthController::logActivity(
            $conn,
            $logConfig,
            $sessionUserId,
            'product_stock_out',
            $updated['product_name'] . " stock-out by {$quantity} unit(s)"
                . ($adjustmentType !== '' ? " | Type: {$adjustmentType}" : '')
                . ($reason !== '' ? " | Reason: {$reason}" : '')
                . ($notes !== '' ? " | Note: {$notes}" : '')
                . ". New quantity: {$updated['quantity']}",
            'product',
            $productId
        );

        jsonResponse([
            'success'      => true,
            'message'      => $updated['product_name'] . " (-{$quantity}) = {$updated['quantity']} units",
            'event'        => 'notification_update',
            'type'         => 'stock_out',
            'new_quantity' => $updated['quantity'],
            'product_id'   => $productId
        ]);
    }

    jsonResponse([
        'success' => false,
        'error'   => 'Invalid action.'
    ], 400);

} catch (InvalidArgumentException $e) {
    jsonResponse([
        'success' => false,
        'error'   => $e->getMessage()
    ], 422);

} catch (RuntimeException $e) {
    jsonResponse([
        'success' => false,
        'error'   => $e->getMessage()
    ], 400);

} catch (Throwable $e) {
    error_log('[product_actions] ' . $e->getMessage());

    jsonResponse([
        'success' => false,
        'error'   => 'Internal server error.'
    ], 500);
}
