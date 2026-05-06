<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap/app.php';
require_once __DIR__ . '/../../middleware/Middleware.php';
require_once __DIR__ . '/../../controllers/SaleController.php';
require_once __DIR__ . '/../../controllers/NotificationController.php';

header('Content-Type: application/json; charset=UTF-8');

Middleware::auth()
    ->role(['admin'])
    ->ajax()
    ->methods(['POST'])
    ->csrf()
    ->throttle('return_sale_item', 20, 60, 'Too many return attempts. Please slow down.');

function returnSaleItemJson(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw ?: '', true);
    if (!is_array($body)) {
        $body = $_POST;
    }

    $saleId = (int) ($body['sale_id'] ?? 0);
    $saleItemId = (int) ($body['sale_item_id'] ?? 0);
    $quantity = (int) ($body['quantity'] ?? 0);
    $reason = (string) ($body['reason'] ?? '');
    $adminId = (int) ($_SESSION['user_id'] ?? 0);

    $logConfig = [
        'table'       => $table_activity_logs,
        'col_user_id' => $activity_log_user_id,
        'col_action'  => $activity_log_action,
        'col_desc'    => $activity_log_desc,
        'col_ip'      => $activity_log_ip,
        'col_created' => $activity_log_created,
    ];

    $result = SaleController::returnSaleItem($conn, $saleId, $saleItemId, $quantity, $adminId, $reason, $logConfig);

    try {
        NotificationController::create(
            $conn,
            $adminId,
            'admin',
            'sale_item_return',
            'Item returned',
            sprintf(
                '%s: %s returned (%d unit(s)).',
                $result['transaction_no'],
                $result['product_name'],
                $result['returned_quantity']
            ),
            'bi-arrow-return-left',
            'text-warning',
            '/inventory_system/reports/sales_report.php?sale_id=' . (int) $result['sale_id']
        );

        if ((int) $result['cashier_id'] > 0 && (int) $result['cashier_id'] !== $adminId) {
            NotificationController::create(
                $conn,
                (int) $result['cashier_id'],
                'cashier',
                'sale_item_return',
                'Sale item returned',
                sprintf(
                    '%s from your transaction %s was returned.',
                    $result['product_name'],
                    $result['transaction_no']
                ),
                'bi-arrow-return-left',
                'text-warning',
                '/inventory_system/reports/sales_report.php?sale_id=' . (int) $result['sale_id']
            );
        }
    } catch (Throwable $notificationError) {
        error_log('[return_sale_item.php][notification] ' . $notificationError->getMessage());
    }

    returnSaleItemJson([
        'success' => true,
        'message' => sprintf(
            '%s returned from %s and stock was restored.',
            $result['product_name'],
            $result['transaction_no']
        ),
        'result' => $result,
    ]);
} catch (InvalidArgumentException $e) {
    returnSaleItemJson([
        'success' => false,
        'error' => $e->getMessage(),
    ], 422);
} catch (RuntimeException $e) {
    returnSaleItemJson([
        'success' => false,
        'error' => $e->getMessage(),
    ], 409);
} catch (Throwable $e) {
    error_log('[return_sale_item.php] ' . $e->getMessage());

    returnSaleItemJson([
        'success' => false,
        'error' => 'Unable to process item return right now.',
    ], 500);
}
