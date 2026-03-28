<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get the current page filename
$current_page = basename($_SERVER['PHP_SELF']);

// Check if user is admin
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
?>

<!-- ======= Sidebar ======= -->
<aside id="sidebar" class="sidebar">

  <ul class="sidebar-nav" id="sidebar-nav">

    <!-- Dashboard -->
    <li class="nav-item">
      <a class="nav-link <?php if($current_page == 'index.php'){ echo 'active'; } ?>" href="/inventory_system/index.php">
        <i class="bi bi-grid"></i>
        <span>Dashboard</span>
      </a>
    </li>

    <?php if ($isAdmin): ?>
    <!-- Admin -->
    <li class="nav-item">
      <a class="nav-link collapsed <?php if($current_page == 'manage_staff.php' || $current_page == 'activity_log.php') { echo 'active'; } ?>" 
         data-bs-target="#components-nav" data-bs-toggle="collapse" href="#">
        <i class="bi bi-menu-button-wide"></i>
        <span>Admin</span>
        <i class="bi bi-chevron-down ms-auto"></i>
      </a>

      <ul id="components-nav" 
      class="nav-content collapse <?php if($current_page == 'manage_staff.php' || $current_page == 'activity_log.php'){ echo 'show'; } ?>" 
      data-bs-parent="#sidebar-nav">

        <li>
          <a href="/inventory_system/admin/manage_staff.php" 
             class="<?php if($current_page == 'manage_staff.php'){ echo 'active'; } ?>">
            <i class="bi bi-circle"></i>
            <span>Manage Staff</span>
          </a>
        </li>

         <li>
      <a href="/inventory_system/admin/activity_log.php" 
         class="<?php if($current_page == 'activity_log.php'){ echo 'active'; } ?>">
        <i class="bi bi-circle"></i>
        <span>Activity Log</span>
      </a>
    </li>

      </ul>
    </li>
    <?php endif; ?>

    <!-- Product Management -->
    <li class="nav-item">
      <a class="nav-link collapsed <?php if(in_array($current_page, ['pos.php','manage_product.php','manage_category.php'])){ echo 'active'; } ?>" 
         data-bs-target="#forms-nav" data-bs-toggle="collapse" href="#">
        <i class="bi bi-journal-text"></i>
        <span>Product Management</span>
        <i class="bi bi-chevron-down ms-auto"></i>
      </a>

      <ul id="forms-nav" 
          class="nav-content collapse <?php if(in_array($current_page, ['pos.php','manage_product.php','manage_category.php'])){ echo 'show'; } ?>" 
          data-bs-parent="#sidebar-nav">

        <li>
          <a href="/inventory_system/product_management/pos.php" 
             class="<?php if($current_page == 'pos.php'){ echo 'active'; } ?>">
            <i class="bi bi-circle"></i>
            <span>POS / Sales</span>
          </a>
        </li>

        <li>
          <a href="/inventory_system/product_management/manage_product.php" 
             class="<?php if($current_page == 'manage_product.php'){ echo 'active'; } ?>">
            <i class="bi bi-circle"></i>
            <span>Create Product</span>
          </a>
        </li>

        <li>
          <a href="/inventory_system/product_management/manage_category.php" 
             class="<?php if($current_page == 'manage_category.php'){ echo 'active'; } ?>">
            <i class="bi bi-circle"></i>
            <span>Create Category</span>
          </a>
        </li>

      </ul>
    </li>

    <!-- Tables -->
    <li class="nav-item">
      <a class="nav-link collapsed <?php if($current_page == 'tables-general.html' || $current_page == 'tables-data.html'){ echo 'active'; } ?>" 
         data-bs-target="#tables-nav" data-bs-toggle="collapse" href="#">
        <i class="bi bi-layout-text-window-reverse"></i>
        <span>Reports</span>
        <i class="bi bi-chevron-down ms-auto"></i>
      </a>

      <ul id="tables-nav" 
          class="nav-content collapse <?php if($current_page == 'tables-general.html' || $current_page == 'tables-data.html'){ echo 'show'; } ?>" 
          data-bs-parent="#sidebar-nav">

        <li>
          <a href="/inventory_system/page/tables-general.html" 
             class="<?php if($current_page == 'tables-general.html'){ echo 'active'; } ?>">
            <i class="bi bi-circle"></i>
            <span>General Tables</span>
          </a>
        </li>

        <li>
          <a href="/inventory_system/page/tables-data.html" 
             class="<?php if($current_page == 'tables-data.html'){ echo 'active'; } ?>">
            <i class="bi bi-circle"></i>
            <span>Data Tables</span>
          </a>
        </li>

      </ul>
    </li>

    <!-- Pages Heading -->
    <li class="nav-heading">Pages</li>

    <!-- Profile -->
    <li class="nav-item">
      <a class="nav-link <?php if($current_page == 'users-profile.html'){ echo 'active'; } ?>" 
         href="/inventory_system/users-profile.html">
        <i class="bi bi-person"></i>
        <span>Profile</span>
      </a>
    </li>

    <!-- FAQ -->
    <li class="nav-item">
      <a class="nav-link <?php if($current_page == 'pages-faq.html'){ echo 'active'; } ?>" 
         href="/inventory_system/pages-faq.html">
        <i class="bi bi-question-circle"></i>
        <span>F.A.Q</span>
      </a>
    </li>

    <!-- Contact -->
    <li class="nav-item">
      <a class="nav-link <?php if($current_page == 'pages-contact.html'){ echo 'active'; } ?>" 
         href="/inventory_system/pages-contact.html">
        <i class="bi bi-envelope"></i>
        <span>Contact</span>
      </a>
    </li>

    <!-- Register -->
    <li class="nav-item">
      <a class="nav-link <?php if($current_page == 'pages-register.html'){ echo 'active'; } ?>" 
         href="/inventory_system/pages-register.html">
        <i class="bi bi-card-list"></i>
        <span>Register</span>
      </a>
    </li>

    <!-- Login -->
    <li class="nav-item">
      <a class="nav-link <?php if($current_page == 'pages-login.html'){ echo 'active'; } ?>" 
         href="/inventory_system/pages-login.html">
        <i class="bi bi-box-arrow-in-right"></i>
        <span>Login</span>
      </a>
    </li>

    <!-- Error 404 -->
    <li class="nav-item">
      <a class="nav-link <?php if($current_page == 'pages-error-404.html'){ echo 'active'; } ?>" 
         href="/inventory_system/pages-error-404.html">
        <i class="bi bi-dash-circle"></i>
        <span>Error 404</span>
      </a>
    </li>

    <!-- Blank Page -->
    <li class="nav-item">
      <a class="nav-link <?php if($current_page == 'pages-blank.html'){ echo 'active'; } ?>" 
         href="/inventory_system/pages-blank.html">
        <i class="bi bi-file-earmark"></i>
        <span>Blank</span>
      </a>
    </li>

  </ul>

</aside>
<!-- End Sidebar -->
