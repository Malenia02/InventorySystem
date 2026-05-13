<?php
declare(strict_types=1);

require_once __DIR__ . '/ListQueryHelper.php';

final class SupplierController
{
    private const TABLE = 'suppliers';
    private const ID_FIELD = 'supplier_id';
    private static bool $paginationIndexesChecked = false;

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

    public static function paginate(PDO $pdo, array $filters = []): array
    {
        self::ensurePaginationIndexes($pdo);

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = (int) ($filters['per_page'] ?? 25);
        $allowedPerPage = [10, 25, 50, 100];
        $perPage = in_array($perPage, $allowedPerPage, true) ? $perPage : 25;
        $search = trim((string) ($filters['search'] ?? ''));
        $status = strtolower(trim((string) ($filters['status'] ?? 'all')));

        $where = [];
        $params = [];

        if ($search !== '') {
            $where = array_merge($where, ListQueryHelper::buildTokenizedLikeFilters(
                [
                    'supplier_name',
                    "IFNULL(contact_person, '')",
                    "IFNULL(phone, '')",
                    "IFNULL(email, '')",
                    "IFNULL(address, '')",
                ],
                ListQueryHelper::extractSearchTerms($search),
                $params,
                'supplier_search'
            ));
        }

        if (in_array($status, ['active', 'inactive'], true)) {
            $where[] = 'status = :status';
            $params[':status'] = $status;
        } else {
            $status = 'all';
        }

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM ' . self::TABLE . $whereSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $dataStmt = $pdo->prepare("
            SELECT supplier_id, supplier_name, contact_person, phone, email, address, status
            FROM " . self::TABLE . "
            {$whereSql}
            ORDER BY supplier_name ASC, supplier_id DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $key => $value) {
            $dataStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $dataStmt->execute();

        return [
            'items' => $dataStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'search' => $search,
            'status' => $status,
        ];
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

    private static function ensurePaginationIndexes(PDO $conn): void
    {
        if (self::$paginationIndexesChecked) {
            return;
        }

        ListQueryHelper::ensureIndex(
            $conn,
            self::TABLE,
            'idx_suppliers_status_name',
            'CREATE INDEX idx_suppliers_status_name ON suppliers (status, supplier_name, supplier_id)'
        );
        ListQueryHelper::ensureIndex(
            $conn,
            self::TABLE,
            'idx_suppliers_contact_person',
            'CREATE INDEX idx_suppliers_contact_person ON suppliers (contact_person)'
        );

        self::$paginationIndexesChecked = true;
    }
}
