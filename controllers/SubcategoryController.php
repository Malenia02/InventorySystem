<?php
declare(strict_types=1);

require_once __DIR__ . '/ListQueryHelper.php';

final class SubcategoryController
{
    private const TABLE = 'subcategories';
    private const VALID_STATUSES = ['active', 'inactive'];
    private static bool $schemaChecked = false;
    private static bool $paginationIndexesChecked = false;

    public static function ensureSchema(PDO $conn): void
    {
        if (self::$schemaChecked) {
            return;
        }

        $tableStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = :table_name
        ");
        $tableStmt->execute([':table_name' => self::TABLE]);

        if ((int) $tableStmt->fetchColumn() === 0) {
            throw new RuntimeException('Subcategory schema is not installed.');
        }

        self::ensureProductColumn($conn);
        self::$schemaChecked = true;
    }

    public static function all(PDO $conn, ?int $categoryId = null, ?string $status = null): array
    {
        self::ensureSchema($conn);

        $sql = "
            SELECT
                sc.subcategory_id,
                sc.category_id,
                sc.subcategory_name,
                sc.description,
                sc.status,
                sc.created_at,
                c.category_name
            FROM " . self::TABLE . " sc
            INNER JOIN categories c ON sc.category_id = c.category_id
        ";

        $where = [];
        $params = [];

        if ($categoryId !== null && $categoryId > 0) {
            $where[] = 'sc.category_id = :category_id';
            $params[':category_id'] = $categoryId;
        }

        if ($status !== null) {
            $status = self::validateStatus($status);
            $where[] = 'sc.status = :status';
            $params[':status'] = $status;
        }

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY c.category_name ASC, sc.subcategory_name ASC, sc.subcategory_id DESC';

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function paginate(PDO $conn, array $filters = []): array
    {
        self::ensureSchema($conn);
        self::ensurePaginationIndexes($conn);

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = (int) ($filters['per_page'] ?? 25);
        $allowedPerPage = [10, 25, 50, 100];
        $perPage = in_array($perPage, $allowedPerPage, true) ? $perPage : 25;
        $search = trim((string) ($filters['search'] ?? ''));
        $status = trim((string) ($filters['status'] ?? 'all'));
        $categoryId = (int) ($filters['category_id'] ?? 0);

        $where = [];
        $params = [];

        if ($search !== '') {
            $where = array_merge($where, ListQueryHelper::buildTokenizedLikeFilters(
                ['sc.subcategory_name', "IFNULL(sc.description, '')", 'c.category_name'],
                ListQueryHelper::extractSearchTerms($search),
                $params,
                'subcategory_search'
            ));
        }

        if ($status !== '' && $status !== 'all') {
            $status = self::validateStatus($status);
            $where[] = 'sc.status = :status';
            $params[':status'] = $status;
        } else {
            $status = 'all';
        }

        if ($categoryId > 0) {
            $where[] = 'sc.category_id = :category_id';
            $params[':category_id'] = $categoryId;
        } else {
            $categoryId = 0;
        }

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        $countSql = "
            SELECT COUNT(*)
            FROM " . self::TABLE . " sc
            INNER JOIN categories c ON sc.category_id = c.category_id
            {$whereSql}
        ";
        $countStmt = $conn->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $dataSql = "
            SELECT
                sc.subcategory_id,
                sc.category_id,
                sc.subcategory_name,
                sc.description,
                sc.status,
                sc.created_at,
                c.category_name
            FROM " . self::TABLE . " sc
            INNER JOIN categories c ON sc.category_id = c.category_id
            {$whereSql}
            ORDER BY c.category_name ASC, sc.subcategory_name ASC, sc.subcategory_id DESC
            LIMIT :limit OFFSET :offset
        ";
        $dataStmt = $conn->prepare($dataSql);
        foreach ($params as $key => $value) {
            $dataStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
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
            'category_id' => $categoryId,
        ];
    }

    public static function getById(PDO $conn, int $id): ?array
    {
        self::ensureSchema($conn);

        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid subcategory ID.');
        }

        $stmt = $conn->prepare("
            SELECT
                sc.subcategory_id,
                sc.category_id,
                sc.subcategory_name,
                sc.description,
                sc.status,
                sc.created_at,
                c.category_name
            FROM " . self::TABLE . " sc
            INNER JOIN categories c ON sc.category_id = c.category_id
            WHERE sc.subcategory_id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function add(PDO $conn, int $categoryId, string $name, ?string $description = null): int|string
    {
        self::ensureSchema($conn);
        self::assertCategoryExists($conn, $categoryId);

        $payload = self::validatePayload($categoryId, $name, $description);

        try {
            $stmt = $conn->prepare("
                INSERT INTO " . self::TABLE . " (
                    category_id,
                    subcategory_name,
                    description,
                    status
                ) VALUES (
                    :category_id,
                    :name,
                    :description,
                    'active'
                )
            ");
            $stmt->execute([
                ':category_id' => $payload['category_id'],
                ':name' => $payload['name'],
                ':description' => $payload['description'],
            ]);

            return (int) $conn->lastInsertId();
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return 'duplicate';
            }

            throw $e;
        }
    }

    public static function update(PDO $conn, int $id, int $categoryId, string $name, ?string $description = null): bool|string
    {
        self::ensureSchema($conn);

        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid subcategory ID.');
        }

        self::assertExists($conn, $id);
        self::assertCategoryExists($conn, $categoryId);

        $payload = self::validatePayload($categoryId, $name, $description);

        try {
            $stmt = $conn->prepare("
                UPDATE " . self::TABLE . "
                SET
                    category_id = :category_id,
                    subcategory_name = :name,
                    description = :description
                WHERE subcategory_id = :id
            ");
            $stmt->execute([
                ':category_id' => $payload['category_id'],
                ':name' => $payload['name'],
                ':description' => $payload['description'],
                ':id' => $id,
            ]);

            return true;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return 'duplicate';
            }

            throw $e;
        }
    }

    public static function toggleStatus(PDO $conn, int $id): string|false
    {
        self::ensureSchema($conn);

        $subcategory = self::getById($conn, $id);
        if (!$subcategory) {
            return false;
        }

        $newStatus = (($subcategory['status'] ?? 'inactive') === 'active') ? 'inactive' : 'active';
        $stmt = $conn->prepare("
            UPDATE " . self::TABLE . "
            SET status = :status
            WHERE subcategory_id = :id
        ");
        $stmt->execute([
            ':status' => $newStatus,
            ':id' => $id,
        ]);

        return $newStatus;
    }

    public static function assertExistsForCategory(PDO $conn, int $subcategoryId, int $categoryId): void
    {
        self::ensureSchema($conn);

        if ($subcategoryId <= 0) {
            throw new InvalidArgumentException('Invalid subcategory.');
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM " . self::TABLE . "
            WHERE subcategory_id = :id
              AND category_id = :category_id
        ");
        $stmt->execute([
            ':id' => $subcategoryId,
            ':category_id' => $categoryId,
        ]);

        if ((int) $stmt->fetchColumn() === 0) {
            throw new RuntimeException('Selected subcategory does not belong to the chosen category.');
        }
    }

    private static function ensureProductColumn(PDO $conn): void
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'products'
              AND column_name = 'subcategory_id'
        ");
        $stmt->execute();

        if ((int) $stmt->fetchColumn() === 0) {
            throw new RuntimeException('Product subcategory schema is not installed.');
        }
    }

    private static function validatePayload(int $categoryId, string $name, ?string $description): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        $description = trim((string) $description);
        $description = $description === '' ? null : $description;

        if ($categoryId <= 0) {
            throw new InvalidArgumentException('Category is required.');
        }

        if ($name === '') {
            throw new InvalidArgumentException('Subcategory name is required.');
        }

        if (mb_strlen($name) > 100) {
            throw new InvalidArgumentException('Subcategory name must not exceed 100 characters.');
        }

        return [
            'category_id' => $categoryId,
            'name' => $name,
            'description' => $description,
        ];
    }

    private static function assertExists(PDO $conn, int $id): void
    {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM " . self::TABLE . " WHERE subcategory_id = :id");
        $stmt->execute([':id' => $id]);

        if ((int) $stmt->fetchColumn() === 0) {
            throw new RuntimeException('Subcategory not found.');
        }
    }

    private static function assertCategoryExists(PDO $conn, int $categoryId): void
    {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM categories WHERE category_id = :id");
        $stmt->execute([':id' => $categoryId]);

        if ((int) $stmt->fetchColumn() === 0) {
            throw new RuntimeException('Category not found.');
        }
    }

    private static function validateStatus(string $status): string
    {
        $status = strtolower(trim($status));

        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid subcategory status.');
        }

        return $status;
    }

    private static function ensurePaginationIndexes(PDO $conn): void
    {
        if (self::$paginationIndexesChecked) {
            return;
        }

        ListQueryHelper::ensureIndex(
            $conn,
            self::TABLE,
            'idx_subcategories_status_category_name',
            'CREATE INDEX idx_subcategories_status_category_name ON subcategories (status, category_id, subcategory_name, subcategory_id)'
        );

        self::$paginationIndexesChecked = true;
    }
}
