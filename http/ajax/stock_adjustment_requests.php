<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap/app.php';
require_once __DIR__ . '/../../middleware/Middleware.php';
require_once __DIR__ . '/../../controllers/AuthController.php';
require_once __DIR__ . '/../../controllers/NotificationController.php';
require_once __DIR__ . '/../../controllers/StockAdjustmentController.php';

header('Content-Type: application/json; charset=UTF-8');

Middleware::auth()
    ->role(['admin', 'cashier', 'staff'])
    ->ajax()
    ->methods(['POST'])
    ->csrf()
    ->throttle('stock_adjustment_requests', 25, 60, 'Too many stock adjustment actions. Please slow down.');

function stockAdjustmentJson(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function stockAdjustmentPersonLabel(array $row, string $prefix): string
{
    $name = trim((string) ($row[$prefix . '_first_name'] ?? '') . ' ' . (string) ($row[$prefix . '_last_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    return (string) ($row[$prefix . '_username'] ?? 'User');
}

function stockAdjustmentDateText(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '-';
    }

    $timestamp = strtotime($value);
    return $timestamp !== false ? date('M d, Y g:i A', $timestamp) : '-';
}

function stockAdjustmentRequestView(array $request, bool $isAdmin): array
{
    $status = strtolower((string) ($request['status'] ?? 'pending'));

    return [
        'request_id' => (int) ($request['request_id'] ?? 0),
        'status' => $status,
        'status_label' => ucfirst($status),
        'status_class' => match ($status) {
            'approved' => 'is-approved',
            'declined' => 'is-declined',
            default => 'is-pending',
        },
        'product_name' => (string) ($request['product_name'] ?? 'Product'),
        'direction' => (string) ($request['direction'] ?? 'stock_out'),
        'direction_label' => strtolower((string) ($request['direction'] ?? 'stock_out')) === 'stock_in' ? 'Stock In' : 'Stock Out',
        'quantity' => (int) ($request['quantity'] ?? 0),
        'adjustment_type' => (string) ($request['adjustment_type'] ?? 'manual_adjustment'),
        'adjustment_type_label' => ucwords(str_replace('_', ' ', (string) ($request['adjustment_type'] ?? 'manual_adjustment'))),
        'reason' => (string) ($request['reason'] ?? ''),
        'notes' => (string) ($request['notes'] ?? ''),
        'review_note' => (string) ($request['review_note'] ?? ''),
        'requested_at_label' => stockAdjustmentDateText($request['requested_at'] ?? null),
        'reviewed_at_label' => stockAdjustmentDateText($request['reviewed_at'] ?? null),
        'category_name' => (string) ($request['category_name'] ?? 'Uncategorized'),
        'current_quantity' => (int) ($request['current_quantity'] ?? 0),
        'before_quantity' => isset($request['before_quantity']) ? (int) $request['before_quantity'] : null,
        'after_quantity' => isset($request['after_quantity']) ? (int) $request['after_quantity'] : null,
        'requester_label' => stockAdjustmentPersonLabel($request, 'requester'),
        'reviewer_label' => stockAdjustmentPersonLabel($request, 'reviewer'),
        'can_review' => $isAdmin && $status === 'pending',
    ];
}

function stockAdjustmentCounts(PDO $conn, string $role, ?int $userId): array
{
    return StockAdjustmentController::countByStatus($conn, $role, $userId);
}

try {
    StockAdjustmentController::ensureSchema($conn);
    ProductController::ensureStockMovementSchema($conn);

    $body = $_POST;
    $action = strtolower(trim((string) ($body['action'] ?? '')));
    $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
    $sessionRole = strtolower(trim((string) ($_SESSION['role'] ?? 'staff')));
    $isAdmin = $sessionRole === 'admin';

    if ($sessionUserId <= 0) {
        throw new RuntimeException('Your session has expired. Please log in again.');
    }

    $logConfig = [
        'table' => $table_activity_logs,
        'col_user_id' => $activity_log_user_id,
        'col_action' => $activity_log_action,
        'col_desc' => $activity_log_desc,
        'col_ip' => $activity_log_ip,
        'col_created' => $activity_log_created,
    ];

    if ($action === 'submit') {
        if ($isAdmin) {
            throw new RuntimeException('Admin can apply stock changes directly from Manage Products.');
        }

        $created = StockAdjustmentController::submitRequest($conn, $body, $sessionUserId);

        NotificationController::create(
            $conn,
            $sessionUserId,
            'admin',
            'stock_adjustment_request',
            'Stock Adjustment Request',
            sprintf(
                '%s requested %s for %s (%d unit%s).',
                stockAdjustmentPersonLabel($created, 'requester'),
                strtolower((string) ($created['direction'] ?? 'stock_out')) === 'stock_in' ? 'stock in' : 'stock out',
                (string) ($created['product_name'] ?? 'a product'),
                (int) ($created['quantity'] ?? 0),
                (int) ($created['quantity'] ?? 0) === 1 ? '' : 's'
            ),
            'bi-clipboard-check',
            'text-warning',
            '/inventory_system/admin/approval_center.php'
        );

        AuthController::logActivity(
            $conn,
            $logConfig,
            $sessionUserId,
            'stock_adjustment_request_create',
            sprintf(
                'Requested %s of %d unit(s) for %s. Type: %s. Reason: %s',
                strtolower((string) ($created['direction'] ?? 'stock_out')) === 'stock_in' ? 'stock in' : 'stock out',
                (int) ($created['quantity'] ?? 0),
                (string) ($created['product_name'] ?? 'Product'),
                str_replace('_', ' ', (string) ($created['adjustment_type'] ?? 'manual_adjustment')),
                (string) ($created['reason'] ?? '')
            ),
            'stock_adjustment_request',
            (int) ($created['request_id'] ?? 0),
            'warning'
        );

        stockAdjustmentJson([
            'success' => true,
            'message' => 'Stock adjustment request sent to the owner/admin.',
            'request' => stockAdjustmentRequestView($created, false),
            'counts' => stockAdjustmentCounts($conn, $sessionRole, $sessionUserId),
        ]);
    }

    if ($action === 'review') {
        if (!$isAdmin) {
            throw new RuntimeException('Only admin can review stock adjustment requests.');
        }

        $stepUpPassword = (string) ($body['step_up_password'] ?? '');
        AuthController::requireStepUpOrPassword($conn, $sessionUserId, $stepUpPassword);

        $requestId = max(0, (int) ($body['request_id'] ?? 0));
        $decision = (string) ($body['decision'] ?? '');
        $reviewNote = (string) ($body['review_note'] ?? '');
        $reviewed = StockAdjustmentController::reviewRequest($conn, $requestId, $sessionUserId, $decision, $reviewNote);
        AuthController::markStepUpVerified();

        $reviewDecision = strtolower((string) ($reviewed['status'] ?? $decision));
        NotificationController::create(
            $conn,
            (int) ($reviewed['requester_user_id'] ?? 0),
            in_array((string) ($reviewed['requester_role'] ?? ''), ['cashier', 'staff', 'admin'], true)
                ? (string) $reviewed['requester_role']
                : 'staff',
            'stock_adjustment_' . $reviewDecision,
            $reviewDecision === 'approved' ? 'Stock Adjustment Approved' : 'Stock Adjustment Declined',
            $reviewDecision === 'approved'
                ? sprintf(
                    'Your request for %s was approved. Inventory moved from %d to %d.',
                    (string) ($reviewed['product_name'] ?? 'the selected product'),
                    (int) ($reviewed['before_quantity'] ?? 0),
                    (int) ($reviewed['after_quantity'] ?? 0)
                )
                : sprintf(
                    'Your request for %s was declined.%s',
                    (string) ($reviewed['product_name'] ?? 'the selected product'),
                    !empty($reviewed['review_note']) ? ' Note: ' . (string) $reviewed['review_note'] : ''
                ),
            $reviewDecision === 'approved' ? 'bi-check2-circle' : 'bi-x-circle',
            $reviewDecision === 'approved' ? 'text-success' : 'text-danger',
            '/inventory_system/stock_adjustment_requests.php'
        );

        AuthController::logActivity(
            $conn,
            $logConfig,
            $sessionUserId,
            'stock_adjustment_request_' . $reviewDecision,
            sprintf(
                '%s request #%d for %s.%s',
                ucfirst($reviewDecision),
                $requestId,
                (string) ($reviewed['product_name'] ?? 'Product'),
                $reviewDecision === 'approved'
                    ? ' Quantity changed from ' . (int) ($reviewed['before_quantity'] ?? 0) . ' to ' . (int) ($reviewed['after_quantity'] ?? 0) . '.'
                    : ($reviewed['review_note'] !== '' ? ' Note: ' . (string) $reviewed['review_note'] : '')
            ),
            'stock_adjustment_request',
            $requestId,
            $reviewDecision === 'approved' ? 'info' : 'warning'
        );

        stockAdjustmentJson([
            'success' => true,
            'message' => $reviewDecision === 'approved'
                ? 'Stock adjustment approved and applied successfully.'
                : 'Stock adjustment request declined.',
            'request' => stockAdjustmentRequestView($reviewed, true),
            'counts' => stockAdjustmentCounts($conn, 'admin', null),
        ]);
    }

    throw new InvalidArgumentException('Unsupported stock adjustment action.');
} catch (InvalidArgumentException $e) {
    stockAdjustmentJson([
        'success' => false,
        'error' => $e->getMessage(),
    ], 422);
} catch (RuntimeException $e) {
    stockAdjustmentJson([
        'success' => false,
        'error' => $e->getMessage(),
    ], 409);
} catch (Throwable $e) {
    error_log('[stock_adjustment_requests ajax] ' . $e->getMessage());
    stockAdjustmentJson([
        'success' => false,
        'error' => 'Unable to process the stock adjustment request right now.',
    ], 500);
}
