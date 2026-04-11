<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap/app.php';
require_once __DIR__ . '/../../middleware/Middleware.php';
require_once __DIR__ . '/../../controllers/AuthController.php';
require_once __DIR__ . '/../../controllers/PosConfigController.php';

header('Content-Type: application/json; charset=UTF-8');

Middleware::auth()
    ->role(['admin', 'cashier'])
    ->ajax()
    ->methods(['POST'])
    ->csrf();

function checkout_json(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function checkout_discount_percent(array $product, string $unitType = 'piece'): float
{
    $field = match ($unitType) {
        'box' => 'box_sale_price',
        'case' => 'case_sale_price',
        default => 'sale_price',
    };

    if (!isset($product[$field]) || $product[$field] === null || $product[$field] === '') {
        return 0.0;
    }

    return max(0.0, min(100.0, (float) $product[$field]));
}

function checkout_normalize_items(array $items): array
{
    $normalized = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $productId = (int) ($item['product_id'] ?? 0);
        $quantity = (int) ($item['quantity'] ?? 0);
        $unitType = strtolower(trim((string) ($item['unit_type'] ?? 'piece')));
        $unitMultiplier = max(1, (int) ($item['unit_multiplier'] ?? 1));

        if ($productId <= 0 || $quantity <= 0) {
            continue;
        }

        if (!in_array($unitType, ['piece', 'box', 'case'], true)) {
            $unitType = 'piece';
        }

        $key = $productId . ':' . $unitType . ':' . $unitMultiplier;

        if (!isset($normalized[$key])) {
            $normalized[$key] = [
                'product_id' => $productId,
                'quantity'   => 0,
                'unit_type'  => $unitType,
                'unit_multiplier' => $unitMultiplier,
            ];
        }

        $normalized[$key]['quantity'] += $quantity;
    }

    return array_values($normalized);
}

try {
    $rawBody = file_get_contents('php://input');
    $body = json_decode($rawBody ?: '', true);

    if (!is_array($body)) {
        checkout_json([
            'success' => false,
            'error'   => 'Invalid JSON payload.',
        ], 400);
    }

    $items = checkout_normalize_items((array) ($body['items'] ?? []));
    $paymentMethod = strtolower(trim((string) ($body['payment_method'] ?? 'cash')));
    $allowedPayments = ['cash', 'card', 'gcash', 'other'];

    if (!in_array($paymentMethod, $allowedPayments, true)) {
        checkout_json([
            'success' => false,
            'error'   => 'Invalid payment method.',
        ], 422);
    }

    if ($items === []) {
        checkout_json([
            'success' => false,
            'error'   => 'Cart is empty.',
        ], 422);
    }

    $productIds = array_column($items, 'product_id');
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);

    if ($sessionUserId <= 0) {
        checkout_json([
            'success' => false,
            'error'   => 'Unauthorized.',
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
    $vatRate = PosConfigController::taxRate($conn) / 100;

    $conn->beginTransaction();

    $productStmt = $conn->prepare("
        SELECT
            product_id,
            product_name,
            price,
            box_price,
            case_price,
            sale_price,
            box_sale_price,
            case_sale_price,
            on_sale,
            vatable,
            quantity,
            status
        FROM {$table_products}
        WHERE product_id IN ({$placeholders})
        FOR UPDATE
    ");
    $productStmt->execute($productIds);

    $products = [];
    foreach ($productStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $product) {
        $products[(int) $product['product_id']] = $product;
    }

    $subtotal = 0.0;
    $discountAmount = 0.0;
    $taxAmount = 0.0;
    $saleItems = [];

    foreach ($items as $item) {
        $productId = (int) $item['product_id'];
        $quantity = (int) $item['quantity'];
        $unitType = (string) ($item['unit_type'] ?? 'piece');
        $unitMultiplier = max(1, (int) ($item['unit_multiplier'] ?? 1));
        $product = $products[$productId] ?? null;

        if ($product === null || ($product['status'] ?? 'inactive') !== 'active') {
            throw new RuntimeException('One or more products are unavailable.');
        }

        $availableQty = (int) ($product['quantity'] ?? 0);
        $requiredBaseQty = $quantity * $unitMultiplier;
        if ($availableQty < $requiredBaseQty) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            checkout_json([
                'success'   => false,
                'error'     => 'Insufficient stock for one or more items.',
                'product'   => $product['product_name'],
                'available' => $availableQty,
                'requested' => $requiredBaseQty,
            ], 409);
        }

        $regularPrice = match ($unitType) {
            'box' => round((float) ($product['box_price'] ?? 0), 2),
            'case' => round((float) ($product['case_price'] ?? 0), 2),
            default => round((float) ($product['price'] ?? 0), 2),
        };
        if ($regularPrice <= 0) {
            throw new RuntimeException('One or more products are missing a valid selling price.');
        }
        $discountPercent = checkout_discount_percent($product, $unitType);
        $unitPrice = round($regularPrice * (1 - ($discountPercent / 100)), 2);
        $lineSubtotal = round($unitPrice * $quantity, 2);
        $lineDiscount = round(max(0, $regularPrice - $unitPrice) * $quantity, 2);
        $lineTax = !empty($product['vatable']) ? round($lineSubtotal * $vatRate, 2) : 0.0;

        $subtotal += $lineSubtotal;
        $discountAmount += $lineDiscount;
        $taxAmount += $lineTax;
        $saleItems[] = [
            'product_id' => $productId,
            'quantity'   => $quantity,
            'unit_type'  => $unitType,
            'unit_multiplier' => $unitMultiplier,
            'unit_price' => $unitPrice,
        ];
    }

    $subtotal = round($subtotal, 2);
    $discountAmount = round($discountAmount, 2);
    $taxAmount = round($taxAmount, 2);
    $grandTotal = round($subtotal + $taxAmount, 2);

    $saleStmt = $conn->prepare("
        INSERT INTO {$table_sales}
            (total_amount, tax, discount, payment_method, user_id)
        VALUES
            (:total_amount, :tax, :discount, :payment_method, :user_id)
    ");
    $saleStmt->execute([
        ':total_amount'   => $grandTotal,
        ':tax'            => $taxAmount,
        ':discount'       => $discountAmount,
        ':payment_method' => $paymentMethod,
        ':user_id'        => $sessionUserId,
    ]);

    $saleId = (int) $conn->lastInsertId();

    $itemStmt = $conn->prepare("
        INSERT INTO {$table_sale_items}
            (sale_id, product_id, unit_price, quantity, unit_type, unit_multiplier)
        VALUES
            (:sale_id, :product_id, :unit_price, :quantity, :unit_type, :unit_multiplier)
    ");
    $stockAuditStmt = $conn->prepare("
        INSERT INTO stock_audit_log
            (product_id, change_qty, current_qty, action, user_id, timestamp)
        VALUES
            (:product_id, :change_qty, :current_qty, :action, :user_id, NOW())
    ");
    $remainingQtyByProduct = [];
    foreach ($products as $lockedProductId => $lockedProduct) {
        $remainingQtyByProduct[(int) $lockedProductId] = (int) ($lockedProduct['quantity'] ?? 0);
    }

    foreach ($saleItems as $saleItem) {
        $itemStmt->execute([
            ':sale_id'    => $saleId,
            ':product_id' => $saleItem['product_id'],
            ':unit_price' => $saleItem['unit_price'],
            ':quantity'   => $saleItem['quantity'],
            ':unit_type'  => $saleItem['unit_type'],
            ':unit_multiplier' => $saleItem['unit_multiplier'],
        ]);

        $productId = (int) $saleItem['product_id'];
        $baseQtySold = (int) $saleItem['quantity'] * max(1, (int) $saleItem['unit_multiplier']);
        $remainingQtyByProduct[$productId] = max(
            0,
            ((int) ($remainingQtyByProduct[$productId] ?? 0)) - $baseQtySold
        );

        $stockAuditStmt->execute([
            ':product_id'  => $productId,
            ':change_qty'  => -$baseQtySold,
            ':current_qty' => $remainingQtyByProduct[$productId],
            ':action'      => 'sale',
            ':user_id'     => $sessionUserId,
        ]);
    }

    $conn->commit();

    AuthController::logActivity(
        $conn,
        $logConfig,
        $sessionUserId,
        'sale_create',
        sprintf(
            'Completed sale #%d with %d item(s), payment: %s, total: %.2f',
            $saleId,
            array_sum(array_map(static fn(array $item): int => (int) $item['quantity'] * (int) $item['unit_multiplier'], $saleItems)),
            $paymentMethod,
            $grandTotal
        ),
        'sale',
        $saleId
    );

    checkout_json([
        'success' => true,
        'sale_id' => $saleId,
        'message' => 'Sale saved successfully.',
        'server_totals' => [
            'subtotal' => $subtotal,
            'discount' => $discountAmount,
            'tax'      => $taxAmount,
            'grand'    => $grandTotal,
        ],
    ]);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    error_log('[checkout.php] ' . $e->getMessage());

    checkout_json([
        'success' => false,
        'error'   => 'Unable to complete checkout right now.',
    ], 500);
}
