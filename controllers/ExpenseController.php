<?php
declare(strict_types=1);

final class ExpenseController
{
    private const TABLE = 'expenses';

    public static function ensureSchema(PDO $conn): void
    {
        if (!app_has_table($conn, self::TABLE) && !app_runtime_schema_changes_allowed()) {
            app_fail_runtime_schema_change(self::TABLE);
        }

        $conn->exec("
            CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
                expense_id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                expense_date DATE NOT NULL,
                category VARCHAR(100) NOT NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                notes TEXT DEFAULT NULL,
                created_by INT(11) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                payment_status VARCHAR(20) NOT NULL DEFAULT 'paid',
                due_date DATE DEFAULT NULL,
                paid_at DATETIME DEFAULT NULL,
                paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                payment_method VARCHAR(50) DEFAULT NULL,
                reference_no VARCHAR(100) DEFAULT NULL,
                updated_at DATETIME DEFAULT NULL,
                KEY idx_expenses_date (expense_date),
                KEY idx_expenses_category (category),
                KEY idx_expenses_created_by (created_by),
                KEY idx_expenses_status (payment_status),
                KEY idx_expenses_due_date (due_date),
                CONSTRAINT expenses_ibfk_1 FOREIGN KEY (created_by) REFERENCES users (user_id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        $addedPaymentColumns = false;
        $addedPaymentColumns = self::ensureColumn($conn, 'payment_status', "VARCHAR(20) NOT NULL DEFAULT 'paid'") || $addedPaymentColumns;
        $addedPaymentColumns = self::ensureColumn($conn, 'due_date', 'DATE DEFAULT NULL') || $addedPaymentColumns;
        $addedPaymentColumns = self::ensureColumn($conn, 'paid_at', 'DATETIME DEFAULT NULL') || $addedPaymentColumns;
        $addedPaymentColumns = self::ensureColumn($conn, 'paid_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00') || $addedPaymentColumns;
        $addedPaymentColumns = self::ensureColumn($conn, 'payment_method', 'VARCHAR(50) DEFAULT NULL') || $addedPaymentColumns;
        $addedPaymentColumns = self::ensureColumn($conn, 'reference_no', 'VARCHAR(100) DEFAULT NULL') || $addedPaymentColumns;
        self::ensureColumn($conn, 'updated_at', 'DATETIME DEFAULT NULL');
        self::ensureIndex($conn, 'idx_expenses_status', 'payment_status');
        self::ensureIndex($conn, 'idx_expenses_due_date', 'due_date');

        if ($addedPaymentColumns) {
            $conn->exec("
                UPDATE " . self::TABLE . "
                SET
                    payment_status = 'paid',
                    paid_amount = amount,
                    paid_at = COALESCE(paid_at, created_at),
                    updated_at = COALESCE(updated_at, created_at)
                WHERE paid_amount = 0
                    AND (payment_status IS NULL OR payment_status = '' OR payment_status = 'paid')
            ");
        }
    }

    public static function create(PDO $conn, array $payload, int $userId): array
    {
        self::ensureSchema($conn);

        if ($userId <= 0) {
            throw new InvalidArgumentException('Invalid expense creator.');
        }

        $expenseDate = self::normalizeDate((string) ($payload['expense_date'] ?? date('Y-m-d')));
        $category = self::normalizeCategory((string) ($payload['category'] ?? ''));
        $amount = self::normalizeAmount($payload['amount'] ?? null);
        $notes = self::normalizeNotes((string) ($payload['notes'] ?? ''));
        $paymentStatus = self::normalizePaymentStatus((string) ($payload['payment_status'] ?? 'paid'));
        $dueDate = self::normalizeOptionalDate((string) ($payload['due_date'] ?? ''));
        $paidAt = self::normalizeOptionalDateTime((string) ($payload['paid_at'] ?? ''));
        $paidAmount = self::normalizePaidAmount($payload['paid_amount'] ?? null, $amount, $paymentStatus);
        $paymentMethod = self::normalizeShortText((string) ($payload['payment_method'] ?? ''), 50);
        $referenceNo = self::normalizeShortText((string) ($payload['reference_no'] ?? ''), 100);

        if ($paymentStatus === 'paid' && $paidAt === null) {
            $paidAt = date('Y-m-d H:i:s');
        }

        if ($paymentStatus === 'unpaid') {
            $paidAt = null;
            $paidAmount = 0.0;
            $paymentMethod = null;
            $referenceNo = null;
        }

        $stmt = $conn->prepare("
            INSERT INTO " . self::TABLE . " (
                expense_date,
                category,
                amount,
                notes,
                created_by,
                created_at,
                payment_status,
                due_date,
                paid_at,
                paid_amount,
                payment_method,
                reference_no,
                updated_at
            ) VALUES (
                :expense_date,
                :category,
                :amount,
                :notes,
                :created_by,
                NOW(),
                :payment_status,
                :due_date,
                :paid_at,
                :paid_amount,
                :payment_method,
                :reference_no,
                NOW()
            )
        ");
        $stmt->execute([
            ':expense_date' => $expenseDate,
            ':category' => $category,
            ':amount' => $amount,
            ':notes' => $notes,
            ':created_by' => $userId,
            ':payment_status' => $paymentStatus,
            ':due_date' => $dueDate,
            ':paid_at' => $paidAt,
            ':paid_amount' => $paidAmount,
            ':payment_method' => $paymentMethod,
            ':reference_no' => $referenceNo,
        ]);

        return self::find($conn, (int) $conn->lastInsertId()) ?? throw new RuntimeException('Expense saved but could not be reloaded.');
    }

    public static function markPaid(PDO $conn, int $expenseId, array $payload, int $userId): array
    {
        self::ensureSchema($conn);

        if ($userId <= 0) {
            throw new InvalidArgumentException('Invalid payment user.');
        }

        $expense = self::find($conn, $expenseId);
        if (!$expense) {
            throw new InvalidArgumentException('Expense record was not found.');
        }

        $amount = (float) ($expense['amount'] ?? 0);
        $currentPaid = (float) ($expense['paid_amount'] ?? 0);
        $openBalance = max(0.0, round($amount - $currentPaid, 2));
        if ($openBalance <= 0) {
            throw new InvalidArgumentException('This expense is already fully paid.');
        }

        $paymentAmount = self::normalizeAmount($payload['paid_amount'] ?? $openBalance);
        if ($paymentAmount > $openBalance) {
            throw new InvalidArgumentException('Payment amount cannot exceed the open balance.');
        }

        $newPaidAmount = round($currentPaid + $paymentAmount, 2);
        $newStatus = $newPaidAmount >= $amount ? 'paid' : 'partially_paid';
        $paidAt = self::normalizeOptionalDateTime((string) ($payload['paid_at'] ?? '')) ?? date('Y-m-d H:i:s');
        $paymentMethod = self::normalizeShortText((string) ($payload['payment_method'] ?? ''), 50);
        $referenceNo = self::normalizeShortText((string) ($payload['reference_no'] ?? ''), 100);

        if ($paymentMethod === null) {
            throw new InvalidArgumentException('Please select or enter the payment method.');
        }

        $stmt = $conn->prepare("
            UPDATE " . self::TABLE . "
            SET
                payment_status = :payment_status,
                paid_amount = :paid_amount,
                paid_at = :paid_at,
                payment_method = :payment_method,
                reference_no = :reference_no,
                updated_at = NOW()
            WHERE expense_id = :expense_id
            LIMIT 1
        ");
        $stmt->execute([
            ':payment_status' => $newStatus,
            ':paid_amount' => $newPaidAmount,
            ':paid_at' => $paidAt,
            ':payment_method' => $paymentMethod,
            ':reference_no' => $referenceNo,
            ':expense_id' => $expenseId,
        ]);

        return self::find($conn, $expenseId) ?? throw new RuntimeException('Expense updated but could not be reloaded.');
    }

    public static function listExpenses(PDO $conn, int $limit = 200): array
    {
        self::ensureSchema($conn);
        $limit = max(1, min(500, $limit));

        $stmt = $conn->prepare("
            SELECT
                e.expense_id,
                e.expense_date,
                e.category,
                e.amount,
                e.notes,
                e.created_at,
                e.payment_status,
                e.due_date,
                e.paid_at,
                e.paid_amount,
                e.payment_method,
                e.reference_no,
                u.first_name,
                u.last_name,
                u.username
            FROM " . self::TABLE . " e
            LEFT JOIN users u ON u.user_id = e.created_by
            ORDER BY e.expense_date DESC, e.expense_id DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function summary(PDO $conn): array
    {
        self::ensureSchema($conn);
        $stmt = $conn->query("
            SELECT
                IFNULL(SUM(CASE WHEN expense_date = CURDATE() THEN amount ELSE 0 END), 0) AS today_total,
                IFNULL(SUM(CASE WHEN expense_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN amount ELSE 0 END), 0) AS month_total,
                IFNULL(SUM(CASE WHEN paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN paid_amount ELSE 0 END), 0) AS paid_30_total,
                IFNULL(SUM(CASE WHEN payment_status IN ('unpaid', 'partially_paid') THEN GREATEST(amount - paid_amount, 0) ELSE 0 END), 0) AS open_total,
                IFNULL(SUM(CASE WHEN payment_status IN ('unpaid', 'partially_paid') AND due_date < CURDATE() THEN GREATEST(amount - paid_amount, 0) ELSE 0 END), 0) AS overdue_total,
                IFNULL(SUM(CASE WHEN payment_status IN ('unpaid', 'partially_paid') AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN GREATEST(amount - paid_amount, 0) ELSE 0 END), 0) AS due_soon_total,
                IFNULL(SUM(CASE WHEN payment_status = 'partially_paid' THEN GREATEST(amount - paid_amount, 0) ELSE 0 END), 0) AS partial_open_total,
                COUNT(*) AS total_rows
            FROM " . self::TABLE . "
        ");
        $summary = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

        $categoryStmt = $conn->query("
            SELECT category, SUM(amount) AS total
            FROM " . self::TABLE . "
            GROUP BY category
            ORDER BY total DESC, category ASC
            LIMIT 1
        ");
        $topCategory = $categoryStmt ? ($categoryStmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;

        return [
            'today_total' => (float) ($summary['today_total'] ?? 0),
            'month_total' => (float) ($summary['month_total'] ?? 0),
            'paid_30_total' => (float) ($summary['paid_30_total'] ?? 0),
            'open_total' => (float) ($summary['open_total'] ?? 0),
            'overdue_total' => (float) ($summary['overdue_total'] ?? 0),
            'due_soon_total' => (float) ($summary['due_soon_total'] ?? 0),
            'partial_open_total' => (float) ($summary['partial_open_total'] ?? 0),
            'total_rows' => (int) ($summary['total_rows'] ?? 0),
            'top_category' => $topCategory,
        ];
    }

    public static function analytics(PDO $conn, int $days = 30): array
    {
        self::ensureSchema($conn);
        $days = max(7, min(365, $days));
        $daysSql = (int) $days;

        $categoryStmt = $conn->query("
            SELECT category, SUM(amount) AS total, COUNT(*) AS entries
            FROM " . self::TABLE . "
            WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL {$daysSql} DAY)
            GROUP BY category
            ORDER BY total DESC, category ASC
            LIMIT 8
        ");

        $trendStmt = $conn->query("
            SELECT expense_date, SUM(amount) AS total
            FROM " . self::TABLE . "
            WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL {$daysSql} DAY)
            GROUP BY expense_date
            ORDER BY expense_date ASC
        ");

        $largestStmt = $conn->query("
            SELECT category, amount, notes, expense_date
            FROM " . self::TABLE . "
            WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL {$daysSql} DAY)
            ORDER BY amount DESC, expense_id DESC
            LIMIT 1
        ");

        return [
            'days' => $days,
            'category_breakdown' => $categoryStmt ? ($categoryStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [],
            'daily_trend' => $trendStmt ? ($trendStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [],
            'largest_expense' => $largestStmt ? ($largestStmt->fetch(PDO::FETCH_ASSOC) ?: null) : null,
        ];
    }

    private static function find(PDO $conn, int $expenseId): ?array
    {
        $stmt = $conn->prepare("
            SELECT
                e.expense_id,
                e.expense_date,
                e.category,
                e.amount,
                e.notes,
                e.created_at,
                e.payment_status,
                e.due_date,
                e.paid_at,
                e.paid_amount,
                e.payment_method,
                e.reference_no,
                u.first_name,
                u.last_name,
                u.username
            FROM " . self::TABLE . " e
            LEFT JOIN users u ON u.user_id = e.created_by
            WHERE e.expense_id = :expense_id
            LIMIT 1
        ");
        $stmt->execute([':expense_id' => $expenseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function normalizeDate(string $date): string
    {
        $date = trim($date);
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dt) {
            throw new InvalidArgumentException('Invalid expense date.');
        }
        return $dt->format('Y-m-d');
    }

    private static function normalizeCategory(string $category): string
    {
        $category = trim($category);
        if ($category === '') {
            throw new InvalidArgumentException('Please enter an expense category.');
        }
        return mb_substr($category, 0, 100);
    }

    private static function normalizeAmount(mixed $amount): float
    {
        $value = is_numeric($amount) ? (float) $amount : 0.0;
        if ($value <= 0) {
            throw new InvalidArgumentException('Expense amount must be greater than zero.');
        }
        return round($value, 2);
    }

    private static function normalizeNotes(string $notes): ?string
    {
        $notes = trim($notes);
        if ($notes === '') {
            return null;
        }
        return mb_substr($notes, 0, 1000);
    }

    private static function normalizePaymentStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === '') {
            return 'paid';
        }

        $allowed = ['paid', 'unpaid', 'partially_paid'];
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Invalid payment status.');
        }

        return $status;
    }

    private static function normalizePaidAmount(mixed $amount, float $expenseAmount, string $status): float
    {
        if ($status === 'paid') {
            return $expenseAmount;
        }

        if ($status === 'unpaid') {
            return 0.0;
        }

        $paidAmount = is_numeric($amount) ? round((float) $amount, 2) : 0.0;
        if ($paidAmount <= 0 || $paidAmount >= $expenseAmount) {
            throw new InvalidArgumentException('Partial payment must be greater than zero and less than the full amount.');
        }

        return $paidAmount;
    }

    private static function normalizeOptionalDate(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }

        $dt = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dt) {
            throw new InvalidArgumentException('Invalid due date.');
        }

        return $dt->format('Y-m-d');
    }

    private static function normalizeOptionalDateTime(string $dateTime): ?string
    {
        $dateTime = trim($dateTime);
        if ($dateTime === '') {
            return null;
        }

        $formats = ['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d'];
        foreach ($formats as $format) {
            $dt = DateTime::createFromFormat($format, $dateTime);
            if ($dt instanceof DateTime) {
                if ($format === 'Y-m-d') {
                    $dt->setTime(0, 0, 0);
                }
                return $dt->format('Y-m-d H:i:s');
            }
        }

        throw new InvalidArgumentException('Invalid payment date.');
    }

    private static function normalizeShortText(string $value, int $maxLength): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $maxLength);
    }

    private static function ensureColumn(PDO $conn, string $column, string $definition): bool
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table
                AND COLUMN_NAME = :column
        ");
        $stmt->execute([
            ':table' => self::TABLE,
            ':column' => $column,
        ]);

        if ((int) $stmt->fetchColumn() > 0) {
            return false;
        }

        if (!app_runtime_schema_changes_allowed()) {
            app_fail_runtime_schema_change(self::TABLE . '.' . $column);
        }

        $conn->exec("ALTER TABLE " . self::TABLE . " ADD COLUMN `{$column}` {$definition}");
        return true;
    }

    private static function ensureIndex(PDO $conn, string $indexName, string $column): void
    {
        if (function_exists('app_runtime_schema_changes_allowed') && !app_runtime_schema_changes_allowed()) {
            return;
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table
                AND INDEX_NAME = :index_name
        ");
        $stmt->execute([
            ':table' => self::TABLE,
            ':index_name' => $indexName,
        ]);

        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }

        $conn->exec("ALTER TABLE " . self::TABLE . " ADD KEY `{$indexName}` (`{$column}`)");
    }
}
