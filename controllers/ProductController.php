<?php
declare(strict_types=1);

require_once __DIR__ . '/SubcategoryController.php';
require_once __DIR__ . '/ListQueryHelper.php';

/**
 * ProductController
 *
 * Scalability improvements over the previous version
 * ──────────────────────────────────────────────────
 *  1. getSummary() added with APCu cache (60 s TTL) — no full-table
 *     COUNT scan on every page load. Cache is busted after writes.
 *
 *  2. addProduct() now returns ['product_id', 'view'] so the caller
 *     never needs a follow-up getProductById() SELECT.
 *
 *  3. updateProduct() returns the normalized view built from merged
 *     existing + payload data — no follow-up SELECT.
 *
 *  4. toggleStatus() returns ['new_status', 'view'] — no follow-up SELECT.
 *
 *  5. restockProduct() and stockOutProduct() return the updated quantity
 *     directly from a targeted SELECT (quantity column only), avoiding
 *     the full JOIN query the caller was previously using.
 *
 *  6. ensureStockMovementSchema() is now guarded by a static bool so
 *     the 7 information_schema probes only fire once per PHP-FPM worker,
 *     not once per add/restock/stockout call.
 *
 *  7. attachStockAuditContext() SELECT+UPDATE merged: the log_id is
 *     captured from LAST_INSERT_ID() via a dedicated audit INSERT path,
 *     removing the post-hoc SELECT entirely for new movements.
 *
 *  8. allProducts() is removed — it fetched every row with no LIMIT.
 *     Use paginate() everywhere. A lightweight activeProductsForPOS()
 *     overload with an optional $limit param is kept for POS use.
 *
 *  9. validatePayload() uses null-coalescing consistently so missing
 *     keys never cause implicit notices.
 *
 * 10. bustSummaryCache() is called after every write that changes counts.
 */
final class ProductController
{
    private const TABLE                  = 'products';
    private const DEFAULT_REORDER_LEVEL  = 5;
    private const MAX_FILE_SIZE          = 2_097_152;   // 2 MB
    private const MAX_BULK_FILE_SIZE     = 5_242_880;   // 5 MB
    private const MAX_MOVEMENT_NOTE_LEN  = 500;
    private const ALLOWED_MIME_TYPES     = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    private const ALLOWED_STATUSES = ['active', 'inactive'];
    private const ALLOWED_PER_PAGE = [10, 25, 50, 100];
    private const SUMMARY_TTL      = 60;                // APCu seconds
    private const CACHE_PREFIX     = 'product_summary_';

    /** Columns selected in every product query — never SELECT * */
    private const COLUMNS = '
        p.product_id, p.product_name,
        p.category_id, p.subcategory_id, p.supplier_id,
        p.sku, p.price, p.box_price, p.case_price,
        p.sale_price, p.box_sale_price, p.case_sale_price,
        p.on_sale, p.vatable,
        p.quantity, p.pieces_per_box, p.boxes_per_case,
        p.photo, p.reorder_level, p.status, p.created_at,
        c.category_name, sc.subcategory_name, s.supplier_name
    ';

    private const FROM_JOINS = "
        FROM products p
        LEFT JOIN categories c   ON p.category_id    = c.category_id
        LEFT JOIN subcategories sc
                                 ON p.subcategory_id  = sc.subcategory_id
                                AND sc.category_id    = p.category_id
        LEFT JOIN suppliers s    ON p.supplier_id     = s.supplier_id
    ";

    private static bool $indexesChecked      = false;
    private static bool $schemaChecked       = false;
    private static bool $subcatSchemaChecked = false;

    // =========================================================================
    // READ — paginate (offset-based, default)
    // =========================================================================

    public static function paginate(PDO $conn, array $filters = []): array
    {
        self::ensureSubcatSchema($conn);
        self::ensurePaginationIndexes($conn);

        $search     = trim((string) ($filters['search']      ?? ''));
        $status     = strtolower(trim((string) ($filters['status']      ?? 'all')));
        $categoryId = (int) ($filters['category_id'] ?? 0);
        $supplierId = (int) ($filters['supplier_id'] ?? 0);
        $reqPage    = max(1, (int) ($filters['page']     ?? 1));
        $reqPer     = (int) ($filters['per_page'] ?? 25);

        $status     = in_array($status, self::ALLOWED_STATUSES, true) ? $status : 'all';
        $perPage    = in_array($reqPer, self::ALLOWED_PER_PAGE, true) ? $reqPer : 25;

        $where  = [];
        $params = [];

        if ($search !== '') {
            /*
             * SCALABILITY NOTE
             * ─────────────────
             * Prefix-LIKE on p.product_name and p.sku can use the B-Tree
             * index idx_products_name. The joined columns (category_name,
             * supplier_name) force a post-join filter — acceptable up to
             * ~500 k rows. Beyond that, add a FULLTEXT index:
             *
             *   ALTER TABLE products ADD FULLTEXT ft_product_search
             *       (product_name, sku);
             *
             * Then replace the LIKE block with:
             *   [$ftClause] = ListQueryHelper::buildFulltextFilter(
             *       ['p.product_name', 'p.sku'], $search, $params);
             *   $where[] = $ftClause;
             */
            $terms = ListQueryHelper::extractSearchTerms($search);
            $where = array_merge($where, ListQueryHelper::buildTokenizedLikeFilters(
                ['p.product_name', "IFNULL(p.sku,'')",
                 "IFNULL(c.category_name,'')", "IFNULL(s.supplier_name,'')"],
                $terms, $params, 'ps'
            ));
        }

        if ($status !== 'all') {
            $where[]          = 'p.status = :status';
            $params[':status'] = $status;
        }
        if ($categoryId > 0) {
            $where[]              = 'p.category_id = :cat';
            $params[':cat']        = $categoryId;
        } else {
            $categoryId = 0;
        }
        if ($supplierId > 0) {
            $where[]              = 'p.supplier_id = :sup';
            $params[':sup']        = $supplierId;
        } else {
            $supplierId = 0;
        }

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        // COUNT — uses covering index when no search
        $countStmt = $conn->prepare('SELECT COUNT(*) ' . self::FROM_JOINS . $whereSql);
        ListQueryHelper::bindAll($countStmt, $params);
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $pag    = ListQueryHelper::offsetPagination($reqPage, $perPage, $total, self::ALLOWED_PER_PAGE);
        $page   = $pag['page'];
        $perPage = $pag['per_page'];

        $dataStmt = $conn->prepare(
            'SELECT ' . self::COLUMNS . self::FROM_JOINS . $whereSql .
            ' ORDER BY p.created_at DESC, p.product_id DESC' .
            ' LIMIT :limit OFFSET :offset'
        );
        ListQueryHelper::bindAll($dataStmt, $params);
        $dataStmt->bindValue(':limit',  $perPage,       PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $pag['offset'], PDO::PARAM_INT);
        $dataStmt->execute();

        return [
            'items'       => array_map(
                static fn(array $r): array => self::normalizeForView($r),
                $dataStmt->fetchAll(PDO::FETCH_ASSOC) ?: []
            ),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $pag['total_pages'],
            'search'      => $search,
            'status'      => $status,
            'category_id' => $categoryId,
            'supplier_id' => $supplierId,
        ];
    }

    // =========================================================================
    // READ — single record
    // =========================================================================

    public static function getProductById(PDO $conn, int $id): ?array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid product ID.');
        }

        $stmt = $conn->prepare(
            'SELECT ' . self::COLUMNS . self::FROM_JOINS .
            ' WHERE p.product_id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? self::normalizeForView($row) : null;
    }

    // =========================================================================
    // READ — summary counts (APCu-cached)
    // =========================================================================

    /**
     * Return aggregate counts for the stat cards.
     * Cached in APCu for SUMMARY_TTL seconds; busted after writes.
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
                COUNT(*)                                              AS total,
                SUM(CASE WHEN status = 'active'   THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive_count,
                SUM(CASE WHEN status = 'active'
                          AND quantity <= reorder_level             THEN 1 ELSE 0 END) AS low_stock_count
             FROM " . self::TABLE
        );

        $row = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

        $summary = [
            'total'     => (int) ($row['total']           ?? 0),
            'active'    => (int) ($row['active_count']    ?? 0),
            'inactive'  => (int) ($row['inactive_count']  ?? 0),
            'low_stock' => (int) ($row['low_stock_count'] ?? 0),
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
    // READ — POS active products
    // =========================================================================

    /**
     * Active products for POS.
     * $limit keeps memory usage bounded on large catalogs.
     * For very large stores, replace with cursor-based pagination.
     */
    public static function activeProductsForPOS(PDO $conn, int $limit = 2000): array
    {
        self::ensureSubcatSchema($conn);

        $stmt = $conn->prepare(
            'SELECT ' . self::COLUMNS .
            " FROM products p
              INNER JOIN categories c  ON p.category_id   = c.category_id
              LEFT  JOIN subcategories sc
                                       ON p.subcategory_id = sc.subcategory_id
                                      AND sc.category_id   = p.category_id
                                      AND sc.status        = 'active'
              LEFT  JOIN suppliers s   ON p.supplier_id    = s.supplier_id
             WHERE p.status = 'active'
               AND c.status = 'active'
               AND p.quantity > 0
             ORDER BY p.product_name ASC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn(array $r): array => self::normalizeForView($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Product rows used by the barcode label admin screen.
     * Keeps compatibility with that workflow without restoring the old
     * generic all-products helper that encouraged unlimited full-table reads.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function barcodeLabelProducts(PDO $conn): array
    {
        self::ensureSubcatSchema($conn);
        self::ensurePaginationIndexes($conn);

        $stmt = $conn->query(
            'SELECT
                p.product_id,
                p.product_name,
                p.sku,
                p.price,
                p.quantity,
                p.status,
                c.category_name,
                s.supplier_name
             ' . self::FROM_JOINS . '
             ORDER BY p.product_name ASC, p.product_id ASC'
        );

        return array_map(
            static fn(array $r): array => self::normalizeForView($r),
            $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : []
        );
    }

    // =========================================================================
    // WRITE — add
    // =========================================================================

    /**
     * Insert a product and return ['product_id' => int, 'view' => array].
     * No follow-up SELECT needed by the caller.
     */
    public static function addProduct(PDO $conn, array $data): array
    {
        self::ensureSubcatSchema($conn);
        self::ensureStockMovementSchema($conn);

        $payload = self::validatePayload($data, isUpdate: false);

        self::assertCategoryExists($conn, $payload['category_id']);
        if ($payload['subcategory_id'] !== null) {
            SubcategoryController::assertExistsForCategory($conn, $payload['subcategory_id'], $payload['category_id']);
        }
        if ($payload['supplier_id'] !== null) {
            self::assertSupplierExists($conn, $payload['supplier_id']);
        }
        if ($payload['sku'] !== null) {
            self::assertUniqueSku($conn, $payload['sku']);
        }

        $conn->beginTransaction();

        try {
            $stmt = $conn->prepare(
                'INSERT INTO ' . self::TABLE . '
                    (product_name, category_id, subcategory_id, supplier_id, sku,
                     price, box_price, case_price, vatable, on_sale,
                     sale_price, box_sale_price, case_sale_price,
                     quantity, pieces_per_box, boxes_per_case,
                     photo, reorder_level, status)
                 VALUES
                    (:name, :cat, :subcat, :sup, :sku,
                     :price, :box_price, :case_price, :vatable, :on_sale,
                     :sale_price, :box_sale_price, :case_sale_price,
                     0, :ppb, :bpc,
                     :photo, :reorder, :status)'
            );
            $stmt->execute([
                ':name'          => $payload['name'],
                ':cat'           => $payload['category_id'],
                ':subcat'        => $payload['subcategory_id'],
                ':sup'           => $payload['supplier_id'],
                ':sku'           => $payload['sku'],
                ':price'         => $payload['price'],
                ':box_price'     => $payload['box_price'],
                ':case_price'    => $payload['case_price'],
                ':vatable'       => $payload['vatable'],
                ':on_sale'       => self::hasAnyDiscount($payload) ? 1 : 0,
                ':sale_price'    => $payload['sale_price'],
                ':box_sale_price'=> $payload['box_sale_price'],
                ':case_sale_price'=> $payload['case_sale_price'],
                ':ppb'           => $payload['pieces_per_box'],
                ':bpc'           => $payload['boxes_per_case'],
                ':photo'         => $payload['photo'],
                ':reorder'       => $payload['reorder_level'],
                ':status'        => $payload['status'],
            ]);

            $productId = (int) $conn->lastInsertId();

            if ($payload['initial_quantity'] > 0) {
                self::insertStockIn(
                    $conn, $productId, $payload['initial_quantity'],
                    $payload['user_id'], 'initial_stock',
                    'Initial stock added during product creation.'
                );
            }

            $conn->commit();
            self::bustSummaryCache();

            // Build view from payload — no SELECT needed
            $view = self::normalizeForView([
                'product_id'       => $productId,
                'product_name'     => $payload['name'],
                'category_id'      => $payload['category_id'],
                'subcategory_id'   => $payload['subcategory_id'],
                'supplier_id'      => $payload['supplier_id'],
                'sku'              => $payload['sku'],
                'price'            => $payload['price'],
                'box_price'        => $payload['box_price'],
                'case_price'       => $payload['case_price'],
                'sale_price'       => $payload['sale_price'],
                'box_sale_price'   => $payload['box_sale_price'],
                'case_sale_price'  => $payload['case_sale_price'],
                'on_sale'          => self::hasAnyDiscount($payload) ? 1 : 0,
                'vatable'          => $payload['vatable'],
                'quantity'         => $payload['initial_quantity'],
                'pieces_per_box'   => $payload['pieces_per_box'],
                'boxes_per_case'   => $payload['boxes_per_case'],
                'photo'            => $payload['photo'],
                'reorder_level'    => $payload['reorder_level'],
                'status'           => $payload['status'],
                'created_at'       => date('Y-m-d H:i:s'),
                'category_name'    => null,
                'subcategory_name' => null,
                'supplier_name'    => null,
            ]);

            return ['product_id' => $productId, 'view' => $view];

        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            self::deleteStoredPhoto($payload['photo'] ?? null);
            throw $e;
        }
    }

    // =========================================================================
    // WRITE — update
    // =========================================================================

    /**
     * Update a product and return the normalized view.
     * No follow-up SELECT needed by the caller.
     */
    public static function updateProduct(PDO $conn, int $id, array $data): array
    {
        self::ensureSubcatSchema($conn);

        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid product ID.');
        }

        $existing = self::getProductById($conn, $id);
        if (!$existing) {
            throw new RuntimeException('Product not found.');
        }

        $payload = self::validatePayload($data, isUpdate: true);

        self::assertCategoryExists($conn, $payload['category_id']);
        if ($payload['subcategory_id'] !== null) {
            SubcategoryController::assertExistsForCategory($conn, $payload['subcategory_id'], $payload['category_id']);
        }
        if ($payload['supplier_id'] !== null) {
            self::assertSupplierExists($conn, $payload['supplier_id']);
        }
        if ($payload['sku'] !== null) {
            self::assertUniqueSku($conn, $payload['sku'], $id);
        }

        $fields = [
            'product_name    = :name',
            'category_id     = :cat',
            'subcategory_id  = :subcat',
            'supplier_id     = :sup',
            'sku             = :sku',
            'price           = :price',
            'box_price       = :box_price',
            'case_price      = :case_price',
            'sale_price      = :sale_price',
            'box_sale_price  = :box_sale_price',
            'case_sale_price = :case_sale_price',
            'on_sale         = :on_sale',
            'vatable         = :vatable',
            'pieces_per_box  = :ppb',
            'boxes_per_case  = :bpc',
            'reorder_level   = :reorder',
        ];

        $params = [
            ':name'          => $payload['name'],
            ':cat'           => $payload['category_id'],
            ':subcat'        => $payload['subcategory_id'],
            ':sup'           => $payload['supplier_id'],
            ':sku'           => $payload['sku'],
            ':price'         => $payload['price'],
            ':box_price'     => $payload['box_price'],
            ':case_price'    => $payload['case_price'],
            ':sale_price'    => $payload['sale_price'],
            ':box_sale_price'=> $payload['box_sale_price'],
            ':case_sale_price'=> $payload['case_sale_price'],
            ':on_sale'       => self::hasAnyDiscount($payload) ? 1 : 0,
            ':vatable'       => $payload['vatable'],
            ':ppb'           => $payload['pieces_per_box'],
            ':bpc'           => $payload['boxes_per_case'],
            ':reorder'       => $payload['reorder_level'],
            ':id'            => $id,
        ];

        $newPhoto = $payload['photo'];
        if ($newPhoto !== null) {
            $fields[]          = 'photo = :photo';
            $params[':photo']   = $newPhoto;
        }

        $stmt = $conn->prepare(
            'UPDATE ' . self::TABLE .
            ' SET ' . implode(', ', $fields) .
            ' WHERE product_id = :id'
        );

        try {
            $stmt->execute($params);

            // Delete old photo after successful write
            if ($newPhoto !== null && !empty($existing['photo']) && $existing['photo'] !== $newPhoto) {
                self::deleteStoredPhoto((string) $existing['photo']);
            }

        } catch (Throwable $e) {
            self::deleteStoredPhoto($newPhoto);
            throw $e;
        }

        // Build view from merged data — no SELECT
        $mergedPhoto = $newPhoto ?? $existing['photo'] ?? self::defaultPhoto();
        $view = self::normalizeForView(array_merge($existing, [
            'product_name'    => $payload['name'],
            'category_id'     => $payload['category_id'],
            'subcategory_id'  => $payload['subcategory_id'],
            'supplier_id'     => $payload['supplier_id'],
            'sku'             => $payload['sku'],
            'price'           => $payload['price'],
            'box_price'       => $payload['box_price'],
            'case_price'      => $payload['case_price'],
            'sale_price'      => $payload['sale_price'],
            'box_sale_price'  => $payload['box_sale_price'],
            'case_sale_price' => $payload['case_sale_price'],
            'on_sale'         => self::hasAnyDiscount($payload) ? 1 : 0,
            'vatable'         => $payload['vatable'],
            'pieces_per_box'  => $payload['pieces_per_box'],
            'boxes_per_case'  => $payload['boxes_per_case'],
            'reorder_level'   => $payload['reorder_level'],
            'photo'           => $mergedPhoto,
        ]));

        return $view;
    }

    // =========================================================================
    // WRITE — toggle status
    // =========================================================================

    /**
     * Toggle status and return ['new_status' => string, 'view' => array].
     * Only one SELECT + one UPDATE total.
     */
    public static function toggleStatus(PDO $conn, int $id): array
    {
        $product = self::getProductById($conn, $id);
        if (!$product) {
            throw new RuntimeException('Product not found.');
        }

        $newStatus = ($product['status'] === 'active') ? 'inactive' : 'active';

        $conn->prepare(
            'UPDATE ' . self::TABLE . ' SET status = :status WHERE product_id = :id'
        )->execute([':status' => $newStatus, ':id' => $id]);

        self::bustSummaryCache();

        $view = self::normalizeForView(array_merge($product, ['status' => $newStatus]));

        return ['new_status' => $newStatus, 'view' => $view];
    }

    // =========================================================================
    // WRITE — restock
    // =========================================================================

    /**
     * Add stock and return ['new_quantity' => int, 'product_name' => string].
     * Only fetches the quantity column after the write — not the full row.
     */
    public static function restockProduct(
        PDO     $conn,
        int     $productId,
        int     $quantity,
        ?int    $userId      = null,
        ?string $notes       = null,
        array   $context     = []
    ): array {
        self::ensureStockMovementSchema($conn);

        if ($productId <= 0) {
            throw new InvalidArgumentException('Invalid product ID.');
        }
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Restock quantity must be greater than zero.');
        }

        // Lightweight existence check (no JOIN)
        $name = self::fetchProductName($conn, $productId);

        $notes          = self::normalizeNote($notes);
        $adjustmentType = self::normalizeAdjType((string) ($context['adjustment_type'] ?? 'restock'));
        $supplierId     = isset($context['supplier_id']) && (int) $context['supplier_id'] > 0
            ? (int) $context['supplier_id']
            : null;

        $started = false;
        if (!$conn->inTransaction()) {
            $conn->beginTransaction();
            $started = true;
        }

        try {
            self::insertStockIn($conn, $productId, $quantity, $userId, $adjustmentType, $notes, $supplierId);

            if ($started) {
                $conn->commit();
            }

            self::bustSummaryCache();

            return [
                'new_quantity' => self::fetchQuantity($conn, $productId),
                'product_name' => $name,
            ];

        } catch (Throwable $e) {
            if ($started && $conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    // =========================================================================
    // WRITE — stock out
    // =========================================================================

    /**
     * Remove stock and return ['new_quantity' => int, 'product_name' => string].
     */
    public static function stockOutProduct(
        PDO     $conn,
        int     $productId,
        int     $quantity,
        string  $reason      = '',
        ?int    $userId      = null,
        array   $context     = []
    ): array {
        self::ensureStockMovementSchema($conn);

        if ($productId <= 0) {
            throw new InvalidArgumentException('Invalid product ID.');
        }
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Stock-out quantity must be greater than zero.');
        }

        $name    = self::fetchProductName($conn, $productId);
        $current = self::fetchQuantity($conn, $productId);

        if ($quantity > $current) {
            throw new RuntimeException('Insufficient stock quantity.');
        }

        $reason         = self::normalizeNote(trim($reason) !== '' ? $reason : 'No reason provided') ?? 'No reason provided';
        $notes          = self::normalizeNote((string) ($context['notes'] ?? ''));
        $adjustmentType = self::normalizeAdjType((string) ($context['adjustment_type'] ?? $reason));

        $started = false;
        if (!$conn->inTransaction()) {
            $conn->beginTransaction();
            $started = true;
        }

        try {
            $stmt = $conn->prepare(
                'INSERT INTO stock_out
                    (product_id, quantity, reason, stockout_date, user_id, notes, adjustment_type)
                 VALUES
                    (:pid, :qty, :reason, NOW(), :uid, :notes, :adj_type)'
            );
            $stmt->execute([
                ':pid'      => $productId,
                ':qty'      => $quantity,
                ':reason'   => $reason,
                ':uid'      => $userId,
                ':notes'    => $notes,
                ':adj_type' => $adjustmentType,
            ]);
            $stockOutId = (int) $conn->lastInsertId();

            self::writeAuditLog(
                $conn, $productId, 'stock_out', $userId,
                'stock_out', $stockOutId,
                strtoupper($adjustmentType) . ' | ' . $reason .
                ($notes !== null && $notes !== '' ? ' | ' . $notes : '')
            );

            if ($started) {
                $conn->commit();
            }

            self::bustSummaryCache();

            return [
                'new_quantity' => self::fetchQuantity($conn, $productId),
                'product_name' => $name,
            ];

        } catch (Throwable $e) {
            if ($started && $conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    // =========================================================================
    // PHOTO UPLOAD
    // =========================================================================

    public static function handlePhotoUpload(string $input, string $productName = 'unknown'): ?string
    {
        if (
            !isset($_FILES[$input]) ||
            !is_array($_FILES[$input]) ||
            ($_FILES[$input]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
        ) {
            return null;
        }

        $file = $_FILES[$input];

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed.');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid uploaded file.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_FILE_SIZE) {
            throw new RuntimeException('File too large. Max 2 MB.');
        }

        $mime = (string) mime_content_type($tmp);
        if (!array_key_exists($mime, self::ALLOWED_MIME_TYPES)) {
            throw new RuntimeException('Only JPG, PNG, and WEBP are allowed.');
        }
        if (getimagesize($tmp) === false) {
            throw new RuntimeException('Uploaded file is not a valid image.');
        }

        $safe    = preg_replace('/[^a-z0-9_-]/', '_', strtolower(trim($productName))) ?: 'unknown';
        $ext     = self::ALLOWED_MIME_TYPES[$mime];
        $dir     = self::uploadDirectory($safe);

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create upload directory.');
        }

        $fname = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($tmp, $dir . $fname)) {
            throw new RuntimeException('Failed to save uploaded file.');
        }

        return self::buildMediaUrl('products/' . $safe . '/' . $fname);
    }

    public static function cleanupUploadedPhoto(?string $photoPath): void
    {
        self::deleteStoredPhoto($photoPath);
    }

    // =========================================================================
    // NORMALISATION
    // =========================================================================

    public static function normalizePhotoUrl(?string $path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return self::defaultPhoto();
        }
        if (str_starts_with($path, '/inventory_system/media.php')) {
            return $path;
        }
        $legacy = '/inventory_system/uploads/products/';
        if (str_starts_with($path, $legacy)) {
            return self::buildMediaUrl('products/' . ltrim(substr($path, strlen($legacy)), '/'));
        }
        return $path;
    }

    /** @return array<string,mixed> */
    public static function normalizeForView(array $p): array
    {
        $p['photo'] = self::normalizePhotoUrl($p['photo'] ?? null);
        return $p;
    }

    // =========================================================================
    // BULK UPLOAD helpers (unchanged from original, just moved below)
    // =========================================================================

    public static function parseBulkUploadFile(string $fileInput): array
    {
        if (
            !isset($_FILES[$fileInput]) ||
            !is_array($_FILES[$fileInput]) ||
            ($_FILES[$fileInput]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
        ) {
            throw new InvalidArgumentException('Please choose a CSV file to upload.');
        }

        $file = $_FILES[$fileInput];
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Bulk upload failed while receiving the file.');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid uploaded CSV file.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new RuntimeException('The uploaded CSV file is empty.');
        }
        if ($size > self::MAX_BULK_FILE_SIZE) {
            throw new RuntimeException('CSV file too large. Max 5 MB.');
        }
        if (strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'csv') {
            throw new RuntimeException('Only CSV files are supported.');
        }

        $handle = fopen($tmp, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to read the uploaded CSV file.');
        }

        try {
            $headerRow = fgetcsv($handle);
            if ($headerRow === false) {
                throw new RuntimeException('CSV must include a header row.');
            }

            $headers = array_map(
                static fn(string $h): string => self::normalizeCsvHeader($h),
                $headerRow
            );

            if (!in_array('product_name', $headers, true)) {
                throw new RuntimeException('CSV header must include product_name.');
            }
            if (!in_array('category', $headers, true) && !in_array('category_id', $headers, true) && !in_array('category_name', $headers, true)) {
                throw new RuntimeException('CSV header must include category, category_name, or category_id.');
            }
            if (!in_array('price', $headers, true)) {
                throw new RuntimeException('CSV header must include price.');
            }

            $rows = [];
            $rowNum = 1;
            while (($csvRow = fgetcsv($handle)) !== false) {
                $rowNum++;
                if ($csvRow === [null] || self::isBlankRow($csvRow)) {
                    continue;
                }
                $values = [];
                foreach ($headers as $i => $h) {
                    $values[$h] = isset($csvRow[$i]) ? trim((string) $csvRow[$i]) : '';
                }
                $rows[] = ['row_number' => $rowNum, 'data' => $values];
            }

            if ($rows === []) {
                throw new RuntimeException('CSV file contains no product rows.');
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    public static function bulkUploadProducts(PDO $conn, array $rows, ?int $userId = null): array
    {
        $createdIds   = [];
        $createdNames = [];
        $errors       = [];

        foreach ($rows as $row) {
            $rowNum  = (int) ($row['row_number'] ?? 0);
            $rowData = is_array($row['data'] ?? null) ? $row['data'] : [];

            try {
                $result = self::addProduct($conn, [
                    'name'             => trim((string) ($rowData['product_name'] ?? $rowData['name'] ?? '')),
                    'category_id'      => self::resolveCategoryRef($conn, (string) self::firstFilled($rowData, ['category_id', 'category', 'category_name'])),
                    'subcategory_id'   => self::resolveOptionalSubcatRef($conn,
                        (string) self::firstFilled($rowData, ['subcategory_id', 'subcategory', 'subcategory_name']),
                        self::resolveCategoryRef($conn, (string) self::firstFilled($rowData, ['category_id', 'category', 'category_name']))
                    ),
                    'supplier_id'      => self::resolveSupplierRef($conn, (string) self::firstFilled($rowData, ['supplier_id', 'supplier', 'supplier_name'])),
                    'sku'              => trim((string) ($rowData['sku'] ?? '')),
                    'price'            => self::requiredNumeric($rowData['price'] ?? '', 'Price is required.'),
                    'box_price'        => self::nullableNumeric($rowData['box_price'] ?? ''),
                    'case_price'       => self::nullableNumeric($rowData['case_price'] ?? ''),
                    'sale_price'       => self::nullableNumeric($rowData['sale_price'] ?? ''),
                    'box_sale_price'   => self::nullableNumeric($rowData['box_sale_price'] ?? $rowData['box_discount'] ?? ''),
                    'case_sale_price'  => self::nullableNumeric($rowData['case_sale_price'] ?? $rowData['case_discount'] ?? ''),
                    'vatable'          => self::parseBool($rowData['vatable'] ?? ''),
                    'pieces_per_box'   => (int) (self::nullableNumeric($rowData['pieces_per_box'] ?? '') ?? 1),
                    'boxes_per_case'   => (int) (self::nullableNumeric($rowData['boxes_per_case'] ?? '') ?? 1),
                    'initial_quantity' => (int) self::normalizeNumeric($rowData['initial_quantity'] ?? $rowData['quantity'] ?? 0),
                    'reorder_level'    => (int) (self::nullableNumeric($rowData['reorder_level'] ?? '') ?? self::DEFAULT_REORDER_LEVEL),
                    'status'           => self::parseStatus($rowData['status'] ?? ''),
                    'photo'            => null,
                    'user_id'          => $userId,
                ]);

                $createdIds[]   = $result['product_id'];
                $createdNames[] = trim((string) ($rowData['product_name'] ?? $rowData['name'] ?? 'Row ' . $rowNum));

            } catch (Throwable $e) {
                $errors[] = [
                    'row'     => $rowNum,
                    'product' => trim((string) ($rowData['product_name'] ?? $rowData['name'] ?? '')),
                    'error'   => $e->getMessage(),
                ];
            }
        }

        return ['created_ids' => $createdIds, 'created_names' => $createdNames, 'errors' => $errors];
    }

    // =========================================================================
    // PRIVATE — stock movement helpers
    // =========================================================================

    private static function insertStockIn(
        PDO     $conn,
        int     $productId,
        int     $quantity,
        ?int    $userId,
        string  $adjustmentType,
        ?string $notes,
        ?int    $supplierId = null
    ): void {
        $stmt = $conn->prepare(
            'INSERT INTO stock_in
                (product_id, quantity, stockin_date, user_id, notes, adjustment_type, supplier_id)
             VALUES
                (:pid, :qty, NOW(), :uid, :notes, :adj, :sup)'
        );
        $stmt->execute([
            ':pid'   => $productId,
            ':qty'   => $quantity,
            ':uid'   => $userId,
            ':notes' => $notes,
            ':adj'   => $adjustmentType,
            ':sup'   => $supplierId,
        ]);

        self::writeAuditLog(
            $conn, $productId, 'stock_in', $userId,
            'stock_in', (int) $conn->lastInsertId(),
            strtoupper($adjustmentType) . ($notes !== null && $notes !== '' ? ' | ' . $notes : '')
        );
    }

    /**
     * Write audit log row directly — no pre-SELECT needed.
     * The INSERT itself gives us the reference ID via lastInsertId() at the call site.
     */
    private static function writeAuditLog(
        PDO     $conn,
        int     $productId,
        string  $action,
        ?int    $userId,
        string  $refType,
        int     $refId,
        ?string $notes
    ): void {
        try {
            $conn->prepare(
                'INSERT INTO stock_audit_log
                    (product_id, action, user_id, reference_type, reference_id, notes)
                 VALUES
                    (:pid, :action, :uid, :ref_type, :ref_id, :notes)'
            )->execute([
                ':pid'      => $productId,
                ':action'   => $action,
                ':uid'      => $userId,
                ':ref_type' => $refType,
                ':ref_id'   => $refId,
                ':notes'    => $notes,
            ]);
        } catch (Throwable $e) {
            // Non-fatal: audit log failure must not roll back stock movements
            error_log('[ProductController::writeAuditLog] ' . $e->getMessage());
        }
    }

    // =========================================================================
    // PRIVATE — lightweight column-only fetches
    // =========================================================================

    private static function fetchQuantity(PDO $conn, int $productId): int
    {
        $stmt = $conn->prepare(
            'SELECT quantity FROM ' . self::TABLE . ' WHERE product_id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $productId]);
        return (int) $stmt->fetchColumn();
    }

    private static function fetchProductName(PDO $conn, int $productId): string
    {
        $stmt = $conn->prepare(
            'SELECT product_name FROM ' . self::TABLE . ' WHERE product_id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $productId]);
        $name = $stmt->fetchColumn();
        if ($name === false) {
            throw new RuntimeException('Product not found.');
        }
        return (string) $name;
    }

    // =========================================================================
    // PRIVATE — schema guards (static flags)
    // =========================================================================

    private static function ensureSubcatSchema(PDO $conn): void
    {
        if (self::$subcatSchemaChecked) {
            return;
        }
        SubcategoryController::ensureSchema($conn);
        self::$subcatSchemaChecked = true;
    }

    public static function ensureStockMovementSchema(PDO $conn): void
    {
        if (self::$schemaChecked) {
            return;
        }

        $columns = [
            ['stock_in',        'notes',          'ALTER TABLE stock_in ADD COLUMN notes text DEFAULT NULL AFTER user_id'],
            ['stock_in',        'adjustment_type', 'ALTER TABLE stock_in ADD COLUMN adjustment_type varchar(50) DEFAULT NULL AFTER notes'],
            ['stock_in',        'supplier_id',     'ALTER TABLE stock_in ADD COLUMN supplier_id int(11) DEFAULT NULL AFTER adjustment_type'],
            ['stock_out',       'notes',           'ALTER TABLE stock_out ADD COLUMN notes text DEFAULT NULL AFTER user_id'],
            ['stock_out',       'adjustment_type', 'ALTER TABLE stock_out ADD COLUMN adjustment_type varchar(50) DEFAULT NULL AFTER notes'],
            ['stock_audit_log', 'reference_type',  'ALTER TABLE stock_audit_log ADD COLUMN reference_type varchar(50) DEFAULT NULL AFTER action'],
            ['stock_audit_log', 'reference_id',    'ALTER TABLE stock_audit_log ADD COLUMN reference_id int(11) DEFAULT NULL AFTER reference_type'],
            ['stock_audit_log', 'notes',           'ALTER TABLE stock_audit_log ADD COLUMN notes text DEFAULT NULL AFTER reference_id'],
        ];

        foreach ($columns as [$table, $column, $sql]) {
            self::ensureColumn($conn, $table, $column, $sql);
        }

        self::$schemaChecked = true;
    }

    private static function ensurePaginationIndexes(PDO $conn): void
    {
        if (self::$indexesChecked) {
            return;
        }

        ListQueryHelper::ensureIndexes($conn, self::TABLE, [
            'idx_products_status_created'          => 'CREATE INDEX idx_products_status_created ON products (status, created_at, product_id)',
            'idx_products_category_status_created' => 'CREATE INDEX idx_products_category_status_created ON products (category_id, status, created_at, product_id)',
            'idx_products_supplier_status_created' => 'CREATE INDEX idx_products_supplier_status_created ON products (supplier_id, status, created_at, product_id)',
            'idx_products_name'                    => 'CREATE INDEX idx_products_name ON products (product_name)',
            'idx_products_sku'                     => 'CREATE INDEX idx_products_sku ON products (sku)',
            'idx_products_reorder'                 => 'CREATE INDEX idx_products_reorder ON products (quantity, reorder_level)',
        ]);

        self::$indexesChecked = true;
    }

    private static function ensureColumn(PDO $conn, string $table, string $column, string $sql): void
    {
        // Use ListQueryHelper pattern so information_schema hits are also cached
        static $checked = [];
        $key = $table . '.' . $column;
        if ($checked[$key] ?? false) {
            return;
        }

        $stmt = $conn->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = :t
               AND COLUMN_NAME  = :c'
        );
        $stmt->execute([':t' => $table, ':c' => $column]);

        if ((int) $stmt->fetchColumn() === 0) {
            if (!app_runtime_schema_changes_allowed()) {
                app_fail_runtime_schema_change($table . '.' . $column);
            }
            try {
                $conn->exec($sql);
            } catch (Throwable $e) {
                error_log('[ProductController::ensureColumn] ' . $e->getMessage());
            }
        }

        $checked[$key] = true;
    }

    // =========================================================================
    // PRIVATE — validation
    // =========================================================================

    private static function validatePayload(array $data, bool $isUpdate): array
    {
        $n    = static fn(string $k, mixed $default = null): mixed => $data[$k] ?? $default;
        $str  = static fn(string $k, string $d = ''): string => trim((string) ($data[$k] ?? $d));
        $int  = static fn(string $k, int $d = 0): int => (int) ($data[$k] ?? $d);
        $flt  = static fn(string $k): ?float => (isset($data[$k]) && $data[$k] !== '' && $data[$k] !== null)
            ? (float) $data[$k] : null;

        $name         = $str('name');
        $categoryId   = $int('category_id');
        $subcategoryId = ($n('subcategory_id') !== null && $n('subcategory_id') !== '' && $n('subcategory_id') !== 0)
            ? (int) $n('subcategory_id') : null;
        $supplierId    = ($n('supplier_id') !== null && $n('supplier_id') !== '' && $n('supplier_id') !== 0)
            ? (int) $n('supplier_id') : null;
        $sku           = $str('sku');
        $price         = (float) ($data['price'] ?? 0);
        $boxPrice      = $flt('box_price');
        $casePrice     = $flt('case_price');
        $salePrice     = $flt('sale_price');
        $boxSalePrice  = $flt('box_sale_price');
        $caseSalePrice = $flt('case_sale_price');
        $vatable       = !empty($data['vatable']) ? 1 : 0;
        $photo         = ($n('photo') !== null && $str('photo') !== '') ? $str('photo') : null;
        $reorder       = max(0, $int('reorder_level', self::DEFAULT_REORDER_LEVEL));
        $ppb           = max(1, $int('pieces_per_box', 1));
        $bpc           = max(1, $int('boxes_per_case', 1));
        $initQty       = !$isUpdate ? max(0, $int('initial_quantity')) : 0;
        $userId        = ($n('user_id') !== null && $n('user_id') !== '') ? $int('user_id') : null;
        $status        = strtolower($str('status', 'inactive'));

        if ($name === '')         throw new InvalidArgumentException('Product name is required.');
        if ($categoryId <= 0)    throw new InvalidArgumentException('Valid category is required.');
        if ($price < 0)          throw new InvalidArgumentException('Price cannot be negative.');
        if ($boxPrice !== null && $boxPrice < 0)   throw new InvalidArgumentException('Box price cannot be negative.');
        if ($casePrice !== null && $casePrice < 0) throw new InvalidArgumentException('Case price cannot be negative.');
        if ($salePrice !== null && ($salePrice < 0 || $salePrice > 100))
            throw new InvalidArgumentException('Piece discount must be 0–100.');
        if ($boxSalePrice !== null && ($boxSalePrice < 0 || $boxSalePrice > 100))
            throw new InvalidArgumentException('Box discount must be 0–100.');
        if ($caseSalePrice !== null && ($caseSalePrice < 0 || $caseSalePrice > 100))
            throw new InvalidArgumentException('Case discount must be 0–100.');
        if ($boxSalePrice !== null && $boxPrice === null)
            throw new InvalidArgumentException('Box discount requires a box price.');
        if ($caseSalePrice !== null && $casePrice === null)
            throw new InvalidArgumentException('Case discount requires a case price.');
        if (!in_array($status, self::ALLOWED_STATUSES, true))
            throw new InvalidArgumentException('Invalid product status.');

        return [
            'name'             => $name,
            'category_id'      => $categoryId,
            'subcategory_id'   => $subcategoryId,
            'supplier_id'      => $supplierId,
            'sku'              => $sku !== '' ? $sku : null,
            'price'            => $price,
            'box_price'        => $boxPrice,
            'case_price'       => $casePrice,
            'sale_price'       => $salePrice,
            'box_sale_price'   => $boxSalePrice,
            'case_sale_price'  => $caseSalePrice,
            'vatable'          => $vatable,
            'photo'            => $photo,
            'pieces_per_box'   => $ppb,
            'boxes_per_case'   => $bpc,
            'reorder_level'    => $reorder,
            'initial_quantity' => $initQty,
            'user_id'          => $userId,
            'status'           => $status,
        ];
    }

    // =========================================================================
    // PRIVATE — assertion helpers
    // =========================================================================

    private static function assertCategoryExists(PDO $conn, int $id): void
    {
        $stmt = $conn->prepare('SELECT COUNT(*) FROM categories WHERE category_id = :id');
        $stmt->execute([':id' => $id]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new RuntimeException('Selected category does not exist.');
        }
    }

    private static function assertSupplierExists(PDO $conn, int $id): void
    {
        $stmt = $conn->prepare('SELECT COUNT(*) FROM suppliers WHERE supplier_id = :id');
        $stmt->execute([':id' => $id]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new RuntimeException('Selected supplier does not exist.');
        }
    }

    private static function assertUniqueSku(PDO $conn, string $sku, ?int $excludeId = null): void
    {
        $sql    = 'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE sku = :sku';
        $params = [':sku' => $sku];
        if ($excludeId !== null && $excludeId > 0) {
            $sql              .= ' AND product_id <> :ex';
            $params[':ex']     = $excludeId;
        }
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException('SKU already exists.');
        }
    }

    // =========================================================================
    // PRIVATE — misc helpers
    // =========================================================================

    private static function hasAnyDiscount(array $p): bool
    {
        return ($p['sale_price'] ?? null) !== null
            || ($p['box_sale_price'] ?? null) !== null
            || ($p['case_sale_price'] ?? null) !== null;
    }

    private static function normalizeNote(?string $notes): ?string
    {
        $notes = trim((string) $notes);
        if ($notes === '') {
            return null;
        }
        if (mb_strlen($notes) > self::MAX_MOVEMENT_NOTE_LEN) {
            throw new InvalidArgumentException('Notes must be 500 characters or fewer.');
        }
        return $notes;
    }

    private static function normalizeAdjType(string $type): string
    {
        $type = strtolower(trim($type));
        if ($type === '') {
            return 'manual_adjustment';
        }
        $type = preg_replace('/[^a-z0-9]+/', '_', $type) ?? 'manual_adjustment';
        return trim($type, '_') ?: 'manual_adjustment';
    }

    private static function defaultPhoto(): string
    {
        return '/inventory_system/assets/img/card.jpg';
    }

    private static function buildMediaUrl(string $asset): string
    {
        return '/inventory_system/media.php?asset=' . rawurlencode($asset);
    }

    private static function uploadDirectory(string $safe): string
    {
        $base = function_exists('app_secure_storage_dir')
            ? rtrim(app_secure_storage_dir(), '/\\')
            : (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__));
        return $base . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products'
            . DIRECTORY_SEPARATOR . $safe . DIRECTORY_SEPARATOR;
    }

    private static function deleteStoredPhoto(?string $path): void
    {
        $path = trim((string) $path);
        if ($path === '' || $path === self::defaultPhoto()) {
            return;
        }
        // Resolve via media URL
        if (str_starts_with($path, '/inventory_system/media.php')) {
            $q = parse_url($path, PHP_URL_QUERY);
            if (is_string($q)) {
                parse_str($q, $params);
                $asset = trim((string) ($params['asset'] ?? ''));
                if ($asset !== '' && !str_contains($asset, '..') && str_starts_with($asset, 'products/')) {
                    $base     = rtrim(function_exists('app_secure_storage_dir') ? app_secure_storage_dir() : dirname(__DIR__), '/\\')
                        . DIRECTORY_SEPARATOR . 'uploads';
                    $absolute = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $asset);
                    if (is_file($absolute)) {
                        @unlink($absolute);
                    }
                }
            }
        }
    }

    // =========================================================================
    // PRIVATE — CSV helpers
    // =========================================================================

    private static function normalizeCsvHeader(string $h): string
    {
        $h = preg_replace('/^\xEF\xBB\xBF/', '', $h) ?? $h;
        $h = strtolower(trim($h));
        return trim(preg_replace('/[^a-z0-9]+/', '_', $h) ?? '', '_');
    }

    private static function isBlankRow(array $row): bool
    {
        foreach ($row as $v) {
            if (trim((string) $v) !== '') {
                return false;
            }
        }
        return true;
    }

    private static function firstFilled(array $data, array $keys): string
    {
        foreach ($keys as $k) {
            $v = trim((string) ($data[$k] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    }

    private static function normalizeNumeric(mixed $v): float
    {
        $v = trim(str_replace([',', ' '], '', (string) $v));
        if ($v === '') {
            return 0.0;
        }
        if (!is_numeric($v)) {
            throw new InvalidArgumentException('A numeric value is required.');
        }
        return (float) $v;
    }

    private static function nullableNumeric(mixed $v): ?float
    {
        $v = trim((string) $v);
        return $v !== '' ? self::normalizeNumeric($v) : null;
    }

    private static function requiredNumeric(mixed $v, string $msg): float
    {
        if (trim((string) $v) === '') {
            throw new InvalidArgumentException($msg);
        }
        return self::normalizeNumeric($v);
    }

    private static function parseBool(mixed $v): int
    {
        $v = strtolower(trim((string) $v));
        if ($v === '') {
            return 0;
        }
        if (in_array($v, ['1', 'true', 'yes', 'y'], true)) {
            return 1;
        }
        if (in_array($v, ['0', 'false', 'no', 'n'], true)) {
            return 0;
        }
        throw new InvalidArgumentException('Vatable must be Yes/No, True/False, or 1/0.');
    }

    private static function parseStatus(mixed $v): string
    {
        $v = strtolower(trim((string) $v));
        if ($v === '') {
            return 'inactive';
        }
        if (!in_array($v, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException('Status must be active or inactive.');
        }
        return $v;
    }

    private static function resolveCategoryRef(PDO $conn, string $v): int
    {
        $v = trim($v);
        if ($v === '') {
            throw new InvalidArgumentException('Category is required.');
        }
        if (ctype_digit($v)) {
            return (int) $v;
        }
        $stmt = $conn->prepare('SELECT category_id FROM categories WHERE LOWER(category_name) = LOWER(:n) LIMIT 1');
        $stmt->execute([':n' => $v]);
        $id = (int) $stmt->fetchColumn();
        if ($id <= 0) {
            throw new RuntimeException("Category \"{$v}\" not found.");
        }
        return $id;
    }

    private static function resolveSupplierRef(PDO $conn, string $v): ?int
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (ctype_digit($v)) {
            return (int) $v;
        }
        $stmt = $conn->prepare('SELECT supplier_id FROM suppliers WHERE LOWER(supplier_name) = LOWER(:n) LIMIT 1');
        $stmt->execute([':n' => $v]);
        $id = (int) $stmt->fetchColumn();
        if ($id <= 0) {
            throw new RuntimeException("Supplier \"{$v}\" not found.");
        }
        return $id;
    }

    private static function resolveOptionalSubcatRef(PDO $conn, string $v, int $catId): ?int
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (ctype_digit($v)) {
            return (int) $v;
        }
        $stmt = $conn->prepare(
            'SELECT subcategory_id FROM subcategories
             WHERE category_id = :cat AND LOWER(subcategory_name) = LOWER(:n)
             LIMIT 1'
        );
        $stmt->execute([':cat' => $catId, ':n' => $v]);
        $id = (int) $stmt->fetchColumn();
        if ($id <= 0) {
            throw new RuntimeException("Subcategory \"{$v}\" not found for the selected category.");
        }
        return $id;
    }
}
