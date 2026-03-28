<?php
if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION['error_code'] = 403;
    $_SESSION['error_message'] = 'Direct access is not allowed.';

    header('Location: /inventory_system/error.php');
    exit;
}

class NotificationController
{
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
        $stmt = $conn->prepare("
            INSERT INTO notifications (
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
            ':icon'        => $icon,
            ':color'       => $color,
            ':link'        => $link
        ]);
    }

    public static function getNotifications(PDO $conn, int $userId, string $role, int $limit = 8): array
    {
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
            FROM notifications
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

    public static function getUnreadCount(PDO $conn, int $userId, string $role): int
    {
        $seenAt = self::getLastSeen($conn, $userId);
        $since  = $seenAt ?: '1970-01-01 00:00:00';

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM notifications
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
            ':since' => $since
        ]);

        return min(99, (int)$stmt->fetchColumn());
    }

    public static function markSeen(PDO $conn, int $userId): void
    {
        $stmt = $conn->prepare("
            INSERT INTO notification_seen (user_id, seen_at)
            VALUES (:uid, NOW())
            ON DUPLICATE KEY UPDATE seen_at = NOW()
        ");
        $stmt->execute([':uid' => $userId]);
    }

    private static function getLastSeen(PDO $conn, int $userId): ?string
    {
        $stmt = $conn->prepare("
            SELECT seen_at
            FROM notification_seen
            WHERE user_id = :uid
            LIMIT 1
        ");
        $stmt->execute([':uid' => $userId]);

        return $stmt->fetchColumn() ?: null;
    }

    public static function timeAgo(?string $time): string
    {
        if (!$time) {
            return 'Unknown';
        }

        $diff = time() - strtotime($time);

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

        return date('M d, Y', strtotime($time));
    }
}