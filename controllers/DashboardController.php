<?php
/**
 * controllers/DashboardController.php
 * All dashboard data queries — sales, revenue, stock, activity.
 */

if (php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(403);
    exit('Access denied.');
}

class DashboardController
{
    // ================================================================
    //  SALES COUNT
    //  $period: 'today' | 'month' | 'year'
    // ================================================================
    public static function salesCount(PDO $conn, string $period = 'today'): int
    {
        $where = self::periodWhere('sale_date', $period);
        $stmt  = $conn->prepare("SELECT COUNT(*) FROM sales WHERE {$where}");
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    // ================================================================
    //  REVENUE
    // ================================================================
    public static function revenue(PDO $conn, string $period = 'month'): float
    {
        $where = self::periodWhere('sale_date', $period);
        $stmt  = $conn->prepare("SELECT IFNULL(SUM(total_amount), 0) FROM sales WHERE {$where}");
        $stmt->execute();
        return (float) $stmt->fetchColumn();
    }

    // ================================================================
    //  SALES CHANGE % vs previous period
    // ================================================================
    public static function salesChange(PDO $conn, string $period = 'today'): array
    {
        $current  = self::salesCount($conn, $period);
        $previous = self::salesCountPrevious($conn, $period);

        $change    = 0;
        $direction = 'neutral';

        if ($previous > 0) {
            $change = round((($current - $previous) / $previous) * 100, 1);
            $direction = $change >= 0 ? 'up' : 'down';
        }

        return [
            'current'   => $current,
            'previous'  => $previous,
            'change'    => abs($change),
            'direction' => $direction,
        ];
    }

    // ================================================================
    //  REVENUE CHANGE % vs previous period
    // ================================================================
    public static function revenueChange(PDO $conn, string $period = 'month'): array
    {
        $current  = self::revenue($conn, $period);
        $previous = self::revenuePrevious($conn, $period);

        $change    = 0;
        $direction = 'neutral';

        if ($previous > 0) {
            $change = round((($current - $previous) / $previous) * 100, 1);
            $direction = $change >= 0 ? 'up' : 'down';
        }

        return [
            'current'   => $current,
            'previous'  => $previous,
            'change'    => abs($change),
            'direction' => $direction,
        ];
    }

    // ================================================================
    //  TOTAL PRODUCTS
    // ================================================================
    public static function totalProducts(PDO $conn): int
    {
        $stmt = $conn->query("SELECT COUNT(*) FROM products WHERE status = 'active'");
        return (int) $stmt->fetchColumn();
    }

    // ================================================================
    //  LOW STOCK PRODUCTS
    //  Products where quantity <= reorder_level
    // ================================================================
    public static function lowStockProducts(PDO $conn, int $limit = 5): array
    {
        $stmt = $conn->prepare("
            SELECT p.product_id, p.product_name, p.quantity,
                   p.reorder_level, p.photo,
                   c.category_name
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            WHERE p.status = 'active'
              AND p.quantity <= p.reorder_level
            ORDER BY p.quantity ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ================================================================
    //  OUT OF STOCK COUNT
    // ================================================================
    public static function outOfStockCount(PDO $conn): int
    {
        $stmt = $conn->query("
            SELECT COUNT(*) FROM products
            WHERE status = 'active' AND quantity = 0
        ");
        return (int) $stmt->fetchColumn();
    }

    // ================================================================
    //  TOP SELLING PRODUCTS
    // ================================================================
    public static function topSellingProducts(PDO $conn, string $period = 'month', int $limit = 5): array
    {
        $where = self::periodWhere('s.sale_date', $period);
        $stmt  = $conn->prepare("
            SELECT p.product_id,
                   p.product_name,
                   p.photo,
                   p.price,
                   SUM(si.quantity)               AS total_sold,
                   SUM(si.quantity * si.unit_price) AS total_revenue
            FROM sale_items si
            JOIN sales   s ON si.sale_id    = s.sale_id
            JOIN products p ON si.product_id = p.product_id
            WHERE {$where}
            GROUP BY si.product_id
            ORDER BY total_sold DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ================================================================
    //  RECENT SALES
    // ================================================================
    public static function recentSales(PDO $conn, int $limit = 10, string $period = 'month'): array
    {
        $where = self::periodWhere('s.sale_date', $period);
        $stmt  = $conn->prepare("
            SELECT s.sale_id,
                   s.total_amount,
                   s.payment_method,
                   s.sale_date,
                   u.first_name,
                   u.last_name,
                   COUNT(si.sale_item_id) AS item_count
            FROM sales s
            LEFT JOIN users      u  ON s.user_id  = u.user_id
            LEFT JOIN sale_items si ON s.sale_id  = si.sale_id
            WHERE {$where}
            GROUP BY s.sale_id
            ORDER BY s.sale_date DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ================================================================
    //  SALES CHART DATA
    //  today → last 24 hours by hour
    //  month → last 30 days
    //  year  → last 12 months
    // ================================================================
    public static function salesChartData(PDO $conn, string $period = 'month'): array
    {
        if ($period === 'today') {
            // Last 24 hours grouped by hour
            $stmt = $conn->query("
                SELECT
                    DATE_FORMAT(sale_date, '%Y-%m-%d %H:00') AS day,
                    COUNT(*)                                  AS sales_count,
                    IFNULL(SUM(total_amount), 0)              AS revenue
                FROM sales
                WHERE sale_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY DATE_FORMAT(sale_date, '%Y-%m-%d %H:00')
                ORDER BY day ASC
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($period === 'year') {
            // Last 12 months grouped by month
            $stmt = $conn->query("
                SELECT
                    DATE_FORMAT(sale_date, '%Y-%m-01') AS day,
                    COUNT(*)                           AS sales_count,
                    IFNULL(SUM(total_amount), 0)       AS revenue
                FROM sales
                WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
                GROUP BY DATE_FORMAT(sale_date, '%Y-%m')
                ORDER BY day ASC
            ");
            $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result = [];
            for ($i = 11; $i >= 0; $i--) {
                $date          = date('Y-m-01', strtotime("-{$i} months"));
                $result[$date] = ['day' => $date, 'sales_count' => 0, 'revenue' => 0];
            }
            foreach ($rows as $row) {
                $result[$row['day']] = $row;
            }
            return array_values($result);
        }

        // Default: month — last 30 days
        $stmt = $conn->query("
            SELECT
                DATE(sale_date)              AS day,
                COUNT(*)                     AS sales_count,
                IFNULL(SUM(total_amount), 0) AS revenue
            FROM sales
            WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
            GROUP BY DATE(sale_date)
            ORDER BY day ASC
        ");
        $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        for ($i = 29; $i >= 0; $i--) {
            $date          = date('Y-m-d', strtotime("-{$i} days"));
            $result[$date] = ['day' => $date, 'sales_count' => 0, 'revenue' => 0];
        }
        foreach ($rows as $row) {
            $result[$row['day']] = $row;
        }
        return array_values($result);
    }

    // ================================================================
    //  RECENT ACTIVITY (stock audit log)
    //  Excludes manual_adjust — those are auto-fired by the
    //  trg_products_after_update trigger whenever any other trigger
    //  updates product quantity, causing duplicate entries.
    // ================================================================
    public static function recentStockActivity(PDO $conn, int $limit = 8): array
    {
        $stmt = $conn->prepare("
            SELECT sal.log_id,
                   sal.action,
                   sal.change_qty,
                   sal.current_qty,
                   sal.timestamp,
                   p.product_name,
                   u.first_name,
                   u.last_name
            FROM stock_audit_log sal
            JOIN products p ON sal.product_id = p.product_id
            LEFT JOIN users u ON sal.user_id  = u.user_id
            WHERE sal.action != 'manual_adjust'
            ORDER BY sal.timestamp DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ================================================================
    //  PAYMENT METHOD BREAKDOWN (for pie chart)
    // ================================================================
    public static function paymentBreakdown(PDO $conn, string $period = 'month'): array
    {
        $where = self::periodWhere('sale_date', $period);
        $stmt  = $conn->prepare("
            SELECT payment_method,
                   COUNT(*)                     AS count,
                   IFNULL(SUM(total_amount), 0) AS total
            FROM sales
            WHERE {$where}
            GROUP BY payment_method
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ================================================================
    //  HELPERS — period WHERE clauses
    // ================================================================
    private static function periodWhere(string $col, string $period): string
    {
        return match($period) {
            'today' => "DATE({$col}) = CURDATE()",
            'month' => "MONTH({$col}) = MONTH(CURDATE()) AND YEAR({$col}) = YEAR(CURDATE())",
            'year'  => "YEAR({$col}) = YEAR(CURDATE())",
            default => "DATE({$col}) = CURDATE()",
        };
    }

    private static function salesCountPrevious(PDO $conn, string $period): int
    {
        $where = match($period) {
            'today' => "DATE(sale_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)",
            'month' => "MONTH(sale_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(sale_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))",
            'year'  => "YEAR(sale_date) = YEAR(CURDATE()) - 1",
            default => "DATE(sale_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)",
        };
        $stmt = $conn->prepare("SELECT COUNT(*) FROM sales WHERE {$where}");
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    private static function revenuePrevious(PDO $conn, string $period): float
    {
        $where = match($period) {
            'today' => "DATE(sale_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)",
            'month' => "MONTH(sale_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(sale_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))",
            'year'  => "YEAR(sale_date) = YEAR(CURDATE()) - 1",
            default => "DATE(sale_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)",
        };
        $stmt = $conn->prepare("SELECT IFNULL(SUM(total_amount), 0) FROM sales WHERE {$where}");
        $stmt->execute();
        return (float) $stmt->fetchColumn();
    }
}