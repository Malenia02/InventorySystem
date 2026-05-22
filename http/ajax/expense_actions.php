<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap/app.php';
require_once __DIR__ . '/../../middleware/Middleware.php';
require_once __DIR__ . '/../../controllers/AuthController.php';
require_once __DIR__ . '/../../controllers/ExpenseController.php';

header('Content-Type: application/json; charset=UTF-8');

Middleware::auth()
    ->role(['admin'])
    ->ajax()
    ->methods(['POST'])
    ->csrf()
    ->throttle('expense_actions', 20, 60, 'Too many expense submissions. Please slow down.');

function expenseJson(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    ExpenseController::ensureSchema($conn);

    $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
    if ($sessionUserId <= 0) {
        throw new RuntimeException('Your session has expired. Please log in again.');
    }

    $action = strtolower(trim((string) ($_POST['action'] ?? 'create')));

    if ($action === 'mark_paid') {
        $expenseId = (int) ($_POST['expense_id'] ?? 0);
        $updated = ExpenseController::markPaid($conn, $expenseId, $_POST, $sessionUserId);

        AuthController::logActivity(
            $conn,
            [
                'table' => $table_activity_logs,
                'col_user_id' => $activity_log_user_id,
                'col_action' => $activity_log_action,
                'col_desc' => $activity_log_desc,
                'col_ip' => $activity_log_ip,
                'col_created' => $activity_log_created,
            ],
            $sessionUserId,
            'expense_payment_update',
            sprintf(
                'Updated payment for expense %s. Status: %s, paid PHP %.2f of PHP %.2f.',
                (string) ($updated['category'] ?? 'Expense'),
                (string) ($updated['payment_status'] ?? 'unknown'),
                (float) ($updated['paid_amount'] ?? 0),
                (float) ($updated['amount'] ?? 0)
            ),
            'expense',
            (int) ($updated['expense_id'] ?? 0)
        );

        $creator = trim((string) ($updated['first_name'] ?? '') . ' ' . (string) ($updated['last_name'] ?? ''));
        if ($creator === '') {
            $creator = (string) ($updated['username'] ?? 'Admin');
        }

        expenseJson([
            'success' => true,
            'message' => 'Expense payment updated.',
            'expense' => expensePayload($updated, $creator),
            'summary' => ExpenseController::summary($conn),
        ]);
    }

    if ($action !== 'create') {
        throw new InvalidArgumentException('Invalid expense action.');
    }

    $created = ExpenseController::create($conn, $_POST, $sessionUserId);

    AuthController::logActivity(
        $conn,
        [
            'table' => $table_activity_logs,
            'col_user_id' => $activity_log_user_id,
            'col_action' => $activity_log_action,
            'col_desc' => $activity_log_desc,
            'col_ip' => $activity_log_ip,
            'col_created' => $activity_log_created,
        ],
        $sessionUserId,
        'expense_create',
        sprintf(
            'Logged expense %s for PHP %.2f on %s.',
            (string) ($created['category'] ?? 'Expense'),
            (float) ($created['amount'] ?? 0),
            (string) ($created['expense_date'] ?? date('Y-m-d'))
        ),
        'expense',
        (int) ($created['expense_id'] ?? 0)
    );

    $summary = ExpenseController::summary($conn);
    $creator = trim((string) ($created['first_name'] ?? '') . ' ' . (string) ($created['last_name'] ?? ''));
    if ($creator === '') {
        $creator = (string) ($created['username'] ?? 'Admin');
    }

    expenseJson([
        'success' => true,
        'message' => 'Expense saved successfully.',
        'expense' => expensePayload($created, $creator),
        'summary' => $summary,
    ]);
} catch (InvalidArgumentException $e) {
    expenseJson(['success' => false, 'error' => $e->getMessage()], 422);
} catch (RuntimeException $e) {
    expenseJson(['success' => false, 'error' => $e->getMessage()], 409);
} catch (Throwable $e) {
    error_log('[expense_actions] ' . $e->getMessage());
    expenseJson(['success' => false, 'error' => 'Unable to save the expense right now.'], 500);
}

function expensePayload(array $expense, string $creator): array
{
    $amount = (float) ($expense['amount'] ?? 0);
    $paidAmount = (float) ($expense['paid_amount'] ?? 0);

    return [
        'expense_id' => (int) ($expense['expense_id'] ?? 0),
        'expense_date' => (string) ($expense['expense_date'] ?? ''),
        'category' => (string) ($expense['category'] ?? ''),
        'amount' => $amount,
        'notes' => (string) ($expense['notes'] ?? ''),
        'creator' => $creator,
        'payment_status' => (string) ($expense['payment_status'] ?? 'paid'),
        'due_date' => (string) ($expense['due_date'] ?? ''),
        'paid_at' => (string) ($expense['paid_at'] ?? ''),
        'paid_amount' => $paidAmount,
        'open_balance' => max(0.0, round($amount - $paidAmount, 2)),
        'payment_method' => (string) ($expense['payment_method'] ?? ''),
        'reference_no' => (string) ($expense['reference_no'] ?? ''),
    ];
}
