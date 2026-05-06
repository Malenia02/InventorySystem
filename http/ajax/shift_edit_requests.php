<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap/app.php';
require_once __DIR__ . '/../../middleware/Middleware.php';
require_once __DIR__ . '/../../controllers/AuthController.php';
require_once __DIR__ . '/../../controllers/ShiftClosingController.php';

header('Content-Type: application/json; charset=UTF-8');

Middleware::auth()
    ->role(['admin', 'cashier'])
    ->ajax()
    ->methods(['POST'])
    ->csrf()
    ->throttle('shift_edit_requests', 20, 60, 'Too many shift edit actions. Please slow down.');

function shiftEditRequestJson(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requestPersonLabel(array $row, string $prefix): string
{
    $name = trim((string) ($row[$prefix . '_first_name'] ?? '') . ' ' . (string) ($row[$prefix . '_last_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    return trim((string) ($row[$prefix . '_username'] ?? ''));
}

function dateTimeText(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '-';
    }

    $timestamp = strtotime($value);
    return $timestamp !== false ? date('M d, Y g:i A', $timestamp) : '-';
}

function requestViewPayload(array $request, bool $isAdmin): array
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
        'shift_date_label' => date('M d, Y', strtotime((string) ($request['shift_date'] ?? date('Y-m-d')))),
        'requested_at_label' => dateTimeText($request['requested_at'] ?? null),
        'approved_until_label' => dateTimeText($request['approved_until'] ?? null),
        'request_reason' => (string) ($request['request_reason'] ?? ''),
        'review_note' => (string) ($request['review_note'] ?? 'No review note yet.'),
        'requester_label' => requestPersonLabel($request, 'requester'),
        'target_label' => requestPersonLabel($request, 'target'),
        'reviewer_label' => requestPersonLabel($request, 'reviewer'),
        'can_review' => $isAdmin && $status === 'pending',
    ];
}

try {
    ShiftClosingController::ensureSchema($conn);

    $body = $_POST;
    $action = strtolower(trim((string) ($body['action'] ?? '')));
    $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
    $sessionRole = (string) ($_SESSION['role'] ?? '');
    $isAdmin = strtolower($sessionRole) === 'admin';

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
        $shiftClosingId = max(0, (int) ($body['shift_closing_id'] ?? 0));
        $reason = (string) ($body['request_reason'] ?? '');
        $created = ShiftClosingController::submitEditRequest($conn, $shiftClosingId, $sessionUserId, $reason);

        AuthController::logActivity(
            $conn,
            $logConfig,
            $sessionUserId,
            'shift_edit_request_create',
            sprintf(
                'Requested edit access for shift %s (record #%d).',
                (string) ($created['shift_date'] ?? date('Y-m-d')),
                (int) ($created['shift_closing_id'] ?? 0)
            ),
            'shift_closing',
            (int) ($created['shift_closing_id'] ?? 0),
            'warning'
        );

        $requestRow = null;
        foreach (ShiftClosingController::listEditRequests($conn, $sessionRole, $sessionUserId, 'all', 100) as $request) {
            if ((int) ($request['request_id'] ?? 0) === (int) ($created['request_id'] ?? 0)) {
                $requestRow = $request;
                break;
            }
        }

        if ($requestRow === null) {
            throw new RuntimeException('Request saved, but the updated request view could not be loaded.');
        }

        $allRequests = ShiftClosingController::listEditRequests($conn, $sessionRole, $sessionUserId, 'all', 100);
        shiftEditRequestJson([
            'success' => true,
            'message' => 'Shift edit request sent to the owner/admin for review.',
            'request' => requestViewPayload($requestRow, $isAdmin),
            'counts' => [
                'pending' => count(array_filter($allRequests, static fn(array $item): bool => ($item['status'] ?? '') === 'pending')),
                'approved' => count(array_filter($allRequests, static fn(array $item): bool => ($item['status'] ?? '') === 'approved')),
                'declined' => count(array_filter($allRequests, static fn(array $item): bool => ($item['status'] ?? '') === 'declined')),
            ],
        ]);
    }

    if ($action === 'review') {
        if (!$isAdmin) {
            throw new RuntimeException('Only admin can review shift edit requests.');
        }

        $stepUpPassword = (string) ($body['step_up_password'] ?? '');
        AuthController::requireStepUpOrPassword($conn, $sessionUserId, $stepUpPassword);

        $requestId = max(0, (int) ($body['request_id'] ?? 0));
        $decision = (string) ($body['decision'] ?? '');
        $reviewNote = (string) ($body['review_note'] ?? '');
        $reviewed = ShiftClosingController::reviewEditRequest($conn, $requestId, $sessionUserId, $decision, $reviewNote);
        AuthController::markStepUpVerified();

        AuthController::logActivity(
            $conn,
            $logConfig,
            $sessionUserId,
            'shift_edit_request_' . strtolower((string) ($reviewed['decision'] ?? 'reviewed')),
            sprintf(
                'Shift edit request #%d was %s.%s',
                $requestId,
                strtolower((string) ($reviewed['decision'] ?? 'reviewed')),
                !empty($reviewed['approved_until']) ? ' Unlock until ' . (string) $reviewed['approved_until'] . '.' : ''
            ),
            'shift_closing_request',
            $requestId,
            strtolower((string) ($reviewed['decision'] ?? '')) === 'approved' ? 'info' : 'warning'
        );

        $requestRow = null;
        foreach (ShiftClosingController::listEditRequests($conn, 'admin', null, 'all', 100) as $request) {
            if ((int) ($request['request_id'] ?? 0) === $requestId) {
                $requestRow = $request;
                break;
            }
        }

        if ($requestRow === null) {
            throw new RuntimeException('Request updated, but the refreshed request view could not be loaded.');
        }

        $allRequests = ShiftClosingController::listEditRequests($conn, 'admin', null, 'all', 100);
        shiftEditRequestJson([
            'success' => true,
            'message' => strtolower((string) ($reviewed['decision'] ?? '')) === 'approved'
                ? 'Request approved and temporary edit access has been opened.'
                : 'Request declined successfully.',
            'request' => requestViewPayload($requestRow, true),
            'counts' => [
                'pending' => count(array_filter($allRequests, static fn(array $item): bool => ($item['status'] ?? '') === 'pending')),
                'approved' => count(array_filter($allRequests, static fn(array $item): bool => ($item['status'] ?? '') === 'approved')),
                'declined' => count(array_filter($allRequests, static fn(array $item): bool => ($item['status'] ?? '') === 'declined')),
            ],
        ]);
    }

    throw new InvalidArgumentException('Unsupported shift edit request action.');
} catch (InvalidArgumentException $e) {
    shiftEditRequestJson([
        'success' => false,
        'error' => $e->getMessage(),
    ], 422);
} catch (RuntimeException $e) {
    shiftEditRequestJson([
        'success' => false,
        'error' => $e->getMessage(),
    ], 409);
} catch (Throwable $e) {
    error_log('[shift_edit_requests ajax] ' . $e->getMessage());
    shiftEditRequestJson([
        'success' => false,
        'error' => 'Unable to process the shift edit request right now.',
    ], 500);
}
