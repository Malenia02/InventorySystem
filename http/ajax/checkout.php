<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap/app.php';
require_once __DIR__ . '/../../middleware/Middleware.php';
require_once __DIR__ . '/../../controllers/AuthController.php';
require_once __DIR__ . '/../../controllers/NotificationController.php';
require_once __DIR__ . '/../../controllers/PosConfigController.php';
require_once __DIR__ . '/../../controllers/ProductController.php';
require_once __DIR__ . '/../../controllers/ShiftClosingController.php';

header('Content-Type: application/json; charset=UTF-8');

Middleware::auth()
    ->role(['admin', 'cashier'])
    ->ajax()
    ->methods(['POST'])
    ->csrf()
    ->throttle('checkout', 20, 60, 'Too many checkout attempts. Please wait a moment and try again.');

function checkout_json(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function checkout_transaction_number(int $saleId, ?string $saleDate = null): string
{
    $datePart = date('Ymd', $saleDate !== null ? strtotime($saleDate) : time());
    return sprintf('SALE-%s-%06d', $datePart, $saleId);
}

function checkout_notify_sale(
    PDO $conn,
    int $userId,
    string $type,
    string $title,
    string $message,
    string $icon = 'bi-bell',
    string $color = 'text-primary',
    ?string $link = null
): void {
    if ($userId <= 0) {
        return;
    }

    try {
        NotificationController::create(
            $conn,
            $userId,
            'admin',
            $type,
            $title,
            $message,
            $icon,
            $color,
            $link !== null && trim($link) !== '' ? $link : '/inventory_system/product_management/pos.php'
        );
    } catch (Throwable $notificationError) {
        error_log('[checkout.php][notification] ' . $notificationError->getMessage());
    }
}

function checkout_actor_label(): string
{
    $name = trim((string) ($_SESSION['first_name'] ?? '') . ' ' . (string) ($_SESSION['last_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    $username = trim((string) ($_SESSION['username'] ?? ''));
    return $username !== '' ? $username : 'Cashier';
}

function checkout_failure_response(
    PDO $conn,
    int $userId,
    string $type,
    string $message,
    int $statusCode = 422,
    array $extra = []
): never {
    checkout_notify_sale(
        $conn,
        $userId,
        $type,
        'Sale failed',
        checkout_actor_label() . ': ' . $message,
        'bi-exclamation-triangle',
        'text-danger'
    );

    checkout_json(array_merge([
        'success' => false,
        'error' => $message,
        'notification_type' => $type,
    ], $extra), $statusCode);
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

        $key = $productId . ':' . $unitType;

        if (!isset($normalized[$key])) {
            $normalized[$key] = [
                'product_id' => $productId,
                'quantity'   => 0,
                'unit_type'  => $unitType,
            ];
        }

        $normalized[$key]['quantity'] += $quantity;
    }

    return array_values($normalized);
}

function checkout_server_unit_multiplier(array $product, string $unitType): int
{
    $piecesPerBox = max(1, (int) ($product['pieces_per_box'] ?? 1));
    $boxesPerCase = max(1, (int) ($product['boxes_per_case'] ?? 1));

    return match ($unitType) {
        'box' => $piecesPerBox,
        'case' => $piecesPerBox * $boxesPerCase,
        default => 1,
    };
}

function checkout_unit_is_available(array $product, string $unitType): bool
{
    return match ($unitType) {
        'box' => (float) ($product['box_price'] ?? 0) > 0,
        'case' => (float) ($product['case_price'] ?? 0) > 0,
        default => true,
    };
}

function checkout_unit_label(string $unitType, int $quantity): string
{
    if ($quantity === 1) {
        return $unitType;
    }

    return match ($unitType) {
        'box' => 'boxes',
        'case' => 'cases',
        default => 'pieces',
    };
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
        checkout_failure_response(
            $conn,
            (int) ($_SESSION['user_id'] ?? 0),
            'sale_failed_invalid_payment',
            'Invalid payment method.',
            422
        );
    }

    if ($items === []) {
        checkout_failure_response(
            $conn,
            (int) ($_SESSION['user_id'] ?? 0),
            'sale_failed_empty_cart',
            'Cart is empty.',
            422
        );
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

    $sessionRole = strtolower((string) ($_SESSION['role'] ?? ''));
    if ($sessionRole !== 'admin' && !ShiftClosingController::hasOpenShiftForToday($conn, $sessionUserId)) {
        checkout_failure_response(
            $conn,
            $sessionUserId,
            'sale_failed_shift_not_started',
            'Start your shift first before saving a sale.',
            422
        );
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
    ProductController::ensureStockMovementSchema($conn);

    $conn->beginTransaction();

    $productStmt = $conn->prepare("
        SELECT
            product_id,
            product_name,
            price,
            box_price,
            case_price,
            pieces_per_box,
            boxes_per_case,
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
        $product = $products[$productId] ?? null;

        if ($product === null || ($product['status'] ?? 'inactive') !== 'active') {
            throw new RuntimeException('One or more products are unavailable.');
        }

        if (!checkout_unit_is_available($product, $unitType)) {
            throw new RuntimeException('One or more products are missing the requested selling unit.');
        }

        $unitMultiplier = checkout_server_unit_multiplier($product, $unitType);

        $availableQty = (int) ($product['quantity'] ?? 0);
        $requiredBaseQty = $quantity * $unitMultiplier;
        if ($availableQty < $requiredBaseQty) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            checkout_failure_response(
                $conn,
                $sessionUserId,
                'sale_failed_low_stock',
                sprintf(
                    'Insufficient stock for %s. Available: %d, requested: %d.',
                    (string) ($product['product_name'] ?? 'one or more items'),
                    $availableQty,
                    $requiredBaseQty
                ),
                409,
                [
                    'product'   => $product['product_name'],
                    'available' => $availableQty,
                    'requested' => $requiredBaseQty,
                ]
            );
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
    $saleDate = date('Y-m-d H:i:s');

    $itemStmt = $conn->prepare("
        INSERT INTO {$table_sale_items}
            (sale_id, product_id, unit_price, quantity, unit_type, unit_multiplier)
        VALUES
            (:sale_id, :product_id, :unit_price, :quantity, :unit_type, :unit_multiplier)
    ");
    $stockAuditStmt = $conn->prepare("
        INSERT INTO stock_audit_log
            (product_id, change_qty, current_qty, action, reference_type, reference_id, notes, user_id, timestamp)
        VALUES
            (:product_id, :change_qty, :current_qty, :action, :reference_type, :reference_id, :notes, :user_id, NOW())
    ");
    $remainingQtyByProduct = [];
    foreach ($products as $lockedProductId => $lockedProduct) {
        $remainingQtyByProduct[(int) $lockedProductId] = (int) ($lockedProduct['quantity'] ?? 0);
    }

    $transactionNo = checkout_transaction_number($saleId, $saleDate);

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

        $note = sprintf(
            'Transaction %s | Sold %d %s',
            $transactionNo,
            (int) $saleItem['quantity'],
            checkout_unit_label((string) $saleItem['unit_type'], (int) $saleItem['quantity'])
        );

        $stockAuditStmt->execute([
            ':product_id'  => $productId,
            ':change_qty'  => -$baseQtySold,
            ':current_qty' => $remainingQtyByProduct[$productId],
            ':action'      => 'sale',
            ':reference_type' => 'sale',
            ':reference_id' => $saleId,
            ':notes' => $note,
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

    checkout_notify_sale(
        $conn,
        $sessionUserId,
        'sale_success',
        'Sale completed',
        sprintf(
            '%s saved %s for PHP %s.',
            checkout_actor_label(),
            $transactionNo,
            number_format($grandTotal, 2)
        ),
        'bi-receipt',
        'text-success',
        '/inventory_system/reports/sales_report.php?sale_id=' . $saleId
    );

    checkout_json([
        'success' => true,
        'sale_id' => $saleId,
        'transaction_no' => $transactionNo,
        'message' => 'Sale saved successfully.',
        'notification_type' => 'sale_success',
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

    checkout_notify_sale(
        $conn,
        $sessionUserId,
        'sale_failed_error',
        'Sale failed',
        checkout_actor_label() . ' could not complete checkout right now.',
        'bi-exclamation-triangle',
        'text-danger'
    );

    checkout_json([
        'success' => false,
        'error'   => 'Unable to complete checkout right now.',
        'notification_type' => 'sale_failed_error',
    ], 500);
}
