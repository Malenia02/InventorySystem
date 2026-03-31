<?php
// Get base URL dynamically
$base_url = '/inventory_system'; // adjust if your folder name changes

// Get current page filename
$current_page = basename($_SERVER['PHP_SELF']);

// Map pages to titles
$pages = [
    'index.php' => 'Dashboard',
    'manage_staff.php' => 'Manage Staff',
    'manage_products.php' => 'Manage Products',
    'manage_product.php' => 'Manage Products',
    'bulk_upload_products.php' => 'Bulk Create Products',
    'manage_subcategory.php' => 'Manage Subcategories',
    'manage_orders.php' => 'Manage Orders',
    // Add more pages here
];

// Get current page title
$current_title = $pages[$current_page] ?? ucfirst(pathinfo($current_page, PATHINFO_FILENAME));
?>

<main id="main" class="main">
    <div class="pagetitle">
        <h1><?= htmlspecialchars($current_title) ?></h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="<?= $base_url ?>/index.php" <?= ($current_page == 'index.php') ? 'class="active"' : '' ?>>Dashboard</a>
                </li>

                <?php if ($current_page != 'index.php'): ?>
                    <li class="breadcrumb-item active"><?= htmlspecialchars($current_title) ?></li>
                <?php endif; ?>
            </ol>
        </nav>
    </div>

