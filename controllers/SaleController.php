<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthController.php';

final class SaleController
{
    public static function ensureReturnSchema(PDO $conn): void
    {
        self::ensureVoidSchema($conn);
        self::ensureColumn($conn, 'sale_items', 'returned_quantity', 'ALTER TABLE sale_items ADD COLUMN returned_quantity int(11) NOT NULL DEFAULT 0 AFTER quantity');
        if (!app_has_table($conn, 'sale_item_returns') && !app_runtime_schema_changes_allowed()) {
            app_fail_runtime_schema_change('sale_item_returns');
        }
        $conn->exec("
            CREATE TABLE IF NOT EXISTS sale_item_returns (
                return_id int(11) NOT NULL AUTO_INCREMENT,
                sale_id int(11) NOT NULL,
                sale_item_id int(11) NOT NULL,
                product_id int(11) NOT NULL,
                quantity int(11) NOT NULL,
                unit_multiplier int(11) NOT NULL DEFAULT 1,
                unit_price decimal(10,2) NOT NULL DEFAULT 0.00,
                tax_amount decimal(10,2) NOT NULL DEFAULT 0.00,
                discount_amount decimal(10,2) NOT NULL DEFAULT 0.00,
                reason text DEFAULT NULL,
                user_id int(11) DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (return_id),
                KEY idx_sale_item_returns_sale_id (sale_id),
                KEY idx_sale_item_returns_sale_item_id (sale_item_id),
                KEY idx_sale_item_returns_product_id (product_id),
                CONSTRAINT sale_item_returns_ibfk_1 FOREIGN KEY (sale_id) REFERENCES sales (sale_id) ON DELETE CASCADE,
                CONSTRAINT sale_item_returns_ibfk_2 FOREIGN KEY (sale_item_id) REFERENCES sale_items (sale_item_id) ON DELETE CASCADE,
                CONSTRAINT sale_item_returns_ibfk_3 FOREIGN KEY (product_id) REFERENCES products (product_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }

    public static function ensureVoidSchema(PDO $conn): void
    {
        self::ensureColumn($conn, 'sales', 'status', "ALTER TABLE sales ADD COLUMN status varchar(20) NOT NULL DEFAULT 'completed' AFTER user_id");
        self::ensureColumn($conn, 'sales', 'voided_at', 'ALTER TABLE sales ADD COLUMN voided_at datetime DEFAULT NULL AFTER status');
        self::ensureColumn($conn, 'sales', 'voided_by', 'ALTER TABLE sales ADD COLUMN voided_by int(11) DEFAULT NULL AFTER voided_at');
        self::ensureColumn($conn, 'sales', 'void_reason', 'ALTER TABLE sales ADD COLUMN void_reason text DEFAULT NULL AFTER voided_by');
        self::ensureIndex($conn, 'sales', 'idx_sales_status', 'CREATE INDEX idx_sales_status ON sales (status)');
    }

    public static function transactionNumber(int $saleId, string $saleDate): string
    {
        return 'SALE-' . date('Ymd', strtotime($saleDate)) . '-' . str_pad((string) $saleId, 6, '0', STR_PAD_LEFT);
    }

    public static function voidSale(PDO $conn, int $saleId, int $adminId, string $reason, array $logConfig): array
    {
        self::ensureReturnSchema($conn);

        $reason = trim(preg_replace('/\s+/', ' ', $reason) ?? '');
        if ($saleId <= 0) {
            throw new InvalidArgumentException('Invalid sale ID.');
        }

        if ($adminId <= 0) {
            throw new RuntimeException('Only an authenticated admin can void a sale.');
        }

        if (mb_strlen($reason) < 5) {
            throw new InvalidArgumentException('Please enter a clear reason for voiding this sale.');
        }

        if (mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('Void reason must not exceed 500 characters.');
        }

        $conn->beginTransaction();
        try {
            $saleStmt = $conn->prepare("
                SELECT sale_id, sale_date, total_amount, payment_method, user_id, status
                FROM sales
                WHERE sale_id = :sale_id
                FOR UPDATE
            ");
            $saleStmt->execute([':sale_id' => $saleId]);
            $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);

            if (!$sale) {
                throw new RuntimeException('Sale not found.');
            }

            if ((string) ($sale['status'] ?? 'completed') === 'voided') {
                throw new RuntimeException('This sale has already been voided.');
            }

            $itemsStmt = $conn->prepare("
                SELECT
                    si.product_id,
                    si.quantity,
                    COALESCE(si.unit_multiplier, 1) AS unit_multiplier,
                    p.product_name,
                    p.quantity AS current_qty
                FROM sale_items si
                INNER JOIN products p ON p.product_id = si.product_id
                WHERE si.sale_id = :sale_id
                FOR UPDATE
            ");
            $itemsStmt->execute([':sale_id' => $saleId]);
            $itemRows = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $items = [];

            foreach ($itemRows as $itemRow) {
                $productId = (int) $itemRow['product_id'];
                if (!isset($items[$productId])) {
                    $items[$productId] = [
                        'product_id' => $productId,
                        'product_name' => (string) ($itemRow['product_name'] ?? ''),
                        'current_qty' => (int) ($itemRow['current_qty'] ?? 0),
                        'restore_qty' => 0,
                    ];
                }

                $items[$productId]['restore_qty'] += max(1, (int) $itemRow['quantity']) * max(1, (int) $itemRow['unit_multiplier']);
            }

            if ($items === []) {
                throw new RuntimeException('This sale has no items to restore.');
            }

            $restoreStmt = $conn->prepare("
                UPDATE products
                SET quantity = quantity + :restore_qty
                WHERE product_id = :product_id
            ");
            $auditStmt = $conn->prepare("
                INSERT INTO stock_audit_log
                    (product_id, change_qty, current_qty, action, reference_type, reference_id, notes, user_id, timestamp)
                VALUES
                    (:product_id, :change_qty, :current_qty, 'manual_adjust', 'sale_void', :reference_id, :notes, :user_id, NOW())
            ");

            $transactionNo = self::transactionNumber((int) $sale['sale_id'], (string) $sale['sale_date']);
            $restoredPieces = 0;

            foreach ($items as $item) {
                $productId = (int) $item['product_id'];
                $pieces = max(1, (int) $item['restore_qty']);
                $newQty = (int) $item['current_qty'] + $pieces;
                $restoredPieces += $pieces;

                $restoreStmt->execute([
                    ':restore_qty' => $pieces,
                    ':product_id' => $productId,
                ]);

                $auditStmt->execute([
                    ':product_id' => $productId,
                    ':change_qty' => $pieces,
                    ':current_qty' => $newQty,
                    ':reference_id' => $saleId,
                    ':notes' => sprintf('Voided %s | Restored stock | Reason: %s', $transactionNo, $reason),
                    ':user_id' => $adminId,
                ]);
            }

            $updateStmt = $conn->prepare("
                UPDATE sales
                SET status = 'voided',
                    voided_at = NOW(),
                    voided_by = :voided_by,
                    void_reason = :void_reason
                WHERE sale_id = :sale_id
            ");
            $updateStmt->execute([
                ':voided_by' => $adminId,
                ':void_reason' => $reason,
                ':sale_id' => $saleId,
            ]);

            $conn->commit();

            AuthController::logActivity(
                $conn,
                $logConfig,
                $adminId,
                'sale_void',
                sprintf('Voided %s and restored %d piece(s). Reason: %s', $transactionNo, $restoredPieces, $reason),
                'sale',
                $saleId,
                'warning'
            );

            return [
                'sale_id' => $saleId,
                'transaction_no' => $transactionNo,
                'restored_pieces' => $restoredPieces,
                'total_amount' => (float) ($sale['total_amount'] ?? 0),
                'cashier_id' => (int) ($sale['user_id'] ?? 0),
            ];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            throw $e;
        }
    }

    public static function returnSaleItem(PDO $conn, int $saleId, int $saleItemId, int $returnQty, int $adminId, string $reason, array $logConfig): array
    {
        self::ensureReturnSchema($conn);

        $reason = trim(preg_replace('/\s+/', ' ', $reason) ?? '');
        if ($saleId <= 0 || $saleItemId <= 0) {
            throw new InvalidArgumentException('Invalid sale item request.');
        }
        if ($adminId <= 0) {
            throw new RuntimeException('Only an authenticated admin can process a return.');
        }
        if ($returnQty <= 0) {
            throw new InvalidArgumentException('Return quantity must be greater than zero.');
        }
        if (mb_strlen($reason) < 5) {
            throw new InvalidArgumentException('Please enter a clear reason for the return.');
        }
        if (mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('Return reason must not exceed 500 characters.');
        }

        $vatRate = 0.0;
        if (class_exists('PosConfigController')) {
            $vatRate = PosConfigController::taxRate($conn) / 100;
        }

        $conn->beginTransaction();
        try {
            $saleStmt = $conn->prepare("
                SELECT sale_id, sale_date, total_amount, tax, discount, user_id, payment_method, status
                FROM sales
                WHERE sale_id = :sale_id
                FOR UPDATE
            ");
            $saleStmt->execute([':sale_id' => $saleId]);
            $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);

            if (!$sale) {
                throw new RuntimeException('Sale not found.');
            }

            $saleStatus = strtolower((string) ($sale['status'] ?? 'completed'));
            if ($saleStatus === 'voided') {
                throw new RuntimeException('Voided sales cannot be returned.');
            }

            $itemStmt = $conn->prepare("
                SELECT
                    si.sale_item_id,
                    si.sale_id,
                    si.product_id,
                    si.quantity,
                    si.returned_quantity,
                    si.unit_type,
                    COALESCE(si.unit_multiplier, 1) AS unit_multiplier,
                    si.unit_price,
                    p.product_name,
                    p.quantity AS current_stock,
                    p.vatable
                FROM sale_items si
                INNER JOIN products p ON p.product_id = si.product_id
                WHERE si.sale_item_id = :sale_item_id
                  AND si.sale_id = :sale_id
                LIMIT 1
                FOR UPDATE
            ");
            $itemStmt->execute([
                ':sale_item_id' => $saleItemId,
                ':sale_id' => $saleId,
            ]);
            $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                throw new RuntimeException('Sale item not found.');
            }

            $availableToReturn = max(0, (int) $item['quantity'] - (int) ($item['returned_quantity'] ?? 0));
            if ($returnQty > $availableToReturn) {
                throw new RuntimeException('Return quantity exceeds the remaining quantity for this sale item.');
            }

            $remainingSubtotalStmt = $conn->prepare("
                SELECT IFNULL(SUM(si.unit_price * (si.quantity - COALESCE(si.returned_quantity, 0))), 0)
                FROM sale_items si
                WHERE si.sale_id = :sale_id
            ");
            $remainingSubtotalStmt->execute([':sale_id' => $saleId]);
            $remainingSubtotal = (float) $remainingSubtotalStmt->fetchColumn();

            $returnSubtotal = round((float) $item['unit_price'] * $returnQty, 2);
            $returnTax = !empty($item['vatable']) ? round($returnSubtotal * $vatRate, 2) : 0.0;
            $remainingDiscount = (float) ($sale['discount'] ?? 0);
            $returnDiscount = $remainingSubtotal > 0
                ? round($remainingDiscount * ($returnSubtotal / $remainingSubtotal), 2)
                : 0.0;
            $returnDiscount = min($returnDiscount, $remainingDiscount);

            $returnBaseQty = $returnQty * max(1, (int) $item['unit_multiplier']);
            $newReturnedQty = (int) ($item['returned_quantity'] ?? 0) + $returnQty;
            $newProductQty = (int) ($item['current_stock'] ?? 0) + $returnBaseQty;

            $updateSaleItemStmt = $conn->prepare("
                UPDATE sale_items
                SET returned_quantity = :returned_quantity
                WHERE sale_item_id = :sale_item_id
            ");
            $updateSaleItemStmt->execute([
                ':returned_quantity' => $newReturnedQty,
                ':sale_item_id' => $saleItemId,
            ]);

            $restoreProductStmt = $conn->prepare("
                UPDATE products
                SET quantity = quantity + :restore_qty
                WHERE product_id = :product_id
            ");
            $restoreProductStmt->execute([
                ':restore_qty' => $returnBaseQty,
                ':product_id' => (int) $item['product_id'],
            ]);

            $returnInsertStmt = $conn->prepare("
                INSERT INTO sale_item_returns
                    (sale_id, sale_item_id, product_id, quantity, unit_multiplier, unit_price, tax_amount, discount_amount, reason, user_id, created_at)
                VALUES
                    (:sale_id, :sale_item_id, :product_id, :quantity, :unit_multiplier, :unit_price, :tax_amount, :discount_amount, :reason, :user_id, NOW())
            ");
            $returnInsertStmt->execute([
                ':sale_id' => $saleId,
                ':sale_item_id' => $saleItemId,
                ':product_id' => (int) $item['product_id'],
                ':quantity' => $returnQty,
                ':unit_multiplier' => (int) $item['unit_multiplier'],
                ':unit_price' => (float) $item['unit_price'],
                ':tax_amount' => $returnTax,
                ':discount_amount' => $returnDiscount,
                ':reason' => $reason,
                ':user_id' => $adminId,
            ]);

            $remainingItemCountStmt = $conn->prepare("
                SELECT COUNT(*)
                FROM sale_items
                WHERE sale_id = :sale_id
                  AND quantity > COALESCE(returned_quantity, 0)
            ");
            $remainingItemCountStmt->execute([':sale_id' => $saleId]);
            $remainingItemCount = (int) $remainingItemCountStmt->fetchColumn();

            $newSaleStatus = $remainingItemCount === 0 ? 'returned' : 'partial_returned';
            $newTotalAmount = max(0.0, round((float) ($sale['total_amount'] ?? 0) - $returnSubtotal - $returnTax, 2));
            $newTax = max(0.0, round((float) ($sale['tax'] ?? 0) - $returnTax, 2));
            $newDiscount = max(0.0, round((float) ($sale['discount'] ?? 0) - $returnDiscount, 2));

            $updateSaleStmt = $conn->prepare("
                UPDATE sales
                SET total_amount = :total_amount,
                    tax = :tax,
                    discount = :discount,
                    status = :status
                WHERE sale_id = :sale_id
            ");
            $updateSaleStmt->execute([
                ':total_amount' => $newTotalAmount,
                ':tax' => $newTax,
                ':discount' => $newDiscount,
                ':status' => $newSaleStatus,
                ':sale_id' => $saleId,
            ]);

            $stockAuditStmt = $conn->prepare("
                INSERT INTO stock_audit_log
                    (product_id, change_qty, current_qty, action, reference_type, reference_id, notes, user_id, timestamp)
                VALUES
                    (:product_id, :change_qty, :current_qty, 'manual_adjust', 'sale_return', :reference_id, :notes, :user_id, NOW())
            ");

            $transactionNo = self::transactionNumber((int) $sale['sale_id'], (string) $sale['sale_date']);
            $stockAuditStmt->execute([
                ':product_id' => (int) $item['product_id'],
                ':change_qty' => $returnBaseQty,
                ':current_qty' => $newProductQty,
                ':reference_id' => $saleId,
                ':notes' => sprintf(
                    'Returned from %s | %s x%d | Reason: %s',
                    $transactionNo,
                    (string) ($item['unit_type'] ?? 'piece'),
                    $returnQty,
                    $reason
                ),
                ':user_id' => $adminId,
            ]);

            $conn->commit();

            AuthController::logActivity(
                $conn,
                $logConfig,
                $adminId,
                'sale_item_return',
                sprintf(
                    'Returned %d %s of %s from %s. Reason: %s',
                    $returnQty,
                    (string) ($item['unit_type'] ?? 'piece'),
                    (string) ($item['product_name'] ?? 'product'),
                    $transactionNo,
                    $reason
                ),
                'sale',
                $saleId,
                'warning'
            );

            return [
                'sale_id' => $saleId,
                'sale_item_id' => $saleItemId,
                'transaction_no' => $transactionNo,
                'status' => $newSaleStatus,
                'returned_quantity' => $returnQty,
                'returned_pieces' => $returnBaseQty,
                'product_name' => (string) ($item['product_name'] ?? 'Product'),
                'return_amount' => round($returnSubtotal + $returnTax, 2),
                'cashier_id' => (int) ($sale['user_id'] ?? 0),
            ];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            throw $e;
        }
    }

    private static function ensureColumn(PDO $conn, string $table, string $column, string $alterSql): void
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = :table
              AND column_name = :column
        ");
        $stmt->execute([
            ':table' => $table,
            ':column' => $column,
        ]);

        if ((int) $stmt->fetchColumn() === 0) {
            if (!app_runtime_schema_changes_allowed()) {
                app_fail_runtime_schema_change($table . '.' . $column);
            }
            $conn->exec($alterSql);
        }
    }

    private static function ensureIndex(PDO $conn, string $table, string $index, string $createSql): void
    {
        if (function_exists('app_runtime_schema_changes_allowed') && !app_runtime_schema_changes_allowed()) {
            return;
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = :table
              AND index_name = :index_name
        ");
        $stmt->execute([
            ':table' => $table,
            ':index_name' => $index,
        ]);

        if ((int) $stmt->fetchColumn() === 0) {
            $conn->exec($createSql);
        }
    }
}
