<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/branding.php';

// Default title if page does not set one
if (!isset($pageTitle)) {
    $pageTitle = 'Inventory System';
}

$headBrand = app_branding($conn ?? null);
$faviconUrl = !empty($headBrand['has_custom_logo'])
    ? (string) $headBrand['logo']
    : '/inventory_system/assets/img/favicon.png';
$appleTouchIconUrl = !empty($headBrand['has_custom_logo'])
    ? (string) $headBrand['logo']
    : '/inventory_system/assets/img/apple-touch-icon.png';
?>

  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">

  <title><?= htmlspecialchars($pageTitle) ?></title>

  <!-- Favicons -->
  <link href="<?= htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8') ?>" rel="icon">
  <link href="<?= htmlspecialchars($appleTouchIconUrl, ENT_QUOTES, 'UTF-8') ?>" rel="apple-touch-icon">

  <!-- Google Fonts -->
  <link href="https://fonts.gstatic.com" rel="preconnect">
  <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700|Nunito:300,400,600,700|Poppins:300,400,500,600,700" rel="stylesheet">

  <!-- Vendor CSS Files -->
  <link href="/inventory_system/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="/inventory_system/assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="/inventory_system/assets/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
  <link href="/inventory_system/assets/vendor/quill/quill.snow.css" rel="stylesheet">
  <link href="/inventory_system/assets/vendor/quill/quill.bubble.css" rel="stylesheet">
  <link href="/inventory_system/assets/vendor/remixicon/remixicon.css" rel="stylesheet">
  <link href="/inventory_system/assets/vendor/simple-datatables/style.css" rel="stylesheet">

  <!-- Template Main CSS -->
  <link href="/inventory_system/assets/css/style.css" rel="stylesheet">
