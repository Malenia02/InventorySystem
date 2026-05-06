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
    ->throttle('shift_closing_actions', 20, 60, 'Too many shift actions. Please slow down.');

function shiftClosingJson(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
    $sessionRole = (string) ($_SESSION['role'] ?? '');
    $isAdmin = strtolower($sessionRole) === 'admin';

    if ($sessionUserId <= 0) {
        throw new RuntimeException('Your session has expired. Please log in again.');
    }

    $targetUserId = $sessionUserId;
    if ($isAdmin && isset($_POST['user_id']) && (int) $_POST['user_id'] > 0) {
        $targetUserId = (int) $_POST['user_id'];
    }

    $shiftAction = strtolower(trim((string) ($_POST['shift_action'] ?? 'close')));
    $redirectParams = [];
    $socketEvent = null;
    $message = '';
    $animateStart = false;

    if ($shiftAction === 'start') {
        $started = ShiftClosingController::startShift($conn, $targetUserId, $_POST);
        $message = 'Shift started successfully. Sales will now be tracked from the opening time.';
        $animateStart = true;
        $socketEvent = [
            'event' => 'notification_update',
            'type' => 'shift_closing_started',
            'target_roles' => ['admin'],
            'target_user_ids' => [$targetUserId],
        ];
        $redirectParams['shift_date'] = $started['shift_date'];
    } else {
        $saved = ShiftClosingController::closeShift($conn, $targetUserId, $_POST);
        $message = !empty($saved['was_override'])
            ? 'Shift closing updated with admin override.'
            : (!empty($saved['was_updated'])
                ? 'Shift closing updated successfully.'
                : 'Shift closed successfully.');
        $socketEvent = [
            'event' => 'notification_update',
            'type' => !empty($saved['was_updated']) ? 'shift_closing_updated' : 'shift_closing_saved',
            'target_roles' => ['admin'],
            'target_user_ids' => [$targetUserId],
        ];
        $redirectParams['shift_date'] = (string) ($saved['shift_date'] ?? date('Y-m-d'));
    }

    if ($isAdmin) {
        $redirectParams['user_id'] = $targetUserId;
    }

    shiftClosingJson([
        'success' => true,
        'message' => $message,
        'redirect_url' => '/inventory_system/shift_closing.php?' . http_build_query($redirectParams),
        'socket_event' => $socketEvent,
        'animate_start' => $animateStart,
    ]);
} catch (InvalidArgumentException $e) {
    shiftClosingJson([
        'success' => false,
        'error' => $e->getMessage(),
    ], 422);
} catch (RuntimeException $e) {
    shiftClosingJson([
        'success' => false,
        'error' => $e->getMessage(),
    ], 409);
} catch (Throwable $e) {
    error_log('[shift_closing_actions ajax] ' . $e->getMessage());
    shiftClosingJson([
        'success' => false,
        'error' => 'Unable to save the shift right now.',
    ], 500);
}
