<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$current_page = basename($_SERVER['PHP_SELF']);
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

$productPages = [
    'pos.php',
    'manage_product.php',
    'bulk_upload_products.php',
    'manage_category.php',
    'manage_subcategory.php',
    'manage_supplier.php'
];

$adminPages = [
    'manage_staff.php',
    'activity_log.php',
    'pos_settings.php'
];

$reportPages = [
    'sales_report.php',
    'inventory_report.php'
];
?>

<aside id="sidebar" class="sidebar">
    <ul class="sidebar-nav" id="sidebar-nav">

        <li class="nav-item">
            <a class="nav-link <?= $current_page === 'index.php' ? '' : 'collapsed' ?>" href="/inventory_system/index.php">
                <i class="bi bi-grid"></i>
                <span>Dashboard</span>
            </a>
        </li>

        <?php if ($isAdmin): ?>
            <li class="nav-item">
                <a
                    class="nav-link <?= in_array($current_page, $adminPages, true) ? '' : 'collapsed' ?>"
                    data-bs-target="#admin-nav"
                    data-bs-toggle="collapse"
                    href="#"
                >
                    <i class="bi bi-shield-lock"></i>
                    <span>Admin</span>
                    <i class="bi bi-chevron-down ms-auto"></i>
                </a>

                <ul
                    id="admin-nav"
                    class="nav-content collapse <?= in_array($current_page, $adminPages, true) ? 'show' : '' ?>"
                    data-bs-parent="#sidebar-nav"
                >
                    <li>
                        <a href="/inventory_system/admin/manage_staff.php" class="<?= $current_page === 'manage_staff.php' ? 'active' : '' ?>">
                            <i class="bi bi-circle"></i>
                            <span>Manage Staff</span>
                        </a>
                    </li>

                    <li>
                        <a href="/inventory_system/admin/activity_log.php" class="<?= $current_page === 'activity_log.php' ? 'active' : '' ?>">
                            <i class="bi bi-circle"></i>
                            <span>Activity Log</span>
                        </a>
                    </li>

                    <li>
                        <a href="/inventory_system/admin/pos_settings.php" class="<?= $current_page === 'pos_settings.php' ? 'active' : '' ?>">
                            <i class="bi bi-circle"></i>
                            <span>POS Config</span>
                        </a>
                    </li>
                </ul>
            </li>
        <?php endif; ?>

        <li class="nav-item">
            <a
                class="nav-link <?= in_array($current_page, $productPages, true) ? '' : 'collapsed' ?>"
                data-bs-target="#product-nav"
                data-bs-toggle="collapse"
                href="#"
            >
                <i class="bi bi-box-seam"></i>
                <span>Inventory Management</span>
                <i class="bi bi-chevron-down ms-auto"></i>
            </a>

            <ul
                id="product-nav"
                class="nav-content collapse <?= in_array($current_page, $productPages, true) ? 'show' : '' ?>"
                data-bs-parent="#sidebar-nav"
            >
                <li>
                    <a href="/inventory_system/product_management/pos.php" class="<?= $current_page === 'pos.php' ? 'active' : '' ?>">
                        <i class="bi bi-circle"></i>
                        <span>POS / Sales</span>
                    </a>
                </li>

                <li>
                    <a href="/inventory_system/product_management/manage_product.php" class="<?= $current_page === 'manage_product.php' ? 'active' : '' ?>">
                        <i class="bi bi-circle"></i>
                        <span>Manage Products</span>
                    </a>
                </li>

                <li>
                    <a href="/inventory_system/product_management/bulk_upload_products.php" class="<?= $current_page === 'bulk_upload_products.php' ? 'active' : '' ?>">
                        <i class="bi bi-circle"></i>
                        <span>Bulk Create Products</span>
                    </a>
                </li>

                <li>
                    <a href="/inventory_system/product_management/manage_category.php" class="<?= $current_page === 'manage_category.php' ? 'active' : '' ?>">
                        <i class="bi bi-circle"></i>
                        <span>Manage Categories</span>
                    </a>
                </li>

                <li>
                    <a href="/inventory_system/product_management/manage_subcategory.php" class="<?= $current_page === 'manage_subcategory.php' ? 'active' : '' ?>">
                        <i class="bi bi-circle"></i>
                        <span>Manage Subcategories</span>
                    </a>
                </li>

                <li>
                    <a href="/inventory_system/supplier_management/manage_supplier.php" class="<?= $current_page === 'manage_supplier.php' ? 'active' : '' ?>">
                        <i class="bi bi-circle"></i>
                        <span>Manage Suppliers</span>
                    </a>
                </li>
            </ul>
        </li>

        <?php if ($isAdmin): ?>
            <li class="nav-item">
                <a class="nav-link <?= in_array($current_page, $reportPages, true) ? '' : 'collapsed' ?>" data-bs-target="#reports-nav" data-bs-toggle="collapse" href="#">
                    <i class="bi bi-bar-chart"></i>
                    <span>Reports</span>
                    <i class="bi bi-chevron-down ms-auto"></i>
                </a>

                <ul id="reports-nav" class="nav-content collapse <?= in_array($current_page, $reportPages, true) ? 'show' : '' ?>" data-bs-parent="#sidebar-nav">
                    <li>
                        <a href="/inventory_system/reports/sales_report.php" class="<?= $current_page === 'sales_report.php' ? 'active' : '' ?>">
                            <i class="bi bi-circle"></i>
                            <span>Sales Report</span>
                        </a>
                    </li>

                    <li>
                        <a href="/inventory_system/reports/inventory_report.php" class="<?= $current_page === 'inventory_report.php' ? 'active' : '' ?>">
                            <i class="bi bi-circle"></i>
                            <span>Inventory Report</span>
                        </a>
                    </li>
                </ul>
            </li>
        <?php endif; ?>

        <li class="nav-heading">Account</li>

        <li class="nav-item">
            <a class="nav-link <?= $current_page === 'profile.php' ? '' : 'collapsed' ?>" href="/inventory_system/profile.php">
                <i class="bi bi-person"></i>
                <span>My Profile</span>
            </a>
        </li>

        <li class="nav-item">
            <form method="POST" action="/inventory_system/logout.php" class="m-0">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Middleware::generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="nav-link collapsed border-0 bg-transparent w-100 text-start">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </button>
            </form>
        </li>

    </ul>
</aside>
