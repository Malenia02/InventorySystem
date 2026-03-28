<?php
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

    private array $allowedOrigins = [
        'http://127.0.0.1',
        'http://localhost',
    ];

    private int $maxPayloadBytes = 4096;
    private int $maxClients = 100;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
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

        $this->clients->attach($conn);
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

        $event = $data['event'] ?? '';
        if ($event !== 'notification_update') {
            return;
        }

        $payload = json_encode([
            'event' => 'notification_update',
            'type'  => $data['type'] ?? 'general',
        ]);

        foreach ($this->clients as $client) {
            $client->send($payload);
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        if ($this->clients->contains($conn)) {
            $this->clients->detach($conn);
        }

        echo "Connection " . spl_object_id($conn) . " disconnected.\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        echo "WebSocket error: {$e->getMessage()}\n";

        if ($this->clients->contains($conn)) {
            $this->clients->detach($conn);
        }

        $conn->close();
    }

    private function isAllowedOrigin(string $origin): bool
    {
        if ($origin === '') {
            return false;
        }

        return in_array(rtrim($origin, '/'), $this->allowedOrigins, true);
    }
}