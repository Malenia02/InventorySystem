<?php
require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/config/config.php';

// Default title if page does not set one
if (!isset($pageTitle)) {
    $pageTitle = 'Inventory System';
}
?>

  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">

  <title><?= htmlspecialchars($pageTitle) ?></title>

  <!-- Favicons -->
  <link href="<?= HOSTURL ?>/assets/img/favicon.png" rel="icon">
  <link href="<?= HOSTURL ?>/assets/img/apple-touch-icon.png" rel="apple-touch-icon">

  <!-- Google Fonts -->
  <link href="https://fonts.gstatic.com" rel="preconnect">
  <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700|Nunito:300,400,600,700|Poppins:300,400,500,600,700" rel="stylesheet">

  <!-- Vendor CSS Files -->
  <link href="<?= HOSTURL ?>/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="<?= HOSTURL ?>/assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="<?= HOSTURL ?>/assets/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
  <link href="<?= HOSTURL ?>/assets/vendor/quill/quill.snow.css" rel="stylesheet">
  <link href="<?= HOSTURL ?>/assets/vendor/quill/quill.bubble.css" rel="stylesheet">
  <link href="<?= HOSTURL ?>/assets/vendor/remixicon/remixicon.css" rel="stylesheet">
  <link href="<?= HOSTURL ?>/assets/vendor/simple-datatables/style.css" rel="stylesheet">

  <!-- Template Main CSS -->
  <link href="<?= HOSTURL ?>/assets/css/style.css" rel="stylesheet">
