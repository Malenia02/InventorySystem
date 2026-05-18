<?php
declare(strict_types=1);

/**
 * category_actions.php  —  AJAX endpoint for category CRUD
 *
 * Improvements over the previous version
 * ────────────────────────────────────────
 *  1.  No ob_start() + include template. Returns structured JSON only;
 *      JS rebuilds the row client-side via buildCategoryRow().
 *
 *  2.  No double (or triple) SELECT after writes.
 *      addCategory()    → returns ['category_id', 'view'] directly.
 *      updateCategory() → returns the merged view directly.
 *      toggleStatus()   → returns ['new_status', 'view'] directly.
 *
 *  3.  CategoryController now throws RuntimeException on duplicate
 *      instead of returning the string 'duplicate'.
 *
 *  4.  buildLogConfig() helper replaces duplicated $GLOBALS array.
 *
 *  5.  notify() helper keeps notification calls DRY.
 */

require_once __DIR__ . '/../../bootstrap/app.php';
require_once __DIR__ . '/../../middleware/Middleware.php';
require_once __DIR__ . '/../../controllers/CategoryController.php';
require_once __DIR__ . '/../../controllers/AuthController.php';
require_once __DIR__ . '/../../controllers/NotificationController.php';

header('Content-Type: application/json; charset=UTF-8');

Middleware::auth()
    ->role(['admin'])
    ->ajax()
    ->methods(['POST'])
    ->csrf()
    ->throttle('category_actions', 30, 60, 'Too many category changes. Please slow down and try again.');

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

function notify(PDO $conn, ?int $userId, string $type, string $title, string $message, string $icon = 'bi-tags', string $color = 'text-primary', ?string $link = null): void
{
    try {
        NotificationController::create($conn, $userId, 'admin', $type, $title, $message, $icon, $color, $link);
    } catch (Throwable $e) {
        error_log('[category_actions notify] ' . $e->getMessage());
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

    // ════════════════════════════════════════════════════════════════════════
    // ADD CATEGORY
    // ════════════════════════════════════════════════════════════════════════
    if (isset($_POST['add_category'])) {
        $name        = trim((string) ($_POST['category_name'] ?? ''));
        $description = trim((string) ($_POST['description']   ?? ''));

        $result     = CategoryController::addCategory($conn, $name, $description);
        $categoryId = $result['category_id'];
        $view       = $result['view'];

        notify($conn, $sessionUserId, 'category', 'Category Added',
            "Category '{$view['category_name']}' was added successfully.",
            'bi-tags', 'text-success',
            '/inventory_system/product_management/manage_category.php');

        AuthController::logActivity($conn, $logConfig, $sessionUserId, 'category_add',
            "Added category: {$view['category_name']}", 'category', $categoryId);

        jsonResponse([
            'success'     => true,
            'message'     => "Category '{$view['category_name']}' added successfully.",
            'event'       => 'notification_update',
            'type'        => 'category',
            'category'    => $view,
            'category_id' => $categoryId,
        ], 201);
    }

    // ════════════════════════════════════════════════════════════════════════
    // EDIT CATEGORY
    // ════════════════════════════════════════════════════════════════════════
    if (isset($_POST['edit_category'])) {
        $id          = (int) ($_POST['category_id']    ?? 0);
        $name        = trim((string) ($_POST['category_name'] ?? ''));
        $description = trim((string) ($_POST['description']   ?? ''));

        if ($id <= 0) {
            jsonResponse(['success' => false, 'error' => 'Invalid category ID.'], 422);
        }

        $view = CategoryController::updateCategory($conn, $id, $name, $description);

        notify($conn, $sessionUserId, 'category', 'Category Updated',
            "Category '{$view['category_name']}' was updated successfully.",
            'bi-pencil-square', 'text-warning',
            '/inventory_system/product_management/manage_category.php');

        AuthController::logActivity($conn, $logConfig, $sessionUserId, 'category_update',
            "Updated category: {$view['category_name']}", 'category', $id);

        jsonResponse([
            'success'     => true,
            'message'     => "Category '{$view['category_name']}' updated successfully.",
            'event'       => 'notification_update',
            'type'        => 'category',
            'category'    => $view,
            'category_id' => $id,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // TOGGLE STATUS
    // ════════════════════════════════════════════════════════════════════════
    if (isset($_POST['toggle_id'])) {
        $id = (int) ($_POST['toggle_id'] ?? 0);

        if ($id <= 0) {
            jsonResponse(['success' => false, 'error' => 'Invalid category ID.'], 422);
        }

        $result      = CategoryController::toggleStatus($conn, $id);
        $newStatus   = $result['new_status'];
        $view        = $result['view'];
        $statusLabel = ucfirst($newStatus);
        $catName     = $view['category_name'] ?? 'Category';

        notify($conn, $sessionUserId, 'category_status', 'Category Status Changed',
            "'{$catName}' is now {$statusLabel}.",
            'bi-arrow-repeat', 'text-info',
            '/inventory_system/product_management/manage_category.php');

        AuthController::logActivity($conn, $logConfig, $sessionUserId, 'category_status_update',
            "Category '{$catName}' status changed to {$statusLabel}", 'category', $id);

        jsonResponse([
            'success'     => true,
            'message'     => "'{$catName}' has been " . ($newStatus === 'active' ? 'activated' : 'deactivated') . ' successfully.',
            'event'       => 'notification_update',
            'type'        => 'category_status',
            'new_status'  => $newStatus,
            'category'    => $view,
            'category_id' => $id,
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
    error_log('[category_actions] ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Internal server error.'], 500);
}