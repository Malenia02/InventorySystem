<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$current_page = basename($_SERVER['PHP_SELF']);
$userRole = (string) ($_SESSION['role'] ?? '');
$isAdmin = $userRole === 'admin';
$isCashier = $userRole === 'cashier';
$isStaff = $userRole === 'staff';

if (!function_exists('sidebar_is_active')) {
    function sidebar_is_active(string $currentPage, array $links): bool
    {
        foreach ($links as $link) {
            if (($link['page'] ?? '') === $currentPage) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('sidebar_render_group')) {
    function sidebar_render_group(string $id, string $label, string $icon, array $links, string $currentPage): void
    {
        if ($links === []) {
            return;
        }

        $isActive = sidebar_is_active($currentPage, $links);
        ?>
        <li class="nav-item">
            <a
                class="nav-link <?= $isActive ? '' : 'collapsed' ?>"
                data-bs-target="#<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>"
                data-bs-toggle="collapse"
                href="#"
            >
                <i class="bi <?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>"></i>
                <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                <i class="bi bi-chevron-down ms-auto"></i>
            </a>

            <ul
                id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>"
                class="nav-content collapse <?= $isActive ? 'show' : '' ?>"
                data-bs-parent="#sidebar-nav"
            >
                <?php foreach ($links as $link): ?>
                    <li>
                        <a
                            href="<?= htmlspecialchars((string) $link['href'], ENT_QUOTES, 'UTF-8') ?>"
                            class="<?= $currentPage === ($link['page'] ?? '') ? 'active' : '' ?>"
                        >
                            <i class="bi <?= htmlspecialchars((string) ($link['icon'] ?? 'bi-circle'), ENT_QUOTES, 'UTF-8') ?>"></i>
                            <span><?= htmlspecialchars((string) $link['label'], ENT_QUOTES, 'UTF-8') ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </li>
        <?php
    }
}

$posLinks = [];
$inventoryLinks = [];
$purchasingLinks = [];
$approvalLinks = [];
$financeLinks = [];
$reportLinks = [];
$systemLinks = [];

if ($isAdmin || $isCashier) {
    $posLinks[] = [
        'page' => 'pos.php',
        'href' => '/inventory_system/product_management/pos.php',
        'label' => 'POS / Sales',
        'icon' => 'bi-cart-check',
    ];

    $posLinks[] = [
        'page' => 'cashier_sales_history.php',
        'href' => '/inventory_system/cashier_sales_history.php',
        'label' => $isCashier ? 'My Sales History' : 'Cashier Sales History',
        'icon' => 'bi-receipt',
    ];

    $posLinks[] = [
        'page' => 'shift_closing.php',
        'href' => '/inventory_system/shift_closing.php',
        'label' => 'Shift Management',
        'icon' => 'bi-journal-check',
    ];
}

if ($isCashier) {
    $posLinks[] = [
        'page' => 'shift_edit_requests.php',
        'href' => '/inventory_system/shift_edit_requests.php',
        'label' => 'Shift Edit Requests',
        'icon' => 'bi-unlock',
    ];
}

if ($isAdmin) {
    $inventoryLinks = [
        [
            'page' => 'manage_product.php',
            'href' => '/inventory_system/product_management/manage_product.php',
            'label' => 'Manage Products',
            'icon' => 'bi-box-seam',
        ],
        [
            'page' => 'bulk_upload_products.php',
            'href' => '/inventory_system/product_management/bulk_upload_products.php',
            'label' => 'Bulk Create Products',
            'icon' => 'bi-cloud-upload',
        ],
        [
            'page' => 'manage_category.php',
            'href' => '/inventory_system/product_management/manage_category.php',
            'label' => 'Categories',
            'icon' => 'bi-tags',
        ],
        [
            'page' => 'manage_subcategory.php',
            'href' => '/inventory_system/product_management/manage_subcategory.php',
            'label' => 'Subcategories',
            'icon' => 'bi-tag',
        ],
        [
            'page' => 'manage_supplier.php',
            'href' => '/inventory_system/supplier_management/manage_supplier.php',
            'label' => 'Suppliers',
            'icon' => 'bi-truck',
        ],
        [
            'page' => 'stock_movement_audit.php',
            'href' => '/inventory_system/admin/stock_movement_audit.php',
            'label' => 'Stock Movement Audit',
            'icon' => 'bi-activity',
        ],
        [
            'page' => 'reorder_planner.php',
            'href' => '/inventory_system/admin/reorder_planner.php',
            'label' => 'Reorder Intelligence',
            'icon' => 'bi-box-arrow-in-down',
        ],
        [
            'page' => 'barcode_labels.php',
            'href' => '/inventory_system/admin/barcode_labels.php',
            'label' => 'Barcode Labels',
            'icon' => 'bi-upc-scan',
        ],
    ];

    $purchasingLinks = [
        [
            'page' => 'purchase_orders.php',
            'href' => '/inventory_system/admin/purchase_orders.php',
            'label' => 'Purchase Orders',
            'icon' => 'bi-bag-check',
        ],
        [
            'page' => 'purchase_receiving_history.php',
            'href' => '/inventory_system/admin/purchase_receiving_history.php',
            'label' => 'Receiving History',
            'icon' => 'bi-clock-history',
        ],
    ];

    $approvalLinks = [
        [
            'page' => 'approval_center.php',
            'href' => '/inventory_system/admin/approval_center.php',
            'label' => 'Approval Center',
            'icon' => 'bi-check2-square',
        ],
        [
            'page' => 'stock_adjustment_requests.php',
            'href' => '/inventory_system/stock_adjustment_requests.php',
            'label' => 'Stock Requests',
            'icon' => 'bi-clipboard-plus',
        ],
        [
            'page' => 'sale_action_requests.php',
            'href' => '/inventory_system/admin/sale_action_requests.php',
            'label' => 'Sale Requests',
            'icon' => 'bi-arrow-counterclockwise',
        ],
        [
            'page' => 'shift_edit_requests.php',
            'href' => '/inventory_system/shift_edit_requests.php',
            'label' => 'Shift Edit Requests',
            'icon' => 'bi-unlock',
        ],
    ];

    $financeLinks = [
        [
            'page' => 'expense_tracker.php',
            'href' => '/inventory_system/admin/expense_tracker.php',
            'label' => 'Expense Tracker',
            'icon' => 'bi-wallet2',
        ],
    ];

    $reportLinks = [
        [
            'page' => 'sales_report.php',
            'href' => '/inventory_system/reports/sales_report.php',
            'label' => 'Sales Report',
            'icon' => 'bi-graph-up-arrow',
        ],
        [
            'page' => 'inventory_report.php',
            'href' => '/inventory_system/reports/inventory_report.php',
            'label' => 'Inventory Report',
            'icon' => 'bi-clipboard-data',
        ],
    ];

    $systemLinks = [
        [
            'page' => 'manage_staff.php',
            'href' => '/inventory_system/admin/manage_staff.php',
            'label' => 'Manage Staff',
            'icon' => 'bi-people',
        ],
        [
            'page' => 'activity_log.php',
            'href' => '/inventory_system/admin/activity_log.php',
            'label' => 'Activity Log',
            'icon' => 'bi-list-check',
        ],
        [
            'page' => 'pos_settings.php',
            'href' => '/inventory_system/admin/pos_settings.php',
            'label' => 'POS Settings',
            'icon' => 'bi-sliders',
        ],
        [
            'page' => 'backup_restore.php',
            'href' => '/inventory_system/admin/backup_restore.php',
            'label' => 'Backup & Restore',
            'icon' => 'bi-database-check',
        ],
        [
            'page' => 'chatbot_test_console.php',
            'href' => '/inventory_system/admin/chatbot_test_console.php',
            'label' => 'Chatbot Test Console',
            'icon' => 'bi-robot',
        ],
    ];
}

if ($isCashier || $isStaff) {
    $inventoryLinks[] = [
        'page' => 'stock_adjustment_requests.php',
        'href' => '/inventory_system/stock_adjustment_requests.php',
        'label' => 'Stock Requests',
        'icon' => 'bi-clipboard-plus',
    ];
}

$showNotifications = $isAdmin || $isCashier;
?>

<aside id="sidebar" class="sidebar">
    <ul class="sidebar-nav" id="sidebar-nav">

        <li class="nav-item">
            <a class="nav-link <?= $current_page === 'index.php' ? '' : 'collapsed' ?>" href="/inventory_system/index.php">
                <i class="bi bi-grid"></i>
                <span>Dashboard</span>
            </a>
        </li>

        <?php sidebar_render_group('pos-nav', 'POS & Cashier', 'bi-shop-window', $posLinks, $current_page); ?>
        <?php sidebar_render_group('inventory-nav', 'Inventory', 'bi-box-seam', $inventoryLinks, $current_page); ?>
        <?php sidebar_render_group('purchasing-nav', 'Purchasing', 'bi-bag-check', $purchasingLinks, $current_page); ?>
        <?php sidebar_render_group('approvals-nav', 'Approvals & Requests', 'bi-ui-checks', $approvalLinks, $current_page); ?>
        <?php sidebar_render_group('finance-nav', 'Finance', 'bi-cash-stack', $financeLinks, $current_page); ?>
        <?php sidebar_render_group('reports-nav', 'Reports', 'bi-bar-chart', $reportLinks, $current_page); ?>
        <?php sidebar_render_group('system-nav', 'System Admin', 'bi-shield-lock', $systemLinks, $current_page); ?>

        <li class="nav-heading">Account</li>

        <?php if ($showNotifications): ?>
            <li class="nav-item">
                <a class="nav-link <?= $current_page === 'notifications.php' ? '' : 'collapsed' ?>" href="/inventory_system/notifications.php">
                    <i class="bi bi-bell"></i>
                    <span>Notification Center</span>
                </a>
            </li>
        <?php endif; ?>

        <li class="nav-item">
            <a class="nav-link <?= $current_page === 'profile.php' ? '' : 'collapsed' ?>" href="/inventory_system/profile.php">
                <i class="bi bi-person"></i>
                <span>My Profile</span>
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link <?= $current_page === 'settings.php' ? '' : 'collapsed' ?>" href="/inventory_system/settings.php">
                <i class="bi bi-gear"></i>
                <span>Account Settings</span>
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
