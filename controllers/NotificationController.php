<?php
declare(strict_types=1);

final class NotificationController
{
    private const TABLE = 'notifications';
    private const SEEN_TABLE = 'notification_seen';
    private const DEFAULT_LIMIT = 8;
    private const MAX_LIMIT = 50;
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
            FROM " . self::TABLE . "
            WHERE role_target = 'all'
               OR role_target = :role
               OR user_id = :uid
            ORDER BY created_at DESC
            LIMIT :limit
        ");

        $stmt->bindValue(':role', $role, PDO::PARAM_STR);
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

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM " . self::TABLE . "
            WHERE (
                role_target = 'all'
                OR role_target = :role
                OR user_id = :uid
            )
            AND created_at > :since
        ");

        $stmt->execute([
            ':role'  => $role,
            ':uid'   => $userId,
            ':since' => $since,
        ]);

        return min(99, (int) $stmt->fetchColumn());
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
}
