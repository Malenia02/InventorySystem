<?php
declare(strict_types=1);

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

function subcategoryJsonResponse(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
    if ($sessionUserId <= 0) {
        subcategoryJsonResponse(['success' => false, 'error' => 'Unauthorized.'], 401);
    }

    $logConfig = [
        'table'       => $table_activity_logs,
        'col_user_id' => $activity_log_user_id,
        'col_action'  => $activity_log_action,
        'col_desc'    => $activity_log_desc,
        'col_ip'      => $activity_log_ip,
        'col_created' => $activity_log_created,
    ];

    if (isset($_POST['add_subcategory'])) {
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $name = trim((string) ($_POST['subcategory_name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        $id = SubcategoryController::add($conn, $categoryId, $name, $description);
        if ($id === 'duplicate') {
            subcategoryJsonResponse(['success' => false, 'error' => "Subcategory '{$name}' already exists in that category."], 409);
        }

        $subcategory = SubcategoryController::getById($conn, (int) $id);
        ob_start();
        include __DIR__ . '/../../templates/subcategory_row.php';
        $newRowHtml = ob_get_clean();

        NotificationController::create(
            $conn,
            $sessionUserId,
            'admin',
            'subcategory',
            'Subcategory Added',
            "{$subcategory['subcategory_name']} was added under {$subcategory['category_name']}.",
            'bi-diagram-3',
            'text-success',
            '/inventory_system/product_management/manage_subcategory.php'
        );

        AuthController::logActivity(
            $conn,
            $logConfig,
            $sessionUserId,
            'subcategory_add',
            "Added subcategory: {$subcategory['subcategory_name']} under {$subcategory['category_name']}",
            'subcategory',
            (int) $id
        );

        subcategoryJsonResponse([
            'success' => true,
            'message' => "Subcategory '{$subcategory['subcategory_name']}' added successfully.",
            'newRowHtml' => $newRowHtml,
        ], 201);
    }

    if (isset($_POST['edit_subcategory'])) {
        $id = (int) ($_POST['subcategory_id'] ?? 0);
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $name = trim((string) ($_POST['subcategory_name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        $result = SubcategoryController::update($conn, $id, $categoryId, $name, $description);
        if ($result === 'duplicate') {
            subcategoryJsonResponse(['success' => false, 'error' => "Subcategory '{$name}' already exists in that category."], 409);
        }

        $subcategory = SubcategoryController::getById($conn, $id);
        ob_start();
        include __DIR__ . '/../../templates/subcategory_row.php';
        $newRowHtml = ob_get_clean();

        AuthController::logActivity(
            $conn,
            $logConfig,
            $sessionUserId,
            'subcategory_update',
            "Updated subcategory: {$subcategory['subcategory_name']}",
            'subcategory',
            $id
        );

        subcategoryJsonResponse([
            'success' => true,
            'message' => "Subcategory '{$subcategory['subcategory_name']}' updated successfully.",
            'newRowHtml' => $newRowHtml,
        ]);
    }

    if (isset($_POST['toggle_subcategory_id'])) {
        $id = (int) ($_POST['toggle_subcategory_id'] ?? 0);
        $newStatus = SubcategoryController::toggleStatus($conn, $id);
        if ($newStatus === false) {
            subcategoryJsonResponse(['success' => false, 'error' => 'Subcategory not found.'], 404);
        }

        $subcategory = SubcategoryController::getById($conn, $id);
        ob_start();
        include __DIR__ . '/../../templates/subcategory_row.php';
        $newRowHtml = ob_get_clean();

        AuthController::logActivity(
            $conn,
            $logConfig,
            $sessionUserId,
            'subcategory_status_update',
            "Subcategory '{$subcategory['subcategory_name']}' status changed to " . ucfirst((string) $newStatus),
            'subcategory',
            $id
        );

        subcategoryJsonResponse([
            'success' => true,
            'message' => "Subcategory '{$subcategory['subcategory_name']}' is now {$newStatus}.",
            'new_status' => $newStatus,
            'newRowHtml' => $newRowHtml,
        ]);
    }

    subcategoryJsonResponse(['success' => false, 'error' => 'Invalid action.'], 400);
} catch (InvalidArgumentException $e) {
    subcategoryJsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
} catch (RuntimeException $e) {
    subcategoryJsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('[subcategory_actions] ' . $e->getMessage());
    subcategoryJsonResponse(['success' => false, 'error' => 'Internal server error.'], 500);
}
