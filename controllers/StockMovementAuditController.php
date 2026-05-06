<?php
declare(strict_types=1);

require_once __DIR__ . '/ProductController.php';

final class StockMovementAuditController
{
    public static function listMovements(PDO $conn, int $limit = 400): array
    {
        ProductController::ensureStockMovementSchema($conn);
        $limit = max(25, min(1000, $limit));

        $stmt = $conn->prepare("
            SELECT
                sal.log_id,
                sal.product_id,
                sal.change_qty,
                sal.current_qty,
                sal.action,
                sal.reference_type,
                sal.reference_id,
                sal.notes,
                sal.user_id,
                sal.timestamp,
                p.product_name,
                p.sku,
                p.quantity AS live_quantity,
                c.category_name,
                s.supplier_name,
                u.first_name,
                u.last_name,
                u.username,
                u.role
            FROM stock_audit_log sal
            INNER JOIN products p ON p.product_id = sal.product_id
            LEFT JOIN categories c ON c.category_id = p.category_id
            LEFT JOIN suppliers s ON s.supplier_id = p.supplier_id
            LEFT JOIN users u ON u.user_id = sal.user_id
            ORDER BY sal.timestamp DESC, sal.log_id DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function productOptions(PDO $conn): array
    {
        $stmt = $conn->query("
            SELECT product_id, product_name, sku
            FROM products
            ORDER BY product_name ASC
            LIMIT 500
        ");

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public static function summary(array $rows): array
    {
        $stockIn = 0;
        $stockOut = 0;
        $sales = 0;
        $returns = 0;
        $products = [];

        foreach ($rows as $row) {
            $change = (int) ($row['change_qty'] ?? 0);
            $action = strtolower((string) ($row['action'] ?? ''));
            $referenceType = strtolower((string) ($row['reference_type'] ?? ''));

            if ($change > 0) {
                $stockIn += $change;
            } elseif ($change < 0) {
                $stockOut += abs($change);
            }

            if ($action === 'sale') {
                $sales += abs($change);
            }

            if ($referenceType === 'sale_return') {
                $returns += $change;
            }

            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId > 0) {
                $products[$productId] = true;
            }
        }

        return [
            'rows' => count($rows),
            'products' => count($products),
            'stock_in' => $stockIn,
            'stock_out' => $stockOut,
            'sales' => $sales,
            'returns' => $returns,
        ];
    }
}
