<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap/app.php';
require_once __DIR__ . '/../../middleware/Middleware.php';
require_once __DIR__ . '/../../controllers/SaleController.php';

header('Content-Type: application/json; charset=UTF-8');

Middleware::auth()
    ->role(['admin', 'cashier'])
    ->ajax()
    ->methods(['GET'])
    ->sameOrigin()
    ->throttle('sale_details', 60, 60, 'Too many sale detail requests. Please wait a moment.');

SaleController::ensureReturnSchema($conn);

function saleDetailsJson(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $saleId = (int) ($_GET['sale_id'] ?? 0);
    $viewerId = (int) ($_SESSION['user_id'] ?? 0);
    $viewerRole = strtolower((string) ($_SESSION['role'] ?? ''));

    if ($saleId <= 0 || $viewerId <= 0) {
        saleDetailsJson([
            'success' => false,
            'error' => 'Invalid sale request.',
        ], 422);
    }

    $saleSql = "
        SELECT
            s.sale_id,
            s.sale_date,
            s.total_amount,
            s.tax,
            s.discount,
            s.payment_method,
            s.user_id,
            s.status,
            s.voided_at,
            s.void_reason,
            vu.first_name AS voided_first_name,
            vu.last_name AS voided_last_name,
            vu.username AS voided_username,
            u.first_name,
            u.last_name,
            u.username
        FROM {$table_sales} s
        LEFT JOIN {$table_users} u ON u.user_id = s.user_id
        LEFT JOIN {$table_users} vu ON vu.user_id = s.voided_by
        WHERE s.sale_id = :sale_id
    ";
    $saleParams = [':sale_id' => $saleId];

    if ($viewerRole !== 'admin') {
        $saleSql .= ' AND s.user_id = :viewer_id';
        $saleParams[':viewer_id'] = $viewerId;
    }

    $saleSql .= ' LIMIT 1';

    $saleStmt = $conn->prepare($saleSql);
    foreach ($saleParams as $key => $value) {
        $saleStmt->bindValue($key, $value, PDO::PARAM_INT);
    }
    $saleStmt->execute();
    $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);

    if (!$sale) {
        saleDetailsJson([
            'success' => false,
            'error' => 'Sale not found or not allowed.',
        ], 404);
    }

    $itemsStmt = $conn->prepare("
        SELECT
            si.sale_item_id,
            si.product_id,
            p.product_name,
            p.sku,
            c.category_name,
            si.unit_price,
            si.quantity,
            COALESCE(si.returned_quantity, 0) AS returned_quantity,
            si.unit_type,
            si.unit_multiplier,
            (si.quantity * COALESCE(si.unit_multiplier, 1)) AS pieces_sold,
            ((si.quantity - COALESCE(si.returned_quantity, 0)) * COALESCE(si.unit_multiplier, 1)) AS net_pieces_sold,
            (si.quantity * si.unit_price) AS line_total,
            ((si.quantity - COALESCE(si.returned_quantity, 0)) * si.unit_price) AS net_line_total
        FROM {$table_sale_items} si
        INNER JOIN {$table_products} p ON p.product_id = si.product_id
        LEFT JOIN {$table_categories} c ON c.category_id = p.category_id
        WHERE si.sale_id = :sale_id
        ORDER BY si.sale_item_id ASC
    ");
    $itemsStmt->execute([':sale_id' => $saleId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $returnsStmt = $conn->prepare("
        SELECT
            sir.return_id,
            sir.sale_item_id,
            sir.product_id,
            sir.quantity,
            sir.unit_multiplier,
            sir.unit_price,
            sir.tax_amount,
            sir.discount_amount,
            sir.reason,
            sir.created_at,
            p.product_name,
            u.first_name,
            u.last_name,
            u.username
        FROM sale_item_returns sir
        INNER JOIN {$table_products} p ON p.product_id = sir.product_id
        LEFT JOIN {$table_users} u ON u.user_id = sir.user_id
        WHERE sir.sale_id = :sale_id
        ORDER BY sir.created_at DESC, sir.return_id DESC
    ");
    $returnsStmt->execute([':sale_id' => $saleId]);
    $returns = $returnsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $cashierName = trim((string) ($sale['first_name'] ?? '') . ' ' . (string) ($sale['last_name'] ?? ''));
    if ($cashierName === '') {
        $cashierName = (string) ($sale['username'] ?? 'Unknown');
    }

    $voidedBy = trim((string) ($sale['voided_first_name'] ?? '') . ' ' . (string) ($sale['voided_last_name'] ?? ''));
    if ($voidedBy === '') {
        $voidedBy = (string) ($sale['voided_username'] ?? '');
    }

    saleDetailsJson([
        'success' => true,
        'sale' => [
            'sale_id' => (int) $sale['sale_id'],
            'transaction_no' => SaleController::transactionNumber((int) $sale['sale_id'], (string) $sale['sale_date']),
            'sale_date' => (string) $sale['sale_date'],
            'cashier_name' => $cashierName,
            'payment_method' => (string) ($sale['payment_method'] ?? ''),
            'status' => (string) ($sale['status'] ?? 'completed'),
            'voided_at' => (string) ($sale['voided_at'] ?? ''),
            'voided_by' => $voidedBy,
            'void_reason' => (string) ($sale['void_reason'] ?? ''),
            'discount' => (float) ($sale['discount'] ?? 0),
            'tax' => (float) ($sale['tax'] ?? 0),
            'total_amount' => (float) ($sale['total_amount'] ?? 0),
            'can_return_items' => $viewerRole === 'admin' && !in_array(strtolower((string) ($sale['status'] ?? 'completed')), ['voided', 'returned'], true),
        ],
        'items' => array_map(
            static fn(array $item): array => [
                'sale_item_id' => (int) $item['sale_item_id'],
                'product_id' => (int) $item['product_id'],
                'product_name' => (string) ($item['product_name'] ?? 'Unknown product'),
                'sku' => (string) ($item['sku'] ?? ''),
                'category_name' => (string) ($item['category_name'] ?? 'Uncategorized'),
                'unit_price' => (float) ($item['unit_price'] ?? 0),
                'quantity' => (int) ($item['quantity'] ?? 0),
                'returned_quantity' => (int) ($item['returned_quantity'] ?? 0),
                'remaining_quantity' => max(0, (int) ($item['quantity'] ?? 0) - (int) ($item['returned_quantity'] ?? 0)),
                'unit_type' => (string) ($item['unit_type'] ?? 'piece'),
                'unit_multiplier' => (int) ($item['unit_multiplier'] ?? 1),
                'pieces_sold' => (int) ($item['pieces_sold'] ?? 0),
                'net_pieces_sold' => (int) ($item['net_pieces_sold'] ?? 0),
                'line_total' => (float) ($item['line_total'] ?? 0),
                'net_line_total' => (float) ($item['net_line_total'] ?? 0),
            ],
            $items
        ),
        'returns' => array_map(static function (array $return): array {
            $userName = trim((string) ($return['first_name'] ?? '') . ' ' . (string) ($return['last_name'] ?? ''));
            if ($userName === '') {
                $userName = (string) ($return['username'] ?? 'Admin');
            }

            return [
                'return_id' => (int) $return['return_id'],
                'sale_item_id' => (int) $return['sale_item_id'],
                'product_id' => (int) $return['product_id'],
                'product_name' => (string) ($return['product_name'] ?? 'Product'),
                'quantity' => (int) ($return['quantity'] ?? 0),
                'unit_multiplier' => (int) ($return['unit_multiplier'] ?? 1),
                'unit_price' => (float) ($return['unit_price'] ?? 0),
                'tax_amount' => (float) ($return['tax_amount'] ?? 0),
                'discount_amount' => (float) ($return['discount_amount'] ?? 0),
                'reason' => (string) ($return['reason'] ?? ''),
                'created_at' => (string) ($return['created_at'] ?? ''),
                'user_name' => $userName,
            ];
        }, $returns),
    ]);
} catch (Throwable $e) {
    error_log('[sale_details.php] ' . $e->getMessage());

    saleDetailsJson([
        'success' => false,
        'error' => 'Failed to load sale details.',
    ], 500);
}
