<?php
declare(strict_types=1);

require_once __DIR__ . '/NotificationController.php';
require_once __DIR__ . '/SaleController.php';
require_once __DIR__ . '/PosConfigController.php';

final class ShiftClosingController
{
    private const TABLE                    = 'shift_closings';
    private const REQUEST_TABLE            = 'shift_closing_edit_requests';
    private const UNIQUE_USER_DATE_INDEX   = 'uniq_shift_closings_user_date';
    private const STATUS_OPEN              = 'open';
    private const STATUS_CLOSED            = 'closed';
    private const EDIT_WINDOW_HOURS        = 8;

    /**
     * SCALABILITY FIX: static bool guard so ensureSchema() only runs
     * INFORMATION_SCHEMA checks once per PHP-FPM worker.
     *
     * The original had no guard — ensureSchema() was called inside
     * shiftForUser(), summaryForUser(), AND recentClosings() on every
     * page load. Each call ran CREATE TABLE IF NOT EXISTS + up to 7
     * ensureColumn() calls, each hitting INFORMATION_SCHEMA.COLUMNS.
     * That's 21+ INFORMATION_SCHEMA queries per page load.
     */
    private static bool $schemaChecked = false;

    // =========================================================================
    // SCHEMA
    // =========================================================================

    public static function ensureSchema(PDO $conn): void
    {
        if (self::$schemaChecked) return;

        // SaleController schema check first (dependency)
        SaleController::ensureReturnSchema($conn);

        if (!app_has_table($conn, self::TABLE) && !app_runtime_schema_changes_allowed()) {
            app_fail_runtime_schema_change(self::TABLE);
        }

        $conn->exec("
            CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
                shift_closing_id   int(11)          NOT NULL AUTO_INCREMENT,
                user_id            int(11)          DEFAULT NULL,
                shift_date         date             NOT NULL,
                opened_at          datetime         NOT NULL DEFAULT current_timestamp(),
                closed_at          datetime         DEFAULT NULL,
                editable_until     datetime         DEFAULT NULL,
                total_transactions int(11)          NOT NULL DEFAULT 0,
                total_items        int(11)          NOT NULL DEFAULT 0,
                total_sales        decimal(12,2)    NOT NULL DEFAULT 0.00,
                cash_sales         decimal(12,2)    NOT NULL DEFAULT 0.00,
                starting_cash      decimal(12,2)    NOT NULL DEFAULT 0.00,
                expected_cash      decimal(12,2)    NOT NULL DEFAULT 0.00,
                counted_cash       decimal(12,2)    NOT NULL DEFAULT 0.00,
                variance           decimal(12,2)    NOT NULL DEFAULT 0.00,
                payment_breakdown_json longtext     DEFAULT NULL,
                notes              text             DEFAULT NULL,
                last_updated_by    int(11)          DEFAULT NULL,
                last_updated_at    datetime         DEFAULT NULL,
                override_reason    text             DEFAULT NULL,
                status             enum('open','closed') NOT NULL DEFAULT 'closed',
                created_at         timestamp        NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (shift_closing_id),
                UNIQUE KEY " . self::UNIQUE_USER_DATE_INDEX . " (user_id, shift_date),
                KEY idx_shift_closings_status    (status),
                KEY idx_shift_closings_closed_at (closed_at),
                CONSTRAINT shift_closings_ibfk_1
                    FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        if (!app_has_table($conn, self::REQUEST_TABLE) && !app_runtime_schema_changes_allowed()) {
            app_fail_runtime_schema_change(self::REQUEST_TABLE);
        }

        $conn->exec("
            CREATE TABLE IF NOT EXISTS " . self::REQUEST_TABLE . " (
                request_id          int(11)          NOT NULL AUTO_INCREMENT,
                shift_closing_id    int(11)          NOT NULL,
                target_user_id      int(11)          NOT NULL,
                requested_by        int(11)          NOT NULL,
                request_reason      text             NOT NULL,
                status              enum('pending','approved','declined') NOT NULL DEFAULT 'pending',
                requested_at        datetime         NOT NULL DEFAULT current_timestamp(),
                reviewed_at         datetime         DEFAULT NULL,
                reviewed_by         int(11)          DEFAULT NULL,
                review_note         text             DEFAULT NULL,
                approved_until      datetime         DEFAULT NULL,
                PRIMARY KEY (request_id),
                KEY idx_shift_edit_requests_shift  (shift_closing_id),
                KEY idx_shift_edit_requests_status (status),
                KEY idx_shift_edit_requests_target (target_user_id),
                CONSTRAINT fk_shift_edit_requests_shift
                    FOREIGN KEY (shift_closing_id)
                    REFERENCES " . self::TABLE . " (shift_closing_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        // Column migrations (safe — each checks existence first)
        self::ensureColumn($conn, 'editable_until',   'editable_until datetime DEFAULT NULL AFTER closed_at');
        self::ensureColumn($conn, 'starting_cash',    'starting_cash decimal(12,2) NOT NULL DEFAULT 0.00 AFTER cash_sales');
        self::ensureColumn($conn, 'last_updated_by',  'last_updated_by int(11) DEFAULT NULL AFTER notes');
        self::ensureColumn($conn, 'last_updated_at',  'last_updated_at datetime DEFAULT NULL AFTER last_updated_by');
        self::ensureColumn($conn, 'override_reason',  'override_reason text DEFAULT NULL AFTER last_updated_at');
        self::ensureColumn($conn, 'status',
            "status enum('open','closed') NOT NULL DEFAULT 'closed' AFTER notes");
        self::ensureClosedAtNullable($conn);
        self::ensureIndex($conn, 'idx_shift_closings_status',
            'ADD KEY idx_shift_closings_status (status)');
        self::ensureUniqueUserDateIndex($conn);

        self::$schemaChecked = true;
    }

    // =========================================================================
    // PUBLIC READ
    // =========================================================================

    public static function shiftForUser(PDO $conn, int $userId, string $shiftDate): ?array
    {
        self::ensureSchema($conn);
        if ($userId <= 0) return null;
        return self::findShiftRow($conn, $userId, self::normalizeShiftDate($shiftDate));
    }

    public static function summaryForUser(PDO $conn, int $userId, string $shiftDate): array
    {
        self::ensureSchema($conn);
        return self::buildSummaryForUser($conn, $userId, $shiftDate);
    }

    public static function salesForUser(PDO $conn, int $userId, string $shiftDate, int $limit = 100): array
    {
        self::ensureSchema($conn);
        if ($userId <= 0) return [];

        $shiftDate      = self::normalizeShiftDate($shiftDate);
        $shift          = self::findShiftRow($conn, $userId, $shiftDate);
        $useShiftWindow = self::shouldUseShiftWindow($shift);
        $limit          = max(1, min(200, $limit));

        [$saleWhere, $saleParams] = self::buildSaleWhereClause($userId, $shiftDate, $shift, $useShiftWindow);

        $stmt = $conn->prepare("
            SELECT
                s.sale_id, s.sale_date, s.total_amount, s.tax, s.discount,
                s.payment_method, s.status,
                COALESCE(items.item_lines,   0) AS item_lines,
                COALESCE(items.total_items,  0) AS total_items
            FROM sales s
            LEFT JOIN (
                SELECT sale_id,
                       COUNT(*) AS item_lines,
                       SUM(
                           GREATEST(COALESCE(quantity,0) - COALESCE(returned_quantity,0), 0)
                           * COALESCE(unit_multiplier,1)
                       ) AS total_items
                FROM sale_items
                GROUP BY sale_id
            ) items ON items.sale_id = s.sale_id
            WHERE {$saleWhere}
            ORDER BY s.sale_date DESC, s.sale_id DESC
            LIMIT :limit
        ");

        foreach ($saleParams as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function recentClosings(PDO $conn, ?int $userId = null, int $limit = 10): array
    {
        self::ensureSchema($conn);
        $limit = max(1, min(50, $limit));

        $sql = "
            SELECT
                sc.shift_closing_id, sc.user_id, sc.shift_date,
                sc.opened_at, sc.closed_at, sc.editable_until,
                sc.total_transactions, sc.total_items, sc.total_sales,
                sc.cash_sales, sc.starting_cash, sc.expected_cash,
                sc.counted_cash, sc.variance, sc.notes,
                sc.last_updated_by, sc.last_updated_at, sc.override_reason,
                sc.status,
                u.first_name, u.last_name, u.username, u.role
            FROM " . self::TABLE . " sc
            INNER JOIN (
                SELECT MAX(shift_closing_id) AS shift_closing_id
                FROM " . self::TABLE . "
                GROUP BY user_id, shift_date
            ) latest ON latest.shift_closing_id = sc.shift_closing_id
            LEFT JOIN users u ON u.user_id = sc.user_id
        ";

        $params = [];
        if ($userId !== null && $userId > 0) {
            $sql              .= ' WHERE sc.user_id = :user_id';
            $params[':user_id'] = $userId;
        }

        $sql .= ' ORDER BY COALESCE(sc.closed_at, sc.opened_at) DESC, sc.shift_closing_id DESC LIMIT :limit';

        $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function hasOpenShiftForToday(PDO $conn, int $userId): bool
    {
        self::ensureSchema($conn);
        if ($userId <= 0) return false;
        $shift = self::findShiftRow($conn, $userId, date('Y-m-d'));
        if ($shift === null) return false;
        return strtolower((string) ($shift['status'] ?? self::STATUS_CLOSED)) === self::STATUS_OPEN;
    }

    public static function editWindowHours(?PDO $conn = null): int
    {
        return $conn instanceof PDO
            ? PosConfigController::shiftEditWindowHours($conn)
            : self::EDIT_WINDOW_HOURS;
    }

    public static function closingEditMeta(?array $shift, bool $isAdmin): array
    {
        $editableUntil = self::effectiveEditableUntil($shift);
        $isClosed      = strtolower((string) ($shift['status'] ?? '')) === self::STATUS_CLOSED;
        $isLocked      = $isClosed
            && $editableUntil !== null
            && strtotime($editableUntil) !== false
            && time() > strtotime($editableUntil);
        $canEdit = !$isClosed || !$isLocked || $isAdmin;

        return [
            'editable_until'        => $editableUntil,
            'is_locked'             => $isLocked,
            'can_edit'              => $canEdit,
            'requires_admin_override' => $isClosed && $isLocked,
        ];
    }

    // =========================================================================
    // PUBLIC WRITE
    // =========================================================================

    public static function startShift(PDO $conn, int $userId, array $input): array
    {
        self::ensureSchema($conn);
        if ($userId <= 0) throw new InvalidArgumentException('A valid user is required to start the shift.');

        $shiftDate    = self::normalizeShiftDate($input['shift_date'] ?? null);
        self::assertShiftStartDateIsToday($shiftDate);
        $startingCash = self::normalizeOptionalMoney($input['starting_cash'] ?? 0, 'Starting cash');
        $started      = false;

        try {
            if (!$conn->inTransaction()) { $conn->beginTransaction(); $started = true; }

            $existing = self::findShiftRow($conn, $userId, $shiftDate, true);
            if ($existing !== null) {
                $st = strtolower((string) ($existing['status'] ?? self::STATUS_CLOSED));
                throw new InvalidArgumentException(
                    $st === self::STATUS_OPEN
                        ? 'This shift is already open.'
                        : 'This shift has already been closed for this date.'
                );
            }

            $conn->prepare("
                INSERT INTO " . self::TABLE . "
                    (user_id, shift_date, opened_at, closed_at, starting_cash, expected_cash, status)
                VALUES
                    (:user, :date, NOW(), NULL, :cash, :expected, :status)
            ")->execute([
                ':user'     => $userId,
                ':date'     => $shiftDate,
                ':cash'     => $startingCash,
                ':expected' => $startingCash,
                ':status'   => self::STATUS_OPEN,
            ]);

            $shiftClosingId = (int) $conn->lastInsertId();

            if ($started && $conn->inTransaction()) $conn->commit();

            self::notifyShiftStarted($conn, $userId, $shiftDate, $startingCash);

            return [
                'shift_closing_id' => $shiftClosingId,
                'shift_date'       => $shiftDate,
                'starting_cash'    => $startingCash,
                'status'           => self::STATUS_OPEN,
            ];
        } catch (Throwable $e) {
            if ($started && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    public static function closeShift(PDO $conn, int $userId, array $input): array
    {
        self::ensureSchema($conn);
        if ($userId <= 0) throw new InvalidArgumentException('A valid user is required to close the shift.');

        $shiftDate      = self::normalizeShiftDate($input['shift_date'] ?? null);
        $countedCash    = self::normalizeMoney($input['counted_cash']   ?? null);
        $notes          = trim((string) ($input['notes']          ?? ''));
        $actorUserId    = max(0, (int) ($input['_actor_user_id']  ?? $userId));
        $actorRole      = strtolower(trim((string) ($input['_actor_role'] ?? '')));
        $overrideReason = trim((string) ($input['override_reason'] ?? ''));

        if (strlen($notes)          > 2000) throw new InvalidArgumentException('Notes must be 2000 characters or fewer.');
        if (strlen($overrideReason) > 2000) throw new InvalidArgumentException('Override reason must be 2000 characters or fewer.');

        $started = false;

        try {
            if (!$conn->inTransaction()) { $conn->beginTransaction(); $started = true; }

            $shift = self::findShiftRow($conn, $userId, $shiftDate, true);
            if ($shift === null) throw new InvalidArgumentException('Start this shift before closing it.');

            $alreadyClosed = strtolower((string) ($shift['status'] ?? self::STATUS_CLOSED)) === self::STATUS_CLOSED;
            $editMeta      = self::closingEditMeta($shift, $actorRole === 'admin');

            if ($alreadyClosed && !$editMeta['can_edit']) {
                throw new InvalidArgumentException('This shift is locked. Ask the owner/admin to approve any update.');
            }
            if ($alreadyClosed && !empty($editMeta['requires_admin_override'])
                && $actorRole === 'admin' && $overrideReason === '') {
                throw new InvalidArgumentException('Admin override reason is required after the edit window expires.');
            }

            $summary      = self::buildSummaryForUser($conn, $userId, $shiftDate, $shift);
            $expectedCash = (float) ($summary['expected_cash'] ?? 0);
            $variance     = round($countedCash - $expectedCash, 2);
            $breakdownJson = json_encode(
                $summary['payment_breakdown'] ?? [],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            $editWindowHours = PosConfigController::shiftEditWindowHours($conn);
            $closedAtSql     = $alreadyClosed ? 'closed_at = closed_at' : 'closed_at = NOW()';

            $conn->prepare("
                UPDATE " . self::TABLE . "
                SET {$closedAtSql},
                    editable_until = CASE
                        WHEN status = :open_status OR closed_at IS NULL
                        THEN DATE_ADD(NOW(), INTERVAL {$editWindowHours} HOUR)
                        ELSE COALESCE(editable_until,
                             DATE_ADD(closed_at, INTERVAL {$editWindowHours} HOUR))
                    END,
                    total_transactions     = :total_tx,
                    total_items            = :total_items,
                    total_sales            = :total_sales,
                    cash_sales             = :cash_sales,
                    expected_cash          = :expected_cash,
                    counted_cash           = :counted_cash,
                    variance               = :variance,
                    payment_breakdown_json = :breakdown,
                    notes                  = :notes,
                    last_updated_by        = :updated_by,
                    last_updated_at        = NOW(),
                    override_reason        = :override,
                    status                 = :status
                WHERE shift_closing_id = :id
            ")->execute([
                ':id'           => (int) $shift['shift_closing_id'],
                ':open_status'  => self::STATUS_OPEN,
                ':total_tx'     => (int) ($summary['total_transactions'] ?? 0),
                ':total_items'  => (int) ($summary['total_items']        ?? 0),
                ':total_sales'  => (float) ($summary['total_sales']      ?? 0),
                ':cash_sales'   => (float) ($summary['cash_sales']       ?? 0),
                ':expected_cash' => $expectedCash,
                ':counted_cash' => $countedCash,
                ':variance'     => $variance,
                ':breakdown'    => $breakdownJson,
                ':notes'        => $notes !== '' ? $notes : null,
                ':updated_by'   => $actorUserId > 0 ? $actorUserId : null,
                ':override'     => $overrideReason !== '' ? $overrideReason : null,
                ':status'       => self::STATUS_CLOSED,
            ]);

            if ($started && $conn->inTransaction()) $conn->commit();

            self::notifyShiftClosing($conn, $userId, $shiftDate, $summary, $alreadyClosed);

            return [
                'shift_closing_id' => (int) $shift['shift_closing_id'],
                'shift_date'       => $shiftDate,
                'expected_cash'    => $expectedCash,
                'counted_cash'     => $countedCash,
                'variance'         => $variance,
                'summary'          => $summary,
                'was_updated'      => $alreadyClosed,
                'was_override'     => $alreadyClosed
                    && !empty($editMeta['requires_admin_override'])
                    && $actorRole === 'admin',
            ];
        } catch (Throwable $e) {
            if ($started && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    public static function submitEditRequest(PDO $conn, int $shiftClosingId, int $requestedBy, string $reason): array
    {
        self::ensureSchema($conn);

        if ($shiftClosingId <= 0 || $requestedBy <= 0) {
            throw new InvalidArgumentException('Invalid shift edit request.');
        }

        $reason = trim($reason);
        if ($reason === '')             throw new InvalidArgumentException('Request reason is required.');
        if (strlen($reason) > 2000)    throw new InvalidArgumentException('Request reason must be 2000 characters or fewer.');

        $started = false;
        try {
            if (!$conn->inTransaction()) { $conn->beginTransaction(); $started = true; }

            $shift = self::findShiftById($conn, $shiftClosingId, true);
            if ($shift === null) throw new InvalidArgumentException('Shift record not found.');

            if ((int) ($shift['user_id'] ?? 0) !== $requestedBy) {
                throw new InvalidArgumentException('You can only request edit access for your own shift.');
            }

            $editMeta = self::closingEditMeta($shift, false);
            if (!$editMeta['is_locked']) {
                throw new InvalidArgumentException('This shift is still editable. No approval request is needed yet.');
            }

            $pendingStmt = $conn->prepare("
                SELECT request_id
                FROM " . self::REQUEST_TABLE . "
                WHERE shift_closing_id = :sc AND requested_by = :rb AND status = 'pending'
                ORDER BY request_id DESC LIMIT 1
            ");
            $pendingStmt->execute([':sc' => $shiftClosingId, ':rb' => $requestedBy]);
            if ($pendingStmt->fetch(PDO::FETCH_ASSOC)) {
                throw new InvalidArgumentException('There is already a pending edit request for this shift.');
            }

            $conn->prepare("
                INSERT INTO " . self::REQUEST_TABLE . "
                    (shift_closing_id, target_user_id, requested_by, request_reason, status, requested_at)
                VALUES
                    (:sc, :tuid, :rb, :reason, 'pending', NOW())
            ")->execute([
                ':sc'     => $shiftClosingId,
                ':tuid'   => (int) $shift['user_id'],
                ':rb'     => $requestedBy,
                ':reason' => $reason,
            ]);

            $requestId = (int) $conn->lastInsertId();

            if ($started && $conn->inTransaction()) $conn->commit();

            self::notifyShiftEditRequest($conn, $requestId, $shift, $reason);

            return [
                'request_id'       => $requestId,
                'shift_closing_id' => $shiftClosingId,
                'shift_date'       => (string) ($shift['shift_date'] ?? ''),
            ];
        } catch (Throwable $e) {
            if ($started && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    public static function listEditRequests(
        PDO     $conn,
        string  $role,
        ?int    $userId = null,
        string  $status = 'all',
        int     $limit  = 50
    ): array {
        self::ensureSchema($conn);
        $limit  = max(1, min(100, $limit));
        $where  = [];
        $params = [];

        if ($role !== 'admin') {
            $where[]              = 'req.requested_by = :requested_by';
            $params[':requested_by'] = max(0, (int) $userId);
        }
        if (in_array($status, ['pending', 'approved', 'declined'], true)) {
            $where[]         = 'req.status = :status';
            $params[':status'] = $status;
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $stmt = $conn->prepare("
            SELECT
                req.request_id, req.shift_closing_id, req.target_user_id,
                req.requested_by, req.request_reason, req.status,
                req.requested_at, req.reviewed_at, req.reviewed_by,
                req.review_note, req.approved_until,
                sc.shift_date, sc.closed_at,
                t.first_name AS target_first_name,   t.last_name AS target_last_name,   t.username AS target_username,
                r.first_name AS requester_first_name, r.last_name AS requester_last_name, r.username AS requester_username,
                rv.first_name AS reviewer_first_name, rv.last_name AS reviewer_last_name, rv.username AS reviewer_username
            FROM " . self::REQUEST_TABLE . " req
            INNER JOIN " . self::TABLE . " sc ON sc.shift_closing_id = req.shift_closing_id
            LEFT  JOIN users t  ON t.user_id  = req.target_user_id
            LEFT  JOIN users r  ON r.user_id  = req.requested_by
            LEFT  JOIN users rv ON rv.user_id = req.reviewed_by
            {$whereSql}
            ORDER BY req.requested_at DESC, req.request_id DESC
            LIMIT :limit
        ");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function reviewEditRequest(
        PDO    $conn,
        int    $requestId,
        int    $adminId,
        string $decision,
        string $reviewNote = ''
    ): array {
        self::ensureSchema($conn);

        $decision   = strtolower(trim($decision));
        $reviewNote = trim($reviewNote);

        if (!in_array($decision, ['approved', 'declined'], true)) {
            throw new InvalidArgumentException('Invalid request decision.');
        }
        if ($decision === 'declined' && $reviewNote === '') {
            throw new InvalidArgumentException('A note is required when declining a request.');
        }
        if (strlen($reviewNote) > 2000) {
            throw new InvalidArgumentException('Review note must be 2000 characters or fewer.');
        }

        $started = false;
        try {
            if (!$conn->inTransaction()) { $conn->beginTransaction(); $started = true; }

            $request = self::findEditRequest($conn, $requestId, true);
            if ($request === null)                         throw new InvalidArgumentException('Shift edit request not found.');
            if ((string) ($request['status'] ?? '') !== 'pending') throw new InvalidArgumentException('This request has already been reviewed.');

            $approvedUntil = null;
            if ($decision === 'approved') {
                $unlockHours   = PosConfigController::shiftUnlockWindowHours($conn);
                $approvedUntil = date('Y-m-d H:i:s', strtotime("+{$unlockHours} hours"));
            }

            $conn->prepare("
                UPDATE " . self::REQUEST_TABLE . "
                SET status = :status, reviewed_at = NOW(), reviewed_by = :rb,
                    review_note = :note, approved_until = :approved_until
                WHERE request_id = :id
            ")->execute([
                ':status'        => $decision,
                ':rb'            => $adminId,
                ':note'          => $reviewNote !== '' ? $reviewNote : null,
                ':approved_until' => $approvedUntil,
                ':id'            => $requestId,
            ]);

            if ($decision === 'approved' && $approvedUntil !== null) {
                $conn->prepare("
                    UPDATE " . self::TABLE . "
                    SET editable_until = :eu
                    WHERE shift_closing_id = :sc
                ")->execute([
                    ':eu' => $approvedUntil,
                    ':sc' => (int) $request['shift_closing_id'],
                ]);
            }

            if ($started && $conn->inTransaction()) $conn->commit();

            self::notifyShiftEditDecision($conn, $request, $decision, $reviewNote, $approvedUntil);

            return [
                'request_id'    => $requestId,
                'decision'      => $decision,
                'approved_until' => $approvedUntil,
            ];
        } catch (Throwable $e) {
            if ($started && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    // =========================================================================
    // PRIVATE — query helpers
    // =========================================================================

    /**
     * Build the WHERE clause and params shared across summary queries.
     * Extracted to eliminate the triple copy-paste from buildSummaryForUser().
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function buildSaleWhereClause(
        int     $userId,
        string  $shiftDate,
        ?array  $shift,
        bool    $useShiftWindow
    ): array {
        $where  = "s.user_id = :user_id AND DATE(s.sale_date) = :shift_date"
                . " AND COALESCE(s.status,'completed') NOT IN ('voided','returned')";
        $params = [':user_id' => $userId, ':shift_date' => $shiftDate];

        if ($useShiftWindow && !empty($shift['opened_at'])) {
            $where           .= ' AND s.sale_date >= :opened_at';
            $params[':opened_at'] = (string) $shift['opened_at'];
        }
        if ($useShiftWindow
            && !empty($shift['closed_at'])
            && strtolower((string) ($shift['status'] ?? self::STATUS_CLOSED)) === self::STATUS_CLOSED
        ) {
            $where            .= ' AND s.sale_date <= :closed_at';
            $params[':closed_at'] = (string) $shift['closed_at'];
        }

        return [$where, $params];
    }

    private static function buildSummaryForUser(
        PDO    $conn,
        int    $userId,
        string $shiftDate,
        ?array $shift = null
    ): array {
        $shiftDate      = self::normalizeShiftDate($shiftDate);
        $shift        ??= self::findShiftRow($conn, $userId, $shiftDate);
        $useShiftWindow = self::shouldUseShiftWindow($shift);

        SaleController::ensureReturnSchema($conn);

        [$saleWhere, $saleParams] = self::buildSaleWhereClause($userId, $shiftDate, $shift, $useShiftWindow);

        // ── Aggregate summary ──────────────────────────────────────────────
        $summaryStmt = $conn->prepare("
            SELECT
                COUNT(DISTINCT s.sale_id)  AS total_transactions,
                IFNULL(SUM(s.total_amount), 0)  AS total_sales,
                IFNULL(SUM(s.tax),          0)  AS total_tax,
                IFNULL(SUM(s.discount),     0)  AS total_discount,
                IFNULL(SUM(CASE WHEN s.payment_method = 'cash' THEN s.total_amount ELSE 0 END), 0) AS cash_sales,
                IFNULL(SUM(items.total_items), 0) AS total_items,
                MIN(s.sale_date) AS first_sale_at,
                MAX(s.sale_date) AS last_sale_at
            FROM sales s
            LEFT JOIN (
                SELECT sale_id,
                       SUM(GREATEST(COALESCE(quantity,0) - COALESCE(returned_quantity,0), 0)
                           * COALESCE(unit_multiplier,1)) AS total_items
                FROM sale_items
                GROUP BY sale_id
            ) items ON items.sale_id = s.sale_id
            WHERE {$saleWhere}
        ");
        $summaryStmt->execute($saleParams);
        $agg = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // ── Payment breakdown ──────────────────────────────────────────────
        $paymentStmt = $conn->prepare("
            SELECT s.payment_method,
                   COUNT(*) AS sale_count,
                   IFNULL(SUM(s.total_amount), 0) AS total_amount
            FROM sales s
            WHERE {$saleWhere}
            GROUP BY s.payment_method
            ORDER BY total_amount DESC, s.payment_method ASC
        ");
        $paymentStmt->execute($saleParams);
        $paymentBreakdown = $paymentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // ── Latest sale ────────────────────────────────────────────────────
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
        $cashSales    = (float) ($agg['cash_sales']      ?? 0);
        $expectedCash = round($startingCash + $cashSales, 2);

        return [
            'shift_closing_id'   => isset($shift['shift_closing_id']) ? (int) $shift['shift_closing_id'] : null,
            'shift_date'         => $shiftDate,
            'status'             => $shift !== null
                ? (string) ($shift['status'] ?? self::STATUS_CLOSED)
                : 'not_started',
            'opened_at'          => $shift['opened_at']   ?? null,
            'closed_at'          => $shift['closed_at']   ?? null,
            'editable_until'     => self::effectiveEditableUntil($shift),
            'starting_cash'      => $startingCash,
            'total_transactions' => (int)   ($agg['total_transactions'] ?? 0),
            'total_items'        => (int)   ($agg['total_items']        ?? 0),
            'total_sales'        => (float) ($agg['total_sales']        ?? 0),
            'total_tax'          => (float) ($agg['total_tax']          ?? 0),
            'total_discount'     => (float) ($agg['total_discount']     ?? 0),
            'cash_sales'         => $cashSales,
            'expected_cash'      => $expectedCash,
            'first_sale_at'      => $agg['first_sale_at'] ?? null,
            'last_sale_at'       => $agg['last_sale_at']  ?? null,
            'payment_breakdown'  => $paymentBreakdown,
            'latest_sale'        => $latestSale,
            'last_updated_by'    => isset($shift['last_updated_by']) ? (int) $shift['last_updated_by'] : null,
            'last_updated_at'    => $shift['last_updated_at']   ?? null,
            'override_reason'    => $shift['override_reason']   ?? null,
        ];
    }

    // =========================================================================
    // PRIVATE — row finders
    // =========================================================================

    private static function findShiftRow(PDO $conn, int $userId, string $shiftDate, bool $forUpdate = false): ?array
    {
        $sql = "
            SELECT shift_closing_id, user_id, shift_date, opened_at, closed_at,
                   editable_until, total_transactions, total_items, total_sales,
                   cash_sales, starting_cash, expected_cash, counted_cash,
                   variance, payment_breakdown_json, notes,
                   last_updated_by, last_updated_at, override_reason, status, created_at
            FROM " . self::TABLE . "
            WHERE user_id = :uid AND shift_date = :date
            ORDER BY shift_closing_id DESC
            LIMIT 1
        ";
        if ($forUpdate) $sql .= ' FOR UPDATE';

        $stmt = $conn->prepare($sql);
        $stmt->execute([':uid' => $userId, ':date' => $shiftDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function findShiftById(PDO $conn, int $id, bool $forUpdate = false): ?array
    {
        $sql = "
            SELECT shift_closing_id, user_id, shift_date, opened_at, closed_at,
                   editable_until, total_transactions, total_items, total_sales,
                   cash_sales, starting_cash, expected_cash, counted_cash,
                   variance, payment_breakdown_json, notes,
                   last_updated_by, last_updated_at, override_reason, status, created_at
            FROM " . self::TABLE . "
            WHERE shift_closing_id = :id
            LIMIT 1
        ";
        if ($forUpdate) $sql .= ' FOR UPDATE';

        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function findEditRequest(PDO $conn, int $requestId, bool $forUpdate = false): ?array
    {
        $sql = "
            SELECT req.request_id, req.shift_closing_id, req.target_user_id,
                   req.requested_by, req.request_reason, req.status,
                   req.requested_at, req.reviewed_at, req.reviewed_by,
                   req.review_note, req.approved_until,
                   sc.shift_date, sc.closed_at
            FROM " . self::REQUEST_TABLE . " req
            INNER JOIN " . self::TABLE . " sc ON sc.shift_closing_id = req.shift_closing_id
            WHERE req.request_id = :id
            LIMIT 1
        ";
        if ($forUpdate) $sql .= ' FOR UPDATE';

        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // =========================================================================
    // PRIVATE — schema helpers
    // =========================================================================

    private static function ensureColumn(PDO $conn, string $column, string $definition): void
    {
        try {
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME   = :t
                  AND COLUMN_NAME  = :c
            ");
            $stmt->execute([':t' => self::TABLE, ':c' => $column]);
            if ((int) $stmt->fetchColumn() > 0) return;

            if (!app_runtime_schema_changes_allowed()) {
                app_fail_runtime_schema_change(self::TABLE . '.' . $column);
            }

            $conn->exec("ALTER TABLE " . self::TABLE . " ADD COLUMN {$definition}");
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
                  AND TABLE_NAME   = :t
                  AND COLUMN_NAME  = 'closed_at'
                LIMIT 1
            ");
            $stmt->execute([':t' => self::TABLE]);
            if (strtoupper((string) $stmt->fetchColumn()) === 'YES') return;

            if (!app_runtime_schema_changes_allowed()) {
                app_fail_runtime_schema_change(self::TABLE . '.closed_at');
            }

            $conn->exec("ALTER TABLE " . self::TABLE . " MODIFY closed_at datetime DEFAULT NULL");
        } catch (Throwable $e) {
            error_log('[ShiftClosingController][schema] ' . $e->getMessage());
        }
    }

    private static function ensureIndex(PDO $conn, string $indexName, string $definition): void
    {
        if (function_exists('app_runtime_schema_changes_allowed') && !app_runtime_schema_changes_allowed()) return;
        try {
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME   = :t
                  AND INDEX_NAME   = :i
            ");
            $stmt->execute([':t' => self::TABLE, ':i' => $indexName]);
            if ((int) $stmt->fetchColumn() > 0) return;

            $conn->exec("ALTER TABLE " . self::TABLE . " {$definition}");
        } catch (Throwable $e) {
            error_log('[ShiftClosingController][schema] ' . $e->getMessage());
        }
    }

    private static function ensureUniqueUserDateIndex(PDO $conn): void
    {
        if (function_exists('app_runtime_schema_changes_allowed') && !app_runtime_schema_changes_allowed()) return;
        try {
            $idx = $conn->query(
                "SHOW INDEX FROM " . self::TABLE . " WHERE Key_name = '" . self::UNIQUE_USER_DATE_INDEX . "'"
            );
            if ($idx instanceof PDOStatement && $idx->fetch(PDO::FETCH_ASSOC)) return;

            $dup = $conn->query("
                SELECT user_id, shift_date, COUNT(*) AS n
                FROM " . self::TABLE . "
                WHERE user_id IS NOT NULL
                GROUP BY user_id, shift_date
                HAVING COUNT(*) > 1
                LIMIT 1
            ");
            if ($dup instanceof PDOStatement && $dup->fetch(PDO::FETCH_ASSOC)) {
                error_log('[ShiftClosingController] Duplicate rows found; skipped unique index creation.');
                return;
            }

            $conn->exec(
                "ALTER TABLE " . self::TABLE .
                " ADD UNIQUE KEY " . self::UNIQUE_USER_DATE_INDEX . " (user_id, shift_date)"
            );
        } catch (Throwable $e) {
            error_log('[ShiftClosingController] ' . $e->getMessage());
        }
    }

    // =========================================================================
    // PRIVATE — business helpers
    // =========================================================================

    private static function shouldUseShiftWindow(?array $shift): bool
    {
        if ($shift === null || empty($shift['opened_at'])) return false;

        $status    = strtolower((string) ($shift['status'] ?? self::STATUS_CLOSED));
        $openedAt  = strtotime((string) $shift['opened_at']);
        $closedAt  = !empty($shift['closed_at']) ? strtotime((string) $shift['closed_at']) : false;
        $savedTx   = (int) ($shift['total_transactions'] ?? 0);

        // Edge case: shift opened and closed within 1 second with saved transactions
        // — treat as an instant close where the time window is not meaningful
        if ($status === self::STATUS_CLOSED
            && $openedAt !== false
            && $closedAt !== false
            && abs($closedAt - $openedAt) <= 1
            && $savedTx > 0
        ) {
            return false;
        }

        return true;
    }

    private static function effectiveEditableUntil(?array $shift): ?string
    {
        if ($shift === null) return null;

        $val = trim((string) ($shift['editable_until'] ?? ''));
        if ($val !== '') return $val;

        $closedAt = trim((string) ($shift['closed_at'] ?? ''));
        if ($closedAt === '') return null;

        $ts = strtotime($closedAt);
        if ($ts === false) return null;

        return date('Y-m-d H:i:s', strtotime('+' . self::EDIT_WINDOW_HOURS . ' hours', $ts));
    }

    private static function normalizeShiftDate(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') return date('Y-m-d');

        $dt = DateTime::createFromFormat('Y-m-d', $value);
        if (!$dt) throw new InvalidArgumentException('Shift date is invalid.');

        return $dt->format('Y-m-d');
    }

    private static function assertShiftStartDateIsToday(string $shiftDate): void
    {
        if ($shiftDate !== date('Y-m-d')) {
            throw new InvalidArgumentException('You can only start a shift for today.');
        }
    }

    private static function normalizeOptionalMoney(mixed $value, string $label): float
    {
        $value = trim((string) $value);
        if ($value === '') return 0.0;

        $n = str_replace([',', ' '], '', $value);
        if (!is_numeric($n)) throw new InvalidArgumentException("{$label} must be numeric.");

        $amount = round((float) $n, 2);
        if ($amount < 0) throw new InvalidArgumentException("{$label} cannot be negative.");

        return $amount;
    }

    private static function normalizeMoney(mixed $value): float
    {
        $value = trim((string) $value);
        if ($value === '') throw new InvalidArgumentException('Counted cash is required.');

        $n = str_replace([',', ' '], '', $value);
        if (!is_numeric($n)) throw new InvalidArgumentException('Counted cash must be numeric.');

        $amount = round((float) $n, 2);
        if ($amount < 0) throw new InvalidArgumentException('Counted cash cannot be negative.');

        return $amount;
    }

    // =========================================================================
    // PRIVATE — notifications
    // =========================================================================

    private static function notifyShiftStarted(PDO $conn, int $userId, string $shiftDate, float $startingCash): void
    {
        try {
            NotificationController::create($conn, $userId, 'admin', 'shift_closing_started',
                'Shift started',
                sprintf('%s started shift for %s with PHP %s starting cash.',
                    self::userDisplayLabel($conn, $userId),
                    date('M d, Y', strtotime($shiftDate)),
                    number_format($startingCash, 2)),
                'bi-play-circle', 'text-success',
                '/inventory_system/shift_closing.php?' . http_build_query([
                    'shift_date' => $shiftDate, 'user_id' => $userId,
                ]));
        } catch (Throwable $e) {
            error_log('[ShiftClosingController][notify] ' . $e->getMessage());
        }
    }

    private static function notifyShiftClosing(PDO $conn, int $userId, string $shiftDate, array $summary, bool $wasUpdated): void
    {
        try {
            NotificationController::create($conn, $userId, 'admin',
                $wasUpdated ? 'shift_closing_updated' : 'shift_closing_saved',
                $wasUpdated ? 'Shift closing updated' : 'Shift closed',
                sprintf('%s shift for %s saved with PHP %s in sales.',
                    self::userDisplayLabel($conn, $userId),
                    date('M d, Y', strtotime($shiftDate)),
                    number_format((float) ($summary['total_sales'] ?? 0), 2)),
                'bi-cash-stack', 'text-primary',
                '/inventory_system/shift_closing.php?' . http_build_query([
                    'shift_date' => $shiftDate, 'user_id' => $userId,
                ]));
        } catch (Throwable $e) {
            error_log('[ShiftClosingController][notify] ' . $e->getMessage());
        }
    }

    private static function notifyShiftEditRequest(PDO $conn, int $requestId, array $shift, string $reason): void
    {
        try {
            $uid = (int) ($shift['user_id'] ?? 0);
            NotificationController::create($conn, $uid, 'admin', 'shift_edit_request',
                'Shift edit request pending',
                sprintf('%s requested access to update the %s shift record. Reason: %s',
                    self::userDisplayLabel($conn, $uid),
                    date('M d, Y', strtotime((string) ($shift['shift_date'] ?? date('Y-m-d')))),
                    $reason),
                'bi-hourglass-split', 'text-warning',
                '/inventory_system/shift_edit_requests.php?request_id=' . $requestId);
        } catch (Throwable $e) {
            error_log('[ShiftClosingController][notify] ' . $e->getMessage());
        }
    }

    private static function notifyShiftEditDecision(
        PDO     $conn,
        array   $request,
        string  $decision,
        string  $reviewNote,
        ?string $approvedUntil
    ): void {
        try {
            $uid       = (int) ($request['requested_by'] ?? 0);
            $shiftDate = (string) ($request['shift_date'] ?? date('Y-m-d'));
            $approved  = $decision === 'approved';
            $message   = $approved
                ? sprintf('Your request to update the %s shift was approved. Edit access open until %s.',
                    date('M d, Y', strtotime($shiftDate)),
                    $approvedUntil !== null ? date('M d, g:i A', strtotime($approvedUntil)) : 'the window ends')
                : sprintf('Your request to update the %s shift was declined.%s',
                    date('M d, Y', strtotime($shiftDate)),
                    $reviewNote !== '' ? ' Note: ' . $reviewNote : '');

            NotificationController::create($conn, $uid, 'cashier',
                $approved ? 'shift_edit_request_approved' : 'shift_edit_request_declined',
                $approved ? 'Shift edit approved' : 'Shift edit declined',
                $message,
                $approved ? 'bi-unlock' : 'bi-slash-circle',
                $approved ? 'text-success' : 'text-danger',
                '/inventory_system/shift_closing.php?' . http_build_query(['shift_date' => $shiftDate]));
        } catch (Throwable $e) {
            error_log('[ShiftClosingController][notify] ' . $e->getMessage());
        }
    }

    private static function userDisplayLabel(PDO $conn, int $userId): string
    {
        $stmt = $conn->prepare(
            "SELECT first_name, last_name, username FROM users WHERE user_id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $userId]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
        if ($name !== '') return $name;
        $u = trim((string) ($row['username'] ?? ''));
        return $u !== '' ? $u : 'Cashier';
    }
}