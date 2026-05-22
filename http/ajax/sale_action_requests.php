<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap/app.php';
require_once __DIR__ . '/../../middleware/Middleware.php';
require_once __DIR__ . '/../../controllers/AuthController.php';
require_once __DIR__ . '/../../controllers/SaleActionRequestController.php';
require_once __DIR__ . '/../../controllers/NotificationController.php';
require_once __DIR__ . '/../../controllers/SaleController.php';

header('Content-Type: application/json; charset=UTF-8');

Middleware::auth()
    ->role(['admin', 'cashier'])
    ->ajax()
    ->methods(['POST'])
    ->csrf()
    ->throttle('sale_action_requests', 20, 60, 'Too many sale action requests. Please slow down.');

function saleActionRequestJson(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function saleActionTransactionNumber(PDO $conn, int $saleId): string
{
    $stmt = $conn->prepare("SELECT sale_date FROM sales WHERE sale_id = :sale_id LIMIT 1");
    $stmt->execute([':sale_id' => $saleId]);
    $saleDate = (string) ($stmt->fetchColumn() ?: date('Y-m-d H:i:s'));
    return SaleController::transactionNumber($saleId, $saleDate);
}

try {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw ?: '', true);
    if (!is_array($body)) {
        $body = $_POST;
    }

    $action = strtolower(trim((string) ($body['action'] ?? 'create')));
    $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
    $sessionRole = strtolower((string) ($_SESSION['role'] ?? ''));

    if ($sessionUserId <= 0) {
        saleActionRequestJson(['success' => false, 'error' => 'Unauthorized.'], 401);
    }

    $logConfig = [
        'table'       => $table_activity_logs,
        'col_user_id' => $activity_log_user_id,
        'col_action'  => $activity_log_action,
        'col_desc'    => $activity_log_desc,
        'col_ip'      => $activity_log_ip,
        'col_created' => $activity_log_created,
    ];

    if ($action === 'create') {
        $request = SaleActionRequestController::create($conn, $body, $sessionUserId, $sessionRole);
        $transactionNo = saleActionTransactionNumber($conn, (int) $request['sale_id']);

        NotificationController::create(
            $conn,
            $sessionUserId,
            'admin',
            'sale_action_request',
            'Sale action request',
            sprintf('%s requested %s for %s.', ucfirst($sessionRole), str_replace('_', ' ', (string) $request['action_type']), $transactionNo),
            'bi-arrow-counterclockwise',
            'text-warning',
            '/inventory_system/admin/sale_action_requests.php'
        );

        saleActionRequestJson([
            'success' => true,
            'message' => 'Request sent to admin for approval.',
            'request' => $request,
        ]);
    }

    if ($action === 'review') {
        if ($sessionRole !== 'admin') {
            throw new RuntimeException('Only admin can review sale action requests.');
        }

        $stepUpPassword = (string) ($body['step_up_password'] ?? '');
        AuthController::requireStepUpOrPassword($conn, $sessionUserId, $stepUpPassword);

        $request = SaleActionRequestController::review(
            $conn,
            (int) ($body['request_id'] ?? 0),
            $sessionUserId,
            (string) ($body['decision'] ?? ''),
            (string) ($body['review_note'] ?? ''),
            $logConfig
        );
        AuthController::markStepUpVerified();
        $transactionNo = saleActionTransactionNumber($conn, (int) $request['sale_id']);
        $approved = strtolower((string) $request['status']) === 'approved';

        NotificationController::create(
            $conn,
            (int) ($request['requester_user_id'] ?? 0),
            'cashier',
            'sale_action_request_' . (string) $request['status'],
            $approved ? 'Sale request approved' : 'Sale request declined',
            sprintf('Your request for %s was %s.', $transactionNo, (string) $request['status']),
            $approved ? 'bi-check2-circle' : 'bi-x-circle',
            $approved ? 'text-success' : 'text-danger',
            '/inventory_system/cashier_sales_history.php?sale_id=' . (int) $request['sale_id']
        );

        saleActionRequestJson([
            'success' => true,
            'message' => $approved ? 'Request approved and applied.' : 'Request declined.',
            'request' => $request,
        ]);
    }

    throw new InvalidArgumentException('Unsupported sale action request.');
} catch (InvalidArgumentException $e) {
    saleActionRequestJson(['success' => false, 'error' => $e->getMessage()], 422);
} catch (RuntimeException $e) {
    saleActionRequestJson(['success' => false, 'error' => $e->getMessage()], 409);
} catch (Throwable $e) {
    error_log('[sale_action_requests.php] ' . $e->getMessage());
    saleActionRequestJson(['success' => false, 'error' => 'Unable to process sale action request.'], 500);
}
