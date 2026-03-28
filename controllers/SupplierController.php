<?php
// File: controllers/SupplierController.php
if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION['error_code'] = 403;
    $_SESSION['error_message'] = 'Direct access is not allowed.';

    header('Location: /inventory_system/error.php');
    exit;
}
class SupplierController {

    // Fetch all suppliers
    public static function all($pdo, $table) {
        $stmt = $pdo->query("SELECT * FROM `$table` ORDER BY supplier_name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Get single supplier by ID
    public static function getById($pdo, $table, $idField, $id) {
        $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE `$idField` = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // 🔹 Check if supplier already exists
    public static function existsByName($pdo, $table, $name) {
        $stmt = $pdo->prepare("SELECT supplier_id FROM `$table` WHERE supplier_name = :name LIMIT 1");
        $stmt->execute(['name' => $name]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // 🔹 Add new supplier with duplicate protection
    public static function addSupplier($pdo, $table, $name, $contact, $phone, $email, $address) {

        // Check duplicate
        if (self::existsByName($pdo, $table, $name)) {
            return [
                'success' => false,
                'message' => 'Supplier already exists.'
            ];
        }

        $stmt = $pdo->prepare("
            INSERT INTO `$table` (supplier_name, contact_person, phone, email, address) 
            VALUES (:name, :contact, :phone, :email, :address)
        ");

        $stmt->execute([
            'name' => $name,
            'contact' => $contact,
            'phone' => $phone,
            'email' => $email,
            'address' => $address
        ]);

        return [
            'success' => true,
            'supplier_id' => $pdo->lastInsertId(),
            'message' => 'Supplier created successfully.'
        ];
    }

    // Update supplier
    public static function updateSupplier($pdo, $table, $idField, $id, $name, $contact, $phone, $email, $address) {
        $stmt = $pdo->prepare("
            UPDATE `$table` 
            SET supplier_name = :name, contact_person = :contact, phone = :phone, email = :email, address = :address 
            WHERE `$idField` = :id
        ");

        return $stmt->execute([
            'name' => $name,
            'contact' => $contact,
            'phone' => $phone,
            'email' => $email,
            'address' => $address,
            'id' => $id
        ]);
    }

    // Delete supplier (optional)
    public static function deleteSupplier($pdo, $table, $idField, $id) {
        $stmt = $pdo->prepare("DELETE FROM `$table` WHERE `$idField` = :id");
        return $stmt->execute(['id' => $id]);
    }
}
