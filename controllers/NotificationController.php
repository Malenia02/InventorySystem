<?php
declare(strict_types=1);

final class NotificationController
{
    private const TABLE = 'notifications';
    private const SEEN_TABLE = 'notification_seen';
    private const DEFAULT_LIMIT = 8;
    private const MAX_LIMIT = 50;
    private const CENTER_MAX_LIMIT = 100;
    private const ALLOWED_ROLE_TARGETS = ['all', 'admin', 'staff', 'cashier'];

    public static function create(
        PDO $conn,
        ?int $userId,
        string $roleTarget,
        string $type,
        string $title,
        string $message,
        string $icon = 'bi-bell',
        string $color = 'text-primary',
        ?string $link = null
    ): bool {
        $roleTarget = self::normalizeRoleTarget($roleTarget);
        $type = trim($type);
        $title = trim($title);
        $message = trim($message);
        $icon = trim($icon);
        $color = trim($color);
        $link = self::nullableTrim($link);

        if ($type === '') {
            throw new InvalidArgumentException('Notification type is required.');
        }

        if ($title === '') {
            throw new InvalidArgumentException('Notification title is required.');
        }

        if ($message === '') {
            throw new InvalidArgumentException('Notification message is required.');
        }

        $stmt = $conn->prepare("
            INSERT INTO " . self::TABLE . " (
                user_id,
                role_target,
                type,
                title,
                message,
                icon,
                color,
                link,
                created_at
            ) VALUES (
                :user_id,
                :role_target,
                :type,
                :title,
                :message,
                :icon,
                :color,
                :link,
                NOW()
            )
        ");

        return $stmt->execute([
            ':user_id'     => $userId,
            ':role_target' => $roleTarget,
            ':type'        => $type,
            ':title'       => $title,
            ':message'     => $message,
            ':icon'        => $icon !== '' ? $icon : 'bi-bell',
            ':color'       => $color !== '' ? $color : 'text-primary',
            ':link'        => $link,
        ]);
    }

    public static function getNotifications(PDO $conn, int $userId, string $role, int $limit = self::DEFAULT_LIMIT): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Invalid user ID.');
        }

        $role = trim($role);
        $limit = self::normalizeLimit($limit);

        $visibilitySql = self::visibilitySql($role);

        $stmt = $conn->prepare("
            SELECT
                notification_id,
                user_id,
                role_target,
                type,
                title,
                message,
                icon,
                color,
                link,
                created_at AS time
            FROM " . self::TABLE . " n
            WHERE {$visibilitySql}
            ORDER BY created_at DESC
            LIMIT :limit
        ");

        if (strtolower($role) === 'admin') {
            $stmt->bindValue(':role', $role, PDO::PARAM_STR);
        }
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function getNotificationFeed(PDO $conn, int $userId, string $role, int $limit = 20): array
    {
        $all = self::getNotifications($conn, $userId, $role, $limit);
        $lastSeen = self::getLastSeen($conn, $userId);

        $all = array_map(
            static function (array $notification): array {
                $notification['time_ago'] = self::timeAgo($notification['time'] ?? null);
                return $notification;
            },
            $all
        );

        if ($lastSeen === null) {
            return [
                'all' => $all,
                'unread' => $all,
                'previous' => [],
                'last_seen' => null,
            ];
        }

        $lastSeenTimestamp = strtotime($lastSeen);
        $unread = [];
        $previous = [];

        foreach ($all as $notification) {
            $createdAt = strtotime((string) ($notification['time'] ?? ''));

            if ($createdAt !== false && $lastSeenTimestamp !== false && $createdAt > $lastSeenTimestamp) {
                $unread[] = $notification;
                continue;
            }

            $previous[] = $notification;
        }

        return [
            'all' => $all,
            'unread' => $unread,
            'previous' => $previous,
            'last_seen' => $lastSeen,
        ];
    }

    public static function getUnreadCount(PDO $conn, int $userId, string $role): int
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Invalid user ID.');
        }

        $role = trim($role);
        $seenAt = self::getLastSeen($conn, $userId);
        $since = $seenAt ?? '1970-01-01 00:00:00';
        $visibilitySql = self::visibilitySql($role);

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM " . self::TABLE . " n
            WHERE {$visibilitySql}
            AND created_at > :since
        ");

        if (strtolower($role) === 'admin') {
            $stmt->bindValue(':role', $role, PDO::PARAM_STR);
        }
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':since', $since, PDO::PARAM_STR);
        $stmt->execute();

        return min(99, (int) $stmt->fetchColumn());
    }

    public static function getNotificationCenter(PDO $conn, int $userId, string $role, array $filters = [], int $limit = 80): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Invalid user ID.');
        }

        $role = strtolower(trim($role));
        $limit = max(1, min($limit, self::CENTER_MAX_LIMIT));
        $lastSeen = self::getLastSeen($conn, $userId);
        $since = $lastSeen ?? '1970-01-01 00:00:00';
        $visibilitySql = self::visibilitySql($role);
        $params = [
            ':uid' => $userId,
            ':since' => $since,
        ];

        if ($role === 'admin') {
            $params[':role'] = $role;
        }

        $where = [$visibilitySql];
        $search = trim((string) ($filters['search'] ?? ''));
        $category = strtolower(trim((string) ($filters['category'] ?? 'all')));
        $status = strtolower(trim((string) ($filters['status'] ?? 'all')));
        $period = strtolower(trim((string) ($filters['period'] ?? 'all')));
        $actorId = (int) ($filters['actor_id'] ?? 0);

        if ($search !== '') {
            $where[] = "(
                n.title LIKE :search_title
                OR n.message LIKE :search_message
                OR n.type LIKE :search_type
                OR u.username LIKE :search_username
                OR CONCAT_WS(' ', u.first_name, u.last_name) LIKE :search_name
            )";
            $searchTerm = '%' . $search . '%';
            $params[':search_title'] = $searchTerm;
            $params[':search_message'] = $searchTerm;
            $params[':search_type'] = $searchTerm;
            $params[':search_username'] = $searchTerm;
            $params[':search_name'] = $searchTerm;
        }

        if ($status === 'unread') {
            $where[] = 'n.created_at > :status_since';
            $params[':status_since'] = $since;
        } elseif ($status === 'previous') {
            $where[] = 'n.created_at <= :status_since';
            $params[':status_since'] = $since;
        }

        if ($category !== 'all') {
            $categorySql = self::categoryCondition($category);
            if ($categorySql !== null) {
                $where[] = $categorySql;
            }
        }

        if ($period !== 'all') {
            $periodSql = self::periodCondition($period);
            if ($periodSql !== null) {
                $where[] = $periodSql;
            }
        }

        if ($role === 'admin' && $actorId > 0) {
            $where[] = 'n.user_id = :actor_id';
            $params[':actor_id'] = $actorId;
        }

        $whereSql = implode(' AND ', $where);
        $rowsSql = "
            SELECT
                n.notification_id,
                n.user_id,
                n.role_target,
                n.type,
                n.title,
                n.message,
                n.icon,
                n.color,
                n.link,
                n.created_at AS time,
                CASE WHEN n.created_at > :since THEN 1 ELSE 0 END AS is_unread,
                u.first_name,
                u.last_name,
                u.username,
                u.role AS actor_role
            FROM " . self::TABLE . " n
            LEFT JOIN users u ON u.user_id = n.user_id
            WHERE {$whereSql}
            ORDER BY n.created_at DESC, n.notification_id DESC
            LIMIT :limit
        ";

        $stmt = $conn->prepare($rowsSql);
        self::bindNotificationCenterParams($stmt, $params);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $items = array_map(
            static function (array $row): array {
                $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
                $row['actor_name'] = $name !== '' ? $name : (string) ($row['username'] ?? 'System');
                $row['time_ago'] = self::timeAgo($row['time'] ?? null);
                $row['category'] = self::notificationCategory((string) ($row['type'] ?? ''));
                $row['is_unread'] = (int) ($row['is_unread'] ?? 0) === 1;
                return $row;
            },
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );

        return [
            'items' => $items,
            'stats' => self::centerStats($conn, $userId, $role, $since),
            'actors' => $role === 'admin' ? self::centerActors($conn, $userId, $role) : [],
            'last_seen' => $lastSeen,
        ];
    }

    public static function markSeen(PDO $conn, int $userId): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Invalid user ID.');
        }

        $stmt = $conn->prepare("
            INSERT INTO " . self::SEEN_TABLE . " (user_id, seen_at)
            VALUES (:uid, NOW())
            ON DUPLICATE KEY UPDATE seen_at = NOW()
        ");
        $stmt->execute([':uid' => $userId]);
    }

    public static function getLastSeen(PDO $conn, int $userId): ?string
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Invalid user ID.');
        }

        $stmt = $conn->prepare("
            SELECT seen_at
            FROM " . self::SEEN_TABLE . "
            WHERE user_id = :uid
            LIMIT 1
        ");
        $stmt->execute([':uid' => $userId]);

        $result = $stmt->fetchColumn();

        return $result !== false ? (string) $result : null;
    }

    public static function timeAgo(?string $time): string
    {
        if ($time === null || trim($time) === '') {
            return 'Unknown';
        }

        $timestamp = strtotime($time);
        if ($timestamp === false) {
            return 'Unknown';
        }

        $diff = time() - $timestamp;

        if ($diff < 0) {
            return date('M d, Y', $timestamp);
        }

        if ($diff < 60) {
            return 'Just now';
        }

        if ($diff < 3600) {
            return floor($diff / 60) . ' min ago';
        }

        if ($diff < 86400) {
            return floor($diff / 3600) . ' hr ago';
        }

        if ($diff < 604800) {
            return floor($diff / 86400) . ' day(s) ago';
        }

        return date('M d, Y', $timestamp);
    }

    private static function normalizeRoleTarget(string $roleTarget): string
    {
        $roleTarget = strtolower(trim($roleTarget));

        if (!in_array($roleTarget, self::ALLOWED_ROLE_TARGETS, true)) {
            throw new InvalidArgumentException('Invalid notification role target.');
        }

        return $roleTarget;
    }

    private static function normalizeLimit(int $limit): int
    {
        if ($limit <= 0) {
            return self::DEFAULT_LIMIT;
        }

        return min($limit, self::MAX_LIMIT);
    }

    private static function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private static function visibilitySql(string $role): string
    {
        $role = strtolower(trim($role));

        if ($role === 'admin') {
            return "(n.role_target = 'all' OR n.role_target = :role OR n.user_id = :uid)";
        }

        return "(n.role_target = 'all' OR n.user_id = :uid)";
    }

    private static function categoryCondition(string $category): ?string
    {
        return match ($category) {
            'sales' => "n.type LIKE 'sale_%'",
            'shift' => "n.type LIKE 'shift_closing_%'",
            'inventory' => "(
                n.type LIKE 'product_%'
                OR n.type LIKE 'category_%'
                OR n.type LIKE 'subcategory_%'
                OR n.type LIKE 'supplier_%'
                OR n.type LIKE 'stock_%'
                OR n.type LIKE 'reorder_%'
            )",
            'security' => "(
                n.type LIKE 'auth_%'
                OR n.type LIKE 'security_%'
                OR n.type LIKE '%lockout%'
                OR n.type LIKE '%error%'
            )",
            'system' => "(
                n.type NOT LIKE 'sale_%'
                AND n.type NOT LIKE 'shift_closing_%'
                AND n.type NOT LIKE 'product_%'
                AND n.type NOT LIKE 'category_%'
                AND n.type NOT LIKE 'subcategory_%'
                AND n.type NOT LIKE 'supplier_%'
                AND n.type NOT LIKE 'stock_%'
                AND n.type NOT LIKE 'reorder_%'
                AND n.type NOT LIKE 'auth_%'
                AND n.type NOT LIKE 'security_%'
                AND n.type NOT LIKE '%lockout%'
                AND n.type NOT LIKE '%error%'
            )",
            default => null,
        };
    }

    private static function periodCondition(string $period): ?string
    {
        return match ($period) {
            'today' => 'DATE(n.created_at) = CURDATE()',
            'week' => 'n.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
            'month' => 'n.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
            default => null,
        };
    }

    private static function notificationCategory(string $type): string
    {
        $type = strtolower(trim($type));

        if (str_starts_with($type, 'sale_')) {
            return 'sales';
        }

        if (str_starts_with($type, 'shift_closing_')) {
            return 'shift';
        }

        if (
            str_starts_with($type, 'product_')
            || str_starts_with($type, 'category_')
            || str_starts_with($type, 'subcategory_')
            || str_starts_with($type, 'supplier_')
            || str_starts_with($type, 'stock_')
            || str_starts_with($type, 'reorder_')
        ) {
            return 'inventory';
        }

        if (
            str_starts_with($type, 'auth_')
            || str_starts_with($type, 'security_')
            || str_contains($type, 'lockout')
            || str_contains($type, 'error')
        ) {
            return 'security';
        }

        return 'system';
    }

    private static function centerStats(PDO $conn, int $userId, string $role, string $since): array
    {
        $visibilitySql = self::visibilitySql($role);
        $stmt = $conn->prepare("
            SELECT
                COUNT(*) AS total,
                IFNULL(SUM(CASE WHEN n.created_at > :since THEN 1 ELSE 0 END), 0) AS unread,
                IFNULL(SUM(CASE WHEN n.type LIKE 'sale_%' THEN 1 ELSE 0 END), 0) AS sales,
                IFNULL(SUM(CASE WHEN n.type LIKE 'shift_closing_%' THEN 1 ELSE 0 END), 0) AS shift,
                IFNULL(SUM(CASE WHEN " . self::categoryCondition('inventory') . " THEN 1 ELSE 0 END), 0) AS inventory
            FROM " . self::TABLE . " n
            LEFT JOIN users u ON u.user_id = n.user_id
            WHERE {$visibilitySql}
        ");
        $params = [
            ':uid' => $userId,
            ':since' => $since,
        ];
        if ($role === 'admin') {
            $params[':role'] = $role;
        }
        self::bindNotificationCenterParams($stmt, $params);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'unread' => (int) ($row['unread'] ?? 0),
            'sales' => (int) ($row['sales'] ?? 0),
            'shift' => (int) ($row['shift'] ?? 0),
            'inventory' => (int) ($row['inventory'] ?? 0),
        ];
    }

    private static function centerActors(PDO $conn, int $userId, string $role): array
    {
        $visibilitySql = self::visibilitySql($role);
        $stmt = $conn->prepare("
            SELECT DISTINCT
                u.user_id,
                u.first_name,
                u.last_name,
                u.username,
                u.role
            FROM " . self::TABLE . " n
            INNER JOIN users u ON u.user_id = n.user_id
            WHERE {$visibilitySql}
            ORDER BY u.first_name ASC, u.last_name ASC, u.username ASC
            LIMIT 100
        ");
        $params = [':uid' => $userId];
        if ($role === 'admin') {
            $params[':role'] = $role;
        }
        self::bindNotificationCenterParams($stmt, $params);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private static function bindNotificationCenterParams(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($key, $value, $type);
        }
    }
}
