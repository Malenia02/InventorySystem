<?php
declare(strict_types=1);

final class SupplierController
{
    private const TABLE = 'suppliers';
    private const ID_FIELD = 'supplier_id';

    public static function all(PDO $pdo): array
    {
        $stmt = $pdo->prepare("
            SELECT supplier_id, supplier_name, contact_person, phone, email, address, status
            FROM " . self::TABLE . "
            ORDER BY supplier_name ASC
        ");
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function getById(PDO $pdo, int $id): ?array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid supplier ID.');
        }

        $stmt = $pdo->prepare("
            SELECT supplier_id, supplier_name, contact_person, phone, email, address, status
            FROM " . self::TABLE . "
            WHERE " . self::ID_FIELD . " = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function addSupplier(
        PDO $pdo,
        string $name,
        ?string $contact,
        ?string $phone,
        ?string $email,
        ?string $address
    ): array {
        $payload = self::validatePayload($name, $contact, $phone, $email, $address);

        if (self::existsByName($pdo, $payload['name'])) {
            return [
                'success' => false,
                'message' => 'Supplier already exists.'
            ];
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO " . self::TABLE . " (
                    supplier_name,
                    contact_person,
                    phone,
                    email,
                    address,
                    status
                ) VALUES (
                    :name,
                    :contact,
                    :phone,
                    :email,
                    :address,
                    'active'
                )
            ");

            $stmt->execute([
                ':name'    => $payload['name'],
                ':contact' => $payload['contact'],
                ':phone'   => $payload['phone'],
                ':email'   => $payload['email'],
                ':address' => $payload['address'],
            ]);

            return [
                'success'       => true,
                'supplier_id'   => (int) $pdo->lastInsertId(),
                'supplier_name' => $payload['name'],
                'message'       => 'Supplier created successfully.'
            ];
        } catch (PDOException $e) {
            error_log('[SupplierController::addSupplier] ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Failed to create supplier.'
            ];
        }
    }

    public static function updateSupplier(
        PDO $pdo,
        int $id,
        string $name,
        ?string $contact,
        ?string $phone,
        ?string $email,
        ?string $address
    ): array {
        if ($id <= 0) {
            return [
                'success' => false,
                'message' => 'Invalid supplier ID.'
            ];
        }

        $existing = self::getById($pdo, $id);
        if (!$existing) {
            return [
                'success' => false,
                'message' => 'Supplier not found.'
            ];
        }

        $payload = self::validatePayload($name, $contact, $phone, $email, $address);

        if (self::existsByName($pdo, $payload['name'], $id)) {
            return [
                'success' => false,
                'message' => 'Another supplier with this name already exists.'
            ];
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE " . self::TABLE . "
                SET supplier_name = :name,
                    contact_person = :contact,
                    phone = :phone,
                    email = :email,
                    address = :address
                WHERE " . self::ID_FIELD . " = :id
            ");

            $stmt->execute([
                ':name'    => $payload['name'],
                ':contact' => $payload['contact'],
                ':phone'   => $payload['phone'],
                ':email'   => $payload['email'],
                ':address' => $payload['address'],
                ':id'      => $id,
            ]);

            return [
                'success' => true,
                'message' => 'Supplier updated successfully.'
            ];
        } catch (PDOException $e) {
            error_log('[SupplierController::updateSupplier] ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Failed to update supplier.'
            ];
        }
    }

    public static function toggleStatus(PDO $pdo, int $id): array
    {
        if ($id <= 0) {
            return [
                'success' => false,
                'message' => 'Invalid supplier ID.'
            ];
        }

        $supplier = self::getById($pdo, $id);
        if (!$supplier) {
            return [
                'success' => false,
                'message' => 'Supplier not found.'
            ];
        }

        $currentStatus = (string) ($supplier['status'] ?? 'active');
        $newStatus = $currentStatus === 'active' ? 'inactive' : 'active';

        try {
            $stmt = $pdo->prepare("
                UPDATE " . self::TABLE . "
                SET status = :status
                WHERE " . self::ID_FIELD . " = :id
            ");

            $stmt->execute([
                ':status' => $newStatus,
                ':id'     => $id,
            ]);

            return [
                'success'    => true,
                'message'    => 'Supplier status updated successfully.',
                'new_status' => $newStatus,
            ];
        } catch (PDOException $e) {
            error_log('[SupplierController::toggleStatus] ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Failed to update supplier status.'
            ];
        }
    }

    public static function deleteSupplier(PDO $pdo, int $id): bool
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid supplier ID.');
        }

        $stmt = $pdo->prepare("
            DELETE FROM " . self::TABLE . "
            WHERE " . self::ID_FIELD . " = :id
        ");

        return $stmt->execute([':id' => $id]);
    }

    private static function existsByName(PDO $pdo, string $name, ?int $excludeId = null): bool
    {
        $sql = "
            SELECT " . self::ID_FIELD . "
            FROM " . self::TABLE . "
            WHERE LOWER(TRIM(supplier_name)) = LOWER(TRIM(:name))
        ";

        $params = [':name' => $name];

        if ($excludeId !== null) {
            $sql .= " AND " . self::ID_FIELD . " <> :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }

        $sql .= " LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private static function validatePayload(
        string $name,
        ?string $contact,
        ?string $phone,
        ?string $email,
        ?string $address
    ): array {
        $name    = trim($name);
        $contact = self::nullableTrim($contact);
        $phone   = self::nullableTrim($phone);
        $email   = self::nullableTrim($email);
        $address = self::nullableTrim($address);

        if ($name === '') {
            throw new InvalidArgumentException('Supplier name is required.');
        }

        if (mb_strlen($name) > 150) {
            throw new InvalidArgumentException('Supplier name is too long.');
        }

        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email address.');
        }

        return [
            'name'    => $name,
            'contact' => $contact,
            'phone'   => $phone,
            'email'   => $email,
            'address' => $address,
        ];
    }

    private static function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}