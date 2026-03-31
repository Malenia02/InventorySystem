<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap/app.php';
require_once __DIR__ . '/../../middleware/Middleware.php';
require_once __DIR__ . '/../../controllers/StaffController.php';
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
    echo json_encode($payload);
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

    if (isset($_POST['add_staff'])) {
        $firstName = trim((string) ($_POST['firstname'] ?? ''));
        $lastName  = trim((string) ($_POST['lastname'] ?? ''));
        $staffName = trim($firstName . '_' . $lastName, '_');

        $photoPath = null;
        if (!empty($_FILES['photo']['name'])) {
            $photoPath = StaffController::handlePhotoUpload('photo', $staffName);
        }

        $conn->beginTransaction();

        $staffId = StaffController::addStaff($conn, [
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'email'      => (string) ($_POST['email'] ?? ''),
            'username'   => (string) ($_POST['username'] ?? ''),
            'password'   => (string) ($_POST['password'] ?? ''),
            'role'       => (string) ($_POST['role'] ?? 'staff'),
            'photo'      => $photoPath,
        ]);

        $staff = StaffController::getStaffById($conn, $staffId);
        if (!$staff) {
            throw new RuntimeException('Failed to load the new staff record.');
        }

        $staffView = StaffController::normalizeForView($staff);

        AuthController::logActivity(
            $conn,
            $logConfig,
            $sessionUserId,
            'staff_add',
            'Added staff: ' . $staffView['full_name'] . ' (' . $staffView['role'] . ')',
            'user',
            $staffId
        );

        NotificationController::create(
            $conn,
            $sessionUserId,
            'admin',
            'staff',
            'Staff Added',
            $staffView['full_name'] . ' joined the team.',
            'bi-person-plus',
            'text-success',
            '/inventory_system/admin/manage_staff.php'
        );

        $conn->commit();

        jsonResponse([
            'success'  => true,
            'message'  => "Nice work! {$staffView['full_name']} is now part of the team.",
            'staff'    => $staffView,
            'staff_id' => $staffId
        ], 201);
    }

    if (isset($_POST['edit_staff'])) {
        $staffId   = (int) ($_POST['staff_id'] ?? 0);
        $firstName = trim((string) ($_POST['firstname'] ?? ''));
        $lastName  = trim((string) ($_POST['lastname'] ?? ''));
        $staffName = trim($firstName . '_' . $lastName, '_');

        $before = StaffController::getStaffById($conn, $staffId);
        if (!$before) {
            jsonResponse([
                'success' => false,
                'error'   => 'Staff not found.'
            ], 404);
        }

        $photoPath = null;
        if (!empty($_FILES['photo']['name'])) {
            $photoPath = StaffController::handlePhotoUpload('photo', $staffName);
        }

        $conn->beginTransaction();

        StaffController::updateStaff($conn, $staffId, [
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'email'      => (string) ($_POST['email'] ?? ''),
            'username'   => (string) ($_POST['username'] ?? ''),
            'password'   => (string) ($_POST['password'] ?? ''),
            'role'       => (string) ($_POST['role'] ?? 'staff'),
            'photo'      => $photoPath,
        ]);

        $staff = StaffController::getStaffById($conn, $staffId);
        if (!$staff) {
            throw new RuntimeException('Failed to load the updated staff record.');
        }

        $staffView = StaffController::normalizeForView($staff);

        AuthController::logActivity(
            $conn,
            $logConfig,
            $sessionUserId,
            'staff_update',
            'Updated staff: ' . $staffView['full_name'],
            'user',
            $staffId
        );

        NotificationController::create(
            $conn,
            $sessionUserId,
            'admin',
            'staff',
            'Staff Updated',
            $staffView['full_name'] . ' was updated.',
            'bi-pencil-square',
            'text-warning',
            '/inventory_system/admin/manage_staff.php'
        );

        $conn->commit();

        jsonResponse([
            'success'  => true,
            'message'  => "Sweet update. {$staffView['full_name']}'s details are saved.",
            'staff'    => $staffView,
            'staff_id' => $staffId
        ]);
    }

    if (isset($_POST['toggle_id'])) {
        $staffId = (int) ($_POST['toggle_id'] ?? 0);

        $staff = StaffController::getStaffById($conn, $staffId);
        if (!$staff) {
            jsonResponse([
                'success' => false,
                'error'   => 'Staff not found.'
            ], 404);
        }

        $conn->beginTransaction();

        $newStatus = StaffController::toggleStatus($conn, $staffId, $sessionUserId);

        $updated = StaffController::getStaffById($conn, $staffId);
        if (!$updated) {
            throw new RuntimeException('Failed to reload staff after status change.');
        }

        $staffView = StaffController::normalizeForView($updated);
        $statusLabel = ucfirst($newStatus);

        AuthController::logActivity(
            $conn,
            $logConfig,
            $sessionUserId,
            'staff_status_update',
            $staffView['full_name'] . ' status changed to ' . $statusLabel,
            'user',
            $staffId
        );

        NotificationController::create(
            $conn,
            $sessionUserId,
            'admin',
            'staff_status',
            'Staff Status Updated',
            $staffView['full_name'] . ' is now ' . strtolower($statusLabel) . '.',
            'bi-arrow-repeat',
            'text-info',
            '/inventory_system/admin/manage_staff.php'
        );

        $conn->commit();

        jsonResponse([
            'success'    => true,
            'message'    => $staffView['full_name'] . ' is now ' . strtolower($statusLabel) . '.',
            'staff'      => $staffView,
            'staff_id'   => $staffId,
            'new_status' => $newStatus
        ]);
    }

    jsonResponse([
        'success' => false,
        'error'   => 'Invalid action.'
    ], 400);

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    if ((string) $e->getCode() === '23000') {
        jsonResponse([
            'success' => false,
            'error'   => 'Username or email already exists.'
        ], 409);
    }

    error_log('[staff_actions PDO] ' . $e->getMessage());

    jsonResponse([
        'success' => false,
        'error'   => 'Database error while saving staff.'
    ], 500);

} catch (InvalidArgumentException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    jsonResponse([
        'success' => false,
        'error'   => $e->getMessage()
    ], 422);

} catch (RuntimeException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    jsonResponse([
        'success' => false,
        'error'   => $e->getMessage()
    ], 400);

} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    error_log('[staff_actions] ' . $e->getMessage());

    jsonResponse([
        'success' => false,
        'error'   => 'Internal server error.'
    ], 500);
}