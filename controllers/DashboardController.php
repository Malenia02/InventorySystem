<?php
declare(strict_types=1);

final class DashboardController
{
    private const ALLOWED_PERIODS = ['today', 'week', 'month', 'year'];

    public static function salesCount(PDO $conn, string $period = 'today'): int
    {
        $where = self::periodWhere('sale_date', $period);
        $stmt = $conn->prepare("SELECT COUNT(*) FROM sales WHERE {$where}");
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public static function revenue(PDO $conn, string $period = 'month'): float
    {
        $where = self::periodWhere('sale_date', $period);
        $stmt = $conn->prepare("SELECT IFNULL(SUM(total_amount), 0) FROM sales WHERE {$where}");
        $stmt->execute();

        return (float) $stmt->fetchColumn();
    }

    public static function totalItemsSold(PDO $conn, string $period = 'today'): int
    {
        $where = self::periodWhere('s.sale_date', $period);
        $stmt = $conn->prepare("
            SELECT IFNULL(SUM(si.quantity * COALESCE(si.unit_multiplier, 1)), 0)
            FROM sale_items si
            INNER JOIN sales s ON si.sale_id = s.sale_id
            WHERE {$where}
        ");
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public static function salesChange(PDO $conn, string $period = 'today'): array
    {
        $period = self::normalizePeriod($period);

        $current = self::salesCount($conn, $period);
        $previous = self::salesCountPrevious($conn, $period);

        $change = 0.0;
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

    public static function revenueChange(PDO $conn, string $period = 'month'): array
    {
        $period = self::normalizePeriod($period);

        $current = self::revenue($conn, $period);
        $previous = self::revenuePrevious($conn, $period);

        $change = 0.0;
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

    public static function totalProducts(PDO $conn): int
    {
        $stmt = $conn->query("SELECT COUNT(*) FROM products WHERE status = 'active'");
        return (int) $stmt->fetchColumn();
    }

    public static function lowStockProducts(PDO $conn, int $limit = 5): array
    {
        $limit = self::normalizeLimit($limit, 5, 100);

        $stmt = $conn->prepare("
            SELECT
                p.product_id,
                p.product_name,
                p.quantity,
                p.reorder_level,
                p.photo,
                c.category_name
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            WHERE p.status = 'active'
              AND p.quantity <= p.reorder_level
            ORDER BY p.quantity ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function outOfStockCount(PDO $conn): int
    {
        $stmt = $conn->query("
            SELECT COUNT(*)
            FROM products
            WHERE status = 'active' AND quantity = 0
        ");

        return (int) $stmt->fetchColumn();
    }

    public static function topSellingProducts(PDO $conn, string $period = 'month', int $limit = 5): array
    {
        $period = self::normalizePeriod($period);
        $limit = self::normalizeLimit($limit, 5, 100);
        $where = self::periodWhere('s.sale_date', $period);

        $stmt = $conn->prepare("
            SELECT
                p.product_id,
                p.product_name,
                p.photo,
                p.price,
                SUM(si.quantity * COALESCE(si.unit_multiplier, 1)) AS total_sold,
                SUM(si.quantity * si.unit_price) AS total_revenue
            FROM sale_items si
            JOIN sales s ON si.sale_id = s.sale_id
            JOIN products p ON si.product_id = p.product_id
            WHERE {$where}
            GROUP BY p.product_id, p.product_name, p.photo, p.price
            ORDER BY total_sold DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function recentSales(PDO $conn, int $limit = 10, string $period = 'month'): array
    {
        $period = self::normalizePeriod($period);
        $limit = self::normalizeLimit($limit, 10, 200);
        $where = self::periodWhere('s.sale_date', $period);

        $stmt = $conn->prepare("
            SELECT
                s.sale_id,
                s.total_amount,
                s.payment_method,
                s.sale_date,
                u.first_name,
                u.last_name,
                COUNT(si.sale_item_id) AS item_count
            FROM sales s
            LEFT JOIN users u ON s.user_id = u.user_id
            LEFT JOIN sale_items si ON s.sale_id = si.sale_id
            WHERE {$where}
            GROUP BY s.sale_id, s.total_amount, s.payment_method, s.sale_date, u.first_name, u.last_name
            ORDER BY s.sale_date DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function topRevenueProducts(PDO $conn, string $period = 'month', int $limit = 5): array
    {
        $period = self::normalizePeriod($period);
        $limit = self::normalizeLimit($limit, 5, 100);
        $where = self::periodWhere('s.sale_date', $period);

        $stmt = $conn->prepare("
            SELECT
                p.product_id,
                p.product_name,
                p.photo,
                p.price,
                SUM(si.quantity * COALESCE(si.unit_multiplier, 1)) AS total_sold,
                SUM(si.quantity * si.unit_price) AS total_revenue
            FROM sale_items si
            JOIN sales s ON si.sale_id = s.sale_id
            JOIN products p ON si.product_id = p.product_id
            WHERE {$where}
            GROUP BY p.product_id, p.product_name, p.photo, p.price
            ORDER BY total_revenue DESC, total_sold DESC, p.product_name ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function userSalesCount(PDO $conn, int $userId, string $period = 'today'): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $where = self::periodWhere('sale_date', $period);
        $stmt = $conn->prepare("SELECT COUNT(*) FROM sales WHERE user_id = :user_id AND {$where}");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public static function userRevenue(PDO $conn, int $userId, string $period = 'today'): float
    {
        if ($userId <= 0) {
            return 0.0;
        }

        $where = self::periodWhere('sale_date', $period);
        $stmt = $conn->prepare("SELECT IFNULL(SUM(total_amount), 0) FROM sales WHERE user_id = :user_id AND {$where}");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return (float) $stmt->fetchColumn();
    }

    public static function userItemsSold(PDO $conn, int $userId, string $period = 'today'): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $where = self::periodWhere('s.sale_date', $period);
        $stmt = $conn->prepare("
            SELECT IFNULL(SUM(si.quantity * COALESCE(si.unit_multiplier, 1)), 0)
            FROM sale_items si
            INNER JOIN sales s ON si.sale_id = s.sale_id
            WHERE s.user_id = :user_id AND {$where}
        ");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public static function userRecentSales(PDO $conn, int $userId, int $limit = 10, string $period = 'month'): array
    {
        if ($userId <= 0) {
            return [];
        }

        $period = self::normalizePeriod($period);
        $limit = self::normalizeLimit($limit, 10, 200);
        $where = self::periodWhere('s.sale_date', $period);

        $stmt = $conn->prepare("
            SELECT
                s.sale_id,
                s.total_amount,
                s.payment_method,
                s.sale_date,
                COUNT(si.sale_item_id) AS item_count
            FROM sales s
            LEFT JOIN sale_items si ON s.sale_id = si.sale_id
            WHERE s.user_id = :user_id AND {$where}
            GROUP BY s.sale_id, s.total_amount, s.payment_method, s.sale_date
            ORDER BY s.sale_date DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function userPaymentBreakdown(PDO $conn, int $userId, string $period = 'month'): array
    {
        if ($userId <= 0) {
            return [];
        }

        $where = self::periodWhere('sale_date', $period);

        $stmt = $conn->prepare("
            SELECT
                payment_method,
                COUNT(*) AS count,
                IFNULL(SUM(total_amount), 0) AS total
            FROM sales
            WHERE user_id = :user_id AND {$where}
            GROUP BY payment_method
            ORDER BY total DESC, payment_method ASC
        ");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function userSalesChartData(PDO $conn, int $userId, string $period = 'month'): array
    {
        if ($userId <= 0) {
            return [];
        }

        $period = self::normalizePeriod($period);

        if ($period === 'today') {
            $stmt = $conn->prepare("
                SELECT
                    DATE_FORMAT(sale_date, '%Y-%m-%d %H:00') AS day,
                    COUNT(*) AS sales_count,
                    IFNULL(SUM(total_amount), 0) AS revenue
                FROM sales
                WHERE user_id = :user_id
                  AND sale_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY DATE_FORMAT(sale_date, '%Y-%m-%d %H:00')
                ORDER BY day ASC
            ");
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        if ($period === 'year') {
            $stmt = $conn->prepare("
                SELECT
                    DATE_FORMAT(sale_date, '%Y-%m-01') AS day,
                    COUNT(*) AS sales_count,
                    IFNULL(SUM(total_amount), 0) AS revenue
                FROM sales
                WHERE user_id = :user_id
                  AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
                GROUP BY DATE_FORMAT(sale_date, '%Y-%m')
                ORDER BY day ASC
            ");
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $result = [];

            for ($i = 11; $i >= 0; $i--) {
                $date = date('Y-m-01', strtotime("-{$i} months"));
                $result[$date] = [
                    'day' => $date,
                    'sales_count' => 0,
                    'revenue' => 0,
                ];
            }

            foreach ($rows as $row) {
                $result[$row['day']] = $row;
            }

            return array_values($result);
        }

        if ($period === 'week') {
            $stmt = $conn->prepare("
                SELECT
                    DATE(sale_date) AS day,
                    COUNT(*) AS sales_count,
                    IFNULL(SUM(total_amount), 0) AS revenue
                FROM sales
                WHERE user_id = :user_id
                  AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                GROUP BY DATE(sale_date)
                ORDER BY day ASC
            ");
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $result = [];

            for ($i = 6; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-{$i} days"));
                $result[$date] = [
                    'day' => $date,
                    'sales_count' => 0,
                    'revenue' => 0,
                ];
            }

            foreach ($rows as $row) {
                $result[$row['day']] = $row;
            }

            return array_values($result);
        }

        $stmt = $conn->prepare("
            SELECT
                DATE(sale_date) AS day,
                COUNT(*) AS sales_count,
                IFNULL(SUM(total_amount), 0) AS revenue
            FROM sales
            WHERE user_id = :user_id
              AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
            GROUP BY DATE(sale_date)
            ORDER BY day ASC
        ");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $result = [];

        for ($i = 29; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $result[$date] = [
                'day' => $date,
                'sales_count' => 0,
                'revenue' => 0,
            ];
        }

        foreach ($rows as $row) {
            $result[$row['day']] = $row;
        }

        return array_values($result);
    }

    public static function salesChartData(PDO $conn, string $period = 'month'): array
    {
        $period = self::normalizePeriod($period);

        if ($period === 'today') {
            $stmt = $conn->query("
                SELECT
                    DATE_FORMAT(sale_date, '%Y-%m-%d %H:00') AS day,
                    COUNT(*) AS sales_count,
                    IFNULL(SUM(total_amount), 0) AS revenue
                FROM sales
                WHERE sale_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY DATE_FORMAT(sale_date, '%Y-%m-%d %H:00')
                ORDER BY day ASC
            ");

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        if ($period === 'year') {
            $stmt = $conn->query("
                SELECT
                    DATE_FORMAT(sale_date, '%Y-%m-01') AS day,
                    COUNT(*) AS sales_count,
                    IFNULL(SUM(total_amount), 0) AS revenue
                FROM sales
                WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
                GROUP BY DATE_FORMAT(sale_date, '%Y-%m')
                ORDER BY day ASC
            ");

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $result = [];

            for ($i = 11; $i >= 0; $i--) {
                $date = date('Y-m-01', strtotime("-{$i} months"));
                $result[$date] = [
                    'day'         => $date,
                    'sales_count' => 0,
                    'revenue'     => 0,
                ];
            }

            foreach ($rows as $row) {
                $result[$row['day']] = $row;
            }

            return array_values($result);
        }

        if ($period === 'week') {
            $stmt = $conn->query("
                SELECT
                    DATE(sale_date) AS day,
                    COUNT(*) AS sales_count,
                    IFNULL(SUM(total_amount), 0) AS revenue
                FROM sales
                WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                GROUP BY DATE(sale_date)
                ORDER BY day ASC
            ");

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $result = [];

            for ($i = 6; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-{$i} days"));
                $result[$date] = [
                    'day'         => $date,
                    'sales_count' => 0,
                    'revenue'     => 0,
                ];
            }

            foreach ($rows as $row) {
                $result[$row['day']] = $row;
            }

            return array_values($result);
        }

        $stmt = $conn->query("
            SELECT
                DATE(sale_date) AS day,
                COUNT(*) AS sales_count,
                IFNULL(SUM(total_amount), 0) AS revenue
            FROM sales
            WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
            GROUP BY DATE(sale_date)
            ORDER BY day ASC
        ");

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $result = [];

        for ($i = 29; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $result[$date] = [
                'day'         => $date,
                'sales_count' => 0,
                'revenue'     => 0,
            ];
        }

        foreach ($rows as $row) {
            $result[$row['day']] = $row;
        }

        return array_values($result);
    }

    public static function recentStockActivity(PDO $conn, int $limit = 8, ?int $userId = null): array
    {
        $limit = self::normalizeLimit($limit, 8, 100);
        $where = "WHERE sal.action != 'manual_adjust'";
        $params = [];

        if ($userId !== null && $userId > 0) {
            $where .= " AND sal.user_id = :user_id";
            $params[':user_id'] = $userId;
        }

        $stmt = $conn->prepare("
            SELECT
                sal.log_id,
                sal.action,
                sal.change_qty,
                sal.current_qty,
                sal.timestamp,
                p.product_name,
                u.first_name,
                u.last_name
            FROM stock_audit_log sal
            JOIN products p ON sal.product_id = p.product_id
            LEFT JOIN users u ON sal.user_id = u.user_id
            {$where}
            ORDER BY sal.timestamp DESC
            LIMIT :limit
        ");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function paymentBreakdown(PDO $conn, string $period = 'month'): array
    {
        $where = self::periodWhere('sale_date', $period);

        $stmt = $conn->prepare("
            SELECT
                payment_method,
                COUNT(*) AS count,
                IFNULL(SUM(total_amount), 0) AS total
            FROM sales
            WHERE {$where}
            GROUP BY payment_method
        ");
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function topPaymentMethodSummary(PDO $conn, string $period = 'month'): ?array
    {
        $rows = self::paymentBreakdown($conn, $period);
        return $rows[0] ?? null;
    }

    public static function lowStockCount(PDO $conn): int
    {
        $stmt = $conn->query("
            SELECT COUNT(*)
            FROM products
            WHERE status = 'active'
              AND quantity <= reorder_level
              AND quantity > 0
        ");

        return (int) $stmt->fetchColumn();
    }

    public static function chatbotLowStock(PDO $conn, int $limit = 8): array
    {
        $limit = self::normalizeLimit($limit, 8, 50);

        $stmt = $conn->prepare("
            SELECT
                p.product_id,
                p.product_name,
                p.quantity,
                p.reorder_level,
                c.category_name
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            WHERE p.status = 'active'
              AND p.quantity <= p.reorder_level
            ORDER BY p.quantity ASC, p.product_name ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function chatbotBestSellers(PDO $conn, string $period = 'today', int $limit = 5): array
    {
        $period = self::normalizeChatPeriod($period);
        $limit = self::normalizeLimit($limit, 5, 25);
        $where = self::chatPeriodWhere('s.sale_date', $period);

        $stmt = $conn->prepare("
            SELECT
                p.product_id,
                p.product_name,
                SUM(si.quantity * COALESCE(si.unit_multiplier, 1)) AS total_pieces,
                SUM(si.quantity * si.unit_price) AS total_revenue
            FROM sale_items si
            INNER JOIN sales s ON si.sale_id = s.sale_id
            INNER JOIN products p ON si.product_id = p.product_id
            WHERE {$where}
            GROUP BY p.product_id, p.product_name
            ORDER BY total_pieces DESC, total_revenue DESC, p.product_name ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function chatbotReorderSuggestions(PDO $conn, int $limit = 8): array
    {
        $limit = self::normalizeLimit($limit, 8, 50);

        $stmt = $conn->prepare("
            SELECT
                p.product_id,
                p.product_name,
                p.quantity,
                p.reorder_level,
                p.pieces_per_box,
                p.boxes_per_case,
                c.category_name,
                IFNULL(sales_30.total_pieces_sold, 0) AS total_pieces_sold_30d
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN (
                SELECT
                    si.product_id,
                    SUM(si.quantity * COALESCE(si.unit_multiplier, 1)) AS total_pieces_sold
                FROM sale_items si
                INNER JOIN sales s ON si.sale_id = s.sale_id
                WHERE s.sale_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY si.product_id
            ) sales_30 ON sales_30.product_id = p.product_id
            WHERE p.status = 'active'
              AND p.quantity <= p.reorder_level
            ORDER BY p.quantity ASC, total_pieces_sold_30d DESC, p.product_name ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            $dailyPieces = ((float) ($row['total_pieces_sold_30d'] ?? 0)) / 30;
            $coverDays = $dailyPieces > 0 ? round(((int) ($row['quantity'] ?? 0)) / $dailyPieces, 1) : null;
            $recommendedPieces = max(
                (int) ($row['reorder_level'] ?? 0) - (int) ($row['quantity'] ?? 0),
                (int) ceil(max($dailyPieces * 7, 0))
            );

            $row['avg_daily_pieces'] = round($dailyPieces, 1);
            $row['cover_days'] = $coverDays;
            $row['recommended_pieces'] = max(0, $recommendedPieces);

            return $row;
        }, $rows);
    }

    public static function chatbotSlowMovingProducts(PDO $conn, int $limit = 8): array
    {
        $limit = self::normalizeLimit($limit, 8, 50);

        $stmt = $conn->prepare("
            SELECT
                p.product_id,
                p.product_name,
                p.quantity,
                c.category_name,
                IFNULL(sales_30.total_pieces_sold, 0) AS total_pieces_sold_30d,
                MAX(s.sale_date) AS last_sale_date
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN sale_items si ON si.product_id = p.product_id
            LEFT JOIN sales s ON s.sale_id = si.sale_id
                AND s.sale_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            LEFT JOIN (
                SELECT
                    si2.product_id,
                    SUM(si2.quantity * COALESCE(si2.unit_multiplier, 1)) AS total_pieces_sold
                FROM sale_items si2
                INNER JOIN sales s2 ON s2.sale_id = si2.sale_id
                WHERE s2.sale_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY si2.product_id
            ) sales_30 ON sales_30.product_id = p.product_id
            WHERE p.status = 'active'
            GROUP BY p.product_id, p.product_name, p.quantity, c.category_name, sales_30.total_pieces_sold
            ORDER BY total_pieces_sold_30d ASC, p.quantity DESC, p.product_name ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function noRecentSalesProducts(PDO $conn, int $days = 30, int $limit = 8): array
    {
        $days = max(1, min($days, 365));
        $limit = self::normalizeLimit($limit, 8, 50);
        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');

        $stmt = $conn->prepare("
            SELECT
                p.product_id,
                p.product_name,
                p.quantity,
                p.reorder_level,
                c.category_name,
                MAX(s.sale_date) AS last_sale_date
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            LEFT JOIN sale_items si ON si.product_id = p.product_id
            LEFT JOIN sales s ON s.sale_id = si.sale_id
            WHERE p.status = 'active'
            GROUP BY p.product_id, p.product_name, p.quantity, p.reorder_level, c.category_name
            HAVING MAX(s.sale_date) IS NULL OR MAX(s.sale_date) < :cutoff
            ORDER BY
                CASE WHEN MAX(s.sale_date) IS NULL THEN 0 ELSE 1 END ASC,
                MAX(s.sale_date) ASC,
                p.quantity DESC,
                p.product_name ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':cutoff', $cutoff);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function noRecentSalesCount(PDO $conn, int $days = 30): int
    {
        $days = max(1, min($days, 365));
        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');

        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM (
                SELECT p.product_id, MAX(s.sale_date) AS last_sale_date
                FROM products p
                LEFT JOIN sale_items si ON si.product_id = p.product_id
                LEFT JOIN sales s ON s.sale_id = si.sale_id
                WHERE p.status = 'active'
                GROUP BY p.product_id
                HAVING MAX(s.sale_date) IS NULL OR MAX(s.sale_date) < :cutoff
            ) dormant_products
        ");
        $stmt->bindValue(':cutoff', $cutoff);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public static function cashierPerformance(PDO $conn, string $period = 'today', int $limit = 5): array
    {
        $period = self::normalizePeriod($period);
        $limit = self::normalizeLimit($limit, 5, 25);
        $where = self::periodWhere('s.sale_date', $period);

        $stmt = $conn->prepare("
            SELECT
                s.user_id,
                u.first_name,
                u.last_name,
                u.username,
                COUNT(DISTINCT s.sale_id) AS sale_count,
                IFNULL(SUM(s.total_amount), 0) AS total_revenue,
                IFNULL(SUM(si.quantity * COALESCE(si.unit_multiplier, 1)), 0) AS items_sold
            FROM sales s
            LEFT JOIN users u ON s.user_id = u.user_id
            LEFT JOIN sale_items si ON si.sale_id = s.sale_id
            WHERE {$where}
            GROUP BY s.user_id, u.first_name, u.last_name, u.username
            ORDER BY total_revenue DESC, sale_count DESC, items_sold DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function chatbotProductUnitSales(PDO $conn, string $productTerm, string $unitType, string $period = 'week'): ?array
    {
        $productTerm = trim($productTerm);
        $unitType = strtolower(trim($unitType));
        $period = self::normalizeChatPeriod($period);

        if ($productTerm === '') {
            throw new InvalidArgumentException('Product name is required.');
        }

        if (!in_array($unitType, ['piece', 'box', 'case'], true)) {
            throw new InvalidArgumentException('Unsupported unit type.');
        }

        $where = self::chatPeriodWhere('s.sale_date', $period);

        $stmt = $conn->prepare("
            SELECT
                p.product_id,
                p.product_name,
                si.unit_type,
                SUM(si.quantity) AS units_sold,
                SUM(si.quantity * COALESCE(si.unit_multiplier, 1)) AS pieces_sold,
                SUM(si.quantity * si.unit_price) AS revenue
            FROM sale_items si
            INNER JOIN sales s ON s.sale_id = si.sale_id
            INNER JOIN products p ON p.product_id = si.product_id
            WHERE {$where}
              AND si.unit_type = :unit_type
              AND p.product_name LIKE :product_term
            GROUP BY p.product_id, p.product_name, si.unit_type
            ORDER BY units_sold DESC, p.product_name ASC
            LIMIT 1
        ");
        $stmt->execute([
            ':unit_type' => $unitType,
            ':product_term' => '%' . $productTerm . '%',
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    public static function chatbotTopCategories(PDO $conn, string $period = 'month', int $limit = 5): array
    {
        $period = self::normalizeChatPeriod($period);
        $limit = self::normalizeLimit($limit, 5, 25);
        $where = self::chatPeriodWhere('s.sale_date', $period);

        $stmt = $conn->prepare("
            SELECT
                c.category_id,
                c.category_name,
                SUM(si.quantity * COALESCE(si.unit_multiplier, 1)) AS total_pieces,
                SUM(si.quantity * si.unit_price) AS total_revenue
            FROM sale_items si
            INNER JOIN sales s ON s.sale_id = si.sale_id
            INNER JOIN products p ON p.product_id = si.product_id
            LEFT JOIN categories c ON c.category_id = p.category_id
            WHERE {$where}
            GROUP BY c.category_id, c.category_name
            ORDER BY total_revenue DESC, total_pieces DESC, c.category_name ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function chatbotSystemErrorsToday(): array
    {
        $todayKey = date('d-M-Y');
        $entries = array_filter(
            self::readErrorLogEntries(),
            static fn(array $entry): bool => ($entry['date_key'] ?? '') === $todayKey
        );

        return array_values($entries);
    }

    public static function chatbotRecentSystemErrors(int $limit = 5): array
    {
        return array_slice(self::readErrorLogEntries(), 0, self::normalizeLimit($limit, 5, 25));
    }

    public static function chatbotRecentChatbotErrors(int $limit = 5): array
    {
        $entries = array_filter(
            self::readErrorLogEntries(),
            static fn(array $entry): bool => str_contains(strtolower((string) ($entry['message'] ?? '')), '[dashboard_chatbot]')
        );

        return array_slice(array_values($entries), 0, self::normalizeLimit($limit, 5, 25));
    }

    public static function chatbotLastSystemError(): ?array
    {
        $entries = self::readErrorLogEntries();
        return $entries[0] ?? null;
    }

    private static function periodWhere(string $column, string $period): string
    {
        $period = self::normalizePeriod($period);

        return match ($period) {
            'today' => "DATE({$column}) = CURDATE()",
            'week'  => "{$column} >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "MONTH({$column}) = MONTH(CURDATE()) AND YEAR({$column}) = YEAR(CURDATE())",
            'year'  => "YEAR({$column}) = YEAR(CURDATE())",
        };
    }

    private static function chatPeriodWhere(string $column, string $period): string
    {
        $period = self::normalizeChatPeriod($period);

        return match ($period) {
            'today' => "DATE({$column}) = CURDATE()",
            'week'  => "{$column} >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "{$column} >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            'year'  => "{$column} >= DATE_SUB(NOW(), INTERVAL 365 DAY)",
        };
    }

    private static function salesCountPrevious(PDO $conn, string $period): int
    {
        $period = self::normalizePeriod($period);

        $where = match ($period) {
            'today' => "DATE(sale_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)",
            'week'  => "sale_date >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND sale_date < DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "MONTH(sale_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(sale_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))",
            'year'  => "YEAR(sale_date) = YEAR(CURDATE()) - 1",
        };

        $stmt = $conn->prepare("SELECT COUNT(*) FROM sales WHERE {$where}");
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    private static function revenuePrevious(PDO $conn, string $period): float
    {
        $period = self::normalizePeriod($period);

        $where = match ($period) {
            'today' => "DATE(sale_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)",
            'week'  => "sale_date >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND sale_date < DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "MONTH(sale_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(sale_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))",
            'year'  => "YEAR(sale_date) = YEAR(CURDATE()) - 1",
        };

        $stmt = $conn->prepare("SELECT IFNULL(SUM(total_amount), 0) FROM sales WHERE {$where}");
        $stmt->execute();

        return (float) $stmt->fetchColumn();
    }

    private static function normalizePeriod(string $period): string
    {
        $period = strtolower(trim($period));

        if (!in_array($period, self::ALLOWED_PERIODS, true)) {
            throw new InvalidArgumentException('Invalid dashboard period.');
        }

        return $period;
    }

    private static function normalizeChatPeriod(string $period): string
    {
        $period = strtolower(trim($period));
        $allowed = ['today', 'week', 'month', 'year'];

        if (!in_array($period, $allowed, true)) {
            throw new InvalidArgumentException('Invalid chatbot period.');
        }

        return $period;
    }

    private static function normalizeLimit(int $limit, int $default, int $max): int
    {
        if ($limit <= 0) {
            return $default;
        }

        return min($limit, $max);
    }

    private static function readErrorLogEntries(): array
    {
        $logPath = defined('LOG_PATH') ? LOG_PATH . '/php-error.log' : '';

        if ($logPath === '' || !is_file($logPath) || !is_readable($logPath)) {
            return [];
        }

        $lines = @file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || $lines === []) {
            return [];
        }

        $entries = [];

        foreach (array_reverse($lines) as $line) {
            $parsed = self::parseErrorLogLine((string) $line);
            if ($parsed !== null) {
                $entries[] = $parsed;
            }
        }

        return $entries;
    }

    private static function parseErrorLogLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        if (preg_match('/^\[(?<timestamp>[^\]]+)\]\s*(?<message>.+)$/', $line, $matches) !== 1) {
            return [
                'timestamp' => 'Unknown time',
                'date_key' => '',
                'message' => $line,
            ];
        }

        $timestamp = trim((string) ($matches['timestamp'] ?? ''));
        $message = trim((string) ($matches['message'] ?? ''));
        $dateKey = '';

        $date = \DateTime::createFromFormat('d-M-Y H:i:s e', $timestamp)
            ?: \DateTime::createFromFormat('d-M-Y H:i:s', $timestamp);

        if ($date instanceof \DateTimeInterface) {
            $dateKey = $date->format('d-M-Y');
            $timestamp = $date->format('M d, Y h:i A');
        }

        return [
            'timestamp' => $timestamp,
            'date_key' => $dateKey,
            'message' => self::sanitizeErrorMessage($message),
        ];
    }

    private static function sanitizeErrorMessage(string $message): string
    {
        $message = trim($message);
        $lower = strtolower($message);

        return match (true) {
            str_contains($lower, 'csrf validation failed') => 'Security token validation failed.',
            str_contains($lower, 'origin check failed') || str_contains($lower, 'referer check failed') => 'Request origin validation failed.',
            str_contains($lower, 'unauthorized access') => 'Unauthorized access attempt was blocked.',
            str_contains($lower, 'database connection failed') => 'Database connection failed.',
            str_contains($lower, 'invalid request origin') => 'A request with an invalid origin was blocked.',
            str_contains($lower, '[dashboard_chatbot]') => 'Dashboard assistant request failed.',
            str_contains($lower, 'upload failed') || str_contains($lower, 'invalid uploaded file') => 'A file upload validation error occurred.',
            str_contains($lower, 'fatal error') || str_contains($lower, '[shutdown]') => 'A fatal application error was recorded.',
            default => 'Application error recorded. Review secured server logs for full details.',
        };
    }
}
