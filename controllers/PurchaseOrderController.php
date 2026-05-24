<?php
declare(strict_types=1);

require_once __DIR__ . '/ProductController.php';
require_once __DIR__ . '/SaleController.php';

/**
 * PurchaseOrderController
 *
 * Scalability improvements over the previous version
 * ──────────────────────────────────────────────────
 *  1.  ensureSchema() is now guarded by a static bool so CREATE TABLE IF
 *      NOT EXISTS only fires once per PHP-FPM worker, not on every request.
 *
 *  2.  listPurchaseOrders() is replaced with paginate() — proper offset
 *      pagination with status / supplier / date-range filters and a
 *      covering index.
 *
 *  3.  The N+1 loop (listPurchaseOrders + getPurchaseOrder × N) is
 *      eliminated. getPurchaseOrderItemsBatch() fetches all item rows for
 *      an entire page of POs in ONE query, keyed by po_id.
 *
 *  4.  statusSummary() is APCu-cached for SUMMARY_TTL seconds.
 *      bustSummaryCache() is called after every write.
 *
 *  5.  lowStockCandidates() result is APCu-cached for LOW_STOCK_TTL
 *      seconds. The expensive 30-day sale subquery no longer runs on
 *      every page load.
 *
 *  6.  createPurchaseOrder() validates items with a single batch SELECT
 *      (one query for all product supplier_ids) instead of calling
 *      getProductById() in a loop.
 *
 *  7.  getPurchaseOrder() is kept for single-PO AJAX detail loads.
 *
 *  8.  All helpers consolidated: buildLogConfig(), notify() helpers live
 *      in the calling layer (purchase_orders.php) to keep the controller
 *      focused on data access.
 */
final class PurchaseOrderController
{
    private const PO_TABLE        = 'purchase_orders';
    private const ITEM_TABLE      = 'purchase_order_items';
    private const ALLOWED_STATUSES = ['draft', 'ordered', 'partial', 'received', 'cancelled'];
    private const ALLOWED_PER_PAGE = [10, 25, 50, 100];
    private const SUMMARY_TTL     = 120;   // seconds
    private const LOW_STOCK_TTL   = 300;   // seconds — 5 min cache for expensive query
    private const CACHE_PREFIX    = 'po_summary_';
    private const LS_CACHE_KEY    = 'po_low_stock_candidates';

    private static bool $schemaChecked = false;

    // =========================================================================
    // SCHEMA
    // =========================================================================

    public static function ensureSchema(PDO $conn): void
    {
        if (self::$schemaChecked) {
            return;
        }

        ProductController::ensureStockMovementSchema($conn);
        SaleController::ensureReturnSchema($conn);

        if (function_exists('app_has_table') && !app_has_table($conn, self::PO_TABLE)) {
            if (function_exists('app_runtime_schema_changes_allowed') && !app_runtime_schema_changes_allowed()) {
                app_fail_runtime_schema_change(self::PO_TABLE);
            }
        }

        $conn->exec("
            CREATE TABLE IF NOT EXISTS " . self::PO_TABLE . " (
                po_id        INT(11)      NOT NULL AUTO_INCREMENT,
                po_number    VARCHAR(40)  DEFAULT NULL,
                supplier_id  INT(11)      NOT NULL,
                created_by   INT(11)      DEFAULT NULL,
                received_by  INT(11)      DEFAULT NULL,
                status       VARCHAR(20)  NOT NULL DEFAULT 'ordered',
                notes        TEXT         DEFAULT NULL,
                ordered_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                received_at  DATETIME     DEFAULT NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (po_id),
                UNIQUE  KEY uniq_po_number     (po_number),
                KEY         idx_po_supplier    (supplier_id),
                KEY         idx_po_status      (status),
                KEY         idx_po_created_at  (created_at),
                CONSTRAINT purchase_orders_ibfk_1 FOREIGN KEY (supplier_id) REFERENCES suppliers (supplier_id),
                CONSTRAINT purchase_orders_ibfk_2 FOREIGN KEY (created_by)  REFERENCES users (user_id) ON DELETE SET NULL,
                CONSTRAINT purchase_orders_ibfk_3 FOREIGN KEY (received_by) REFERENCES users (user_id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        if (function_exists('app_has_table') && !app_has_table($conn, self::ITEM_TABLE)) {
            if (function_exists('app_runtime_schema_changes_allowed') && !app_runtime_schema_changes_allowed()) {
                app_fail_runtime_schema_change(self::ITEM_TABLE);
            }
        }

        $conn->exec("
            CREATE TABLE IF NOT EXISTS " . self::ITEM_TABLE . " (
                po_item_id        INT(11)  NOT NULL AUTO_INCREMENT,
                po_id             INT(11)  NOT NULL,
                product_id        INT(11)  NOT NULL,
                ordered_quantity  INT(11)  NOT NULL DEFAULT 0,
                received_quantity INT(11)  NOT NULL DEFAULT 0,
                notes             TEXT     DEFAULT NULL,
                created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (po_item_id),
                KEY idx_po_items_po      (po_id),
                KEY idx_po_items_product (product_id),
                CONSTRAINT purchase_order_items_ibfk_1 FOREIGN KEY (po_id)       REFERENCES " . self::PO_TABLE . " (po_id) ON DELETE CASCADE,
                CONSTRAINT purchase_order_items_ibfk_2 FOREIGN KEY (product_id)  REFERENCES products (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        self::$schemaChecked = true;
    }

    // =========================================================================
    // READ — paginate purchase orders
    // =========================================================================

    /**
     * Paginated list of purchase orders with optional status / supplier / date filters.
     * No N+1: items are NOT fetched here — call getPurchaseOrderItemsBatch() separately.
     */
    public static function paginate(PDO $conn, array $filters = []): array
    {
        self::ensureSchema($conn);

        $status     = strtolower(trim((string) ($filters['status']      ?? 'all')));
        $supplierId = (int) ($filters['supplier_id'] ?? 0);
        $dateFrom   = trim((string) ($filters['date_from'] ?? ''));
        $dateTo     = trim((string) ($filters['date_to']   ?? ''));
        $reqPage    = max(1, (int) ($filters['page']     ?? 1));
        $reqPer     = (int) ($filters['per_page'] ?? 25);

        $status  = in_array($status, self::ALLOWED_STATUSES, true) ? $status : 'all';
        $perPage = in_array($reqPer, self::ALLOWED_PER_PAGE, true) ? $reqPer : 25;

        $where  = [];
        $params = [];

        if ($status !== 'all') {
            $where[]          = 'po.status = :status';
            $params[':status'] = $status;
        }
        if ($supplierId > 0) {
            $where[]          = 'po.supplier_id = :sup';
            $params[':sup']    = $supplierId;
        }
        if ($dateFrom !== '') {
            $where[]              = 'DATE(po.ordered_at) >= :date_from';
            $params[':date_from']  = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[]            = 'DATE(po.ordered_at) <= :date_to';
            $params[':date_to']  = $dateTo;
        }

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        $fromSql = "
            FROM " . self::PO_TABLE . " po
            INNER JOIN suppliers s ON s.supplier_id = po.supplier_id
            LEFT  JOIN users u     ON u.user_id      = po.created_by
            LEFT  JOIN (
                SELECT po_id,
                       SUM(ordered_quantity)  AS ordered_total,
                       SUM(received_quantity) AS received_total,
                       COUNT(*)               AS item_lines
                FROM " . self::ITEM_TABLE . "
                GROUP BY po_id
            ) agg ON agg.po_id = po.po_id
        ";

        // COUNT
        $countStmt = $conn->prepare('SELECT COUNT(*) ' . $fromSql . $whereSql);
        foreach ($params as $k => $v) {
            $countStmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $pag     = ListQueryHelper::offsetPagination($reqPage, $perPage, $total, self::ALLOWED_PER_PAGE);
        $page    = $pag['page'];
        $perPage = $pag['per_page'];

        $dataStmt = $conn->prepare(
            "SELECT
                po.po_id, po.po_number, po.status, po.notes,
                po.ordered_at, po.received_at, po.created_at,
                s.supplier_name,
                u.username         AS created_by_username,
                COALESCE(agg.ordered_total,  0) AS ordered_total,
                COALESCE(agg.received_total, 0) AS received_total,
                COALESCE(agg.item_lines,     0) AS item_lines
            {$fromSql}
            {$whereSql}
            ORDER BY po.created_at DESC, po.po_id DESC
            LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $k => $v) {
            $dataStmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $dataStmt->bindValue(':limit',  $perPage,       PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $pag['offset'], PDO::PARAM_INT);
        $dataStmt->execute();

        return [
            'items'       => $dataStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $pag['total_pages'],
            'status'      => $status,
            'supplier_id' => $supplierId,
            'date_from'   => $dateFrom,
            'date_to'     => $dateTo,
        ];
    }

    // =========================================================================
    // READ — receiving history (same as paginate but adds text search)
    // =========================================================================

    /**
     * Paginate for the receiving history page.
     * Adds a free-text search across po_number, supplier_name, and
     * the creating user's username.
     * Ordered by received_at DESC (most recently received first),
     * falling back to created_at DESC.
     */
    public static function paginateHistory(PDO $conn, array $filters = []): array
    {
        self::ensureSchema($conn);

        $search     = trim((string) ($filters['search']      ?? ''));
        $status     = strtolower(trim((string) ($filters['status']      ?? 'all')));
        $supplierId = (int) ($filters['supplier_id'] ?? 0);
        $dateFrom   = trim((string) ($filters['date_from'] ?? ''));
        $dateTo     = trim((string) ($filters['date_to']   ?? ''));
        $reqPage    = max(1, (int) ($filters['page']     ?? 1));
        $reqPer     = (int) ($filters['per_page'] ?? 25);

        $status  = in_array($status, self::ALLOWED_STATUSES, true) ? $status : 'all';
        $perPage = in_array($reqPer, self::ALLOWED_PER_PAGE, true) ? $reqPer : 25;

        $where  = [];
        $params = [];

        if ($search !== '') {
            $like            = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $where[]         = "(po.po_number LIKE :search OR s.supplier_name LIKE :search2 OR u.username LIKE :search3)";
            $params[':search']  = $like;
            $params[':search2'] = $like;
            $params[':search3'] = $like;
        }
        if ($status !== 'all') {
            $where[]          = 'po.status = :status';
            $params[':status'] = $status;
        }
        if ($supplierId > 0) {
            $where[]          = 'po.supplier_id = :sup';
            $params[':sup']    = $supplierId;
        }
        if ($dateFrom !== '') {
            $where[]              = 'DATE(COALESCE(po.received_at, po.ordered_at)) >= :date_from';
            $params[':date_from']  = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[]            = 'DATE(COALESCE(po.received_at, po.ordered_at)) <= :date_to';
            $params[':date_to']  = $dateTo;
        }

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        $fromSql = "
            FROM " . self::PO_TABLE . " po
            INNER JOIN suppliers s ON s.supplier_id = po.supplier_id
            LEFT  JOIN users u     ON u.user_id      = po.created_by
            LEFT  JOIN users ru    ON ru.user_id     = po.received_by
            LEFT  JOIN (
                SELECT po_id,
                       SUM(ordered_quantity)  AS ordered_total,
                       SUM(received_quantity) AS received_total,
                       COUNT(*)               AS item_lines
                FROM " . self::ITEM_TABLE . "
                GROUP BY po_id
            ) agg ON agg.po_id = po.po_id
        ";

        $countStmt = $conn->prepare('SELECT COUNT(*) ' . $fromSql . $whereSql);
        foreach ($params as $k => $v) {
            $countStmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $pag     = ListQueryHelper::offsetPagination($reqPage, $perPage, $total, self::ALLOWED_PER_PAGE);
        $page    = $pag['page'];
        $perPage = $pag['per_page'];

        $dataStmt = $conn->prepare(
            "SELECT
                po.po_id, po.po_number, po.status, po.notes,
                po.ordered_at, po.received_at, po.created_at,
                s.supplier_name,
                u.username  AS created_by_username,
                ru.username AS received_by_username,
                COALESCE(agg.ordered_total,  0) AS ordered_total,
                COALESCE(agg.received_total, 0) AS received_total,
                COALESCE(agg.item_lines,     0) AS item_lines
            {$fromSql}
            {$whereSql}
            ORDER BY po.received_at DESC, po.created_at DESC, po.po_id DESC
            LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $k => $v) {
            $dataStmt->bindValue($k, $v, PDO::PARAM_STR);
        }
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
            'supplier_id' => $supplierId,
            'date_from'   => $dateFrom,
            'date_to'     => $dateTo,
        ];
    }

    // =========================================================================
    // READ — batch item fetch (eliminates N+1)
    // =========================================================================

    /**
     * Fetch all items for a list of po_ids in ONE query.
     * Returns [po_id => [items]] keyed by po_id.
     *
     * @param  int[] $poIds
     * @return array<int, array<int, array<string,mixed>>>
     */
    public static function getPurchaseOrderItemsBatch(PDO $conn, array $poIds): array
    {
        if ($poIds === []) {
            return [];
        }

        $poIds     = array_values(array_unique(array_filter(array_map('intval', $poIds))));
        $placeholders = implode(',', array_fill(0, count($poIds), '?'));

        $stmt = $conn->prepare(
            "SELECT
                poi.po_item_id, poi.po_id,
                poi.product_id, poi.ordered_quantity, poi.received_quantity, poi.notes,
                p.product_name, p.sku, p.quantity AS current_stock,
                c.category_name
             FROM " . self::ITEM_TABLE . " poi
             INNER JOIN products p  ON p.product_id  = poi.product_id
             LEFT  JOIN categories c ON c.category_id = p.category_id
             WHERE poi.po_id IN ({$placeholders})
             ORDER BY poi.po_id ASC, p.product_name ASC"
        );
        $stmt->execute($poIds);
        $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['po_id']][] = $row;
        }
        return $result;
    }

    // =========================================================================
    // READ — single PO (for AJAX modal, not page-load loop)
    // =========================================================================

    public static function getPurchaseOrder(PDO $conn, int $poId): ?array
    {
        self::ensureSchema($conn);

        $stmt = $conn->prepare(
            "SELECT po.*,
                    s.supplier_name, s.contact_person, s.phone, s.email,
                    u.username  AS created_by_username,
                    ru.username AS received_by_username
             FROM " . self::PO_TABLE . " po
             INNER JOIN suppliers s ON s.supplier_id = po.supplier_id
             LEFT  JOIN users u     ON u.user_id      = po.created_by
             LEFT  JOIN users ru    ON ru.user_id     = po.received_by
             WHERE po.po_id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $poId]);
        $po = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$po) {
            return null;
        }

        $items = self::getPurchaseOrderItemsBatch($conn, [(int) $poId]);
        $po['items'] = $items[$poId] ?? [];

        return $po;
    }

    // =========================================================================
    // READ — summary (APCu-cached)
    // =========================================================================

    public static function statusSummary(PDO $conn): array
    {
        $cacheKey = self::CACHE_PREFIX . 'status';

        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($cacheKey, $hit);
            if ($hit && is_array($cached)) {
                return $cached;
            }
        }

        $stmt = $conn->query(
            "SELECT status, COUNT(*) AS total
             FROM " . self::PO_TABLE . "
             GROUP BY status"
        );
        $rows    = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $summary = ['ordered' => 0, 'partial' => 0, 'received' => 0, 'cancelled' => 0];
        foreach ($rows as $row) {
            $s = (string) ($row['status'] ?? '');
            if (isset($summary[$s])) {
                $summary[$s] = (int) ($row['total'] ?? 0);
            }
        }

        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $summary, self::SUMMARY_TTL);
        }

        return $summary;
    }

    public static function bustSummaryCache(): void
    {
        if (function_exists('apcu_delete')) {
            apcu_delete(self::CACHE_PREFIX . 'status');
            apcu_delete(self::LS_CACHE_KEY);
        }
    }

    // =========================================================================
    // READ — suppliers dropdown
    // =========================================================================

    public static function supplierOptions(PDO $conn): array
    {
        self::ensureSchema($conn);
        $stmt = $conn->query(
            "SELECT supplier_id, supplier_name
             FROM suppliers
             WHERE status = 'active'
             ORDER BY supplier_name ASC"
        );
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    // =========================================================================
    // READ — low stock candidates (APCu-cached)
    // =========================================================================

    public static function lowStockCandidates(PDO $conn, int $supplierId = 0): array
    {
        // Only cache when no supplier filter (the full list)
        $useCache = $supplierId === 0;
        $cacheKey = self::LS_CACHE_KEY;

        if ($useCache && function_exists('apcu_fetch')) {
            $cached = apcu_fetch($cacheKey, $hit);
            if ($hit && is_array($cached)) {
                return $cached;
            }
        }

        self::ensureSchema($conn);

        $where  = "p.status = 'active' AND p.reorder_level > 0 AND p.quantity <= p.reorder_level";
        $params = [];

        if ($supplierId > 0) {
            $where          .= ' AND p.supplier_id = :sid';
            $params[':sid']  = $supplierId;
        }

        $stmt = $conn->prepare("
            SELECT
                p.product_id,
                p.product_name,
                p.supplier_id,
                s.supplier_name,
                p.quantity,
                p.reorder_level,
                p.pieces_per_box,
                p.boxes_per_case,
                c.category_name,
                IFNULL(sales_30.total_pieces_sold, 0) AS total_pieces_sold_30d
            FROM products p
            LEFT JOIN suppliers  s ON s.supplier_id  = p.supplier_id
            LEFT JOIN categories c ON c.category_id  = p.category_id
            LEFT JOIN (
                SELECT si.product_id,
                       SUM(
                           GREATEST(COALESCE(si.quantity, 0) - COALESCE(si.returned_quantity, 0), 0)
                           * COALESCE(si.unit_multiplier, 1)
                       ) AS total_pieces_sold
                FROM sale_items si
                INNER JOIN sales sl ON sl.sale_id = si.sale_id
                WHERE COALESCE(sl.status, 'completed') NOT IN ('voided','returned')
                  AND sl.sale_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY si.product_id
            ) sales_30 ON sales_30.product_id = p.product_id
            WHERE {$where}
            ORDER BY s.supplier_name ASC, p.quantity ASC, p.product_name ASC
        ");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_INT);
        }
        $stmt->execute();

        $rows = array_map(static function (array $row): array {
            $daily = ((float) ($row['total_pieces_sold_30d'] ?? 0)) / 30;
            $gap   = max(0, (int) ($row['reorder_level'] ?? 0) - (int) ($row['quantity'] ?? 0));
            $rec   = max($gap, (int) ceil(max($daily * 7, 0)));
            $row['avg_daily_pieces']    = round($daily, 1);
            $row['cover_days']          = $daily > 0 ? round(((int) ($row['quantity'] ?? 0)) / $daily, 1) : null;
            $row['recommended_pieces']  = $rec;
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

        if ($useCache && function_exists('apcu_store')) {
            apcu_store($cacheKey, $rows, self::LOW_STOCK_TTL);
        }

        return $rows;
    }

    // =========================================================================
    // WRITE — create purchase order
    // =========================================================================

    public static function createPurchaseOrder(
        PDO    $conn,
        int    $supplierId,
        array  $items,
        int    $userId,
        string $notes = ''
    ): array {
        self::ensureSchema($conn);

        if ($supplierId <= 0) {
            throw new InvalidArgumentException('Supplier is required.');
        }
        if ($userId <= 0) {
            throw new InvalidArgumentException('Authenticated user is required.');
        }

        $supplier = self::findSupplier($conn, $supplierId);
        if ($supplier === null) {
            throw new RuntimeException('Supplier not found.');
        }

        $normalized = self::normalizeItems($items);
        if ($normalized === []) {
            throw new InvalidArgumentException('Choose at least one product to include in the purchase order.');
        }

        // Validate all products in ONE batch query instead of N getProductById() calls
        $productIds   = array_column($normalized, 'product_id');
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $batchStmt    = $conn->prepare(
            "SELECT product_id, supplier_id FROM products WHERE product_id IN ({$placeholders})"
        );
        $batchStmt->execute($productIds);
        $found = [];
        foreach ($batchStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $found[(int) $row['product_id']] = (int) $row['supplier_id'];
        }

        foreach ($normalized as $item) {
            $pid = (int) $item['product_id'];
            if (!isset($found[$pid])) {
                throw new RuntimeException("Product ID {$pid} does not exist.");
            }
            if ($found[$pid] !== $supplierId) {
                throw new RuntimeException('All selected products must belong to the chosen supplier.');
            }
        }

        $conn->beginTransaction();
        try {
            $conn->prepare(
                "INSERT INTO " . self::PO_TABLE . "
                    (supplier_id, created_by, status, notes, ordered_at)
                 VALUES (:sup, :uid, 'ordered', :notes, NOW())"
            )->execute([
                ':sup'   => $supplierId,
                ':uid'   => $userId,
                ':notes' => self::normalizeNotes($notes),
            ]);

            $poId     = (int) $conn->lastInsertId();
            $poNumber = self::formatPoNumber($poId);

            $conn->prepare(
                "UPDATE " . self::PO_TABLE . " SET po_number = :n WHERE po_id = :id"
            )->execute([':n' => $poNumber, ':id' => $poId]);

            $insItem = $conn->prepare(
                "INSERT INTO " . self::ITEM_TABLE . "
                    (po_id, product_id, ordered_quantity, notes)
                 VALUES (:po, :prod, :qty, :notes)"
            );
            foreach ($normalized as $item) {
                $insItem->execute([
                    ':po'    => $poId,
                    ':prod'  => (int) $item['product_id'],
                    ':qty'   => (int) $item['quantity'],
                    ':notes' => self::normalizeNotes((string) ($item['notes'] ?? '')),
                ]);
            }

            $conn->commit();
            self::bustSummaryCache();

            return [
                'po_id'         => $poId,
                'po_number'     => $poNumber,
                'supplier_name' => (string) $supplier['supplier_name'],
                'item_count'    => count($normalized),
            ];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    // =========================================================================
    // WRITE — receive purchase order
    // =========================================================================

    public static function receivePurchaseOrder(
        PDO    $conn,
        int    $poId,
        array  $receivedItems,
        int    $userId,
        string $notes = ''
    ): array {
        self::ensureSchema($conn);

        if ($poId <= 0) {
            throw new InvalidArgumentException('Purchase order is required.');
        }
        if ($userId <= 0) {
            throw new InvalidArgumentException('Authenticated user is required.');
        }

        $receipts = [];
        foreach ($receivedItems as $poItemId => $qty) {
            $poItemId = (int) $poItemId;
            $qty      = (int) $qty;
            if ($poItemId > 0 && $qty > 0) {
                $receipts[$poItemId] = $qty;
            }
        }
        if ($receipts === []) {
            throw new InvalidArgumentException('Enter at least one received quantity greater than zero.');
        }

        $conn->beginTransaction();
        try {
            $po = self::getPurchaseOrderForUpdate($conn, $poId);
            if ($po === null) {
                throw new RuntimeException('Purchase order not found.');
            }
            if (($po['status'] ?? '') === 'received') {
                throw new RuntimeException('This purchase order has already been fully received.');
            }
            if (($po['status'] ?? '') === 'cancelled') {
                throw new RuntimeException('Cancelled purchase orders cannot be received.');
            }

            $items = self::purchaseOrderItemsForUpdate($conn, $poId);
            if ($items === []) {
                throw new RuntimeException('This purchase order has no items.');
            }

            $updItem = $conn->prepare(
                "UPDATE " . self::ITEM_TABLE . "
                 SET received_quantity = :qty
                 WHERE po_item_id = :id"
            );

            $receivedLines  = 0;
            $receivedPieces = 0;

            foreach ($items as $item) {
                $poItemId  = (int) $item['po_item_id'];
                if (!isset($receipts[$poItemId])) {
                    continue;
                }

                $remaining  = max(0, (int) $item['ordered_quantity'] - (int) $item['received_quantity']);
                $receiveQty = min($receipts[$poItemId], $remaining);
                if ($receiveQty <= 0) {
                    continue;
                }

                ProductController::restockProduct(
                    $conn,
                    (int) $item['product_id'],
                    $receiveQty,
                    $userId,
                    trim('PO ' . (string) ($po['po_number'] ?? self::formatPoNumber($poId)) .
                         ($notes !== '' ? ' | ' . $notes : '')),
                    [
                        'adjustment_type' => 'purchase_receive',
                        'supplier_id'     => (int) ($po['supplier_id'] ?? 0),
                        'reference_type'  => 'purchase_order',
                        'reference_id'    => $poId,
                    ]
                );

                $updItem->execute([
                    ':qty' => (int) $item['received_quantity'] + $receiveQty,
                    ':id'  => $poItemId,
                ]);

                $receivedLines++;
                $receivedPieces += $receiveQty;
            }

            if ($receivedLines === 0) {
                throw new RuntimeException('No valid receivable quantities were submitted.');
            }

            $remStmt = $conn->prepare(
                "SELECT COUNT(*)
                 FROM " . self::ITEM_TABLE . "
                 WHERE po_id = :id
                   AND received_quantity < ordered_quantity"
            );
            $remStmt->execute([':id' => $poId]);
            $newStatus = (int) $remStmt->fetchColumn() === 0 ? 'received' : 'partial';

            $mergedNotes = trim((string) ($po['notes'] ?? ''));
            if ($notes !== '') {
                $mergedNotes = trim($mergedNotes !== '' ? $mergedNotes . ' | ' . $notes : $notes);
            }

            $conn->prepare(
                "UPDATE " . self::PO_TABLE . "
                 SET status      = :status,
                     received_by = :uid,
                     received_at = CASE WHEN :s2 = 'received' THEN NOW() ELSE received_at END,
                     notes       = :notes
                 WHERE po_id = :id"
            )->execute([
                ':status' => $newStatus,
                ':uid'    => $userId,
                ':s2'     => $newStatus,
                ':notes'  => self::normalizeNotes($mergedNotes),
                ':id'     => $poId,
            ]);

            $conn->commit();
            self::bustSummaryCache();

            return [
                'po_id'           => $poId,
                'po_number'       => (string) ($po['po_number'] ?? self::formatPoNumber($poId)),
                'status'          => $newStatus,
                'received_lines'  => $receivedLines,
                'received_pieces' => $receivedPieces,
                'supplier_name'   => (string) ($po['supplier_name'] ?? ''),
            ];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    // =========================================================================
    // WRITE — cancel purchase order
    // =========================================================================

    /**
     * Cancel a purchase order.
     *
     * Rules
     * ─────
     *  • Only 'ordered' or 'partial' orders can be cancelled.
     *    - 'received'  → already fully delivered, cannot undo.
     *    - 'cancelled' → already cancelled, idempotent guard.
     *  • Partial orders (some stock already received) can still be cancelled;
     *    the already-received stock remains in inventory — it was a legitimate
     *    receipt and should not be reversed here. The cancel only closes the
     *    outstanding expectation.
     *  • An optional $reason string is appended to the PO notes for audit trail.
     *  • bustSummaryCache() is called so stat cards reflect the new state.
     *
     * @throws InvalidArgumentException  If poId or userId is invalid.
     * @throws RuntimeException          If PO not found or status prevents cancel.
     * @return array{po_id:int, po_number:string, supplier_name:string, previous_status:string}
     */
    public static function cancelPurchaseOrder(
        PDO    $conn,
        int    $poId,
        int    $userId,
        string $reason = ''
    ): array {
        self::ensureSchema($conn);

        if ($poId <= 0) {
            throw new InvalidArgumentException('Purchase order is required.');
        }
        if ($userId <= 0) {
            throw new InvalidArgumentException('Authenticated user is required.');
        }

        $conn->beginTransaction();
        try {
            $po = self::getPurchaseOrderForUpdate($conn, $poId);

            if ($po === null) {
                throw new RuntimeException('Purchase order not found.');
            }

            $currentStatus = strtolower((string) ($po['status'] ?? ''));

            if ($currentStatus === 'cancelled') {
                throw new RuntimeException('This purchase order is already cancelled.');
            }
            if ($currentStatus === 'received') {
                throw new RuntimeException('Fully received purchase orders cannot be cancelled.');
            }
            if (!in_array($currentStatus, ['ordered', 'partial'], true)) {
                throw new RuntimeException('Only ordered or partially received purchase orders can be cancelled.');
            }

            // Append reason to existing notes for audit trail
            $existingNotes = trim((string) ($po['notes'] ?? ''));
            $cancelNote    = 'CANCELLED by user #' . $userId
                . (trim($reason) !== '' ? ' — Reason: ' . trim($reason) : '');
            $mergedNotes   = $existingNotes !== ''
                ? $existingNotes . ' | ' . $cancelNote
                : $cancelNote;

            $conn->prepare(
                "UPDATE " . self::PO_TABLE . "
                 SET status     = 'cancelled',
                     notes      = :notes,
                     updated_at = NOW()
                 WHERE po_id = :id"
            )->execute([
                ':notes' => self::normalizeNotes($mergedNotes),
                ':id'    => $poId,
            ]);

            $conn->commit();
            self::bustSummaryCache();

            return [
                'po_id'           => $poId,
                'po_number'       => (string) ($po['po_number'] ?? self::formatPoNumber($poId)),
                'supplier_name'   => (string) ($po['supplier_name'] ?? ''),
                'previous_status' => $currentStatus,
            ];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    // =========================================================================
    // PRIVATE — helpers
    // =========================================================================

    private static function normalizeItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $pid = (int) ($item['product_id'] ?? 0);
            $qty = (int) ($item['quantity']   ?? 0);
            if ($pid > 0 && $qty > 0) {
                $out[] = ['product_id' => $pid, 'quantity' => $qty, 'notes' => (string) ($item['notes'] ?? '')];
            }
        }
        return $out;
    }

    private static function findSupplier(PDO $conn, int $id): ?array
    {
        $stmt = $conn->prepare(
            "SELECT supplier_id, supplier_name FROM suppliers WHERE supplier_id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function getPurchaseOrderForUpdate(PDO $conn, int $poId): ?array
    {
        $stmt = $conn->prepare(
            "SELECT po.po_id, po.po_number, po.supplier_id, po.status, po.notes, s.supplier_name
             FROM " . self::PO_TABLE . " po
             INNER JOIN suppliers s ON s.supplier_id = po.supplier_id
             WHERE po.po_id = :id
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->execute([':id' => $poId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function purchaseOrderItemsForUpdate(PDO $conn, int $poId): array
    {
        $stmt = $conn->prepare(
            "SELECT po_item_id, po_id, product_id, ordered_quantity, received_quantity
             FROM " . self::ITEM_TABLE . "
             WHERE po_id = :id
             ORDER BY po_item_id ASC
             FOR UPDATE"
        );
        $stmt->execute([':id' => $poId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private static function normalizeNotes(string $notes): ?string
    {
        $notes = trim(preg_replace('/\s+/', ' ', $notes) ?? '');
        return $notes !== '' ? mb_substr($notes, 0, 1000) : null;
    }

    private static function formatPoNumber(int $poId): string
    {
        return 'PO-' . date('Ymd') . '-' . str_pad((string) $poId, 6, '0', STR_PAD_LEFT);
    }
}