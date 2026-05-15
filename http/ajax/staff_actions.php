<?php
declare(strict_types=1);

/**
 * staff_actions.php  –  AJAX endpoint for staff CRUD
 *
 * Scalability / correctness improvements
 * ───────────────────────────────────────
 * 1.  addStaff() and updateStaff() now return the normalized view directly
 *     without a second SELECT after the write.
 *
 * 2.  Photo upload happens BEFORE the DB transaction so the file is on disk
 *     by the time we open a connection slot.  If the transaction rolls back,
 *     the uploaded file is deleted via StaffController::deleteUploadedPhoto().
 *
 * 3.  toggleStatus() also returns the view without a follow-up SELECT.
 *
 * 4.  A single jsonResponse() helper terminates the script cleanly.
 *
 * 5.  The throttle middleware already handles rate-limiting; no extra needed.
 */

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
    ->csrf()
    ->throttle('staff_actions', 25, 60, 'Too many staff changes – please wait a moment and try again.');

// ── Helpers ──────────────────────────────────────────────────────────────────

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function buildLogConfig(): array
{
    // These constants / globals are set by bootstrap/app.php
    return [
        'table'       => $GLOBALS['table_activity_logs']    ?? 'activity_logs',
        'col_user_id' => $GLOBALS['activity_log_user_id']   ?? 'user_id',
        'col_action'  => $GLOBALS['activity_log_action']    ?? 'action',
        'col_desc'    => $GLOBALS['activity_log_desc']      ?? 'description',
        'col_ip'      => $GLOBALS['activity_log_ip']        ?? 'ip_address',
        'col_created' => $GLOBALS['activity_log_created']   ?? 'created_at',
    ];
}

// ── Auth guard ────────────────────────────────────────────────────────────────

$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
if ($sessionUserId <= 0) {
    jsonResponse(['success' => false, 'error' => 'Unauthorized.'], 401);
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

try {

    // ════════════════════════════════════════════════════════════════════════
    // ADD STAFF
    // ════════════════════════════════════════════════════════════════════════
    if (isset($_POST['add_staff'])) {

        $firstName = trim((string) ($_POST['firstname'] ?? ''));
        $lastName  = trim((string) ($_POST['lastname']  ?? ''));
        $staffName = trim($firstName . '_' . $lastName, '_');

        // 1. Upload photo BEFORE the transaction (file I/O outside DB slot)
        $photoPath = null;
        if (!empty($_FILES['photo']['name'])) {
            $photoPath = StaffController::handlePhotoUpload('photo', $staffName);
        }

        $conn->beginTransaction();

        try {
            $result = StaffController::addStaff($conn, [
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'email'      => (string) ($_POST['email']    ?? ''),
                'username'   => (string) ($_POST['username'] ?? ''),
                'password'   => (string) ($_POST['password'] ?? ''),
                'role'       => (string) ($_POST['role']     ?? 'staff'),
                'photo'      => $photoPath,
            ]);

            $staffId   = $result['staff_id'];
            $staffView = $result['view'];

            AuthController::logActivity(
                $conn,
                buildLogConfig(),
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

        } catch (Throwable $e) {
            $conn->rollBack();
            // Delete orphaned photo if the DB write failed
            StaffController::deleteUploadedPhoto($photoPath);
            throw $e;
        }

        jsonResponse([
            'success'  => true,
            'message'  => "{$staffView['full_name']} is now part of the team.",
            'staff'    => $staffView,
            'staff_id' => $staffId,
        ], 201);
    }

    // ════════════════════════════════════════════════════════════════════════
    // EDIT STAFF
    // ════════════════════════════════════════════════════════════════════════
    if (isset($_POST['edit_staff'])) {

        $staffId   = (int) ($_POST['staff_id'] ?? 0);
        $firstName = trim((string) ($_POST['firstname'] ?? ''));
        $lastName  = trim((string) ($_POST['lastname']  ?? ''));
        $staffName = trim($firstName . '_' . $lastName, '_');

        if ($staffId <= 0) {
            jsonResponse(['success' => false, 'error' => 'Invalid staff ID.'], 422);
        }

        // Verify existence before touching the filesystem
        $before = StaffController::getStaffById($conn, $staffId);
        if (!$before) {
            jsonResponse(['success' => false, 'error' => 'Staff not found.'], 404);
        }

        // 1. Upload photo BEFORE the transaction
        $photoPath = null;
        if (!empty($_FILES['photo']['name'])) {
            $photoPath = StaffController::handlePhotoUpload('photo', $staffName);
        }

        $conn->beginTransaction();

        try {
            $staffView = StaffController::updateStaff($conn, $staffId, [
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'email'      => (string) ($_POST['email']    ?? ''),
                'username'   => (string) ($_POST['username'] ?? ''),
                'password'   => (string) ($_POST['password'] ?? ''),
                'role'       => (string) ($_POST['role']     ?? 'staff'),
                'photo'      => $photoPath,
            ]);

            AuthController::logActivity(
                $conn,
                buildLogConfig(),
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

        } catch (Throwable $e) {
            $conn->rollBack();
            // Delete the newly-uploaded photo; the old one is untouched
            StaffController::deleteUploadedPhoto($photoPath);
            throw $e;
        }

        jsonResponse([
            'success'  => true,
            'message'  => "{$staffView['full_name']}'s details have been saved.",
            'staff'    => $staffView,
            'staff_id' => $staffId,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // TOGGLE STATUS
    // ════════════════════════════════════════════════════════════════════════
    if (isset($_POST['toggle_id'])) {

        $staffId = (int) ($_POST['toggle_id'] ?? 0);
        if ($staffId <= 0) {
            jsonResponse(['success' => false, 'error' => 'Invalid staff ID.'], 422);
        }

        $conn->beginTransaction();

        try {
            $result      = StaffController::toggleStatus($conn, $staffId, $sessionUserId);
            $newStatus   = $result['new_status'];
            $staffView   = $result['view'];
            $statusLabel = ucfirst($newStatus);

            AuthController::logActivity(
                $conn,
                buildLogConfig(),
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

        } catch (Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        jsonResponse([
            'success'    => true,
            'message'    => "{$staffView['full_name']} is now {$statusLabel}.",
            'staff'      => $staffView,
            'staff_id'   => $staffId,
            'new_status' => $newStatus,
        ]);
    }

    jsonResponse(['success' => false, 'error' => 'Invalid action.'], 400);

// ── Error handlers ────────────────────────────────────────────────────────────

} catch (PDOException $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }

    // Duplicate key (username / email)
    if ((string) $e->getCode() === '23000') {
        jsonResponse(['success' => false, 'error' => 'Username or email already exists.'], 409);
    }

    error_log('[staff_actions PDO] ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'A database error occurred. Please try again.'], 500);

} catch (InvalidArgumentException $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 422);

} catch (RuntimeException $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);

} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('[staff_actions] ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'An unexpected error occurred.'], 500);
}