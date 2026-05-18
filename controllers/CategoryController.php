<?php
declare(strict_types=1);

require_once __DIR__ . '/ListQueryHelper.php';

/**
 * CategoryController
 *
 * Scalability improvements over the previous version
 * ──────────────────────────────────────────────────
 *  1.  addCategory() throws RuntimeException on duplicate instead of
 *      returning the string 'duplicate'. Consistent with StaffController
 *      and ProductController.
 *
 *  2.  updateCategory() same — throws instead of returning 'duplicate'.
 *
 *  3.  addCategory() returns ['category_id', 'view'] — no follow-up SELECT.
 *
 *  4.  updateCategory() returns the merged view — no follow-up SELECT.
 *
 *  5.  toggleStatus() returns ['new_status', 'view'] — one SELECT +
 *      one UPDATE, no second SELECT after the write.
 *
 *  6.  getSummary() added with APCu cache (60 s TTL).
 *      Returns total, active, inactive, with_products.
 *
 *  7.  paginate() uses ListQueryHelper::bindAll() and ::offsetPagination().
 *
 *  8.  all() accepts an optional $limit so dropdown usage is bounded.
 *
 *  9.  count() removed — merged into getSummary().
 *
 * 10.  assertCategoryExists() removed — getCategoryById() used directly.
 */
final class CategoryController
{
    private const TABLE            = 'categories';
    private const VALID_STATUSES   = ['active', 'inactive'];
    private const MAX_NAME_LEN     = 100;
    private const MAX_DESC_LEN     = 1_000;
    private const ALLOWED_PER_PAGE = [10, 25, 50, 100];
    private const SUMMARY_TTL      = 60;
    private const CACHE_PREFIX     = 'category_summary_';

    private static bool $indexesChecked = false;

    // =========================================================================
    // READ — all (for dropdowns)
    // =========================================================================

    /** @return array<int, array<string,mixed>> */
    public static function all(PDO $conn, ?string $status = null, int $limit = 500): array
    {
        $where  = [];
        $params = [];

        if ($status !== null) {
            $where[]          = 'status = :status';
            $params[':status'] = self::validStatus($status);
        }

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $limitSql = $limit > 0 ? ' LIMIT ' . $limit : '';

        $stmt = $conn->prepare(
            'SELECT category_id, category_name, description, status, created_at
             FROM ' . self::TABLE . $whereSql .
            ' ORDER BY category_name ASC, category_id DESC' . $limitSql
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // =========================================================================
    // READ — paginate
    // =========================================================================

    public static function paginate(PDO $conn, array $filters = []): array
    {
        self::ensureIndexes($conn);

        $search  = trim((string) ($filters['search']   ?? ''));
        $status  = trim((string) ($filters['status']   ?? 'all'));
        $reqPage = max(1, (int) ($filters['page']      ?? 1));
        $reqPer  = (int) ($filters['per_page'] ?? 25);

        $status  = ($status !== '' && $status !== 'all' && in_array($status, self::VALID_STATUSES, true))
            ? $status : 'all';
        $perPage = in_array($reqPer, self::ALLOWED_PER_PAGE, true) ? $reqPer : 25;

        $where  = [];
        $params = [];

        if ($search !== '') {
            $terms = ListQueryHelper::extractSearchTerms($search);
            $where = array_merge($where, ListQueryHelper::buildTokenizedLikeFilters(
                ['category_name', "IFNULL(description,'')"],
                $terms, $params, 'cs'
            ));
        }

        if ($status !== 'all') {
            $where[]          = 'status = :status';
            $params[':status'] = $status;
        }

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $conn->prepare('SELECT COUNT(*) FROM ' . self::TABLE . $whereSql);
        ListQueryHelper::bindAll($countStmt, $params);
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $pag     = ListQueryHelper::offsetPagination($reqPage, $perPage, $total, self::ALLOWED_PER_PAGE);
        $page    = $pag['page'];
        $perPage = $pag['per_page'];

        $dataStmt = $conn->prepare(
            'SELECT category_id, category_name, description, status, created_at
             FROM ' . self::TABLE . $whereSql .
            ' ORDER BY category_name ASC, category_id DESC
              LIMIT :limit OFFSET :offset'
        );
        ListQueryHelper::bindAll($dataStmt, $params);
        $dataStmt->bindValue(':limit',  $perPage,       PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $pag['offset'], PDO::PARAM_INT);
        $dataStmt->execute();

        return [
            'items'       => $dataStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $pag['total_pages'],
            'search'      => $search,
            'status'      => $status,
        ];
    }

    // =========================================================================
    // READ — single record
    // =========================================================================

    /** @return array<string,mixed>|null */
    public static function getCategoryById(PDO $conn, int $id): ?array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid category ID.');
        }

        $stmt = $conn->prepare(
            'SELECT category_id, category_name, description, status, created_at
             FROM ' . self::TABLE . ' WHERE category_id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    // =========================================================================
    // READ — summary (APCu-cached)
    // =========================================================================

    /**
     * Aggregate counts for the stat cards.
     * Cached in APCu for SUMMARY_TTL seconds; busted after every write.
     *
     * @return array{total:int, active:int, inactive:int, with_products:int}
     */
    public static function getSummary(PDO $conn): array
    {
        $cacheKey = self::CACHE_PREFIX . 'counts';

        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($cacheKey, $hit);
            if ($hit && is_array($cached)) {
                return $cached;
            }
        }

        $stmt = $conn->query(
            "SELECT
                COUNT(*)                                                  AS total,
                SUM(CASE WHEN c.status = 'active'   THEN 1 ELSE 0 END)   AS active_count,
                SUM(CASE WHEN c.status = 'inactive' THEN 1 ELSE 0 END)   AS inactive_count,
                SUM(CASE WHEN p.category_id IS NOT NULL THEN 1 ELSE 0 END) AS with_products
             FROM categories c
             LEFT JOIN (
                 SELECT DISTINCT category_id
                 FROM products
                 WHERE status = 'active'
             ) p ON p.category_id = c.category_id"
        );

        $row     = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        $summary = [
            'total'         => (int) ($row['total']          ?? 0),
            'active'        => (int) ($row['active_count']   ?? 0),
            'inactive'      => (int) ($row['inactive_count'] ?? 0),
            'with_products' => (int) ($row['with_products']  ?? 0),
        ];

        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $summary, self::SUMMARY_TTL);
        }

        return $summary;
    }

    public static function bustSummaryCache(): void
    {
        if (function_exists('apcu_delete')) {
            apcu_delete(self::CACHE_PREFIX . 'counts');
        }
    }

    // =========================================================================
    // WRITE — add
    // =========================================================================

    /**
     * Insert a new category and return ['category_id', 'view'].
     * No follow-up SELECT needed by the caller.
     *
     * @throws RuntimeException        On duplicate name.
     * @throws InvalidArgumentException On validation failure.
     * @return array{category_id:int, view:array<string,mixed>}
     */
    public static function addCategory(PDO $conn, string $name, ?string $description = null): array
    {
        $payload = self::validatePayload($name, $description);

        try {
            $conn->prepare(
                "INSERT INTO " . self::TABLE . " (category_name, description, status)
                 VALUES (:name, :desc, 'active')"
            )->execute([':name' => $payload['name'], ':desc' => $payload['description']]);

            $id = (int) $conn->lastInsertId();
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                throw new RuntimeException("Category '{$payload['name']}' already exists.");
            }
            throw $e;
        }

        self::bustSummaryCache();

        $view = [
            'category_id'   => $id,
            'category_name' => $payload['name'],
            'description'   => $payload['description'],
            'status'        => 'active',
            'created_at'    => date('Y-m-d H:i:s'),
        ];

        return ['category_id' => $id, 'view' => $view];
    }

    // =========================================================================
    // WRITE — update
    // =========================================================================

    /**
     * Update a category and return the normalized view. No follow-up SELECT.
     *
     * @throws RuntimeException        On duplicate name or not found.
     * @throws InvalidArgumentException On validation failure.
     * @return array<string,mixed>
     */
    public static function updateCategory(PDO $conn, int $id, string $name, ?string $description = null): array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid category ID.');
        }

        $existing = self::getCategoryById($conn, $id);
        if (!$existing) {
            throw new RuntimeException('Category not found.');
        }

        $payload = self::validatePayload($name, $description);

        try {
            $conn->prepare(
                'UPDATE ' . self::TABLE .
                ' SET category_name = :name, description = :desc
                  WHERE category_id = :id'
            )->execute([':name' => $payload['name'], ':desc' => $payload['description'], ':id' => $id]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                throw new RuntimeException("Category '{$payload['name']}' already exists.");
            }
            throw $e;
        }

        self::bustSummaryCache();

        return array_merge($existing, [
            'category_name' => $payload['name'],
            'description'   => $payload['description'],
        ]);
    }

    // =========================================================================
    // WRITE — toggle status
    // =========================================================================

    /**
     * Toggle status and return ['new_status', 'view'].
     * One SELECT + one UPDATE total.
     *
     * @throws RuntimeException        If category not found.
     * @throws InvalidArgumentException If ID invalid.
     * @return array{new_status:string, view:array<string,mixed>}
     */
    public static function toggleStatus(PDO $conn, int $id): array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid category ID.');
        }

        $category = self::getCategoryById($conn, $id);
        if (!$category) {
            throw new RuntimeException('Category not found.');
        }

        $newStatus = ($category['status'] ?? 'inactive') === 'active' ? 'inactive' : 'active';

        $conn->prepare(
            'UPDATE ' . self::TABLE . ' SET status = :status WHERE category_id = :id'
        )->execute([':status' => $newStatus, ':id' => $id]);

        self::bustSummaryCache();

        return [
            'new_status' => $newStatus,
            'view'       => array_merge($category, ['status' => $newStatus]),
        ];
    }

    // =========================================================================
    // PRIVATE — validation & helpers
    // =========================================================================

    /** @return array{name:string, description:string|null} */
    private static function validatePayload(string $name, ?string $description): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        $desc = trim((string) $description);
        $desc = $desc !== '' ? $desc : null;

        if ($name === '') {
            throw new InvalidArgumentException('Category name is required.');
        }
        if (mb_strlen($name) > self::MAX_NAME_LEN) {
            throw new InvalidArgumentException('Category name must not exceed ' . self::MAX_NAME_LEN . ' characters.');
        }
        if ($desc !== null && mb_strlen($desc) > self::MAX_DESC_LEN) {
            throw new InvalidArgumentException('Description must not exceed ' . self::MAX_DESC_LEN . ' characters.');
        }

        return ['name' => $name, 'description' => $desc];
    }

    private static function validStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid category status.');
        }
        return $status;
    }

    private static function ensureIndexes(PDO $conn): void
    {
        if (self::$indexesChecked) {
            return;
        }

        ListQueryHelper::ensureIndexes($conn, self::TABLE, [
            'idx_categories_status_name' =>
                'CREATE INDEX idx_categories_status_name ON categories (status, category_name, category_id)',
            'idx_categories_name' =>
                'CREATE INDEX idx_categories_name ON categories (category_name)',
        ]);

        self::$indexesChecked = true;
    }
}