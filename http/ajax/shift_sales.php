<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap/app.php';
require_once __DIR__ . '/../../middleware/Middleware.php';
require_once __DIR__ . '/../../controllers/ShiftClosingController.php';
require_once __DIR__ . '/../../controllers/SaleController.php';

header('Content-Type: application/json; charset=UTF-8');

Middleware::auth()
    ->role(['admin', 'cashier'])
    ->ajax()
    ->methods(['GET'])
    ->sameOrigin()
    ->throttle('shift_sales', 60, 60, 'Too many shift sales requests. Please wait a moment.');

function shiftSalesJson(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $viewerId = (int) ($_SESSION['user_id'] ?? 0);
    $viewerRole = strtolower((string) ($_SESSION['role'] ?? ''));
    $userId = (int) ($_GET['user_id'] ?? $viewerId);
    $shiftDate = (string) ($_GET['shift_date'] ?? date('Y-m-d'));

    if ($viewerId <= 0) {
        throw new RuntimeException('Your session has expired. Please log in again.');
    }

    if ($viewerRole !== 'admin') {
        $userId = $viewerId;
    }

    $summary = ShiftClosingController::summaryForUser($conn, $userId, $shiftDate);
    $sales = ShiftClosingController::salesForUser($conn, $userId, $shiftDate, 100);

    $cashierStmt = $conn->prepare("
        SELECT first_name, last_name, username
        FROM users
        WHERE user_id = :user_id
        LIMIT 1
    ");
    $cashierStmt->execute([':user_id' => $userId]);
    $cashier = $cashierStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $cashierName = trim((string) ($cashier['first_name'] ?? '') . ' ' . (string) ($cashier['last_name'] ?? ''));
    if ($cashierName === '') {
        $cashierName = (string) ($cashier['username'] ?? 'Cashier');
    }

    shiftSalesJson([
        'success' => true,
        'summary' => [
            'shift_date' => $shiftDate,
            'cashier_name' => $cashierName,
            'total_transactions' => (int) ($summary['total_transactions'] ?? 0),
            'total_items' => (int) ($summary['total_items'] ?? 0),
            'total_sales' => (float) ($summary['total_sales'] ?? 0),
            'cash_sales' => (float) ($summary['cash_sales'] ?? 0),
        ],
        'sales' => array_map(static function (array $sale) use ($viewerRole): array {
            return [
                'sale_id' => (int) ($sale['sale_id'] ?? 0),
                'transaction_no' => SaleController::transactionNumber((int) ($sale['sale_id'] ?? 0), (string) ($sale['sale_date'] ?? '')),
                'sale_date' => (string) ($sale['sale_date'] ?? ''),
                'total_amount' => (float) ($sale['total_amount'] ?? 0),
                'tax' => (float) ($sale['tax'] ?? 0),
                'discount' => (float) ($sale['discount'] ?? 0),
                'payment_method' => (string) ($sale['payment_method'] ?? ''),
                'status' => (string) ($sale['status'] ?? 'completed'),
                'item_lines' => (int) ($sale['item_lines'] ?? 0),
                'total_items' => (int) ($sale['total_items'] ?? 0),
                'details_url' => $viewerRole === 'admin'
                    ? '/inventory_system/reports/sales_report.php?sale_id=' . (int) ($sale['sale_id'] ?? 0)
                    : null,
            ];
        }, $sales),
    ]);
} catch (InvalidArgumentException $e) {
    shiftSalesJson([
        'success' => false,
        'error' => $e->getMessage(),
    ], 422);
} catch (RuntimeException $e) {
    shiftSalesJson([
        'success' => false,
        'error' => $e->getMessage(),
    ], 409);
} catch (Throwable $e) {
    error_log('[shift_sales.php] ' . $e->getMessage());
    shiftSalesJson([
        'success' => false,
        'error' => 'Unable to load shift sales right now.',
    ], 500);
}
