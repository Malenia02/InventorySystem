<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/controllers/AuthController.php';

if (session_status() === PHP_SESSION_NONE && php_sapi_name() !== 'cli') {
    AuthController::configureSessionCookie();
    session_start();
}

function media_deny(): never
{
    http_response_code(404);
    exit('Not found');
}

function media_secure_uploads_dir(): string
{
    if (function_exists('app_secure_storage_dir')) {
        return rtrim(app_secure_storage_dir(), '/\\') . DIRECTORY_SEPARATOR . 'uploads';
    }

    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'secure' . DIRECTORY_SEPARATOR . 'uploads';
}

function media_requires_auth(string $asset): bool
{
    return str_starts_with($asset, 'products/')
        || str_starts_with($asset, 'staff/');
}

$asset = trim((string) ($_GET['asset'] ?? ''));
if ($asset === '' || str_contains($asset, '..')) {
    media_deny();
}

if (preg_match('#^[a-z0-9/_\.-]+$#i', $asset) !== 1) {
    media_deny();
}

if (
    !str_starts_with($asset, 'products/')
    && !str_starts_with($asset, 'staff/')
    && !str_starts_with($asset, 'pos-config/')
) {
    media_deny();
}

if (media_requires_auth($asset)) {
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$baseDir = media_secure_uploads_dir();
$absolutePath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $asset);
$realBaseDir = realpath($baseDir);
$realDirectory = realpath(dirname($absolutePath));

if ($realBaseDir === false || $realDirectory === false) {
    media_deny();
}

if (!str_starts_with($realDirectory, $realBaseDir)) {
    media_deny();
}

if (!is_file($absolutePath) || !is_readable($absolutePath)) {
    media_deny();
}

$mimeType = mime_content_type($absolutePath);
$allowedMimeTypes = [
    'image/jpeg',
    'image/png',
    'image/webp',
];

if (!is_string($mimeType) || !in_array($mimeType, $allowedMimeTypes, true)) {
    media_deny();
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($absolutePath));
header('Cache-Control: private, max-age=86400');
readfile($absolutePath);
exit;
