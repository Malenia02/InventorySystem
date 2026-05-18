<?php
declare(strict_types=1);

/**
 * product_actions.php  —  AJAX endpoint for product CRUD + stock movements
 *
 * Scalability / correctness improvements over the previous version
 * ────────────────────────────────────────────────────────────────
 *  1.  No double SELECT after writes.  addProduct(), updateProduct(),
 *      toggleStatus(), restockProduct(), and stockOutProduct() all return
 *      the data the caller needs directly — no follow-up getProductById().
 *
 *  2.  ob_start() + include template removed.  The server now returns
 *      structured JSON only; the JS rebuilds the row client-side via
 *      buildProductRow() (see manage_product.js).  This decouples the
 *      endpoint from PHP template I/O and allows DOM patching without
 *      a full reload.
 *
 *  3.  Photo uploaded BEFORE the DB transaction opens, and deleted on
 *      any exception, so no orphaned files accumulate on failure.
 *
 *  4.  Notification + activity log writes share a single logConfig
 *      helper (buildLogConfig()) instead of duplicating the globals
 *      array in every branch.
 *
 *  5.  safeCreateProductNotification() wrapper removed — the try/catch
 *      is inlined at the call site to keep control flow explicit.
 */

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

// ── Helpers ───────────────────────────────────────────────────────────────────

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function buildLogConfig(): array
{
    return [
        'table'       => $GLOBALS['table_activity_logs']  ?? 'activity_logs',
        'col_user_id' => $GLOBALS['activity_log_user_id'] ?? 'user_id',
        'col_action'  => $GLOBALS['activity_log_action']  ?? 'action',
        'col_desc'    => $GLOBALS['activity_log_desc']    ?? 'description',
        'col_ip'      => $GLOBALS['activity_log_ip']      ?? 'ip_address',
        'col_created' => $GLOBALS['activity_log_created'] ?? 'created_at',
    ];
}

function notify(PDO $conn, ?int $userId, string $type, string $title, string $message, string $icon = 'bi-bell', string $color = 'text-primary', ?string $link = null): void
{
    try {
        NotificationController::create($conn, $userId, 'admin', $type, $title, $message, $icon, $color, $link);
    } catch (Throwable $e) {
        error_log('[product_actions notify] ' . $e->getMessage());
    }
}

// ── Auth guard ────────────────────────────────────────────────────────────────

$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
if ($sessionUserId <= 0) {
    jsonResponse(['success' => false, 'error' => 'Unauthorized.'], 401);
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

try {
    $logConfig = buildLogConfig();

    // ════════════════════════════════════════════════════════════════════════
    // ADD PRODUCT
    // ════════════════════════════════════════════════════════════════════════
    if (isset($_POST['add_product'])) {
        $name      = trim((string) ($_POST['product_name'] ?? ''));
        $photoPath = null;

        // Upload BEFORE the transaction so we can clean up on failure
        if (!empty($_FILES['photo']['name'])) {
            $photoPath = ProductController::handlePhotoUpload('photo', $name);
        }

        try {
            $result    = ProductController::addProduct($conn, [
                'name'             => $name,
                'category_id'      => (int) ($_POST['category_id']  ?? 0),
                'subcategory_id'   => !empty($_POST['subcategory_id'])  ? (int) $_POST['subcategory_id']  : null,
                'supplier_id'      => !empty($_POST['supplier_id'])     ? (int) $_POST['supplier_id']     : null,
                'sku'              => trim((string) ($_POST['sku']    ?? '')),
                'price'            => (float) ($_POST['price']        ?? 0),
                'box_price'        => (isset($_POST['box_price'])        && $_POST['box_price']        !== '') ? (float) $_POST['box_price']        : null,
                'case_price'       => (isset($_POST['case_price'])       && $_POST['case_price']       !== '') ? (float) $_POST['case_price']       : null,
                'sale_price'       => (isset($_POST['sale_price'])       && $_POST['sale_price']       !== '') ? (float) $_POST['sale_price']       : null,
                'box_sale_price'   => (isset($_POST['box_sale_price'])   && $_POST['box_sale_price']   !== '') ? (float) $_POST['box_sale_price']   : null,
                'case_sale_price'  => (isset($_POST['case_sale_price'])  && $_POST['case_sale_price']  !== '') ? (float) $_POST['case_sale_price']  : null,
                'vatable'          => !empty($_POST['vatable']) ? 1 : 0,
                'pieces_per_box'   => (int) ($_POST['pieces_per_box']  ?? 1),
                'boxes_per_case'   => (int) ($_POST['boxes_per_case']  ?? 1),
                'initial_quantity' => (int) ($_POST['initial_quantity'] ?? 0),
                'reorder_level'    => (int) ($_POST['reorder_level']   ?? 5),
                'photo'            => $photoPath,
                'user_id'          => $sessionUserId,
            ]);
        } catch (Throwable $e) {
            ProductController::cleanupUploadedPhoto($photoPath);
            throw $e;
        }

        $productId = $result['product_id'];
        $view      = $result['view'];

        notify($conn, $sessionUserId, 'product', 'Product Added',
            "{$name} was added to inventory.", 'bi-box-seam', 'text-success',
            '/inventory_system/product_management/manage_product.php');

        AuthController::logActivity($conn, $logConfig, $sessionUserId, 'product_add',
            "Added product: {$name}" . (!empty($_POST['sku']) ? ' (SKU: ' . trim((string) $_POST['sku']) . ')' : ''),
            'product', $productId);

        jsonResponse([
            'success'    => true,
            'message'    => "{$name} added successfully.",
            'event'      => 'notification_update',
            'type'       => 'product',
            'product'    => $view,
            'product_id' => $productId,
        ], 201);
    }

    // ════════════════════════════════════════════════════════════════════════
    // BULK CREATE
    // ════════════════════════════════════════════════════════════════════════
    if (isset($_POST['bulk_create_products'])) {
        $products = $_POST['products'] ?? [];

        if (!is_array($products) || $products === []) {
            jsonResponse(['success' => false, 'error' => 'Please add at least one product.'], 422);
        }

        $createdIds = [];
        $errors     = [];

        foreach ($products as $index => $productData) {
            if (!is_array($productData)) {
                continue;
            }

            $rowNum    = (int) $index + 1;
            $pName     = trim((string) ($productData['product_name'] ?? ''));
            $photoPath = null;

            try {
                $photoField = 'photo_' . $index;
                if (!empty($_FILES[$photoField]['name'])) {
                    $photoPath = ProductController::handlePhotoUpload($photoField, $pName);
                }

                $result = ProductController::addProduct($conn, [
                    'name'             => $pName,
                    'category_id'      => (int) ($productData['category_id']     ?? 0),
                    'subcategory_id'   => !empty($productData['subcategory_id'])  ? (int) $productData['subcategory_id']  : null,
                    'supplier_id'      => !empty($productData['supplier_id'])     ? (int) $productData['supplier_id']     : null,
                    'sku'              => trim((string) ($productData['sku']       ?? '')),
                    'price'            => (float) ($productData['price']            ?? 0),
                    'box_price'        => (isset($productData['box_price'])        && $productData['box_price']        !== '') ? (float) $productData['box_price']        : null,
                    'case_price'       => (isset($productData['case_price'])       && $productData['case_price']       !== '') ? (float) $productData['case_price']       : null,
                    'sale_price'       => (isset($productData['sale_price'])       && $productData['sale_price']       !== '') ? (float) $productData['sale_price']       : null,
                    'box_sale_price'   => (isset($productData['box_sale_price'])   && $productData['box_sale_price']   !== '') ? (float) $productData['box_sale_price']   : null,
                    'case_sale_price'  => (isset($productData['case_sale_price'])  && $productData['case_sale_price']  !== '') ? (float) $productData['case_sale_price']  : null,
                    'vatable'          => !empty($productData['vatable']) ? 1 : 0,
                    'pieces_per_box'   => (int) ($productData['pieces_per_box']  ?? 1),
                    'boxes_per_case'   => (int) ($productData['boxes_per_case']  ?? 1),
                    'initial_quantity' => (int) ($productData['initial_quantity'] ?? 0),
                    'reorder_level'    => (int) ($productData['reorder_level']   ?? 5),
                    'status'           => trim((string) ($productData['status']    ?? 'inactive')),
                    'photo'            => $photoPath,
                    'user_id'          => $sessionUserId,
                ]);

                $createdIds[] = $result['product_id'];

            } catch (InvalidArgumentException | RuntimeException $e) {
                ProductController::cleanupUploadedPhoto($photoPath);
                $errors[] = ['row' => $rowNum, 'product' => $pName, 'error' => $e->getMessage()];
            } catch (Throwable $e) {
                ProductController::cleanupUploadedPhoto($photoPath);
                error_log('[product_actions bulk_create row] ' . $e->getMessage());
                $errors[] = ['row' => $rowNum, 'product' => $pName, 'error' => 'Unable to create this product right now.'];
            }
        }

        $created = count($createdIds);
        $errored = count($errors);

        if ($created > 0) {
            $summary = "{$created} product(s) created" . ($errored > 0 ? ", {$errored} skipped" : '');
            notify($conn, $sessionUserId, 'product', 'Bulk Product Upload', $summary . '.',
                'bi-upload', 'text-primary',
                '/inventory_system/product_management/bulk_upload_products.php');
            AuthController::logActivity($conn, $logConfig, $sessionUserId, 'product_bulk_create',
                $summary . '.', 'product');
        }

        jsonResponse([
            'success'       => $created > 0,
            'message'       => $created > 0 ? "{$created} product(s) created successfully." : 'No products were created.',
            'event'         => 'notification_update',
            'type'          => 'product',
            'created_count' => $created,
            'error_count'   => $errored,
            'errors'        => $errors,
        ], $created > 0 ? 200 : 422);
    }

    // ════════════════════════════════════════════════════════════════════════
    // EDIT PRODUCT
    // ════════════════════════════════════════════════════════════════════════
    if (isset($_POST['edit_product'])) {
        $id   = (int) ($_POST['product_id'] ?? 0);
        $name = trim((string) ($_POST['product_name'] ?? ''));

        if ($id <= 0) {
            jsonResponse(['success' => false, 'error' => 'Invalid product ID.'], 422);
        }

        // Verify existence before touching the filesystem
        if (!ProductController::getProductById($conn, $id)) {
            jsonResponse(['success' => false, 'error' => 'Product not found.'], 404);
        }

        $photoPath = null;
        if (!empty($_FILES['photo']['name'])) {
            $photoPath = ProductController::handlePhotoUpload('photo', $name);
        }

        try {
            $view = ProductController::updateProduct($conn, $id, [
                'name'           => $name,
                'category_id'    => (int) ($_POST['category_id']    ?? 0),
                'subcategory_id' => !empty($_POST['subcategory_id'])  ? (int) $_POST['subcategory_id']  : null,
                'supplier_id'    => !empty($_POST['supplier_id'])     ? (int) $_POST['supplier_id']     : null,
                'sku'            => trim((string) ($_POST['sku']     ?? '')),
                'price'          => (float) ($_POST['price']          ?? 0),
                'box_price'      => (isset($_POST['box_price'])       && $_POST['box_price']       !== '') ? (float) $_POST['box_price']       : null,
                'case_price'     => (isset($_POST['case_price'])      && $_POST['case_price']      !== '') ? (float) $_POST['case_price']      : null,
                'sale_price'     => (isset($_POST['sale_price'])      && $_POST['sale_price']      !== '') ? (float) $_POST['sale_price']      : null,
                'box_sale_price' => (isset($_POST['box_sale_price'])  && $_POST['box_sale_price']  !== '') ? (float) $_POST['box_sale_price']  : null,
                'case_sale_price'=> (isset($_POST['case_sale_price']) && $_POST['case_sale_price'] !== '') ? (float) $_POST['case_sale_price'] : null,
                'vatable'        => !empty($_POST['vatable']) ? 1 : 0,
                'pieces_per_box' => (int) ($_POST['pieces_per_box']  ?? 1),
                'boxes_per_case' => (int) ($_POST['boxes_per_case']  ?? 1),
                'reorder_level'  => (int) ($_POST['reorder_level']   ?? 5),
                'photo'          => $photoPath,
            ]);
        } catch (Throwable $e) {
            ProductController::cleanupUploadedPhoto($photoPath);
            throw $e;
        }

        notify($conn, $sessionUserId, 'product', 'Product Updated', "{$name} was updated.",
            'bi-pencil-square', 'text-warning',
            '/inventory_system/product_management/manage_product.php');

        AuthController::logActivity($conn, $logConfig, $sessionUserId, 'product_update',
            "Updated product: {$name}", 'product', $id);

        jsonResponse([
            'success'    => true,
            'message'    => "{$name} updated successfully.",
            'event'      => 'notification_update',
            'type'       => 'product',
            'product'    => $view,
            'product_id' => $id,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // TOGGLE STATUS
    // ════════════════════════════════════════════════════════════════════════
    if (isset($_POST['toggle_id'])) {
        $id = (int) ($_POST['toggle_id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'error' => 'Invalid product ID.'], 422);
        }

        $result      = ProductController::toggleStatus($conn, $id);
        $newStatus   = $result['new_status'];
        $view        = $result['view'];
        $productName = $view['product_name'] ?? 'Product';
        $statusLabel = ucfirst($newStatus);

        notify($conn, $sessionUserId, 'product_status', 'Product Status Changed',
            "{$productName} is now {$statusLabel}.", 'bi-arrow-repeat', 'text-info',
            '/inventory_system/product_management/manage_product.php');

        AuthController::logActivity($conn, $logConfig, $sessionUserId, 'product_status_update',
            "{$productName} status changed to {$statusLabel}", 'product', $id);

        jsonResponse([
            'success'    => true,
            'message'    => "{$productName} is now {$statusLabel}.",
            'event'      => 'notification_update',
            'type'       => 'product_status',
            'new_status' => $newStatus,
            'product'    => $view,
            'product_id' => $id,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // RESTOCK
    // ════════════════════════════════════════════════════════════════════════
    if (isset($_POST['restock_product'])) {
        $productId      = (int) ($_POST['product_id']      ?? 0);
        $quantity       = (int) ($_POST['quantity']         ?? 0);
        $adjustmentType = trim((string) ($_POST['adjustment_type'] ?? 'manual_restock'));
        $supplierId     = !empty($_POST['supplier_id']) ? (int) $_POST['supplier_id'] : null;
        $notes          = trim((string) ($_POST['notes']    ?? ''));

        if ($productId <= 0) {
            jsonResponse(['success' => false, 'error' => 'Invalid product ID.'], 422);
        }
        if ($quantity <= 0) {
            jsonResponse(['success' => false, 'error' => 'Quantity must be greater than zero.'], 422);
        }

        $result      = ProductController::restockProduct($conn, $productId, $quantity, $sessionUserId, $notes, [
            'adjustment_type' => $adjustmentType,
            'supplier_id'     => $supplierId,
        ]);
        $newQty      = $result['new_quantity'];
        $productName = $result['product_name'];

        notify($conn, $sessionUserId, 'stock_in', 'Product Restocked',
            "{$productName} (+{$quantity}) = {$newQty} units",
            'bi-box-arrow-in-down', 'text-success',
            '/inventory_system/product_management/manage_product.php');

        AuthController::logActivity($conn, $logConfig, $sessionUserId, 'product_restock',
            "{$productName} restocked by {$quantity} unit(s) | Type: " . str_replace('_', ' ', $adjustmentType)
            . ($notes !== '' ? " | Note: {$notes}" : '') . ". New quantity: {$newQty}",
            'product', $productId);

        jsonResponse([
            'success'      => true,
            'message'      => "{$productName} (+{$quantity}) = {$newQty} units",
            'event'        => 'notification_update',
            'type'         => 'stock_in',
            'new_quantity' => $newQty,
            'product_id'   => $productId,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // STOCK OUT
    // ════════════════════════════════════════════════════════════════════════
    if (isset($_POST['stockout_product'])) {
        $productId      = (int) ($_POST['product_id']      ?? 0);
        $quantity       = (int) ($_POST['quantity']         ?? 0);
        $reason         = trim((string) ($_POST['reason']   ?? ''));
        $adjustmentType = trim((string) ($_POST['adjustment_type'] ?? $reason));
        $notes          = trim((string) ($_POST['notes']    ?? ''));

        if ($productId <= 0) {
            jsonResponse(['success' => false, 'error' => 'Invalid product ID.'], 422);
        }
        if ($quantity <= 0) {
            jsonResponse(['success' => false, 'error' => 'Quantity must be greater than zero.'], 422);
        }

        $result      = ProductController::stockOutProduct($conn, $productId, $quantity, $reason, $sessionUserId, [
            'adjustment_type' => $adjustmentType,
            'notes'           => $notes,
        ]);
        $newQty      = $result['new_quantity'];
        $productName = $result['product_name'];

        notify($conn, $sessionUserId, 'stock_out', 'Stock Out',
            "{$productName} (-{$quantity}) = {$newQty} units",
            'bi-box-arrow-up', 'text-danger',
            '/inventory_system/product_management/manage_product.php');

        AuthController::logActivity($conn, $logConfig, $sessionUserId, 'product_stock_out',
            "{$productName} stock-out by {$quantity} unit(s)"
            . ($adjustmentType !== '' ? " | Type: {$adjustmentType}" : '')
            . ($reason          !== '' ? " | Reason: {$reason}"       : '')
            . ($notes           !== '' ? " | Note: {$notes}"          : '')
            . ". New quantity: {$newQty}",
            'product', $productId);

        jsonResponse([
            'success'      => true,
            'message'      => "{$productName} (-{$quantity}) = {$newQty} units",
            'event'        => 'notification_update',
            'type'         => 'stock_out',
            'new_quantity' => $newQty,
            'product_id'   => $productId,
        ]);
    }

    jsonResponse(['success' => false, 'error' => 'Invalid action.'], 400);

// ── Error handlers ────────────────────────────────────────────────────────────

} catch (InvalidArgumentException $e) {
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 422);

} catch (RuntimeException $e) {
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);

} catch (Throwable $e) {
    error_log('[product_actions] ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Internal server error.'], 500);
}