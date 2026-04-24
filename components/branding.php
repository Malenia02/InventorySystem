<?php
declare(strict_types=1);

require_once __DIR__ . '/../controllers/PosConfigController.php';

function app_branding(?PDO $conn = null): array
{
    $fallback = [
        'name' => 'StockWise',
        'logo' => '/inventory_system/assets/img/logo.png',
        'has_custom_logo' => false,
    ];

    if (!$conn instanceof PDO) {
        return $fallback;
    }

    try {
        $config = PosConfigController::get($conn);
        $storeName = trim((string) ($config['store_name'] ?? ''));
        $logoUrl = PosConfigController::logoUrl($config['logo'] ?? null);
        $hasCustomLogo = $logoUrl !== null && $logoUrl !== '' && app_branding_logo_exists($logoUrl);

        return [
            'name' => $storeName !== '' ? $storeName : $fallback['name'],
            'logo' => $hasCustomLogo ? $logoUrl : $fallback['logo'],
            'has_custom_logo' => $hasCustomLogo,
        ];
    } catch (Throwable $e) {
        error_log('[branding.php] ' . $e->getMessage());
        return $fallback;
    }
}

function app_branding_logo_exists(string $logoUrl): bool
{
    if (!str_starts_with($logoUrl, '/inventory_system/media.php')) {
        return true;
    }

    $query = parse_url($logoUrl, PHP_URL_QUERY);
    if (!is_string($query) || $query === '') {
        return false;
    }

    parse_str($query, $params);
    $asset = trim((string) ($params['asset'] ?? ''));
    if ($asset === '' || str_contains($asset, '..') || preg_match('#^[a-z0-9/_\.-]+$#i', $asset) !== 1) {
        return false;
    }

    if (!str_starts_with($asset, 'pos-config/')) {
        return false;
    }

    $baseDir = function_exists('app_secure_storage_dir')
        ? rtrim(app_secure_storage_dir(), '/\\') . DIRECTORY_SEPARATOR . 'uploads'
        : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';

    $absolutePath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $asset);
    $realBaseDir = realpath($baseDir);
    $realDirectory = realpath(dirname($absolutePath));

    return $realBaseDir !== false
        && $realDirectory !== false
        && str_starts_with($realDirectory, $realBaseDir)
        && is_file($absolutePath)
        && is_readable($absolutePath);
}
