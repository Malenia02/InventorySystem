<?php
declare(strict_types=1);

namespace InventorySystem\WebSocket;

if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION['error_code'] = 403;
    $_SESSION['error_message'] = 'Direct access to the WebSocket server is not allowed.';

    header('Location: /inventory_system/error.php');
    exit;
}


use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class NotificationWebSocket implements MessageComponentInterface
{
    protected \SplObjectStorage $clients;

    private array $allowedOrigins;
    private array $messageBuckets = [];

    private int $maxPayloadBytes = 4096;
    private int $maxClients = 100;
    private int $maxMessagesPerWindow = 12;
    private int $messageWindowSeconds = 5;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
        $this->allowedOrigins = $this->loadAllowedOrigins();
        echo "WebSocket notification server started.\n";
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        if (count($this->clients) >= $this->maxClients) {
            $conn->close();
            return;
        }

        $origin = '';
        if (isset($conn->httpRequest)) {
            $origin = $conn->httpRequest->getHeaderLine('Origin');
        }

        if (!$this->isAllowedOrigin($origin)) {
            echo "Rejected connection from origin: " . ($origin ?: 'UNKNOWN') . "\n";
            $conn->close();
            return;
        }

        $query = [];
        if (isset($conn->httpRequest)) {
            parse_str($conn->httpRequest->getUri()->getQuery(), $query);
        }

        $userId = (int) ($query['uid'] ?? 0);
        $role = trim((string) ($query['role'] ?? ''));
        $timestamp = (int) ($query['ts'] ?? 0);
        $signature = trim((string) ($query['sig'] ?? ''));

        if (!WebSocketSecurity::isValidToken($userId, $role, $origin, $timestamp, $signature)) {
            echo "Rejected unauthenticated WebSocket connection.\n";
            $conn->close();
            return;
        }

        $this->clients->attach($conn, [
            'user_id' => $userId,
            'role' => strtolower($role),
            'origin' => WebSocketSecurity::normalizedOrigin($origin),
        ]);
        $this->messageBuckets[spl_object_id($conn)] = [];
        echo "New connection: " . spl_object_id($conn) . " | Origin: " . $origin . "\n";
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        if (!is_string($msg) || strlen($msg) > $this->maxPayloadBytes) {
            return;
        }

        $data = json_decode($msg, true);
        if (!is_array($data)) {
            return;
        }

        if (!$this->clients->contains($from)) {
            return;
        }

        if ($this->isRateLimited($from)) {
            return;
        }

        $event = $data['event'] ?? '';
        if ($event !== 'notification_update') {
            return;
        }

        $type = trim((string) ($data['type'] ?? 'general'));
        if ($type === '' || strlen($type) > 50 || !preg_match('/^[a-z0-9_-]+$/i', $type)) {
            return;
        }

        $targetRoles = $this->normalizeTargetRoles($data['target_roles'] ?? []);
        $targetUserIds = $this->normalizeTargetUserIds($data['target_user_ids'] ?? []);

        $payload = json_encode([
            'event' => 'notification_update',
            'type'  => $type,
        ]);

        foreach ($this->clients as $client) {
            $clientMeta = is_array($this->clients[$client] ?? null) ? $this->clients[$client] : [];

            if (!$this->shouldDeliverToClient($clientMeta, $targetRoles, $targetUserIds)) {
                continue;
            }

            $client->send($payload);
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        if ($this->clients->contains($conn)) {
            $this->clients->detach($conn);
        }

        unset($this->messageBuckets[spl_object_id($conn)]);

        echo "Connection " . spl_object_id($conn) . " disconnected.\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        echo "WebSocket error: {$e->getMessage()}\n";

        if ($this->clients->contains($conn)) {
            $this->clients->detach($conn);
        }

        unset($this->messageBuckets[spl_object_id($conn)]);

        $conn->close();
    }

    private function isAllowedOrigin(string $origin): bool
    {
        if ($origin === '') {
            return false;
        }

        return in_array(WebSocketSecurity::normalizedOrigin($origin), $this->allowedOrigins, true);
    }

    private function loadAllowedOrigins(): array
    {
        $origins = [
            'http://127.0.0.1',
            'http://localhost',
            'https://127.0.0.1',
            'https://localhost',
        ];

        if (function_exists('env_value')) {
            $configured = trim((string) env_value('WS_ALLOWED_ORIGINS', ''));
            if ($configured !== '') {
                foreach (explode(',', $configured) as $origin) {
                    $origin = trim($origin);
                    if ($origin !== '') {
                        $origins[] = $origin;
                    }
                }
            }
        }

        return array_values(array_unique(array_map(
            static fn (string $origin): string => WebSocketSecurity::normalizedOrigin($origin),
            $origins
        )));
    }

    private function isRateLimited(ConnectionInterface $conn): bool
    {
        $connectionId = spl_object_id($conn);
        $now = time();
        $cutoff = $now - $this->messageWindowSeconds;
        $bucket = $this->messageBuckets[$connectionId] ?? [];
        $bucket = array_values(array_filter(
            $bucket,
            static fn (int $timestamp): bool => $timestamp >= $cutoff
        ));

        if (count($bucket) >= $this->maxMessagesPerWindow) {
            $this->messageBuckets[$connectionId] = $bucket;
            return true;
        }

        $bucket[] = $now;
        $this->messageBuckets[$connectionId] = $bucket;

        return false;
    }

    private function normalizeTargetRoles(mixed $roles): array
    {
        if (!is_array($roles)) {
            return [];
        }

        $allowedRoles = ['admin', 'cashier', 'staff'];
        $normalized = [];

        foreach ($roles as $role) {
            $role = strtolower(trim((string) $role));
            if ($role !== '' && in_array($role, $allowedRoles, true)) {
                $normalized[] = $role;
            }
        }

        return array_values(array_unique(array_slice($normalized, 0, 5)));
    }

    private function normalizeTargetUserIds(mixed $userIds): array
    {
        if (!is_array($userIds)) {
            return [];
        }

        $normalized = [];

        foreach ($userIds as $userId) {
            $userId = (int) $userId;
            if ($userId > 0) {
                $normalized[] = $userId;
            }
        }

        return array_values(array_unique(array_slice($normalized, 0, 20)));
    }

    private function shouldDeliverToClient(array $clientMeta, array $targetRoles, array $targetUserIds): bool
    {
        if ($targetRoles === [] && $targetUserIds === []) {
            return true;
        }

        $clientRole = strtolower(trim((string) ($clientMeta['role'] ?? '')));
        $clientUserId = (int) ($clientMeta['user_id'] ?? 0);

        return in_array($clientRole, $targetRoles, true)
            || in_array($clientUserId, $targetUserIds, true);
    }
}
