<?php
declare(strict_types=1);

require_once __DIR__ . '/SaleController.php';

final class SaleActionRequestController
{
    private const TABLE = 'sale_action_requests';
    private const ACTIONS = ['void_sale', 'return_item'];

    public static function ensureSchema(PDO $conn): void
    {
        if (!app_has_table($conn, self::TABLE) && !app_runtime_schema_changes_allowed()) {
            app_fail_runtime_schema_change(self::TABLE);
        }

        $conn->exec("
            CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
                request_id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                sale_id INT(11) NOT NULL,
                sale_item_id INT(11) DEFAULT NULL,
                requester_user_id INT(11) NOT NULL,
                action_type VARCHAR(30) NOT NULL,
                quantity INT(11) DEFAULT NULL,
                reason TEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                review_note TEXT DEFAULT NULL,
                reviewer_user_id INT(11) DEFAULT NULL,
                reviewed_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_sale_action_requests_status (status),
                KEY idx_sale_action_requests_sale (sale_id),
                KEY idx_sale_action_requests_requester (requester_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }

    public static function create(PDO $conn, array $payload, int $requesterId, string $requesterRole): array
    {
        self::ensureSchema($conn);
        SaleController::ensureReturnSchema($conn);

        $saleId = max(0, (int) ($payload['sale_id'] ?? 0));
        $saleItemId = isset($payload['sale_item_id']) ? max(0, (int) $payload['sale_item_id']) : 0;
        $actionType = strtolower(trim((string) ($payload['action_type'] ?? '')));
        $quantity = isset($payload['quantity']) ? max(0, (int) $payload['quantity']) : null;
        $reason = trim((string) ($payload['reason'] ?? ''));

        if ($saleId <= 0) {
            throw new InvalidArgumentException('Invalid sale request.');
        }

        if (!in_array($actionType, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Invalid sale action request.');
        }

        if ($actionType === 'return_item' && ($saleItemId <= 0 || $quantity === null || $quantity <= 0)) {
            throw new InvalidArgumentException('Choose an item and return quantity.');
        }

        if ($reason === '') {
            throw new InvalidArgumentException('Please enter a reason for this request.');
        }

        $sale = self::loadSale($conn, $saleId);
        if (!$sale) {
            throw new RuntimeException('Sale not found.');
        }

        if (strtolower($requesterRole) !== 'admin' && (int) ($sale['user_id'] ?? 0) !== $requesterId) {
            throw new RuntimeException('You can only request actions for your own sales.');
        }

        if (in_array(strtolower((string) ($sale['status'] ?? 'completed')), ['voided', 'returned'], true)) {
            throw new RuntimeException('This sale can no longer be requested for adjustment.');
        }

        $duplicateStmt = $conn->prepare("
            SELECT request_id
            FROM " . self::TABLE . "
            WHERE sale_id = :sale_id
              AND action_type = :action_type
              AND status = 'pending'
            LIMIT 1
        ");
        $duplicateStmt->execute([
            ':sale_id' => $saleId,
            ':action_type' => $actionType,
        ]);
        if ($duplicateStmt->fetchColumn()) {
            throw new RuntimeException('There is already a pending request for this sale.');
        }

        $stmt = $conn->prepare("
            INSERT INTO " . self::TABLE . " (
                sale_id, sale_item_id, requester_user_id, action_type, quantity, reason, status, created_at
            ) VALUES (
                :sale_id, :sale_item_id, :requester_user_id, :action_type, :quantity, :reason, 'pending', NOW()
            )
        ");
        $stmt->execute([
            ':sale_id' => $saleId,
            ':sale_item_id' => $saleItemId > 0 ? $saleItemId : null,
            ':requester_user_id' => $requesterId,
            ':action_type' => $actionType,
            ':quantity' => $quantity,
            ':reason' => mb_substr($reason, 0, 1000),
        ]);

        return self::find($conn, (int) $conn->lastInsertId()) ?? throw new RuntimeException('Request saved but could not be loaded.');
    }

    public static function listRequests(PDO $conn, string $role, int $userId, int $limit = 200): array
    {
        self::ensureSchema($conn);
        $limit = max(25, min(500, $limit));
        $sql = "
            SELECT sar.*, s.sale_date, s.total_amount, s.payment_method, s.status AS sale_status,
                   ru.first_name AS requester_first_name, ru.last_name AS requester_last_name, ru.username AS requester_username,
                   rv.first_name AS reviewer_first_name, rv.last_name AS reviewer_last_name, rv.username AS reviewer_username
            FROM " . self::TABLE . " sar
            INNER JOIN sales s ON s.sale_id = sar.sale_id
            LEFT JOIN users ru ON ru.user_id = sar.requester_user_id
            LEFT JOIN users rv ON rv.user_id = sar.reviewer_user_id
        ";
        $params = [];

        if (strtolower($role) !== 'admin') {
            $sql .= " WHERE sar.requester_user_id = :user_id";
            $params[':user_id'] = $userId;
        }

        $sql .= " ORDER BY sar.created_at DESC, sar.request_id DESC LIMIT :limit";
        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function review(PDO $conn, int $requestId, int $adminId, string $decision, string $reviewNote, array $logConfig): array
    {
        self::ensureSchema($conn);
        $decision = strtolower(trim($decision));

        if (!in_array($decision, ['approved', 'declined'], true)) {
            throw new InvalidArgumentException('Invalid review decision.');
        }

        $request = self::find($conn, $requestId);
        if (!$request || strtolower((string) ($request['status'] ?? '')) !== 'pending') {
            throw new RuntimeException('Pending request not found.');
        }

        $result = null;
        if ($decision === 'approved') {
            if ((string) $request['action_type'] === 'void_sale') {
                $result = SaleController::voidSale($conn, (int) $request['sale_id'], $adminId, (string) $request['reason'], $logConfig);
            } else {
                $result = SaleController::returnSaleItem(
                    $conn,
                    (int) $request['sale_id'],
                    (int) $request['sale_item_id'],
                    (int) $request['quantity'],
                    $adminId,
                    (string) $request['reason'],
                    $logConfig
                );
            }
        }

        $stmt = $conn->prepare("
            UPDATE " . self::TABLE . "
            SET status = :status,
                review_note = :review_note,
                reviewer_user_id = :reviewer_user_id,
                reviewed_at = NOW()
            WHERE request_id = :request_id
        ");
        $stmt->execute([
            ':status' => $decision,
            ':review_note' => trim($reviewNote) !== '' ? mb_substr(trim($reviewNote), 0, 1000) : null,
            ':reviewer_user_id' => $adminId,
            ':request_id' => $requestId,
        ]);

        $reviewed = self::find($conn, $requestId) ?? $request;
        $reviewed['action_result'] = $result;
        return $reviewed;
    }

    private static function find(PDO $conn, int $requestId): ?array
    {
        $stmt = $conn->prepare("
            SELECT *
            FROM " . self::TABLE . "
            WHERE request_id = :request_id
            LIMIT 1
        ");
        $stmt->execute([':request_id' => $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function loadSale(PDO $conn, int $saleId): ?array
    {
        $stmt = $conn->prepare("
            SELECT sale_id, user_id, status, sale_date
            FROM sales
            WHERE sale_id = :sale_id
            LIMIT 1
        ");
        $stmt->execute([':sale_id' => $saleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
