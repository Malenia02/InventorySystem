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
    ->throttle('void_sale', 12, 60, 'Too many void attempts. Please slow down.');

function voidSaleJson(array $payload, int $statusCode = 200): never
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

    $result = SaleController::voidSale($conn, $saleId, $adminId, $reason, $logConfig);

    try {
        NotificationController::create(
            $conn,
            $adminId,
            'admin',
            'sale_void',
            'Sale voided',
            sprintf('%s was voided and %d piece(s) were restored.', $result['transaction_no'], $result['restored_pieces']),
            'bi-arrow-counterclockwise',
            'text-warning',
            '/inventory_system/reports/sales_report.php'
        );

        if ((int) $result['cashier_id'] > 0 && (int) $result['cashier_id'] !== $adminId) {
            NotificationController::create(
                $conn,
                (int) $result['cashier_id'],
                'cashier',
                'sale_void',
                'Sale voided',
                sprintf('%s from your sales was voided by admin.', $result['transaction_no']),
                'bi-arrow-counterclockwise',
                'text-warning',
                '/inventory_system/reports/sales_report.php'
            );
        }
    } catch (Throwable $notificationError) {
        error_log('[void_sale.php][notification] ' . $notificationError->getMessage());
    }

    voidSaleJson([
        'success' => true,
        'message' => $result['transaction_no'] . ' was voided and stock was restored.',
        'sale' => $result,
    ]);
} catch (InvalidArgumentException $e) {
    voidSaleJson([
        'success' => false,
        'error' => $e->getMessage(),
    ], 422);
} catch (RuntimeException $e) {
    voidSaleJson([
        'success' => false,
        'error' => $e->getMessage(),
    ], 409);
} catch (Throwable $e) {
    error_log('[void_sale.php] ' . $e->getMessage());

    voidSaleJson([
        'success' => false,
        'error' => 'Unable to void sale right now.',
    ], 500);
}
