<?php
declare(strict_types=1);

final class ShiftClosingController
{
    private const TABLE = 'shift_closings';
    private const UNIQUE_USER_DATE_INDEX = 'uniq_shift_closings_user_date';

    public static function ensureSchema(PDO $conn): void
    {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
                shift_closing_id int(11) NOT NULL AUTO_INCREMENT,
                user_id int(11) DEFAULT NULL,
                shift_date date NOT NULL,
                opened_at datetime NOT NULL DEFAULT current_timestamp(),
                closed_at datetime NOT NULL DEFAULT current_timestamp(),
                total_transactions int(11) NOT NULL DEFAULT 0,
                total_items int(11) NOT NULL DEFAULT 0,
                total_sales decimal(12,2) NOT NULL DEFAULT 0.00,
                cash_sales decimal(12,2) NOT NULL DEFAULT 0.00,
                expected_cash decimal(12,2) NOT NULL DEFAULT 0.00,
                counted_cash decimal(12,2) NOT NULL DEFAULT 0.00,
                variance decimal(12,2) NOT NULL DEFAULT 0.00,
                payment_breakdown_json longtext DEFAULT NULL,
                notes text DEFAULT NULL,
                created_at timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (shift_closing_id),
                UNIQUE KEY " . self::UNIQUE_USER_DATE_INDEX . " (user_id, shift_date),
                KEY idx_shift_closings_closed_at (closed_at),
                CONSTRAINT shift_closings_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        self::ensureUniqueUserDateIndex($conn);
    }

    public static function summaryForUser(PDO $conn, int $userId, string $shiftDate): array
    {
        self::ensureSchema($conn);

        return self::buildSummaryForUser($conn, $userId, $shiftDate);
    }

    public static function closeShift(PDO $conn, int $userId, array $input): array
    {
        self::ensureSchema($conn);

        if ($userId <= 0) {
            throw new InvalidArgumentException('A valid user is required to close the shift.');
        }

        $shiftDate = self::normalizeShiftDate($input['shift_date'] ?? null);
        $countedCash = self::normalizeMoney($input['counted_cash'] ?? null);
        $notes = trim((string) ($input['notes'] ?? ''));
        if (strlen($notes) > 2000) {
            throw new InvalidArgumentException('Notes must be 2000 characters or fewer.');
        }

        $startedTransaction = false;

        try {
            if (!$conn->inTransaction()) {
                $conn->beginTransaction();
                $startedTransaction = true;
            }

            $summary = self::buildSummaryForUser($conn, $userId, $shiftDate);
            $expectedCash = (float) ($summary['expected_cash'] ?? 0);
            $variance = round($countedCash - $expectedCash, 2);
            $paymentBreakdownJson = json_encode(
                $summary['payment_breakdown'] ?? [],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            $existingClosingId = self::findExistingClosingId($conn, $userId, $shiftDate, true);
            $wasUpdated = $existingClosingId !== null;
            if ($existingClosingId !== null) {
                $stmt = $conn->prepare("
                    UPDATE " . self::TABLE . "
                    SET
                        closed_at = NOW(),
                        total_transactions = :total_transactions,
                        total_items = :total_items,
                        total_sales = :total_sales,
                        cash_sales = :cash_sales,
                        expected_cash = :expected_cash,
                        counted_cash = :counted_cash,
                        variance = :variance,
                        payment_breakdown_json = :payment_breakdown_json,
                        notes = :notes
                    WHERE shift_closing_id = :shift_closing_id
                ");
                $stmt->execute([
                    ':shift_closing_id' => $existingClosingId,
                    ':total_transactions' => (int) ($summary['total_transactions'] ?? 0),
                    ':total_items' => (int) ($summary['total_items'] ?? 0),
                    ':total_sales' => (float) ($summary['total_sales'] ?? 0),
                    ':cash_sales' => (float) ($summary['cash_sales'] ?? 0),
                    ':expected_cash' => $expectedCash,
                    ':counted_cash' => $countedCash,
                    ':variance' => $variance,
                    ':payment_breakdown_json' => $paymentBreakdownJson,
                    ':notes' => $notes !== '' ? $notes : null,
                ]);
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO " . self::TABLE . " (
                        user_id,
                        shift_date,
                        opened_at,
                        closed_at,
                        total_transactions,
                        total_items,
                        total_sales,
                        cash_sales,
                        expected_cash,
                        counted_cash,
                        variance,
                        payment_breakdown_json,
                        notes
                    ) VALUES (
                        :user_id,
                        :shift_date,
                        NOW(),
                        NOW(),
                        :total_transactions,
                        :total_items,
                        :total_sales,
                        :cash_sales,
                        :expected_cash,
                        :counted_cash,
                        :variance,
                        :payment_breakdown_json,
                        :notes
                    )
                ");
                $stmt->execute([
                    ':user_id' => $userId,
                    ':shift_date' => $shiftDate,
                    ':total_transactions' => (int) ($summary['total_transactions'] ?? 0),
                    ':total_items' => (int) ($summary['total_items'] ?? 0),
                    ':total_sales' => (float) ($summary['total_sales'] ?? 0),
                    ':cash_sales' => (float) ($summary['cash_sales'] ?? 0),
                    ':expected_cash' => $expectedCash,
                    ':counted_cash' => $countedCash,
                    ':variance' => $variance,
                    ':payment_breakdown_json' => $paymentBreakdownJson,
                    ':notes' => $notes !== '' ? $notes : null,
                ]);

                $existingClosingId = (int) $conn->lastInsertId();
            }

            if ($startedTransaction && $conn->inTransaction()) {
                $conn->commit();
            }

            return [
                'shift_closing_id' => $existingClosingId,
                'shift_date' => $shiftDate,
                'expected_cash' => $expectedCash,
                'counted_cash' => $countedCash,
                'variance' => $variance,
                'summary' => $summary,
                'was_updated' => $wasUpdated,
            ];
        } catch (Throwable $e) {
            if ($startedTransaction && $conn->inTransaction()) {
                $conn->rollBack();
            }

            throw $e;
        }
    }

    private static function buildSummaryForUser(PDO $conn, int $userId, string $shiftDate): array
    {
        $summaryStmt = $conn->prepare("
            SELECT
                COUNT(DISTINCT s.sale_id) AS total_transactions,
                IFNULL(SUM(s.total_amount), 0) AS total_sales,
                IFNULL(SUM(s.tax), 0) AS total_tax,
                IFNULL(SUM(s.discount), 0) AS total_discount,
                IFNULL(SUM(CASE WHEN s.payment_method = 'cash' THEN s.total_amount ELSE 0 END), 0) AS cash_sales,
                IFNULL(SUM(items.total_items), 0) AS total_items,
                MIN(s.sale_date) AS first_sale_at,
                MAX(s.sale_date) AS last_sale_at
            FROM sales s
            LEFT JOIN (
                SELECT sale_id, SUM(quantity * COALESCE(unit_multiplier, 1)) AS total_items
                FROM sale_items
                GROUP BY sale_id
            ) items ON items.sale_id = s.sale_id
            WHERE s.user_id = :user_id
              AND DATE(s.sale_date) = :shift_date
        ");
        $summaryStmt->execute([
            ':user_id' => $userId,
            ':shift_date' => $shiftDate,
        ]);
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $paymentStmt = $conn->prepare("
            SELECT
                s.payment_method,
                COUNT(*) AS sale_count,
                IFNULL(SUM(s.total_amount), 0) AS total_amount
            FROM sales s
            WHERE s.user_id = :user_id
              AND DATE(s.sale_date) = :shift_date
            GROUP BY s.payment_method
            ORDER BY total_amount DESC, s.payment_method ASC
        ");
        $paymentStmt->execute([
            ':user_id' => $userId,
            ':shift_date' => $shiftDate,
        ]);
        $paymentBreakdown = $paymentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $latestStmt = $conn->prepare("
            SELECT sale_id, sale_date, total_amount, payment_method
            FROM sales
            WHERE user_id = :user_id
              AND DATE(sale_date) = :shift_date
            ORDER BY sale_date DESC, sale_id DESC
            LIMIT 1
        ");
        $latestStmt->execute([
            ':user_id' => $userId,
            ':shift_date' => $shiftDate,
        ]);
        $latestSale = $latestStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $expectedCash = (float) ($summary['cash_sales'] ?? 0);
        $summary = [
            'shift_date' => $shiftDate,
            'total_transactions' => (int) ($summary['total_transactions'] ?? 0),
            'total_items' => (int) ($summary['total_items'] ?? 0),
            'total_sales' => (float) ($summary['total_sales'] ?? 0),
            'total_tax' => (float) ($summary['total_tax'] ?? 0),
            'total_discount' => (float) ($summary['total_discount'] ?? 0),
            'cash_sales' => $expectedCash,
            'expected_cash' => $expectedCash,
            'first_sale_at' => $summary['first_sale_at'] ?? null,
            'last_sale_at' => $summary['last_sale_at'] ?? null,
            'payment_breakdown' => $paymentBreakdown,
            'latest_sale' => $latestSale,
        ];

        return $summary;
    }

    public static function recentClosings(PDO $conn, ?int $userId = null, int $limit = 10): array
    {
        self::ensureSchema($conn);
        $limit = max(1, min(50, $limit));

        $sql = "
            SELECT
                sc.shift_closing_id,
                sc.user_id,
                sc.shift_date,
                sc.opened_at,
                sc.closed_at,
                sc.total_transactions,
                sc.total_items,
                sc.total_sales,
                sc.cash_sales,
                sc.expected_cash,
                sc.counted_cash,
                sc.variance,
                sc.notes,
                u.first_name,
                u.last_name,
                u.username,
                u.role
            FROM " . self::TABLE . " sc
            INNER JOIN (
                SELECT MAX(shift_closing_id) AS shift_closing_id
                FROM " . self::TABLE . "
                GROUP BY user_id, shift_date
            ) latest ON latest.shift_closing_id = sc.shift_closing_id
            LEFT JOIN users u ON sc.user_id = u.user_id
        ";
        $params = [];
        if ($userId !== null && $userId > 0) {
            $sql .= " WHERE sc.user_id = :user_id";
            $params[':user_id'] = $userId;
        }

        $sql .= " ORDER BY sc.closed_at DESC, sc.shift_closing_id DESC LIMIT :limit";

        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private static function normalizeShiftDate(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return date('Y-m-d');
        }

        $dt = DateTime::createFromFormat('Y-m-d', $value);
        if (!$dt) {
            throw new InvalidArgumentException('Shift date is invalid.');
        }

        return $dt->format('Y-m-d');
    }

    private static function ensureUniqueUserDateIndex(PDO $conn): void
    {
        try {
            $indexStmt = $conn->query("
                SHOW INDEX
                FROM " . self::TABLE . "
                WHERE Key_name = '" . self::UNIQUE_USER_DATE_INDEX . "'
            ");

            if ($indexStmt instanceof PDOStatement && $indexStmt->fetch(PDO::FETCH_ASSOC)) {
                return;
            }

            $duplicateStmt = $conn->query("
                SELECT user_id, shift_date, COUNT(*) AS duplicate_count
                FROM " . self::TABLE . "
                WHERE user_id IS NOT NULL
                GROUP BY user_id, shift_date
                HAVING COUNT(*) > 1
                LIMIT 1
            ");

            if ($duplicateStmt instanceof PDOStatement && $duplicateStmt->fetch(PDO::FETCH_ASSOC)) {
                error_log('[ShiftClosingController] Duplicate shift closing rows detected; skipped unique index creation.');
                return;
            }

            $conn->exec("
                ALTER TABLE " . self::TABLE . "
                ADD UNIQUE KEY " . self::UNIQUE_USER_DATE_INDEX . " (user_id, shift_date)
            ");
        } catch (Throwable $e) {
            error_log('[ShiftClosingController] ' . $e->getMessage());
        }
    }

    private static function findExistingClosingId(PDO $conn, int $userId, string $shiftDate, bool $forUpdate = false): ?int
    {
        $sql = "
            SELECT shift_closing_id
            FROM " . self::TABLE . "
            WHERE user_id = :user_id
              AND shift_date = :shift_date
            ORDER BY shift_closing_id DESC
            LIMIT 1
        ";

        if ($forUpdate) {
            $sql .= " FOR UPDATE";
        }

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':shift_date' => $shiftDate,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (int) $row['shift_closing_id'] : null;
    }

    private static function normalizeMoney(mixed $value): float
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new InvalidArgumentException('Counted cash is required.');
        }

        $normalized = str_replace([',', ' '], '', $value);
        if (!is_numeric($normalized)) {
            throw new InvalidArgumentException('Counted cash must be numeric.');
        }

        $amount = round((float) $normalized, 2);
        if ($amount < 0) {
            throw new InvalidArgumentException('Counted cash cannot be negative.');
        }

        return $amount;
    }
}
