<?php
declare(strict_types=1);

/**
 * subcategory_actions.php  —  AJAX endpoint for subcategory CRUD
 *
 * Improvements over the previous version
 * ────────────────────────────────────────
 *  1.  No ob_start() + include template. Returns structured JSON only;
 *      JS rebuilds the row client-side via buildSubcategoryRow().
 *
 *  2.  No double SELECT after writes.
 *      add()          → returns ['subcategory_id', 'view'] directly.
 *      update()       → returns the merged view directly.
 *      toggleStatus() → returns ['new_status', 'view'] directly.
 *
 *  3.  SubcategoryController now throws RuntimeException on duplicate
 *      instead of returning the string 'duplicate'. The unsafe
 *      (int) $id cast on 'duplicate' that silently became 0 is gone.
 *
 *  4.  buildLogConfig() helper replaces duplicated $GLOBALS array.
 *
 *  5.  Notifications added to edit and toggle actions (were missing).
 *
 *  6.  Input validation done early with explicit jsonResponse() so
 *      the controller never receives invalid data.
 *
 *  7.  All three write paths are wrapped so photo/file cleanup can be
 *      added later without restructuring.
 */

require_once __DIR__ . '/../../bootstrap/app.php';
require_once __DIR__ . '/../../middleware/Middleware.php';
require_once __DIR__ . '/../../controllers/SubcategoryController.php';
require_once __DIR__ . '/../../controllers/AuthController.php';
require_once __DIR__ . '/../../controllers/NotificationController.php';

header('Content-Type: application/json; charset=UTF-8');

Middleware::auth()
    ->role(['admin'])
    ->ajax()
    ->methods(['POST'])
    ->csrf()
    ->throttle('subcategory_actions', 30, 60, 'Too many subcategory changes. Please slow down and try again.');

// ── Helpers ───────────────────────────────────────────────────────────────────

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function buildLogConfig(): array
{
    return [
        'table'       => $GLOBALS['table_activity_logs']  ?? 'activity_logs',
        'col_user_id' => $GLOBALS['activity_log_user_id'] ?? 'user_id',
        'col_action'  => $GLOBALS['activity_log_action']  ?? 'action',
        'col_desc'    => $GLOBALS['activity_log_desc']    ?? 'description',
        'col_ip'      => $GLOBALS['activity_log_ip']      ?? 'ip_address',
        'col_created' => $GLOBALS['activity_log_created'] ?? 'created_at',
    ];
}

function notify(PDO $conn, ?int $userId, string $type, string $title, string $message, string $icon = 'bi-diagram-3', string $color = 'text-primary', ?string $link = null): void
{
    try {
        NotificationController::create($conn, $userId, 'admin', $type, $title, $message, $icon, $color, $link);
    } catch (Throwable $e) {
        error_log('[subcategory_actions notify] ' . $e->getMessage());
    }
}

// ── Auth guard ────────────────────────────────────────────────────────────────

$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
if ($sessionUserId <= 0) {
    jsonResponse(['success' => false, 'error' => 'Unauthorized.'], 401);
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

try {
    $logConfig = buildLogConfig();
    $pageUrl   = '/inventory_system/product_management/manage_subcategory.php';

    // ════════════════════════════════════════════════════════════════════════
    // ADD SUBCATEGORY
    // ════════════════════════════════════════════════════════════════════════
    if (isset($_POST['add_subcategory'])) {
        $categoryId  = (int)    ($_POST['category_id']       ?? 0);
        $name        = trim((string) ($_POST['subcategory_name'] ?? ''));
        $description = trim((string) ($_POST['description']      ?? ''));

        if ($categoryId <= 0) {
            jsonResponse(['success' => false, 'error' => 'Please select a category.'], 422);
        }
        if ($name === '') {
            jsonResponse(['success' => false, 'error' => 'Subcategory name is required.'], 422);
        }

        $result         = SubcategoryController::add($conn, $categoryId, $name, $description);
        $subcategoryId  = $result['subcategory_id'];
        $view           = $result['view'];

        notify($conn, $sessionUserId, 'subcategory', 'Subcategory Added',
            "{$view['subcategory_name']} was added under {$view['category_name']}.",
            'bi-diagram-3', 'text-success', $pageUrl);

        AuthController::logActivity($conn, $logConfig, $sessionUserId, 'subcategory_add',
            "Added subcategory: {$view['subcategory_name']} under {$view['category_name']}",
            'subcategory', $subcategoryId);

        jsonResponse([
            'success'         => true,
            'message'         => "Subcategory '{$view['subcategory_name']}' added successfully.",
            'event'           => 'notification_update',
            'type'            => 'subcategory',
            'subcategory'     => $view,
            'subcategory_id'  => $subcategoryId,
        ], 201);
    }

    // ════════════════════════════════════════════════════════════════════════
    // EDIT SUBCATEGORY
    // ════════════════════════════════════════════════════════════════════════
    if (isset($_POST['edit_subcategory'])) {
        $id          = (int)    ($_POST['subcategory_id']    ?? 0);
        $categoryId  = (int)    ($_POST['category_id']       ?? 0);
        $name        = trim((string) ($_POST['subcategory_name'] ?? ''));
        $description = trim((string) ($_POST['description']      ?? ''));

        if ($id <= 0) {
            jsonResponse(['success' => false, 'error' => 'Invalid subcategory ID.'], 422);
        }
        if ($categoryId <= 0) {
            jsonResponse(['success' => false, 'error' => 'Please select a category.'], 422);
        }
        if ($name === '') {
            jsonResponse(['success' => false, 'error' => 'Subcategory name is required.'], 422);
        }

        $view = SubcategoryController::update($conn, $id, $categoryId, $name, $description);

        notify($conn, $sessionUserId, 'subcategory', 'Subcategory Updated',
            "{$view['subcategory_name']} under {$view['category_name']} was updated.",
            'bi-pencil-square', 'text-warning', $pageUrl);

        AuthController::logActivity($conn, $logConfig, $sessionUserId, 'subcategory_update',
            "Updated subcategory: {$view['subcategory_name']} under {$view['category_name']}",
            'subcategory', $id);

        jsonResponse([
            'success'        => true,
            'message'        => "Subcategory '{$view['subcategory_name']}' updated successfully.",
            'event'          => 'notification_update',
            'type'           => 'subcategory',
            'subcategory'    => $view,
            'subcategory_id' => $id,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // TOGGLE STATUS
    // ════════════════════════════════════════════════════════════════════════
    if (isset($_POST['toggle_subcategory_id'])) {
        $id = (int) ($_POST['toggle_subcategory_id'] ?? 0);

        if ($id <= 0) {
            jsonResponse(['success' => false, 'error' => 'Invalid subcategory ID.'], 422);
        }

        $result      = SubcategoryController::toggleStatus($conn, $id);
        $newStatus   = $result['new_status'];
        $view        = $result['view'];
        $statusLabel = ucfirst($newStatus);
        $subName     = $view['subcategory_name'] ?? 'Subcategory';
        $catName     = $view['category_name']    ?? '';

        notify($conn, $sessionUserId, 'subcategory_status', 'Subcategory Status Changed',
            "'{$subName}' under '{$catName}' is now {$statusLabel}.",
            'bi-arrow-repeat', 'text-info', $pageUrl);

        AuthController::logActivity($conn, $logConfig, $sessionUserId, 'subcategory_status_update',
            "Subcategory '{$subName}' status changed to {$statusLabel}",
            'subcategory', $id);

        jsonResponse([
            'success'        => true,
            'message'        => "'{$subName}' is now {$statusLabel}.",
            'event'          => 'notification_update',
            'type'           => 'subcategory_status',
            'new_status'     => $newStatus,
            'subcategory'    => $view,
            'subcategory_id' => $id,
        ]);
    }

    jsonResponse(['success' => false, 'error' => 'Invalid action.'], 400);

// ── Error handlers ────────────────────────────────────────────────────────────

} catch (InvalidArgumentException $e) {
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 422);

} catch (RuntimeException $e) {
    $msg    = $e->getMessage();
    $status = str_contains(strtolower($msg), 'already exists') ? 409
            : (str_contains(strtolower($msg), 'not found')     ? 404 : 400);
    jsonResponse(['success' => false, 'error' => $msg], $status);

} catch (Throwable $e) {
    error_log('[subcategory_actions] ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Internal server error.'], 500);
}