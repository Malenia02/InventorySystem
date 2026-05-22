<?php
declare(strict_types=1);

require_once __DIR__ . '/ListQueryHelper.php';

/**
 * SubcategoryController
 *
 * Scalability / correctness improvements over the previous version
 * ────────────────────────────────────────────────────────────────
 *  1.  add() throws RuntimeException on duplicate instead of returning
 *      the string 'duplicate'. Consistent with CategoryController and
 *      StaffController. The unsafe (int) $id cast on 'duplicate' is gone.
 *
 *  2.  update() same — throws instead of returning 'duplicate'.
 *
 *  3.  add() returns ['subcategory_id', 'view'] — no follow-up SELECT.
 *
 *  4.  update() returns the merged view — no follow-up SELECT.
 *
 *  5.  toggleStatus() returns ['new_status', 'view'] — one SELECT +
 *      one UPDATE total; no second SELECT after the write.
 *
 *  6.  assertExists() removed — getById() is used directly so we only
 *      pay for one SELECT per write path.
 *
 *  7.  assertCategoryExists() replaced with a lightweight name-fetch
 *      that doubles as the category_name we need for the view, saving
 *      a separate JOIN query.
 *
 *  8.  getSummary() added with APCu cache (60 s TTL).
 *      Returns total, active, inactive, categories_used.
 *
 *  9.  paginate() uses ListQueryHelper::bindAll() and ::offsetPagination().
 *
 * 10.  all() accepts an optional $limit so dropdown usage is bounded.
 */
final class SubcategoryController
{
    private const TABLE            = 'subcategories';
    private const VALID_STATUSES   = ['active', 'inactive'];
    private const MAX_NAME_LEN     = 100;
    private const MAX_DESC_LEN     = 1_000;
    private const ALLOWED_PER_PAGE = [10, 25, 50, 100];
    private const SUMMARY_TTL      = 60;
    private const CACHE_PREFIX     = 'subcategory_summary_';

    private static bool $schemaChecked   = false;
    private static bool $indexesChecked  = false;

    // ── Columns projected in every SELECT ────────────────────────────────────
    private const COLUMNS = '
        sc.subcategory_id,
        sc.category_id,
        sc.subcategory_name,
        sc.description,
        sc.status,
        sc.created_at,
        c.category_name
    ';
    private const FROM_JOIN = "
        FROM subcategories sc
        INNER JOIN categories c ON sc.category_id = c.category_id
    ";

    // =========================================================================
    // SCHEMA GUARD
    // =========================================================================

    public static function ensureSchema(PDO $conn): void
    {
        if (self::$schemaChecked) {
            return;
        }

        // One combined query instead of two separate information_schema hits
        $stmt = $conn->prepare("
            SELECT
                SUM(CASE WHEN TABLE_NAME   = 'subcategories'  THEN 1 ELSE 0 END) AS has_table,
                SUM(CASE WHEN TABLE_NAME   = 'products'
                          AND COLUMN_NAME  = 'subcategory_id' THEN 1 ELSE 0 END) AS has_column
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND ((TABLE_NAME = 'subcategories')
                OR (TABLE_NAME = 'products' AND COLUMN_NAME = 'subcategory_id'))
        ");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        if ((int) ($row['has_table'] ?? 0) === 0) {
            throw new RuntimeException('Subcategory schema is not installed.');
        }
        if ((int) ($row['has_column'] ?? 0) === 0) {
            throw new RuntimeException('Product subcategory schema is not installed.');
        }

        self::$schemaChecked = true;
    }

    // =========================================================================
    // READ — all (for dropdowns)
    // =========================================================================

    /** @return array<int, array<string,mixed>> */
    public static function all(PDO $conn, ?int $categoryId = null, ?string $status = null, int $limit = 1000): array
    {
        self::ensureSchema($conn);

        $where  = [];
        $params = [];

        if ($categoryId !== null && $categoryId > 0) {
            $where[]              = 'sc.category_id = :cat';
            $params[':cat']        = $categoryId;
        }
        if ($status !== null) {
            $where[]              = 'sc.status = :status';
            $params[':status']     = self::validStatus($status);
        }

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $limitSql = $limit > 0 ? ' LIMIT ' . $limit : '';

        $stmt = $conn->prepare(
            'SELECT ' . self::COLUMNS . self::FROM_JOIN . $whereSql .
            ' ORDER BY c.category_name ASC, sc.subcategory_name ASC, sc.subcategory_id DESC' .
            $limitSql
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // =========================================================================
    // READ — paginate
    // =========================================================================

    public static function paginate(PDO $conn, array $filters = []): array
    {
        self::ensureSchema($conn);
        self::ensureIndexes($conn);

        $search     = trim((string) ($filters['search']      ?? ''));
        $status     = trim((string) ($filters['status']      ?? 'all'));
        $categoryId = (int) ($filters['category_id'] ?? 0);
        $reqPage    = max(1, (int) ($filters['page']     ?? 1));
        $reqPer     = (int) ($filters['per_page'] ?? 25);

        $status  = ($status !== '' && $status !== 'all' && in_array($status, self::VALID_STATUSES, true))
            ? $status : 'all';
        $perPage = in_array($reqPer, self::ALLOWED_PER_PAGE, true) ? $reqPer : 25;

        $where  = [];
        $params = [];

        if ($search !== '') {
            $terms = ListQueryHelper::extractSearchTerms($search);
            $where = array_merge($where, ListQueryHelper::buildTokenizedLikeFilters(
                ['sc.subcategory_name', "IFNULL(sc.description,'')", 'c.category_name'],
                $terms, $params, 'ss'
            ));
        }
        if ($status !== 'all') {
            $where[]          = 'sc.status = :status';
            $params[':status'] = $status;
        }
        if ($categoryId > 0) {
            $where[]          = 'sc.category_id = :cat';
            $params[':cat']    = $categoryId;
        } else {
            $categoryId = 0;
        }

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $conn->prepare('SELECT COUNT(*) ' . self::FROM_JOIN . $whereSql);
        ListQueryHelper::bindAll($countStmt, $params);
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $pag     = ListQueryHelper::offsetPagination($reqPage, $perPage, $total, self::ALLOWED_PER_PAGE);
        $page    = $pag['page'];
        $perPage = $pag['per_page'];

        $dataStmt = $conn->prepare(
            'SELECT ' . self::COLUMNS . self::FROM_JOIN . $whereSql .
            ' ORDER BY c.category_name ASC, sc.subcategory_name ASC, sc.subcategory_id DESC' .
            ' LIMIT :limit OFFSET :offset'
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
            'category_id' => $categoryId,
        ];
    }

    // =========================================================================
    // READ — single record
    // =========================================================================

    /** @return array<string,mixed>|null */
    public static function getById(PDO $conn, int $id): ?array
    {
        self::ensureSchema($conn);

        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid subcategory ID.');
        }

        $stmt = $conn->prepare(
            'SELECT ' . self::COLUMNS . self::FROM_JOIN .
            ' WHERE sc.subcategory_id = :id LIMIT 1'
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
     * @return array{total:int, active:int, inactive:int, categories_used:int}
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
                COUNT(*)                                                    AS total,
                SUM(CASE WHEN sc.status = 'active'   THEN 1 ELSE 0 END)    AS active_count,
                SUM(CASE WHEN sc.status = 'inactive' THEN 1 ELSE 0 END)    AS inactive_count,
                COUNT(DISTINCT sc.category_id)                              AS categories_used
             FROM subcategories sc"
        );

        $row     = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        $summary = [
            'total'           => (int) ($row['total']           ?? 0),
            'active'          => (int) ($row['active_count']    ?? 0),
            'inactive'        => (int) ($row['inactive_count']  ?? 0),
            'categories_used' => (int) ($row['categories_used'] ?? 0),
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
     * Insert a subcategory and return ['subcategory_id', 'view'].
     * No follow-up SELECT needed.
     *
     * @throws RuntimeException        On duplicate name within the same category.
     * @throws InvalidArgumentException On validation failure.
     * @return array{subcategory_id:int, view:array<string,mixed>}
     */
    public static function add(PDO $conn, int $categoryId, string $name, ?string $description = null): array
    {
        self::ensureSchema($conn);

        $payload      = self::validatePayload($categoryId, $name, $description);
        $categoryName = self::fetchCategoryName($conn, $categoryId); // also asserts existence

        try {
            $conn->prepare(
                "INSERT INTO " . self::TABLE . "
                    (category_id, subcategory_name, description, status)
                 VALUES (:cat, :name, :desc, 'active')"
            )->execute([
                ':cat'  => $payload['category_id'],
                ':name' => $payload['name'],
                ':desc' => $payload['description'],
            ]);

            $id = (int) $conn->lastInsertId();
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                throw new RuntimeException("Subcategory '{$payload['name']}' already exists in that category.");
            }
            throw $e;
        }

        self::bustSummaryCache();

        $view = [
            'subcategory_id'   => $id,
            'category_id'      => $payload['category_id'],
            'subcategory_name' => $payload['name'],
            'description'      => $payload['description'],
            'status'           => 'active',
            'created_at'       => date('Y-m-d H:i:s'),
            'category_name'    => $categoryName,
        ];

        return ['subcategory_id' => $id, 'view' => $view];
    }

    // =========================================================================
    // WRITE — update
    // =========================================================================

    /**
     * Update a subcategory and return the merged view. No follow-up SELECT.
     *
     * @throws RuntimeException        On duplicate name or not found.
     * @throws InvalidArgumentException On validation failure.
     * @return array<string,mixed>
     */
    public static function update(PDO $conn, int $id, int $categoryId, string $name, ?string $description = null): array
    {
        self::ensureSchema($conn);

        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid subcategory ID.');
        }

        $existing     = self::getById($conn, $id);
        if (!$existing) {
            throw new RuntimeException('Subcategory not found.');
        }

        $payload      = self::validatePayload($categoryId, $name, $description);
        $categoryName = self::fetchCategoryName($conn, $categoryId); // also asserts existence

        try {
            $conn->prepare(
                'UPDATE ' . self::TABLE .
                ' SET category_id = :cat, subcategory_name = :name, description = :desc
                  WHERE subcategory_id = :id'
            )->execute([
                ':cat'  => $payload['category_id'],
                ':name' => $payload['name'],
                ':desc' => $payload['description'],
                ':id'   => $id,
            ]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                throw new RuntimeException("Subcategory '{$payload['name']}' already exists in that category.");
            }
            throw $e;
        }

        self::bustSummaryCache();

        return array_merge($existing, [
            'category_id'      => $payload['category_id'],
            'subcategory_name' => $payload['name'],
            'description'      => $payload['description'],
            'category_name'    => $categoryName,
        ]);
    }

    // =========================================================================
    // WRITE — toggle status
    // =========================================================================

    /**
     * Toggle status and return ['new_status', 'view'].
     * One SELECT + one UPDATE total.
     *
     * @throws RuntimeException        If not found.
     * @throws InvalidArgumentException If ID invalid.
     * @return array{new_status:string, view:array<string,mixed>}
     */
    public static function toggleStatus(PDO $conn, int $id): array
    {
        self::ensureSchema($conn);

        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid subcategory ID.');
        }

        $subcategory = self::getById($conn, $id);
        if (!$subcategory) {
            throw new RuntimeException('Subcategory not found.');
        }

        $newStatus = ($subcategory['status'] ?? 'inactive') === 'active' ? 'inactive' : 'active';

        $conn->prepare(
            'UPDATE ' . self::TABLE . ' SET status = :status WHERE subcategory_id = :id'
        )->execute([':status' => $newStatus, ':id' => $id]);

        self::bustSummaryCache();

        return [
            'new_status' => $newStatus,
            'view'       => array_merge($subcategory, ['status' => $newStatus]),
        ];
    }

    // =========================================================================
    // ASSERTION (used by ProductController)
    // =========================================================================

    public static function assertExistsForCategory(PDO $conn, int $subcategoryId, int $categoryId): void
    {
        self::ensureSchema($conn);

        if ($subcategoryId <= 0) {
            throw new InvalidArgumentException('Invalid subcategory.');
        }

        $stmt = $conn->prepare(
            'SELECT COUNT(*) FROM ' . self::TABLE .
            ' WHERE subcategory_id = :id AND category_id = :cat'
        );
        $stmt->execute([':id' => $subcategoryId, ':cat' => $categoryId]);

        if ((int) $stmt->fetchColumn() === 0) {
            throw new RuntimeException('Selected subcategory does not belong to the chosen category.');
        }
    }

    // =========================================================================
    // PRIVATE — helpers
    // =========================================================================

    /**
     * Fetch the category name for a given category_id.
     * Throws RuntimeException if the category does not exist.
     * Replaces the old assertCategoryExists() + separate name fetch.
     */
    private static function fetchCategoryName(PDO $conn, int $categoryId): string
    {
        if ($categoryId <= 0) {
            throw new InvalidArgumentException('Category is required.');
        }

        $stmt = $conn->prepare('SELECT category_name FROM categories WHERE category_id = :id LIMIT 1');
        $stmt->execute([':id' => $categoryId]);
        $name = $stmt->fetchColumn();

        if ($name === false) {
            throw new RuntimeException('Category not found.');
        }

        return (string) $name;
    }

    /** @return array{category_id:int, name:string, description:string|null} */
    private static function validatePayload(int $categoryId, string $name, ?string $description): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        $desc = trim((string) $description);
        $desc = $desc !== '' ? $desc : null;

        if ($categoryId <= 0) {
            throw new InvalidArgumentException('Category is required.');
        }
        if ($name === '') {
            throw new InvalidArgumentException('Subcategory name is required.');
        }
        if (mb_strlen($name) > self::MAX_NAME_LEN) {
            throw new InvalidArgumentException('Subcategory name must not exceed ' . self::MAX_NAME_LEN . ' characters.');
        }
        if ($desc !== null && mb_strlen($desc) > self::MAX_DESC_LEN) {
            throw new InvalidArgumentException('Description must not exceed ' . self::MAX_DESC_LEN . ' characters.');
        }

        return ['category_id' => $categoryId, 'name' => $name, 'description' => $desc];
    }

    private static function validStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid subcategory status.');
        }
        return $status;
    }

    private static function ensureIndexes(PDO $conn): void
    {
        if (self::$indexesChecked) {
            return;
        }

        ListQueryHelper::ensureIndexes($conn, self::TABLE, [
            'idx_subcategories_status_cat_name' =>
                'CREATE INDEX idx_subcategories_status_cat_name ON subcategories (status, category_id, subcategory_name, subcategory_id)',
            'idx_subcategories_category_id' =>
                'CREATE INDEX idx_subcategories_category_id ON subcategories (category_id)',
            'idx_subcategories_name' =>
                'CREATE INDEX idx_subcategories_name ON subcategories (subcategory_name)',
        ]);

        self::$indexesChecked = true;
    }
}