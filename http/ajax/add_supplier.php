<?php
require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/config/config.php';
require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/controllers/SupplierController.php';

header('Content-Type: application/json');

$name    = trim($_POST['supplier_name'] ?? '');
$contact = trim($_POST['contact_person'] ?? '');
$phone   = trim($_POST['phone'] ?? '');
$email   = trim($_POST['email'] ?? '');
$address = trim($_POST['address'] ?? '');

if ($name === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Supplier name is required.'
    ]);
    exit;
}

$result = SupplierController::addSupplier(
    $conn,
    $table_suppliers,
    $name,
    $contact,
    $phone,
    $email,
    $address
);

echo json_encode($result);
