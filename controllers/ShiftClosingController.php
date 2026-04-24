<?php
declare(strict_types=1);

require_once __DIR__ . '/NotificationController.php';
require_once __DIR__ . '/SaleController.php';

final class ShiftClosingController
{
    private const TABLE = 'shift_closings';
    private const UNIQUE_USER_DATE_INDEX = 'uniq_shift_closings_user_date';
    private const STATUS_OPEN = 'open';
    private const STATUS_CLOSED = 'closed';

    public static function ensureSchema(PDO $conn): void
    {
        SaleController::ensureVoidSchema($conn);

        $conn->exec("
            CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
                shift_closing_id int(11) NOT NULL AUTO_INCREMENT,
                user_id int(11) DEFAULT NULL,
                shift_date date NOT NULL,
                opened_at datetime NOT NULL DEFAULT current_timestamp(),
                closed_at datetime DEFAULT NULL,
                total_transactions int(11) NOT NULL DEFAULT 0,
                total_items int(11) NOT NULL DEFAULT 0,
                total_sales decimal(12,2) NOT NULL DEFAULT 0.00,
                cash_sales decimal(12,2) NOT NULL DEFAULT 0.00,
                starting_cash decimal(12,2) NOT NULL DEFAULT 0.00,
                expected_cash decimal(12,2) NOT NULL DEFAULT 0.00,
                counted_cash decimal(12,2) NOT NULL DEFAULT 0.00,
                variance decimal(12,2) NOT NULL DEFAULT 0.00,
                payment_breakdown_json longtext DEFAULT NULL,
                notes text DEFAULT NULL,
                status enum('open','closed') NOT NULL DEFAULT 'closed',
                created_at timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (shift_closing_id),
                UNIQUE KEY " . self::UNIQUE_USER_DATE_INDEX . " (user_id, shift_date),
                KEY idx_shift_closings_status (status),
                KEY idx_shift_closings_closed_at (closed_at),
                CONSTRAINT shift_closings_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        self::ensureColumn($conn, 'starting_cash', 'starting_cash decimal(12,2) NOT NULL DEFAULT 0.00 AFTER cash_sales');
        self::ensureColumn($conn, 'status', "status enum('open','closed') NOT NULL DEFAULT 'closed' AFTER notes");
        self::ensureClosedAtNullable($conn);
        self::ensureIndex($conn, 'idx_shift_closings_status', 'ADD KEY idx_shift_closings_status (status)');
        self::ensureUniqueUserDateIndex($conn);
    }

    public static function shiftForUser(PDO $conn, int $userId, string $shiftDate): ?array
    {
        self::ensureSchema($conn);

        if ($userId <= 0) {
            return null;
        }

        return self::findShiftRow($conn, $userId, self::normalizeShiftDate($shiftDate));
    }

    public static function summaryForUser(PDO $conn, int $userId, string $shiftDate): array
    {
        self::ensureSchema($conn);

        return self::buildSummaryForUser($conn, $userId, $shiftDate);
    }

    public static function startShift(PDO $conn, int $userId, array $input): array
    {
        self::ensureSchema($conn);

        if ($userId <= 0) {
            throw new InvalidArgumentException('A valid user is required to start the shift.');
        }

        $shiftDate = self::normalizeShiftDate($input['shift_date'] ?? null);
        $startingCash = self::normalizeOptionalMoney($input['starting_cash'] ?? 0, 'Starting cash');
        $startedTransaction = false;

        try {
            if (!$conn->inTransaction()) {
                $conn->beginTransaction();
                $startedTransaction = true;
            }

            $existingShift = self::findShiftRow($conn, $userId, $shiftDate, true);
            if ($existingShift !== null) {
                $status = strtolower((string) ($existingShift['status'] ?? self::STATUS_CLOSED));

                if ($status === self::STATUS_OPEN) {
                    throw new InvalidArgumentException('This shift is already open.');
                }

                throw new InvalidArgumentException('This shift has already been closed for this date.');
            }

            $stmt = $conn->prepare("
                INSERT INTO " . self::TABLE . " (
                    user_id,
                    shift_date,
                    opened_at,
                    closed_at,
                    starting_cash,
                    expected_cash,
                    status
                ) VALUES (
                    :user_id,
                    :shift_date,
                    NOW(),
                    NULL,
                    :starting_cash,
                    :expected_cash,
                    :status
                )
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':shift_date' => $shiftDate,
                ':starting_cash' => $startingCash,
                ':expected_cash' => $startingCash,
                ':status' => self::STATUS_OPEN,
            ]);

            $shiftClosingId = (int) $conn->lastInsertId();

            if ($startedTransaction && $conn->inTransaction()) {
                $conn->commit();
            }

            self::notifyShiftStarted($conn, $userId, $shiftDate, $startingCash);

            return [
                'shift_closing_id' => $shiftClosingId,
                'shift_date' => $shiftDate,
                'starting_cash' => $startingCash,
                'status' => self::STATUS_OPEN,
            ];
        } catch (Throwable $e) {
            if ($startedTransaction && $conn->inTransaction()) {
                $conn->rollBack();
            }

            throw $e;
        }
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

            $shift = self::findShiftRow($conn, $userId, $shiftDate, true);
            if ($shift === null) {
                throw new InvalidArgumentException('Start this shift before closing it.');
            }

            $alreadyClosed = strtolower((string) ($shift['status'] ?? self::STATUS_CLOSED)) === self::STATUS_CLOSED;
            $summary = self::buildSummaryForUser($conn, $userId, $shiftDate, $shift);
            $expectedCash = (float) ($summary['expected_cash'] ?? 0);
            $variance = round($countedCash - $expectedCash, 2);
            $paymentBreakdownJson = json_encode(
                $summary['payment_breakdown'] ?? [],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            $closedAtSql = $alreadyClosed ? 'closed_at = closed_at' : 'closed_at = NOW()';
            $stmt = $conn->prepare("
                UPDATE " . self::TABLE . "
                SET
                    {$closedAtSql},
                    total_transactions = :total_transactions,
                    total_items = :total_items,
                    total_sales = :total_sales,
                    cash_sales = :cash_sales,
                    expected_cash = :expected_cash,
                    counted_cash = :counted_cash,
                    variance = :variance,
                    payment_breakdown_json = :payment_breakdown_json,
                    notes = :notes,
                    status = :status
                WHERE shift_closing_id = :shift_closing_id
            ");
            $stmt->execute([
                ':shift_closing_id' => (int) $shift['shift_closing_id'],
                ':total_transactions' => (int) ($summary['total_transactions'] ?? 0),
                ':total_items' => (int) ($summary['total_items'] ?? 0),
                ':total_sales' => (float) ($summary['total_sales'] ?? 0),
                ':cash_sales' => (float) ($summary['cash_sales'] ?? 0),
                ':expected_cash' => $expectedCash,
                ':counted_cash' => $countedCash,
                ':variance' => $variance,
                ':payment_breakdown_json' => $paymentBreakdownJson,
                ':notes' => $notes !== '' ? $notes : null,
                ':status' => self::STATUS_CLOSED,
            ]);

            if ($startedTransaction && $conn->inTransaction()) {
                $conn->commit();
            }

            self::notifyShiftClosing($conn, $userId, $shiftDate, $summary, $alreadyClosed);

            return [
                'shift_closing_id' => (int) $shift['shift_closing_id'],
                'shift_date' => $shiftDate,
                'expected_cash' => $expectedCash,
                'counted_cash' => $countedCash,
                'variance' => $variance,
                'summary' => $summary,
                'was_updated' => $alreadyClosed,
            ];
        } catch (Throwable $e) {
            if ($startedTransaction && $conn->inTransaction()) {
                $conn->rollBack();
            }

            throw $e;
        }
    }

    private static function buildSummaryForUser(PDO $conn, int $userId, string $shiftDate, ?array $shift = null): array
    {
        $shiftDate = self::normalizeShiftDate($shiftDate);
        $shift ??= self::findShiftRow($conn, $userId, $shiftDate);
        $useShiftWindow = self::shouldUseShiftWindow($shift);
        SaleController::ensureVoidSchema($conn);
        $saleWhere = "s.user_id = :user_id AND DATE(s.sale_date) = :shift_date AND COALESCE(s.status, 'completed') <> 'voided'";
        $saleParams = [
            ':user_id' => $userId,
            ':shift_date' => $shiftDate,
        ];

        if ($useShiftWindow && !empty($shift['opened_at'])) {
            $saleWhere .= ' AND s.sale_date >= :opened_at';
            $saleParams[':opened_at'] = (string) $shift['opened_at'];
        }

        if (
            $useShiftWindow
            && !empty($shift['closed_at'])
            && strtolower((string) ($shift['status'] ?? self::STATUS_CLOSED)) === self::STATUS_CLOSED
        ) {
            $saleWhere .= ' AND s.sale_date <= :closed_at';
            $saleParams[':closed_at'] = (string) $shift['closed_at'];
        }

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
            WHERE {$saleWhere}
        ");
        $summaryStmt->execute($saleParams);
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $paymentStmt = $conn->prepare("
            SELECT
                s.payment_method,
                COUNT(*) AS sale_count,
                IFNULL(SUM(s.total_amount), 0) AS total_amount
            FROM sales s
            WHERE {$saleWhere}
            GROUP BY s.payment_method
            ORDER BY total_amount DESC, s.payment_method ASC
        ");
        $paymentStmt->execute($saleParams);
        $paymentBreakdown = $paymentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $latestStmt = $conn->prepare("
            SELECT s.sale_id, s.sale_date, s.total_amount, s.payment_method
            FROM sales s
            WHERE {$saleWhere}
            ORDER BY s.sale_date DESC, s.sale_id DESC
            LIMIT 1
        ");
        $latestStmt->execute($saleParams);
        $latestSale = $latestStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $startingCash = (float) ($shift['starting_cash'] ?? 0);
        $cashSales = (float) ($summary['cash_sales'] ?? 0);
        $expectedCash = round($startingCash + $cashSales, 2);
        $summary = [
            'shift_closing_id' => isset($shift['shift_closing_id']) ? (int) $shift['shift_closing_id'] : null,
            'shift_date' => $shiftDate,
            'status' => $shift !== null ? (string) ($shift['status'] ?? self::STATUS_CLOSED) : 'not_started',
            'opened_at' => $shift['opened_at'] ?? null,
            'closed_at' => $shift['closed_at'] ?? null,
            'starting_cash' => $startingCash,
            'total_transactions' => (int) ($summary['total_transactions'] ?? 0),
            'total_items' => (int) ($summary['total_items'] ?? 0),
            'total_sales' => (float) ($summary['total_sales'] ?? 0),
            'total_tax' => (float) ($summary['total_tax'] ?? 0),
            'total_discount' => (float) ($summary['total_discount'] ?? 0),
            'cash_sales' => $cashSales,
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
                sc.starting_cash,
                sc.expected_cash,
                sc.counted_cash,
                sc.variance,
                sc.notes,
                sc.status,
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

        $sql .= " ORDER BY COALESCE(sc.closed_at, sc.opened_at) DESC, sc.shift_closing_id DESC LIMIT :limit";

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

    private static function ensureColumn(PDO $conn, string $column, string $definition): void
    {
        try {
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :table_name
                  AND COLUMN_NAME = :column_name
            ");
            $stmt->execute([
                ':table_name' => self::TABLE,
                ':column_name' => $column,
            ]);

            if ((int) $stmt->fetchColumn() > 0) {
                return;
            }

            $conn->exec("
                ALTER TABLE " . self::TABLE . "
                ADD COLUMN {$definition}
            ");
        } catch (Throwable $e) {
            error_log('[ShiftClosingController][schema] ' . $e->getMessage());
        }
    }

    private static function ensureClosedAtNullable(PDO $conn): void
    {
        try {
            $stmt = $conn->prepare("
                SELECT IS_NULLABLE
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :table_name
                  AND COLUMN_NAME = 'closed_at'
                LIMIT 1
            ");
            $stmt->execute([':table_name' => self::TABLE]);
            if (strtoupper((string) $stmt->fetchColumn()) === 'YES') {
                return;
            }

            $conn->exec("
                ALTER TABLE " . self::TABLE . "
                MODIFY closed_at datetime DEFAULT NULL
            ");
        } catch (Throwable $e) {
            error_log('[ShiftClosingController][schema] ' . $e->getMessage());
        }
    }

    private static function ensureIndex(PDO $conn, string $indexName, string $definition): void
    {
        try {
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :table_name
                  AND INDEX_NAME = :index_name
            ");
            $stmt->execute([
                ':table_name' => self::TABLE,
                ':index_name' => $indexName,
            ]);

            if ((int) $stmt->fetchColumn() > 0) {
                return;
            }

            $conn->exec("
                ALTER TABLE " . self::TABLE . "
                {$definition}
            ");
        } catch (Throwable $e) {
            error_log('[ShiftClosingController][schema] ' . $e->getMessage());
        }
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

    private static function findShiftRow(PDO $conn, int $userId, string $shiftDate, bool $forUpdate = false): ?array
    {
        $sql = "
            SELECT
                shift_closing_id,
                user_id,
                shift_date,
                opened_at,
                closed_at,
                total_transactions,
                total_items,
                total_sales,
                cash_sales,
                starting_cash,
                expected_cash,
                counted_cash,
                variance,
                payment_breakdown_json,
                notes,
                status,
                created_at
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

        return $row ?: null;
    }

    private static function shouldUseShiftWindow(?array $shift): bool
    {
        if ($shift === null || empty($shift['opened_at'])) {
            return false;
        }

        $status = strtolower((string) ($shift['status'] ?? self::STATUS_CLOSED));
        $openedAt = strtotime((string) $shift['opened_at']);
        $closedAt = !empty($shift['closed_at']) ? strtotime((string) $shift['closed_at']) : false;
        $savedTransactions = (int) ($shift['total_transactions'] ?? 0);

        if (
            $status === self::STATUS_CLOSED
            && $openedAt !== false
            && $closedAt !== false
            && abs($closedAt - $openedAt) <= 1
            && $savedTransactions > 0
        ) {
            return false;
        }

        return true;
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

    private static function normalizeOptionalMoney(mixed $value, string $fieldLabel): float
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 0.0;
        }

        $normalized = str_replace([',', ' '], '', $value);
        if (!is_numeric($normalized)) {
            throw new InvalidArgumentException($fieldLabel . ' must be numeric.');
        }

        $amount = round((float) $normalized, 2);
        if ($amount < 0) {
            throw new InvalidArgumentException($fieldLabel . ' cannot be negative.');
        }

        return $amount;
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

    private static function notifyShiftStarted(PDO $conn, int $userId, string $shiftDate, float $startingCash): void
    {
        try {
            $userLabel = self::userDisplayLabel($conn, $userId);
            $message = sprintf(
                '%s started shift for %s with PHP %s starting cash.',
                $userLabel,
                date('M d, Y', strtotime($shiftDate)),
                number_format($startingCash, 2)
            );

            NotificationController::create(
                $conn,
                $userId,
                'admin',
                'shift_closing_started',
                'Shift started',
                $message,
                'bi-play-circle',
                'text-success',
                '/inventory_system/shift_closing.php?' . http_build_query([
                    'shift_date' => $shiftDate,
                    'user_id' => $userId,
                ])
            );
        } catch (Throwable $e) {
            error_log('[ShiftClosingController][notification] ' . $e->getMessage());
        }
    }

    private static function notifyShiftClosing(PDO $conn, int $userId, string $shiftDate, array $summary, bool $wasUpdated): void
    {
        try {
            $userLabel = self::userDisplayLabel($conn, $userId);
            $title = $wasUpdated ? 'Shift closing updated' : 'Shift closed';
            $message = sprintf(
                '%s shift for %s saved with PHP %s in sales.',
                $userLabel,
                date('M d, Y', strtotime($shiftDate)),
                number_format((float) ($summary['total_sales'] ?? 0), 2)
            );

            NotificationController::create(
                $conn,
                $userId,
                'admin',
                $wasUpdated ? 'shift_closing_updated' : 'shift_closing_saved',
                $title,
                $message,
                'bi-cash-stack',
                'text-primary',
                '/inventory_system/shift_closing.php?' . http_build_query([
                    'shift_date' => $shiftDate,
                    'user_id' => $userId,
                ])
            );
        } catch (Throwable $e) {
            error_log('[ShiftClosingController][notification] ' . $e->getMessage());
        }
    }

    private static function userDisplayLabel(PDO $conn, int $userId): string
    {
        $stmt = $conn->prepare("
            SELECT first_name, last_name, username
            FROM users
            WHERE user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $username = trim((string) ($row['username'] ?? ''));
        return $username !== '' ? $username : 'Cashier';
    }
}
