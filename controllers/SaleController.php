<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthController.php';

final class SaleController
{
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
        self::ensureVoidSchema($conn);

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
            $conn->exec($alterSql);
        }
    }

    private static function ensureIndex(PDO $conn, string $table, string $index, string $createSql): void
    {
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
