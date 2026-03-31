<?php
declare(strict_types=1);

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
    ->csrf();

function jsonResponse(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);

    if ($sessionUserId <= 0) {
        jsonResponse([
            'success' => false,
            'error'   => 'Unauthorized.'
        ], 401);
    }

    $logConfig = [
        'table'       => $table_activity_logs,
        'col_user_id' => $activity_log_user_id,
        'col_action'  => $activity_log_action,
        'col_desc'    => $activity_log_desc,
        'col_ip'      => $activity_log_ip,
        'col_created' => $activity_log_created,
    ];

    if (isset($_POST['add_category'])) {
        $name = trim((string) ($_POST['category_name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        $id = CategoryController::addCategory($conn, $name, $description);

        if ($id === 'duplicate') {
            jsonResponse([
                'success' => false,
                'error'   => "Category '{$name}' already exists."
            ], 409);
        }

        $category = CategoryController::getCategoryById($conn, (int) $id);

        ob_start();
        include __DIR__ . '/../../templates/category_row_template.php';
        $newRowHtml = ob_get_clean();

        NotificationController::create(
            $conn,
            $sessionUserId,
            'admin',
            'category',
            'Category Added',
            "Category '{$category['category_name']}' was added successfully.",
            'bi-tags',
            'text-success',
            '/inventory_system/category_management/manage_category.php'
        );

        AuthController::logActivity(
            $conn,
            $logConfig,
            $sessionUserId,
            'category_add',
            "Added category: {$category['category_name']}",
            'category',
            (int) $id
        );

        jsonResponse([
            'success'    => true,
            'message'    => "Category '{$category['category_name']}' added successfully.",
            'event'      => 'notification_update',
            'type'       => 'category',
            'newRowHtml' => $newRowHtml
        ], 201);
    }

    if (isset($_POST['edit_category'])) {
        $id = (int) ($_POST['category_id'] ?? 0);
        $name = trim((string) ($_POST['category_name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        $existingCategory = CategoryController::getCategoryById($conn, $id);
        if (!$existingCategory) {
            jsonResponse([
                'success' => false,
                'error'   => 'Category not found.'
            ], 404);
        }

        $result = CategoryController::updateCategory($conn, $id, $name, $description);

        if ($result === 'duplicate') {
            jsonResponse([
                'success' => false,
                'error'   => "Category '{$name}' already exists."
            ], 409);
        }

        $category = CategoryController::getCategoryById($conn, $id);

        ob_start();
        include __DIR__ . '/../../templates/category_row_template.php';
        $newRowHtml = ob_get_clean();

        NotificationController::create(
            $conn,
            $sessionUserId,
            'admin',
            'category',
            'Category Updated',
            "Category '{$category['category_name']}' was updated successfully.",
            'bi-pencil-square',
            'text-warning',
            '/inventory_system/category_management/manage_category.php'
        );

        AuthController::logActivity(
            $conn,
            $logConfig,
            $sessionUserId,
            'category_update',
            "Updated category: {$category['category_name']}",
            'category',
            $id
        );

        jsonResponse([
            'success'    => true,
            'message'    => "Category '{$category['category_name']}' updated successfully.",
            'event'      => 'notification_update',
            'type'       => 'category',
            'newRowHtml' => $newRowHtml
        ]);
    }

    if (isset($_POST['toggle_id'])) {
        $id = (int) ($_POST['toggle_id'] ?? 0);

        $existingCategory = CategoryController::getCategoryById($conn, $id);
        if (!$existingCategory) {
            jsonResponse([
                'success' => false,
                'error'   => 'Category not found.'
            ], 404);
        }

        $newStatus = CategoryController::toggleStatus($conn, $id);

        if ($newStatus === false) {
            jsonResponse([
                'success' => false,
                'error'   => 'Category not found.'
            ], 404);
        }

        $category = CategoryController::getCategoryById($conn, $id);

        ob_start();
        include __DIR__ . '/../../templates/category_row_template.php';
        $newRowHtml = ob_get_clean();

        NotificationController::create(
            $conn,
            $sessionUserId,
            'admin',
            'category_status',
            'Category Status Changed',
            "Category '{$category['category_name']}' status changed to " . ucfirst($newStatus) . '.',
            'bi-arrow-repeat',
            'text-info',
            '/inventory_system/category_management/manage_category.php'
        );

        AuthController::logActivity(
            $conn,
            $logConfig,
            $sessionUserId,
            'category_status_update',
            "Category '{$category['category_name']}' status changed to " . ucfirst($newStatus),
            'category',
            $id
        );

        jsonResponse([
            'success'    => true,
            'message'    => "Category '{$category['category_name']}' has been " . ($newStatus === 'active' ? 'activated' : 'deactivated') . ' successfully.',
            'event'      => 'notification_update',
            'type'       => 'category_status',
            'new_status' => $newStatus,
            'newRowHtml' => $newRowHtml
        ]);
    }

    jsonResponse([
        'success' => false,
        'error'   => 'Invalid action.'
    ], 400);

} catch (InvalidArgumentException $e) {
    jsonResponse([
        'success' => false,
        'error'   => $e->getMessage()
    ], 422);

} catch (RuntimeException $e) {
    jsonResponse([
        'success' => false,
        'error'   => $e->getMessage()
    ], 404);

} catch (Throwable $e) {
    error_log('[category_actions] ' . $e->getMessage());

    jsonResponse([
        'success' => false,
        'error'   => 'Internal server error.'
    ], 500);
}
