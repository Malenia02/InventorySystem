<?php
declare(strict_types=1);

require_once __DIR__ . '/ListQueryHelper.php';

final class CategoryController
{
    private const TABLE = 'categories';
    private const VALID_STATUSES = ['active', 'inactive'];
    private const MAX_NAME_LENGTH = 100;
    private const MAX_DESCRIPTION_LENGTH = 1000;
    private static bool $paginationIndexesChecked = false;

    public static function all(PDO $conn, ?string $status = null): array
    {
        $sql = "
            SELECT
                category_id,
                category_name,
                description,
                status,
                created_at
            FROM " . self::TABLE;

        $params = [];

        if ($status !== null) {
            $status = self::validateStatus($status);
            $sql .= " WHERE status = :status";
            $params[':status'] = $status;
        }

        $sql .= " ORDER BY category_name ASC, category_id DESC";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function paginate(PDO $conn, array $filters = []): array
    {
        self::ensurePaginationIndexes($conn);

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = (int) ($filters['per_page'] ?? 25);
        $allowedPerPage = [10, 25, 50, 100];
        $perPage = in_array($perPage, $allowedPerPage, true) ? $perPage : 25;
        $search = trim((string) ($filters['search'] ?? ''));
        $status = trim((string) ($filters['status'] ?? 'all'));

        $where = [];
        $params = [];

        if ($search !== '') {
            $where = array_merge($where, ListQueryHelper::buildTokenizedLikeFilters(
                ['category_name', "IFNULL(description, '')"],
                ListQueryHelper::extractSearchTerms($search),
                $params,
                'category_search'
            ));
        }

        if ($status !== '' && $status !== 'all') {
            $status = self::validateStatus($status);
            $where[] = 'status = :status';
            $params[':status'] = $status;
        } else {
            $status = 'all';
        }

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $conn->prepare('SELECT COUNT(*) FROM ' . self::TABLE . $whereSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $dataSql = "
            SELECT
                category_id,
                category_name,
                description,
                status,
                created_at
            FROM " . self::TABLE . "
            {$whereSql}
            ORDER BY category_name ASC, category_id DESC
            LIMIT :limit OFFSET :offset
        ";
        $dataStmt = $conn->prepare($dataSql);
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

    public static function getCategoryById(PDO $conn, int $id): ?array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid category ID.');
        }

        $stmt = $conn->prepare("
            SELECT
                category_id,
                category_name,
                description,
                status,
                created_at
            FROM " . self::TABLE . "
            WHERE category_id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);

        $category = $stmt->fetch(PDO::FETCH_ASSOC);

        return $category ?: null;
    }

    public static function addCategory(PDO $conn, string $name, ?string $description = null): int|string
    {
        $payload = self::validatePayload($name, $description);

        try {
            $stmt = $conn->prepare("
                INSERT INTO " . self::TABLE . " (
                    category_name,
                    description,
                    status
                ) VALUES (
                    :name,
                    :description,
                    'active'
                )
            ");

            $stmt->execute([
                ':name'        => $payload['name'],
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

    public static function updateCategory(PDO $conn, int $id, string $name, ?string $description = null): bool|string
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid category ID.');
        }

        self::assertCategoryExists($conn, $id);
        $payload = self::validatePayload($name, $description);

        try {
            $stmt = $conn->prepare("
                UPDATE " . self::TABLE . "
                SET
                    category_name = :name,
                    description   = :description
                WHERE category_id = :id
            ");

            $stmt->execute([
                ':name'        => $payload['name'],
                ':description' => $payload['description'],
                ':id'          => $id,
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
        $category = self::getCategoryById($conn, $id);

        if (!$category) {
            return false;
        }

        $currentStatus = (string) ($category['status'] ?? 'inactive');
        $newStatus = $currentStatus === 'active' ? 'inactive' : 'active';

        $stmt = $conn->prepare("
            UPDATE " . self::TABLE . "
            SET status = :status
            WHERE category_id = :id
        ");
        $stmt->execute([
            ':status' => $newStatus,
            ':id'     => $id,
        ]);

        return $newStatus;
    }

    public static function count(PDO $conn, ?string $status = null): int
    {
        $sql = "SELECT COUNT(*) FROM " . self::TABLE;
        $params = [];

        if ($status !== null) {
            $status = self::validateStatus($status);
            $sql .= " WHERE status = :status";
            $params[':status'] = $status;
        }

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    private static function validatePayload(string $name, ?string $description): array
    {
        $name = self::normalizeName($name);
        $description = self::nullableTrim($description);

        if ($name === '') {
            throw new InvalidArgumentException('Category name is required.');
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException('Category name must not exceed ' . self::MAX_NAME_LENGTH . ' characters.');
        }

        if ($description !== null && mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw new InvalidArgumentException('Category description must not exceed ' . self::MAX_DESCRIPTION_LENGTH . ' characters.');
        }

        return [
            'name'        => $name,
            'description' => $description,
        ];
    }

    private static function assertCategoryExists(PDO $conn, int $id): void
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM " . self::TABLE . "
            WHERE category_id = :id
        ");
        $stmt->execute([':id' => $id]);

        if ((int) $stmt->fetchColumn() === 0) {
            throw new RuntimeException('Category not found.');
        }
    }

    private static function validateStatus(string $status): string
    {
        $status = strtolower(trim($status));

        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid category status.');
        }

        return $status;
    }

    private static function normalizeName(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/', ' ', $value) ?? '';
        return $value;
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
            'idx_categories_status_name',
            'CREATE INDEX idx_categories_status_name ON categories (status, category_name, category_id)'
        );

        self::$paginationIndexesChecked = true;
    }
}
