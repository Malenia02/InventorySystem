<?php
require_once __DIR__ . '/../vendor/autoload.php';
// ==========================
// SESSION
// ==========================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ==========================
// CONSTANTS
// ==========================
if (!defined('HOSTURL')) {
    define('HOSTURL', 'http://localhost/inventory_system');
}

// ==========================
// ERROR LOGGING
// ==========================
ini_set('log_errors', 1);
ini_set('display_errors', 0);
ini_set('error_log', $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/logs/php-error.log');

// ==========================
// LOAD CONFIG.JSON
// ==========================
$configPath = $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/config/config.json';

if (!file_exists($configPath)) {
    die('Missing config.json');
}

$config = json_decode(file_get_contents($configPath), true);

if (!$config) {
    die('Invalid config.json');
}

// ==========================
// DATABASE CONFIG
// ==========================
$dbservername = $config['database']['servername'];
$dbusername   = $config['database']['username'];
$dbpassword   = $config['database']['password'];
$dbname       = $config['database']['dbname'];

// Include PDO connection
require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/database/database.php';

// ==========================
// TABLE REFERENCES
// ==========================

// --- USERS ---
$table_users          = $config['tables']['users']['table'];
$user_id              = $config['tables']['users']['id'];
$user_username        = $config['tables']['users']['username'];
$user_password        = $config['tables']['users']['password'];
$user_role            = $config['tables']['users']['role'];
$user_firstname       = $config['tables']['users']['first_name'];
$user_lastname        = $config['tables']['users']['last_name'];
$user_email           = $config['tables']['users']['email'];
$user_status          = $config['tables']['users']['status'];
$user_deactivated_at  = $config['tables']['users']['deactivated_at'];
$user_photoPath       = $config['tables']['users']['photo'];

// --- CATEGORIES ---
$table_categories     = $config['tables']['categories']['table'];
$category_id          = $config['tables']['categories']['id'];
$category_name        = $config['tables']['categories']['name'];
$category_description = $config['tables']['categories']['description'];
$category_status      = $config['tables']['categories']['status'];
$category_created_at  = $config['tables']['categories']['created_at'];

// --- SUPPLIERS ---
$table_suppliers      = $config['tables']['suppliers']['table'];
$supplier_id          = $config['tables']['suppliers']['id'];
$supplier_name        = $config['tables']['suppliers']['name'];
$supplier_contact     = $config['tables']['suppliers']['contact_person'];
$supplier_phone       = $config['tables']['suppliers']['phone'];
$supplier_email       = $config['tables']['suppliers']['email'];
$supplier_address     = $config['tables']['suppliers']['address'];

// --- PRODUCTS ---
$table_products       = $config['tables']['products']['table'];
$product_id           = $config['tables']['products']['id'];
$product_name         = $config['tables']['products']['name'];
$product_category_id  = $config['tables']['products']['category_id'];
$product_supplier_id  = $config['tables']['products']['supplier_id'];
$product_sku          = $config['tables']['products']['sku'];
$product_price        = $config['tables']['products']['price'];
$product_qty          = $config['tables']['products']['quantity'];
$product_reorder      = $config['tables']['products']['reorder_level'];
$product_created_at   = $config['tables']['products']['created_at'];
$product_status       = $config['tables']['products']['status'];
$product_vatable      = $config['tables']['products']['vatable'];
$product_on_sale      = $config['tables']['products']['on_sale'];
$product_sale_price   = $config['tables']['products']['sale_price'];
$product_photo        = $config['tables']['products']['photo'];
$product_status = $config['tables']['products']['status']; // 'status' column


// --- POS CONFIG ---
$table_pos_config     = $config['tables']['pos_config']['table'];
$pos_config_id        = $config['tables']['pos_config']['id'];
$pos_store_name       = $config['tables']['pos_config']['store_name'];
$pos_store_address    = $config['tables']['pos_config']['store_address'];
$pos_store_phone      = $config['tables']['pos_config']['store_phone'];
$pos_store_email      = $config['tables']['pos_config']['store_email'];
$pos_opening_hours    = $config['tables']['pos_config']['opening_hours'];
$pos_closing_hours    = $config['tables']['pos_config']['closing_hours'];
$pos_tax_rate         = $config['tables']['pos_config']['tax_rate'];
$pos_currency         = $config['tables']['pos_config']['currency'];
$pos_logo             = $config['tables']['pos_config']['logo'];

// --- SALES ---
$table_sales          = $config['tables']['sales']['table'];
$sale_id              = $config['tables']['sales']['id'];
$sale_total           = $config['tables']['sales']['total'];
$sale_tax             = $config['tables']['sales']['tax'];
$sale_discount        = $config['tables']['sales']['discount'];
$sale_payment_method  = $config['tables']['sales']['payment_method'];
$sale_date            = $config['tables']['sales']['date'];
$sale_user_id         = $config['tables']['sales']['user_id'];

// --- SALE ITEMS ---
$table_sale_items      = $config['tables']['sale_items']['table'];
$sale_item_id          = $config['tables']['sale_items']['id'];
$sale_item_sale_id     = $config['tables']['sale_items']['sale_id'];
$sale_item_product_id  = $config['tables']['sale_items']['product_id'];
$sale_item_unit_price  = $config['tables']['sale_items']['unit_price'];
$sale_item_qty         = $config['tables']['sale_items']['quantity'];

// --- STOCK IN ---
$table_stock_in        = $config['tables']['stock_in']['table'];
$stockin_id            = $config['tables']['stock_in']['id'];
$stockin_product_id    = $config['tables']['stock_in']['product_id'];
$stockin_qty           = $config['tables']['stock_in']['quantity'];
$stockin_date          = $config['tables']['stock_in']['date'];
$stockin_user_id       = $config['tables']['stock_in']['user_id'];

// --- STOCK OUT ---
$table_stock_out       = $config['tables']['stock_out']['table'];
$stockout_id           = $config['tables']['stock_out']['id'];
$stockout_product_id   = $config['tables']['stock_out']['product_id'];
$stockout_qty          = $config['tables']['stock_out']['quantity'];
$stockout_reason       = $config['tables']['stock_out']['reason'];
$stockout_date         = $config['tables']['stock_out']['date'];
$stockout_user_id      = $config['tables']['stock_out']['user_id'];


// --- ACTIVITY LOGS ---
$table_activity_logs   = $config['tables']['activity_logs']['table'];
$activity_log_id       = $config['tables']['activity_logs']['id'];
$activity_log_user_id  = $config['tables']['activity_logs']['user_id'];
$activity_log_action   = $config['tables']['activity_logs']['action'];
$activity_log_desc     = $config['tables']['activity_logs']['description'];
$activity_log_ip       = $config['tables']['activity_logs']['ip_address'];
$activity_log_created  = $config['tables']['activity_logs']['created_at'];



// ==========================
// BASIC AUTH CHECK
// ==========================
if (!defined('AUTH_CONTEXT')) {
    $publicPages = ['login.php', 'register.php'];
    $currentPage = basename($_SERVER['SCRIPT_NAME']);

    if (!isset($_SESSION['user_id']) && !in_array($currentPage, $publicPages)) {
        header("Location: /inventory_system/login.php");
        exit;
    }
}
?>
