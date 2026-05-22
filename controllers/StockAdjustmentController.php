<?php
declare(strict_types=1);

require_once __DIR__ . '/ProductController.php';

final class StockAdjustmentController
{
    private const TABLE = 'stock_adjustment_requests';

    public static function ensureSchema(PDO $conn): void
    {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
                request_id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                product_id INT(11) NOT NULL,
                requester_user_id INT(11) NOT NULL,
                reviewer_user_id INT(11) DEFAULT NULL,
                direction VARCHAR(20) NOT NULL DEFAULT 'stock_out',
                quantity INT(11) NOT NULL,
                adjustment_type VARCHAR(50) NOT NULL,
                reason VARCHAR(500) NOT NULL,
                notes TEXT DEFAULT NULL,
                supplier_id INT(11) DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                review_note TEXT DEFAULT NULL,
                before_quantity INT(11) DEFAULT NULL,
                after_quantity INT(11) DEFAULT NULL,
                requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at DATETIME DEFAULT NULL,
                KEY idx_stock_adjustment_status (status),
                KEY idx_stock_adjustment_requester (requester_user_id),
                KEY idx_stock_adjustment_product (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }

    public static function requestableProducts(PDO $conn): array
    {
        ProductController::ensureStockMovementSchema($conn);

        $stmt = $conn->query("
            SELECT
                p.product_id,
                p.product_name,
                p.quantity,
                p.status,
                p.reorder_level,
                p.supplier_id,
                p.pieces_per_box,
                p.boxes_per_case,
                c.category_name,
                s.supplier_name
            FROM products p
            LEFT JOIN categories c ON c.category_id = p.category_id
            LEFT JOIN suppliers s ON s.supplier_id = p.supplier_id
            WHERE p.status = 'active'
            ORDER BY p.product_name ASC
        ");

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public static function submitRequest(PDO $conn, array $payload, int $requesterUserId): array
    {
        self::ensureSchema($conn);

        if ($requesterUserId <= 0) {
            throw new InvalidArgumentException('Invalid requester.');
        }

        $productId = max(0, (int) ($payload['product_id'] ?? 0));
        $quantity = max(0, (int) ($payload['quantity'] ?? 0));
        $direction = self::normalizeDirection((string) ($payload['direction'] ?? 'stock_out'));
        $adjustmentType = self::normalizeAdjustmentType((string) ($payload['adjustment_type'] ?? 'manual_adjustment'));
        $reason = self::normalizeReason((string) ($payload['reason'] ?? ''));
        $notes = self::normalizeNotes((string) ($payload['notes'] ?? ''));
        $supplierId = !empty($payload['supplier_id']) ? max(0, (int) $payload['supplier_id']) : null;

        if ($productId <= 0) {
            throw new InvalidArgumentException('Please choose a product.');
        }

        if ($quantity <= 0) {
            throw new InvalidArgumentException('Quantity must be greater than zero.');
        }

        if ($direction === 'stock_in' && $supplierId !== null && $supplierId <= 0) {
            $supplierId = null;
        }

        $product = ProductController::getProductById($conn, $productId);
        if ($product === null) {
            throw new RuntimeException('Product not found.');
        }

        if ($direction === 'stock_out' && $quantity > (int) ($product['quantity'] ?? 0)) {
            throw new RuntimeException('Requested stock-out exceeds the current product quantity.');
        }

        $stmt = $conn->prepare("
            INSERT INTO " . self::TABLE . " (
                product_id,
                requester_user_id,
                direction,
                quantity,
                adjustment_type,
                reason,
                notes,
                supplier_id,
                status,
                requested_at
            ) VALUES (
                :product_id,
                :requester_user_id,
                :direction,
                :quantity,
                :adjustment_type,
                :reason,
                :notes,
                :supplier_id,
                'pending',
                NOW()
            )
        ");
        $stmt->execute([
            ':product_id' => $productId,
            ':requester_user_id' => $requesterUserId,
            ':direction' => $direction,
            ':quantity' => $quantity,
            ':adjustment_type' => $adjustmentType,
            ':reason' => $reason,
            ':notes' => $notes,
            ':supplier_id' => $supplierId,
        ]);

        return self::findRequest($conn, (int) $conn->lastInsertId()) ?? throw new RuntimeException('Request saved, but could not be reloaded.');
    }

    public static function listRequests(PDO $conn, string $role, ?int $userId = null, string $status = 'all', int $limit = 100): array
    {
        self::ensureSchema($conn);

        $role = strtolower(trim($role));
        $status = strtolower(trim($status));
        $limit = max(1, min($limit, 200));

        $where = [];
        $params = [];

        if ($role !== 'admin') {
            if (($userId ?? 0) <= 0) {
                return [];
            }

            $where[] = 'sar.requester_user_id = :requester_user_id';
            $params[':requester_user_id'] = (int) $userId;
        }

        if ($status !== '' && $status !== 'all') {
            $where[] = 'sar.status = :status';
            $params[':status'] = $status;
        }

        $whereSql = $where !== [] ? ('WHERE ' . implode(' AND ', $where)) : '';

        $stmt = $conn->prepare("
            SELECT
                sar.request_id,
                sar.product_id,
                sar.requester_user_id,
                sar.reviewer_user_id,
                sar.direction,
                sar.quantity,
                sar.adjustment_type,
                sar.reason,
                sar.notes,
                sar.supplier_id,
                sar.status,
                sar.review_note,
                sar.before_quantity,
                sar.after_quantity,
                sar.requested_at,
                sar.reviewed_at,
                p.product_name,
                p.quantity AS current_quantity,
                c.category_name,
                s.supplier_name,
                ru.first_name AS requester_first_name,
                ru.last_name AS requester_last_name,
                ru.username AS requester_username,
                ru.role AS requester_role,
                rv.first_name AS reviewer_first_name,
                rv.last_name AS reviewer_last_name,
                rv.username AS reviewer_username
            FROM " . self::TABLE . " sar
            INNER JOIN products p ON p.product_id = sar.product_id
            LEFT JOIN categories c ON c.category_id = p.category_id
            LEFT JOIN suppliers s ON s.supplier_id = sar.supplier_id
            LEFT JOIN users ru ON ru.user_id = sar.requester_user_id
            LEFT JOIN users rv ON rv.user_id = sar.reviewer_user_id
            {$whereSql}
            ORDER BY sar.requested_at DESC, sar.request_id DESC
            LIMIT :limit
        ");

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function countByStatus(PDO $conn, string $role, ?int $userId = null): array
    {
        $requests = self::listRequests($conn, $role, $userId, 'all', 200);

        return [
            'pending' => count(array_filter($requests, static fn(array $row): bool => ($row['status'] ?? '') === 'pending')),
            'approved' => count(array_filter($requests, static fn(array $row): bool => ($row['status'] ?? '') === 'approved')),
            'declined' => count(array_filter($requests, static fn(array $row): bool => ($row['status'] ?? '') === 'declined')),
        ];
    }

    public static function pendingCount(PDO $conn): int
    {
        self::ensureSchema($conn);

        $stmt = $conn->query("SELECT COUNT(*) FROM " . self::TABLE . " WHERE status = 'pending'");
        return (int) ($stmt ? $stmt->fetchColumn() : 0);
    }

    public static function reviewRequest(PDO $conn, int $requestId, int $reviewerUserId, string $decision, string $reviewNote = ''): array
    {
        self::ensureSchema($conn);

        if ($requestId <= 0 || $reviewerUserId <= 0) {
            throw new InvalidArgumentException('Invalid review request.');
        }

        $decision = strtolower(trim($decision));
        if (!in_array($decision, ['approved', 'declined'], true)) {
            throw new InvalidArgumentException('Invalid review decision.');
        }

        $reviewNote = self::normalizeNotes($reviewNote);
        $request = self::findRequest($conn, $requestId);
        if ($request === null) {
            throw new RuntimeException('Stock adjustment request not found.');
        }

        if (($request['status'] ?? '') !== 'pending') {
            throw new RuntimeException('This stock adjustment request has already been reviewed.');
        }

        if ($decision === 'declined' && ($reviewNote === null || $reviewNote === '')) {
            throw new InvalidArgumentException('Please add a decline reason for the requester.');
        }

        $startedTransaction = false;

        try {
            if (!$conn->inTransaction()) {
                $conn->beginTransaction();
                $startedTransaction = true;
            }

            $beforeQuantity = null;
            $afterQuantity = null;

            if ($decision === 'approved') {
                $product = ProductController::getProductById($conn, (int) ($request['product_id'] ?? 0));
                if ($product === null) {
                    throw new RuntimeException('The product for this request no longer exists.');
                }

                $beforeQuantity = (int) ($product['quantity'] ?? 0);
                $requestNotes = trim((string) ($request['notes'] ?? ''));
                $auditNote = 'Request #' . $requestId
                    . ' from '
                    . self::personLabel($request, 'requester')
                    . ' | Reason: '
                    . (string) ($request['reason'] ?? 'No reason provided');

                if ($requestNotes !== '') {
                    $auditNote .= ' | Note: ' . $requestNotes;
                }

                if (($request['direction'] ?? 'stock_out') === 'stock_in') {
                    ProductController::restockProduct(
                        $conn,
                        (int) ($request['product_id'] ?? 0),
                        (int) ($request['quantity'] ?? 0),
                        $reviewerUserId,
                        $auditNote,
                        [
                            'adjustment_type' => (string) ($request['adjustment_type'] ?? 'manual_restock'),
                            'supplier_id' => !empty($request['supplier_id']) ? (int) $request['supplier_id'] : null,
                            'reference_type' => 'stock_adjustment_request',
                            'reference_id' => $requestId,
                        ]
                    );
                } else {
                    if ((int) ($request['quantity'] ?? 0) > $beforeQuantity) {
                        throw new RuntimeException('Current stock is now lower than the requested stock-out quantity. Please review the request again.');
                    }

                    ProductController::stockOutProduct(
                        $conn,
                        (int) ($request['product_id'] ?? 0),
                        (int) ($request['quantity'] ?? 0),
                        (string) ($request['reason'] ?? 'No reason provided'),
                        $reviewerUserId,
                        [
                            'adjustment_type' => (string) ($request['adjustment_type'] ?? 'manual_adjustment'),
                            'notes' => $auditNote,
                            'reference_type' => 'stock_adjustment_request',
                            'reference_id' => $requestId,
                        ]
                    );
                }

                $updatedProduct = ProductController::getProductById($conn, (int) ($request['product_id'] ?? 0));
                $afterQuantity = (int) ($updatedProduct['quantity'] ?? $beforeQuantity);
            }

            $updateStmt = $conn->prepare("
                UPDATE " . self::TABLE . "
                SET
                    reviewer_user_id = :reviewer_user_id,
                    status = :status,
                    review_note = :review_note,
                    before_quantity = :before_quantity,
                    after_quantity = :after_quantity,
                    reviewed_at = NOW()
                WHERE request_id = :request_id
            ");
            $updateStmt->execute([
                ':reviewer_user_id' => $reviewerUserId,
                ':status' => $decision,
                ':review_note' => $reviewNote,
                ':before_quantity' => $beforeQuantity,
                ':after_quantity' => $afterQuantity,
                ':request_id' => $requestId,
            ]);

            if ($startedTransaction && $conn->inTransaction()) {
                $conn->commit();
            }

            return self::findRequest($conn, $requestId) ?? throw new RuntimeException('Request updated, but could not be reloaded.');
        } catch (Throwable $e) {
            if ($startedTransaction && $conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    public static function findRequest(PDO $conn, int $requestId): ?array
    {
        if ($requestId <= 0) {
            return null;
        }

        $stmt = $conn->prepare("
            SELECT
                sar.request_id,
                sar.product_id,
                sar.requester_user_id,
                sar.reviewer_user_id,
                sar.direction,
                sar.quantity,
                sar.adjustment_type,
                sar.reason,
                sar.notes,
                sar.supplier_id,
                sar.status,
                sar.review_note,
                sar.before_quantity,
                sar.after_quantity,
                sar.requested_at,
                sar.reviewed_at,
                p.product_name,
                p.quantity AS current_quantity,
                c.category_name,
                s.supplier_name,
                ru.first_name AS requester_first_name,
                ru.last_name AS requester_last_name,
                ru.username AS requester_username,
                ru.role AS requester_role,
                rv.first_name AS reviewer_first_name,
                rv.last_name AS reviewer_last_name,
                rv.username AS reviewer_username
            FROM " . self::TABLE . " sar
            INNER JOIN products p ON p.product_id = sar.product_id
            LEFT JOIN categories c ON c.category_id = p.category_id
            LEFT JOIN suppliers s ON s.supplier_id = sar.supplier_id
            LEFT JOIN users ru ON ru.user_id = sar.requester_user_id
            LEFT JOIN users rv ON rv.user_id = sar.reviewer_user_id
            WHERE sar.request_id = :request_id
            LIMIT 1
        ");
        $stmt->execute([':request_id' => $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function normalizeDirection(string $direction): string
    {
        $direction = strtolower(trim($direction));
        return $direction === 'stock_in' ? 'stock_in' : 'stock_out';
    }

    private static function normalizeAdjustmentType(string $type): string
    {
        $type = strtolower(trim($type));
        $type = preg_replace('/[^a-z0-9]+/', '_', $type) ?? '';
        $type = trim($type, '_');

        return $type !== '' ? $type : 'manual_adjustment';
    }

    private static function normalizeReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Please provide the reason for this stock adjustment.');
        }

        if (mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('Reason must be 500 characters or fewer.');
        }

        return $reason;
    }

    private static function normalizeNotes(string $notes): ?string
    {
        $notes = trim($notes);
        if ($notes === '') {
            return null;
        }

        if (mb_strlen($notes) > 500) {
            throw new InvalidArgumentException('Notes must be 500 characters or fewer.');
        }

        return $notes;
    }

    private static function personLabel(array $row, string $prefix): string
    {
        $name = trim((string) ($row[$prefix . '_first_name'] ?? '') . ' ' . (string) ($row[$prefix . '_last_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        return (string) ($row[$prefix . '_username'] ?? 'User');
    }
}
