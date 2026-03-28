<?php
/**
 * http/ajax/checkout.php
 * Receives cart JSON from POS, validates stock,
 * saves sale + sale_items to DB.
 * Triggers handle: stock deduction, audit log, total update.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/middleware/Middleware.php';

Middleware::auth()->csrf(); // Require auth + CSRF for this endpoint

header('Content-Type: application/json');

// ── Auth check ───────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// ── Only accept POST ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

// ── Parse body ───────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);

if (!$body) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$items           = $body['items']           ?? [];
$payment_method  = $body['payment_method']  ?? 'cash';
$total_amount    = floatval($body['total_amount']    ?? 0);
$tax_amount      = floatval($body['tax_amount']      ?? 0);
$discount_amount = floatval($body['discount_amount'] ?? 0);

// Validate payment method
$allowed_payments = ['cash', 'card', 'gcash', 'other'];
if (!in_array($payment_method, $allowed_payments)) {
    $payment_method = 'cash';
}

// Validate cart not empty
if (empty($items)) {
    echo json_encode(['success' => false, 'error' => 'Cart is empty']);
    exit;
}

// ── Stock validation BEFORE transaction ──────────────────────
// Check every item has enough stock — give friendly error if not
$stockCheckStmt = $conn->prepare("
    SELECT product_id, product_name, quantity
    FROM {$table_products}
    WHERE product_id = ?
    AND status = 'active'
");

foreach ($items as $item) {
    $product_id_val = intval($item['product_id'] ?? 0);
    $qty_val        = intval($item['quantity']   ?? 0);

    if ($product_id_val <= 0 || $qty_val <= 0) continue;

    $stockCheckStmt->execute([$product_id_val]);
    $product = $stockCheckStmt->fetch(PDO::FETCH_ASSOC);

    // Product not found or inactive
    if (!$product) {
        echo json_encode([
            'success' => false,
            'error'   => "Product ID {$product_id_val} not found or inactive."
        ]);
        exit;
    }

    // Insufficient stock
    if ($product['quantity'] < $qty_val) {
        echo json_encode([
            'success'   => false,
            'error'     => "Insufficient stock for \"{$product['product_name']}\". " .
                           "Available: {$product['quantity']}, Requested: {$qty_val}",
            'product'   => $product['product_name'],
            'available' => $product['quantity'],
            'requested' => $qty_val,
        ]);
        exit;
    }
}

// ── Insert into DB (transaction) ─────────────────────────────
try {
    $conn->beginTransaction();

    // 1. Insert into sales
    $stmt = $conn->prepare("
        INSERT INTO {$table_sales}
            (total_amount, tax, discount, payment_method, user_id)
        VALUES
            (:total, :tax, :discount, :payment_method, :user_id)
    ");

    $stmt->execute([
        ':total'          => $total_amount,
        ':tax'            => $tax_amount,
        ':discount'       => $discount_amount,
        ':payment_method' => $payment_method,
        ':user_id'        => $_SESSION['user_id'],
    ]);

    $new_sale_id = (int) $conn->lastInsertId();

    // 2. Insert each sale_item
    // trg_sale_items_after_insert fires automatically per row:
    //   → decrements products.quantity
    //   → logs to stock_audit_log (action = 'sale')
    //   → updates sales.total_amount
    $itemStmt = $conn->prepare("
        INSERT INTO {$table_sale_items}
            (sale_id, product_id, unit_price, quantity)
        VALUES
            (:sale_id, :product_id, :unit_price, :quantity)
    ");

    foreach ($items as $item) {
        $product_id_val = intval($item['product_id'] ?? 0);
        $unit_price_val = floatval($item['unit_price'] ?? 0);
        $qty_val        = intval($item['quantity']    ?? 0);

        if ($product_id_val <= 0 || $qty_val <= 0) continue;

        $itemStmt->execute([
            ':sale_id'    => $new_sale_id,
            ':product_id' => $product_id_val,
            ':unit_price' => $unit_price_val,
            ':quantity'   => $qty_val,
        ]);
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'sale_id' => $new_sale_id,
        'message' => 'Sale saved successfully',
    ]);

} catch (PDOException $e) {
    $conn->rollBack();

    $msg = $e->getMessage();

    // Catch trigger-level insufficient stock error
    if (str_contains($msg, 'insufficient stock')) {
        echo json_encode([
            'success' => false,
            'error'   => 'Insufficient stock for one or more items.'
        ]);
    } else {
        error_log('[checkout.php] PDOException: ' . $msg);
        echo json_encode([
            'success' => false,
            'error'   => 'A database error occurred. Please try again.'
        ]);
    }
} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('[checkout.php] Exception: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}