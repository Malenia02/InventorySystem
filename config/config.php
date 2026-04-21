<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/install.php';

use Dotenv\Dotenv;

// ==========================
// LOAD ENV
// ==========================
$projectRoot = dirname(__DIR__);
$externalEnvPath = app_external_env_path();

if (is_file($externalEnvPath)) {
    $dotenv = Dotenv::createImmutable(dirname($externalEnvPath), basename($externalEnvPath));
    $dotenv->load();
}

// ==========================
// HELPERS
// ==========================
if (!function_exists('env_value')) {
    function env_value(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
}

if (!function_exists('app_is_https')) {
    function app_is_https(): bool
    {
        return (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        );
    }
}

if (!function_exists('app_is_ajax_or_json')) {
    function app_is_ajax_or_json(): bool
    {
        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $isJson = str_contains(strtolower($contentType), 'application/json');

        return $isAjax || $isJson;
    }
}

if (!function_exists('safe_redirect')) {
    function safe_redirect(string $location, int $statusCode = 302): never
    {
        header('Location: ' . $location, true, $statusCode);
        exit;
    }
}

if (!function_exists('app_runtime_base_url')) {
    function app_runtime_base_url(string $fallbackUrl): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

        if ($host === '') {
            return rtrim($fallbackUrl, '/');
        }

        $isLocalHost = preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/i', $host) === 1;
        if (!$isLocalHost) {
            return rtrim($fallbackUrl, '/');
        }

        $fallbackPath = (string) parse_url($fallbackUrl, PHP_URL_PATH);
        $baseDir = rtrim($fallbackPath, '/');
        $scheme = app_is_https() ? 'https' : 'http';

        return $scheme . '://' . $host . $baseDir;
    }
}

if (!function_exists('app_host_without_port')) {
    function app_host_without_port(): string
    {
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return '';
        }

        if (str_contains($host, ':')) {
            $parsed = parse_url('http://' . $host, PHP_URL_HOST);
            if (is_string($parsed) && $parsed !== '') {
                return $parsed;
            }
        }

        return $host;
    }
}

if (!function_exists('app_should_force_https')) {
    function app_should_force_https(): bool
    {
        if (app_is_https() || php_sapi_name() === 'cli') {
            return false;
        }

        $host = strtolower(app_host_without_port());
        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return false;
        }

        return true;
    }
}

// ==========================
// PATH CONSTANTS
// ==========================
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $projectRoot);
}

if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', BASE_PATH . '/config');
}

if (!defined('DATABASE_PATH')) {
    define('DATABASE_PATH', BASE_PATH . '/database');
}

if (!defined('LOG_PATH')) {
    define('LOG_PATH', BASE_PATH . '/logs');
}

// ==========================
// OPTIONAL JSON CONFIG
// ==========================
$appConfig = [];
$configJsonPath = CONFIG_PATH . '/config.json';

if (file_exists($configJsonPath)) {
    $json = json_decode((string) file_get_contents($configJsonPath), true);
    if (is_array($json)) {
        $appConfig = $json;
    } else {
        error_log('[config.php] Invalid config.json ignored.');
    }
}

// ==========================
// APP SETTINGS
// ==========================
$appEnv      = (string) env_value('APP_ENV', 'production');
$appDebug    = filter_var(env_value('APP_DEBUG', false), FILTER_VALIDATE_BOOL);
$appUrl      = rtrim((string) env_value('APP_URL', 'http://localhost/inventory_system'), '/');
$appTimezone = (string) env_value('APP_TIMEZONE', 'Asia/Manila');

date_default_timezone_set($appTimezone);

// ==========================
// ERROR LOGGING
// ==========================
if (!is_dir(LOG_PATH)) {
    mkdir(LOG_PATH, 0755, true);
}

ini_set('log_errors', '1');
ini_set('display_errors', $appDebug ? '1' : '0');
ini_set('error_log', LOG_PATH . '/php-error.log');
error_reporting(E_ALL);

// ==========================
// SECURITY HEADERS
// ==========================
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header(
        "Content-Security-Policy: "
        . "default-src 'self'; "
        . "base-uri 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'self'; "
        . "object-src 'none'; "
        . "img-src 'self' data: blob: https:; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https:; "
        . "font-src 'self' data: https://fonts.gstatic.com https:; "
        . "script-src 'self' 'unsafe-inline' https:; "
        . "connect-src 'self' ws: wss: https:;"
    );

    if (app_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// ==========================
// FORCE HTTPS IN PRODUCTION
// ==========================
if ($appEnv === 'production' && app_should_force_https()) {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $uri  = $_SERVER['REQUEST_URI'] ?? '/inventory_system/';
    safe_redirect('https://' . $host . $uri, 301);
}

// ==========================
// SESSION HARDENING
// ==========================
if (session_status() === PHP_SESSION_NONE && php_sapi_name() !== 'cli') {
    $isHttps = app_is_https();

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');

    session_name('INVSYSSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

// ==========================
// SESSION TIMEOUT
// ==========================
if (!defined('SESSION_TIMEOUT')) {
    define('SESSION_TIMEOUT', (int) env_value('SESSION_TIMEOUT', 1800));
}

if (php_sapi_name() !== 'cli' && isset($_SESSION['user_id'])) {
    $lastActivity = $_SESSION['last_activity'] ?? null;

    if ($lastActivity !== null && (time() - (int) $lastActivity) > SESSION_TIMEOUT) {
        $_SESSION = [];
        session_unset();

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool) $params['secure'],
                (bool) $params['httponly']
            );
        }

        session_destroy();

        if (app_is_ajax_or_json()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'success'  => false,
                'error'    => 'Session expired. Please log in again.',
                'redirect' => '/inventory_system/login.php'
            ]);
            exit;
        }

        safe_redirect('/inventory_system/login.php?reason=timeout');
    }

    $_SESSION['last_activity'] = time();

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// ==========================
// CONSTANTS
// ==========================
if (!defined('HOSTURL')) {
    define('HOSTURL', app_runtime_base_url($appUrl));
}

// ==========================
// DATABASE ENV
// ==========================
$dbservername = (string) env_value('DB_HOST', '127.0.0.1');
$dbport       = (string) env_value('DB_PORT', '3306');
$dbusername   = (string) env_value('DB_USER', '');
$dbpassword   = (string) env_value('DB_PASS', '');
$dbname       = (string) env_value('DB_NAME', '');

if ($dbusername === '' || $dbname === '') {
    if (!app_is_install_context() && !app_is_install_route()) {
        error_log('[config.php] Missing DB_USER or DB_NAME in .env');

        if (php_sapi_name() !== 'cli' && !headers_sent()) {
            safe_redirect(app_install_url());
        }

        http_response_code(500);
        exit('Application configuration error.');
    }
}

// ==========================
// TABLE REFERENCES
// ==========================
$table_users          = 'users';
$user_id              = 'user_id';
$user_username        = 'username';
$user_password        = 'password';
$user_role            = 'role';
$user_firstname       = 'first_name';
$user_lastname        = 'last_name';
$user_email           = 'email';
$user_status          = 'status';
$user_deactivated_at  = 'deactivated_at';
$user_photoPath       = 'photo';

$table_categories     = 'categories';
$category_id          = 'category_id';
$category_name        = 'category_name';
$category_description = 'description';
$category_status      = 'status';
$category_created_at  = 'created_at';

$table_suppliers      = 'suppliers';
$supplier_id          = 'supplier_id';
$supplier_name        = 'supplier_name';
$supplier_contact     = 'contact_person';
$supplier_phone       = 'phone';
$supplier_email       = 'email';
$supplier_address     = 'address';

$table_products       = 'products';
$product_id           = 'product_id';
$product_name         = 'product_name';
$product_category_id  = 'category_id';
$product_supplier_id  = 'supplier_id';
$product_sku          = 'sku';
$product_price        = 'price';
$product_qty          = 'quantity';
$product_reorder      = 'reorder_level';
$product_created_at   = 'created_at';
$product_status       = 'status';
$product_vatable      = 'vatable';
$product_on_sale      = 'on_sale';
$product_sale_price   = 'sale_price';
$product_photo        = 'photo';

$table_pos_config     = 'pos_config';
$pos_config_id        = 'config_id';
$pos_store_name       = 'store_name';
$pos_store_address    = 'store_address';
$pos_store_phone      = 'store_phone';
$pos_store_email      = 'store_email';
$pos_opening_hours    = 'opening_hours';
$pos_closing_hours    = 'closing_hours';
$pos_tax_rate         = 'tax_rate';
$pos_currency         = 'currency';
$pos_logo             = 'logo';

$table_sales          = 'sales';
$sale_id              = 'sale_id';
$sale_total           = 'total_amount';
$sale_tax             = 'tax';
$sale_discount        = 'discount';
$sale_payment_method  = 'payment_method';
$sale_date            = 'sale_date';
$sale_user_id         = 'user_id';

$table_sale_items      = 'sale_items';
$sale_item_id          = 'sale_item_id';
$sale_item_sale_id     = 'sale_id';
$sale_item_product_id  = 'product_id';
$sale_item_unit_price  = 'unit_price';
$sale_item_qty         = 'quantity';

$table_stock_in        = 'stock_in';
$stockin_id            = 'stockin_id';
$stockin_product_id    = 'product_id';
$stockin_qty           = 'quantity';
$stockin_date          = 'stockin_date';
$stockin_user_id       = 'user_id';

$table_stock_out       = 'stock_out';
$stockout_id           = 'stockout_id';
$stockout_product_id   = 'product_id';
$stockout_qty          = 'quantity';
$stockout_reason       = 'reason';
$stockout_date         = 'stockout_date';
$stockout_user_id      = 'user_id';

$table_activity_logs   = 'activity_logs';
$activity_log_id       = 'id';
$activity_log_user_id  = 'user_id';
$activity_log_action   = 'action';
$activity_log_desc     = 'description';
$activity_log_ip       = 'ip_address';
$activity_log_created  = 'created_at';
