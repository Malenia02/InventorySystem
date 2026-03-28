<?php
if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $_SESSION['error_code'] = 403;
    $_SESSION['error_message'] = 'Direct access to the WebSocket server is not allowed.';

    header('Location: /inventory_system/error.php');
    exit;
}

require __DIR__ . '/vendor/autoload.php';

use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Factory;
use React\Socket\SocketServer;
use InventorySystem\WebSocket\NotificationWebSocket;

// ✅ CREATE LOOP (THIS WAS MISSING)
$loop = Factory::create();

// ✅ PASS LOOP INTO SOCKET
$socket = new SocketServer('127.0.0.1:8080', [], $loop);

// ✅ PASS LOOP INTO IOSERVER
$server = new IoServer(
    new HttpServer(
        new WsServer(
            new NotificationWebSocket()
        )
    ),
    $socket,
    $loop // <-- REQUIRED FIX
);

echo "Running WebSocket server on ws://127.0.0.1:8080\n";

$server->run();