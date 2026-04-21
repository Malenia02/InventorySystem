<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap/app.php';
require_once __DIR__ . '/../../middleware/Middleware.php';
require_once __DIR__ . '/../../src/WebSocket/WebSocketSecurity.php';

use InventorySystem\WebSocket\WebSocketSecurity;

header('Content-Type: application/json; charset=UTF-8');

Middleware::auth()
    ->ajax()
    ->methods(['GET'])
    ->sameOrigin()
    ->throttle('websocket_auth', 60, 60, 'Too many websocket auth requests. Please wait a moment.');

function websocket_auth_json(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

try {
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $role = trim((string) ($_SESSION['role'] ?? ''));

    if ($userId <= 0 || $role === '') {
        websocket_auth_json([
            'success' => false,
            'error' => 'Unauthorized.',
        ], 401);
    }

    $webSocketBaseUrl = (string) env_value('WS_PUBLIC_URL', 'ws://127.0.0.1:8080');
    $requestScheme = app_is_https() ? 'https' : 'http';
    $requestHost = (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
    $requestOrigin = $requestScheme . '://' . $requestHost;

    websocket_auth_json([
        'success' => true,
        'url' => WebSocketSecurity::buildConnectionUrl($webSocketBaseUrl, $userId, $role, $requestOrigin),
    ]);
} catch (Throwable $e) {
    error_log('[websocket_auth.php] ' . $e->getMessage());

    websocket_auth_json([
        'success' => false,
        'error' => 'Unable to prepare the realtime connection right now.',
    ], 500);
}
