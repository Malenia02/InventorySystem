<?php
declare(strict_types=1);

require_once __DIR__ . '/ProductController.php';
require_once __DIR__ . '/SaleController.php';

final class PurchaseOrderController
{
    private const PO_TABLE = 'purchase_orders';
    private const ITEM_TABLE = 'purchase_order_items';
    private const ALLOWED_STATUSES = ['draft', 'ordered', 'partial', 'received', 'cancelled'];

    public static function ensureSchema(PDO $conn): void
    {
        ProductController::ensureStockMovementSchema($conn);
        SaleController::ensureReturnSchema($conn);

        $conn->exec("
            CREATE TABLE IF NOT EXISTS " . self::PO_TABLE . " (
                po_id int(11) NOT NULL AUTO_INCREMENT,
                po_number varchar(40) DEFAULT NULL,
                supplier_id int(11) NOT NULL,
                created_by int(11) DEFAULT NULL,
                received_by int(11) DEFAULT NULL,
                status varchar(20) NOT NULL DEFAULT 'ordered',
                notes text DEFAULT NULL,
                ordered_at datetime NOT NULL DEFAULT current_timestamp(),
                received_at datetime DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT current_timestamp(),
                updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (po_id),
                UNIQUE KEY uniq_purchase_orders_number (po_number),
                KEY idx_purchase_orders_supplier (supplier_id),
                KEY idx_purchase_orders_status (status),
                CONSTRAINT purchase_orders_ibfk_1 FOREIGN KEY (supplier_id) REFERENCES suppliers (supplier_id),
                CONSTRAINT purchase_orders_ibfk_2 FOREIGN KEY (created_by) REFERENCES users (user_id) ON DELETE SET NULL,
                CONSTRAINT purchase_orders_ibfk_3 FOREIGN KEY (received_by) REFERENCES users (user_id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        $conn->exec("
            CREATE TABLE IF NOT EXISTS " . self::ITEM_TABLE . " (
                po_item_id int(11) NOT NULL AUTO_INCREMENT,
                po_id int(11) NOT NULL,
                product_id int(11) NOT NULL,
                ordered_quantity int(11) NOT NULL DEFAULT 0,
                received_quantity int(11) NOT NULL DEFAULT 0,
                notes text DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (po_item_id),
                KEY idx_purchase_order_items_po (po_id),
                KEY idx_purchase_order_items_product (product_id),
                CONSTRAINT purchase_order_items_ibfk_1 FOREIGN KEY (po_id) REFERENCES " . self::PO_TABLE . " (po_id) ON DELETE CASCADE,
                CONSTRAINT purchase_order_items_ibfk_2 FOREIGN KEY (product_id) REFERENCES products (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }

    public static function supplierOptions(PDO $conn): array
    {
        self::ensureSchema($conn);
        $stmt = $conn->query("
            SELECT supplier_id, supplier_name, status
            FROM suppliers
            WHERE status = 'active'
            ORDER BY supplier_name ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function lowStockCandidates(PDO $conn, int $supplierId = 0): array
    {
        self::ensureSchema($conn);
        $where = "p.status = 'active' AND p.quantity <= p.reorder_level";
        $params = [];

        if ($supplierId > 0) {
            $where .= ' AND p.supplier_id = :supplier_id';
            $params[':supplier_id'] = $supplierId;
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
            LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN (
                SELECT
                    si.product_id,
                    SUM(GREATEST(COALESCE(si.quantity, 0) - COALESCE(si.returned_quantity, 0), 0) * COALESCE(si.unit_multiplier, 1)) AS total_pieces_sold
                FROM sale_items si
                INNER JOIN sales sl ON sl.sale_id = si.sale_id
                WHERE COALESCE(sl.status, 'completed') NOT IN ('voided', 'returned')
                  AND sl.sale_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY si.product_id
            ) sales_30 ON sales_30.product_id = p.product_id
            WHERE {$where}
            ORDER BY s.supplier_name ASC, p.quantity ASC, p.product_name ASC
        ");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            $dailyPieces = ((float) ($row['total_pieces_sold_30d'] ?? 0)) / 30;
            $recommendedPieces = max(
                (int) ($row['reorder_level'] ?? 0) - (int) ($row['quantity'] ?? 0),
                (int) ceil(max($dailyPieces * 7, 0))
            );
            $row['avg_daily_pieces'] = round($dailyPieces, 1);
            $row['cover_days'] = $dailyPieces > 0 ? round(((int) ($row['quantity'] ?? 0)) / $dailyPieces, 1) : null;
            $row['recommended_pieces'] = max(0, $recommendedPieces);
            return $row;
        }, $rows);
    }

    public static function createPurchaseOrder(PDO $conn, int $supplierId, array $items, int $userId, string $notes = ''): array
    {
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

        $normalizedItems = self::normalizeItems($items);
        if ($normalizedItems === []) {
            throw new InvalidArgumentException('Choose at least one product to include in the purchase order.');
        }

        $conn->beginTransaction();
        try {
            $insertPo = $conn->prepare("
                INSERT INTO " . self::PO_TABLE . " (supplier_id, created_by, status, notes, ordered_at)
                VALUES (:supplier_id, :created_by, 'ordered', :notes, NOW())
            ");
            $insertPo->execute([
                ':supplier_id' => $supplierId,
                ':created_by' => $userId,
                ':notes' => self::normalizeNotes($notes),
            ]);

            $poId = (int) $conn->lastInsertId();
            $poNumber = self::formatPoNumber($poId);

            $updatePo = $conn->prepare("UPDATE " . self::PO_TABLE . " SET po_number = :po_number WHERE po_id = :po_id");
            $updatePo->execute([
                ':po_number' => $poNumber,
                ':po_id' => $poId,
            ]);

            $insertItem = $conn->prepare("
                INSERT INTO " . self::ITEM_TABLE . " (po_id, product_id, ordered_quantity, notes)
                VALUES (:po_id, :product_id, :ordered_quantity, :notes)
            ");

            foreach ($normalizedItems as $item) {
                $product = ProductController::getProductById($conn, (int) $item['product_id']);
                if ($product === null) {
                    throw new RuntimeException('One of the selected products no longer exists.');
                }
                if ((int) ($product['supplier_id'] ?? 0) !== $supplierId) {
                    throw new RuntimeException('All selected products must belong to the chosen supplier.');
                }

                $insertItem->execute([
                    ':po_id' => $poId,
                    ':product_id' => (int) $item['product_id'],
                    ':ordered_quantity' => (int) $item['quantity'],
                    ':notes' => self::normalizeNotes((string) ($item['notes'] ?? '')),
                ]);
            }

            $conn->commit();

            return [
                'po_id' => $poId,
                'po_number' => $poNumber,
                'supplier_name' => (string) $supplier['supplier_name'],
                'item_count' => count($normalizedItems),
            ];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    public static function receivePurchaseOrder(PDO $conn, int $poId, array $receivedItems, int $userId, string $notes = ''): array
    {
        self::ensureSchema($conn);
        if ($poId <= 0) {
            throw new InvalidArgumentException('Purchase order is required.');
        }
        if ($userId <= 0) {
            throw new InvalidArgumentException('Authenticated user is required.');
        }

        $normalizedReceipts = [];
        foreach ($receivedItems as $poItemId => $qty) {
            $poItemId = (int) $poItemId;
            $qty = (int) $qty;
            if ($poItemId > 0 && $qty > 0) {
                $normalizedReceipts[$poItemId] = $qty;
            }
        }
        if ($normalizedReceipts === []) {
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

            $updateItem = $conn->prepare("
                UPDATE " . self::ITEM_TABLE . "
                SET received_quantity = :received_quantity
                WHERE po_item_id = :po_item_id
            ");

            $receivedLines = 0;
            $receivedPieces = 0;

            foreach ($items as $item) {
                $poItemId = (int) $item['po_item_id'];
                if (!isset($normalizedReceipts[$poItemId])) {
                    continue;
                }

                $remaining = max(0, (int) $item['ordered_quantity'] - (int) $item['received_quantity']);
                $receiveQty = min($normalizedReceipts[$poItemId], $remaining);
                if ($receiveQty <= 0) {
                    continue;
                }

                ProductController::restockProduct(
                    $conn,
                    (int) $item['product_id'],
                    $receiveQty,
                    $userId,
                    trim('PO ' . (string) ($po['po_number'] ?? self::formatPoNumber($poId)) . ($notes !== '' ? ' | ' . $notes : '')),
                    [
                        'adjustment_type' => 'purchase_receive',
                        'supplier_id' => (int) ($po['supplier_id'] ?? 0),
                        'reference_type' => 'purchase_order',
                        'reference_id' => $poId,
                    ]
                );

                $updateItem->execute([
                    ':received_quantity' => (int) $item['received_quantity'] + $receiveQty,
                    ':po_item_id' => $poItemId,
                ]);

                $receivedLines++;
                $receivedPieces += $receiveQty;
            }

            if ($receivedLines === 0) {
                throw new RuntimeException('No valid receivable quantities were submitted.');
            }

            $remainingStmt = $conn->prepare("
                SELECT COUNT(*)
                FROM " . self::ITEM_TABLE . "
                WHERE po_id = :po_id
                  AND received_quantity < ordered_quantity
            ");
            $remainingStmt->execute([':po_id' => $poId]);
            $remainingCount = (int) $remainingStmt->fetchColumn();
            $newStatus = $remainingCount === 0 ? 'received' : 'partial';

            $updatePo = $conn->prepare("
                UPDATE " . self::PO_TABLE . "
                SET status = :status,
                    received_by = :received_by,
                    received_at = CASE WHEN :received_status = 'received' THEN NOW() ELSE received_at END,
                    notes = :notes
                WHERE po_id = :po_id
            ");
            $mergedNotes = trim((string) ($po['notes'] ?? ''));
            if ($notes !== '') {
                $mergedNotes = trim($mergedNotes !== '' ? $mergedNotes . ' | ' . $notes : $notes);
            }
            $updatePo->execute([
                ':status' => $newStatus,
                ':received_status' => $newStatus,
                ':received_by' => $userId,
                ':notes' => self::normalizeNotes($mergedNotes),
                ':po_id' => $poId,
            ]);

            $conn->commit();

            return [
                'po_id' => $poId,
                'po_number' => (string) ($po['po_number'] ?? self::formatPoNumber($poId)),
                'status' => $newStatus,
                'received_lines' => $receivedLines,
                'received_pieces' => $receivedPieces,
                'supplier_name' => (string) ($po['supplier_name'] ?? ''),
            ];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    public static function listPurchaseOrders(PDO $conn, int $limit = 30): array
    {
        self::ensureSchema($conn);
        $limit = max(1, min(100, $limit));
        $stmt = $conn->prepare("
            SELECT
                po.po_id,
                po.po_number,
                po.status,
                po.notes,
                po.ordered_at,
                po.received_at,
                po.created_at,
                s.supplier_name,
                u.username AS created_by_username,
                SUM(poi.ordered_quantity) AS ordered_total,
                SUM(poi.received_quantity) AS received_total,
                COUNT(poi.po_item_id) AS item_lines
            FROM " . self::PO_TABLE . " po
            INNER JOIN suppliers s ON s.supplier_id = po.supplier_id
            LEFT JOIN users u ON u.user_id = po.created_by
            LEFT JOIN " . self::ITEM_TABLE . " poi ON poi.po_id = po.po_id
            GROUP BY
                po.po_id, po.po_number, po.status, po.notes, po.ordered_at, po.received_at, po.created_at,
                s.supplier_name, u.username
            ORDER BY po.created_at DESC, po.po_id DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function getPurchaseOrder(PDO $conn, int $poId): ?array
    {
        self::ensureSchema($conn);
        $stmt = $conn->prepare("
            SELECT
                po.*,
                s.supplier_name,
                s.contact_person,
                s.phone,
                s.email,
                u.username AS created_by_username,
                ru.username AS received_by_username
            FROM " . self::PO_TABLE . " po
            INNER JOIN suppliers s ON s.supplier_id = po.supplier_id
            LEFT JOIN users u ON u.user_id = po.created_by
            LEFT JOIN users ru ON ru.user_id = po.received_by
            WHERE po.po_id = :po_id
            LIMIT 1
        ");
        $stmt->execute([':po_id' => $poId]);
        $po = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$po) {
            return null;
        }

        $itemStmt = $conn->prepare("
            SELECT
                poi.po_item_id,
                poi.product_id,
                poi.ordered_quantity,
                poi.received_quantity,
                poi.notes,
                p.product_name,
                p.sku,
                p.quantity AS current_stock,
                c.category_name
            FROM " . self::ITEM_TABLE . " poi
            INNER JOIN products p ON p.product_id = poi.product_id
            LEFT JOIN categories c ON c.category_id = p.category_id
            WHERE poi.po_id = :po_id
            ORDER BY p.product_name ASC
        ");
        $itemStmt->execute([':po_id' => $poId]);
        $po['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $po;
    }

    public static function statusSummary(PDO $conn): array
    {
        self::ensureSchema($conn);
        $stmt = $conn->query("
            SELECT status, COUNT(*) AS total
            FROM " . self::PO_TABLE . "
            GROUP BY status
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $summary = [
            'ordered' => 0,
            'partial' => 0,
            'received' => 0,
            'cancelled' => 0,
        ];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (isset($summary[$status])) {
                $summary[$status] = (int) ($row['total'] ?? 0);
            }
        }
        return $summary;
    }

    private static function normalizeItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $productId = (int) ($item['product_id'] ?? 0);
            $qty = (int) ($item['quantity'] ?? 0);
            if ($productId <= 0 || $qty <= 0) {
                continue;
            }
            $normalized[] = [
                'product_id' => $productId,
                'quantity' => $qty,
                'notes' => (string) ($item['notes'] ?? ''),
            ];
        }
        return $normalized;
    }

    private static function findSupplier(PDO $conn, int $supplierId): ?array
    {
        $stmt = $conn->prepare("
            SELECT supplier_id, supplier_name
            FROM suppliers
            WHERE supplier_id = :supplier_id
            LIMIT 1
        ");
        $stmt->execute([':supplier_id' => $supplierId]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
        return $supplier ?: null;
    }

    private static function getPurchaseOrderForUpdate(PDO $conn, int $poId): ?array
    {
        $stmt = $conn->prepare("
            SELECT po.po_id, po.po_number, po.supplier_id, po.status, po.notes, s.supplier_name
            FROM " . self::PO_TABLE . " po
            INNER JOIN suppliers s ON s.supplier_id = po.supplier_id
            WHERE po.po_id = :po_id
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([':po_id' => $poId]);
        $po = $stmt->fetch(PDO::FETCH_ASSOC);
        return $po ?: null;
    }

    private static function purchaseOrderItemsForUpdate(PDO $conn, int $poId): array
    {
        $stmt = $conn->prepare("
            SELECT po_item_id, po_id, product_id, ordered_quantity, received_quantity
            FROM " . self::ITEM_TABLE . "
            WHERE po_id = :po_id
            ORDER BY po_item_id ASC
            FOR UPDATE
        ");
        $stmt->execute([':po_id' => $poId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private static function normalizeNotes(string $notes): ?string
    {
        $notes = trim(preg_replace('/\s+/', ' ', $notes) ?? '');
        if ($notes === '') {
            return null;
        }
        return mb_substr($notes, 0, 1000);
    }

    private static function formatPoNumber(int $poId): string
    {
        return 'PO-' . date('Ymd') . '-' . str_pad((string) $poId, 6, '0', STR_PAD_LEFT);
    }
}
