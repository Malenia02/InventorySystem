<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../bootstrap/app.php';
require_once __DIR__ . '/../../middleware/Middleware.php';
require_once __DIR__ . '/../../controllers/SupplierController.php';
require_once __DIR__ . '/../../controllers/AuthController.php';
require_once __DIR__ . '/../../controllers/NotificationController.php';

Middleware::auth()
    ->role(['admin'])
    ->ajax()
    ->methods(['POST'])
    ->csrf()
    ->throttle('supplier_actions', 30, 60, 'Too many supplier changes. Please wait a moment and try again.');

function jsonResponse(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function renderSupplierRow(array $supplier, int $rowNumber = 1): string
{
    ob_start();
    require __DIR__ . '/../../templates/supplier_row.php';
    return (string) ob_get_clean();
}

function renderSupplierTableBody(PDO $conn): string
{
    $suppliers = SupplierController::all($conn);

    ob_start();
    if ($suppliers !== []) {
        foreach ($suppliers as $index => $supplier) {
            $rowNumber = $index + 1;
            require __DIR__ . '/../../templates/supplier_row.php';
        }
    } else {
        ?>
        <tr>
            <td colspan="8" class="text-center text-muted py-4">No suppliers found.</td>
        </tr>
        <?php
    }

    return (string) ob_get_clean();
}

function renderSupplierTable(PDO $conn): string
{
    ob_start();
    ?>
    <table class="table table-striped table-bordered" id="suppliersTable">
        <thead>
            <tr>
                <th>#</th>
                <th>Supplier Name</th>
                <th>Contact Person</th>
                <th>Phone</th>
                <th>Email</th>
                <th>Address</th>
                <th>Status</th>
                <th style="min-width: 140px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?= renderSupplierTableBody($conn) ?>
        </tbody>
    </table>
    <?php

    return (string) ob_get_clean();
}

function safeCreateNotification(
    PDO $conn,
    ?int $userId,
    string $roleTarget,
    string $type,
    string $title,
    string $message,
    string $icon = 'bi-bell',
    string $color = 'text-primary',
    ?string $link = null
): void {
    try {
        NotificationController::create(
            $conn,
            $userId,
            $roleTarget,
            $type,
            $title,
            $message,
            $icon,
            $color,
            $link
        );
    } catch (Throwable $e) {
        error_log('[supplier_actions notification] ' . $e->getMessage());
    }
}

function normalizeInput(?string $value, int $maxLength = 255): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    if (mb_strlen($value) > $maxLength) {
        $value = mb_substr($value, 0, $maxLength);
    }

    return $value;
}

try {
    $sessionUserId = (int)($_SESSION['user_id'] ?? 0);

    if ($sessionUserId <= 0) {
        jsonResponse([
            'success' => false,
            'error'   => 'Unauthorized.'
        ], 401);
    }

    $action = trim((string)($_POST['action'] ?? ''));
    $supplierId = (int)($_POST['supplier_id'] ?? 0);

    $logConfig = [
        'table'       => $table_activity_logs,
        'col_user_id' => $activity_log_user_id,
        'col_action'  => $activity_log_action,
        'col_desc'    => $activity_log_desc,
        'col_ip'      => $activity_log_ip,
        'col_created' => $activity_log_created,
    ];

    if ($action === 'toggle_status') {
        $supplier = SupplierController::getById($conn, $supplierId);
        if (!$supplier) {
            jsonResponse([
                'success' => false,
                'error'   => 'Supplier not found.'
            ], 404);
        }

        $conn->beginTransaction();

        $result = SupplierController::toggleStatus($conn, $supplierId);
        if (empty($result['success'])) {
            $conn->rollBack();
            jsonResponse([
                'success' => false,
                'error'   => $result['message'] ?? 'Failed to update supplier status.'
            ], 422);
        }

        $updatedSupplier = SupplierController::getById($conn, $supplierId);
        $newStatus = (string)($result['new_status'] ?? ($updatedSupplier['status'] ?? 'inactive'));
        $supplierName = (string)($updatedSupplier['supplier_name'] ?? $supplier['supplier_name'] ?? 'Supplier');
        $statusLabel = ucfirst($newStatus);
        $newRowHtml = $updatedSupplier ? renderSupplierRow($updatedSupplier) : null;

        $conn->commit();

        AuthController::logActivity(
            $conn,
            $logConfig,
            $sessionUserId,
            'supplier_status_update',
            $supplierName . ' status changed to ' . $statusLabel,
            'supplier',
            $supplierId
        );

        safeCreateNotification(
            $conn,
            $sessionUserId,
            'admin',
            'supplier_status',
            'Supplier Status Updated',
            $supplierName . ' is now ' . strtolower($statusLabel) . '.',
            'bi-arrow-repeat',
            'text-info',
            '/inventory_system/supplier_management/manage_supplier.php'
        );

        jsonResponse([
            'success'       => true,
            'message'       => $supplierName . ' is now ' . strtolower($statusLabel) . '.',
            'new_status'    => $newStatus,
            'supplier_id'   => $supplierId,
            'supplier_name' => $supplierName,
            'newRowHtml'    => $newRowHtml,
            'tableBodyHtml' => renderSupplierTableBody($conn),
            'tableHtml'     => renderSupplierTable($conn)
        ]);
    }

    $supplierName  = normalizeInput($_POST['supplier_name'] ?? null, 150);
    $contactPerson = normalizeInput($_POST['contact_person'] ?? null, 150);
    $phone         = normalizeInput($_POST['phone'] ?? null, 50);
    $email         = normalizeInput($_POST['email'] ?? null, 150);
    $address       = normalizeInput($_POST['address'] ?? null, 500);

    if ($action === '') {
        if ($supplierId > 0 && $supplierName !== null) {
            $action = 'edit_supplier';
        } elseif ($supplierName !== null) {
            $action = 'add_supplier';
        }
    }

    if ($supplierName === null) {
        jsonResponse([
            'success' => false,
            'error'   => 'Supplier name is required.'
        ], 422);
    }

    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse([
            'success' => false,
            'error'   => 'Invalid email address.'
        ], 422);
    }

    if ($phone !== null && !preg_match('/^[0-9+\-\s()]{7,50}$/', $phone)) {
        jsonResponse([
            'success' => false,
            'error'   => 'Invalid phone number format.'
        ], 422);
    }

    if ($action === 'add_supplier') {
        $conn->beginTransaction();

        $result = SupplierController::addSupplier(
            $conn,
            $supplierName,
            $contactPerson,
            $phone,
            $email,
            $address
        );

        if (empty($result['success'])) {
            $conn->rollBack();
            jsonResponse([
                'success' => false,
                'error'   => $result['message'] ?? 'Failed to create supplier.'
            ], 409);
        }

        $newSupplierId   = (int)($result['supplier_id'] ?? 0);
        $newSupplierName = (string)($result['supplier_name'] ?? $supplierName);
        $newSupplier = SupplierController::getById($conn, $newSupplierId);
        $newRowHtml = $newSupplier ? renderSupplierRow($newSupplier) : null;

        $conn->commit();

        AuthController::logActivity(
            $conn,
            $logConfig,
            $sessionUserId,
            'supplier_add',
            'Added supplier: ' . $newSupplierName,
            'supplier',
            $newSupplierId
        );

        safeCreateNotification(
            $conn,
            $sessionUserId,
            'admin',
            'supplier',
            'Supplier Added',
            $newSupplierName . ' was added successfully.',
            'bi-truck',
            'text-info',
            '/inventory_system/supplier_management/manage_supplier.php'
        );

        jsonResponse([
            'success'       => true,
            'message'       => $newSupplierName . ' was added successfully.',
            'supplier_id'   => $newSupplierId,
            'supplier_name' => $newSupplierName,
            'newRowHtml'    => $newRowHtml,
            'tableBodyHtml' => renderSupplierTableBody($conn),
            'tableHtml'     => renderSupplierTable($conn),
            'event'         => 'notification_update',
            'type'          => 'supplier'
        ], 201);
    }

    if ($action === 'edit_supplier') {
        $existingSupplier = SupplierController::getById($conn, $supplierId);
        if (!$existingSupplier) {
            jsonResponse([
                'success' => false,
                'error'   => 'Supplier not found.'
            ], 404);
        }

        $conn->beginTransaction();

        $result = SupplierController::updateSupplier(
            $conn,
            $supplierId,
            $supplierName,
            $contactPerson,
            $phone,
            $email,
            $address
        );

        if (empty($result['success'])) {
            $conn->rollBack();
            jsonResponse([
                'success' => false,
                'error'   => $result['message'] ?? 'Failed to update supplier.'
            ], 422);
        }

        $conn->commit();
        $updatedSupplier = SupplierController::getById($conn, $supplierId);
        $newRowHtml = $updatedSupplier ? renderSupplierRow($updatedSupplier) : null;

        AuthController::logActivity(
            $conn,
            $logConfig,
            $sessionUserId,
            'supplier_update',
            'Updated supplier: ' . $supplierName,
            'supplier',
            $supplierId
        );

        safeCreateNotification(
            $conn,
            $sessionUserId,
            'admin',
            'supplier',
            'Supplier Updated',
            $supplierName . ' details were updated.',
            'bi-pencil-square',
            'text-warning',
            '/inventory_system/supplier_management/manage_supplier.php'
        );

        jsonResponse([
            'success'       => true,
            'message'       => $supplierName . ' was updated successfully.',
            'supplier_id'   => $supplierId,
            'supplier_name' => $supplierName,
            'newRowHtml'    => $newRowHtml,
            'tableBodyHtml' => renderSupplierTableBody($conn),
            'tableHtml'     => renderSupplierTable($conn)
        ]);
    }

    jsonResponse([
        'success' => false,
        'error'   => 'Invalid supplier action.'
    ], 400);

} catch (PDOException $e) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }

    if ((string)$e->getCode() === '23000') {
        jsonResponse([
            'success' => false,
            'error'   => 'Supplier already exists.'
        ], 409);
    }

    error_log('[supplier_actions PDO ERROR] ' . $e->getMessage());

    jsonResponse([
        'success' => false,
        'error'   => 'Database error while saving supplier.'
    ], 500);

} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }

    error_log('[supplier_actions ERROR] ' . $e->getMessage());

    jsonResponse([
        'success' => false,
        'error'   => 'Internal server error.'
    ], 500);
}
