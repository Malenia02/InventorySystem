<?php
session_start();

// ── Get error code ────────────────────────────────────────────
// Priority:
// 1. Session (set manually by your PHP code)
// 2. Apache's REDIRECT_STATUS (set automatically by ErrorDocument)
// 3. Query string ?code=403 (set by Middleware redirect)
// 4. Default 0

$error_code = 0;

if (isset($_SESSION['error_code'])) {
    $error_code = (int) $_SESSION['error_code'];
} elseif (isset($_SERVER['REDIRECT_STATUS'])) {
    $error_code = (int) $_SERVER['REDIRECT_STATUS'];
} elseif (isset($_GET['code'])) {
    $error_code = (int) $_GET['code'];
}

// ── Get error message ─────────────────────────────────────────
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
    unset($_SESSION['error_code']);
} else {
    switch ($error_code) {
        case 400:
            $error_message = "Bad request. Please check your input and try again.";
            break;
        case 401:
            $error_message = "You need to log in to access this page.";
            break;
        case 403:
            $error_message = "You don't have permission to access this page.";
            break;
        case 404:
            $error_message = "Sorry, the page you are looking for could not be found.";
            break;
        case 500:
            $error_message = "Sorry, there is a problem with the server. Please try again later.";
            break;
        default:
            $error_message = "An unexpected error occurred. Please try again later.";
            break;
    }
}

// ── Fallback URL ──────────────────────────────────────────────
$previous_url = htmlspecialchars($_SERVER['HTTP_REFERER'] ?? '/inventory_system/index.php');
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>Error <?= $error_code ?: '' ?> - Inventory System</title>

    <!-- Favicons -->
    <link href="/inventory_system/assets/img/favicon.png" rel="icon">
    <link href="/inventory_system/assets/img/apple-touch-icon.png" rel="apple-touch-icon">

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

    <!-- Template Main CSS File -->
    <link href="/inventory_system/assets/css/style.css" rel="stylesheet">
</head>

<body>

    <main>
        <div class="container min-vh-100 d-flex flex-column justify-content-center align-items-center">
            <div class="card p-4" style="max-width:420px; width:100%;">

                <!-- Error code badge -->
                <?php if ($error_code > 0): ?>
                    <h1 class="text-center fw-bold mb-1" style="font-size:64px; color:#4154f1;">
                        <?= $error_code ?>
                    </h1>
                <?php endif; ?>

                <h5 class="card-title text-center pb-2 fs-5">
                    <?php
                    $titles = [
                        400 => 'Bad Request',
                        401 => 'Unauthorized',
                        403 => 'Access Denied',
                        404 => 'Page Not Found',
                        500 => 'Server Error',
                    ];
                    echo htmlspecialchars($titles[$error_code] ?? 'Something Went Wrong');
                    ?>
                </h5>

                <p class="text-center alert alert-danger">
                    <?= htmlspecialchars($error_message) ?>
                </p>

                <button class="btn btn-primary w-100 mb-2"
                    onclick="window.location.href='/inventory_system/index.php';">
                    <i class="bi bi-house me-1"></i> Go to Home
                </button>

                <button class="btn btn-secondary w-100"
                    onclick="window.location.href='<?= $previous_url ?>';">
                    <i class="bi bi-arrow-left me-1"></i> Go Back
                </button>

            </div>
        </div>
    </main>

    <!-- Back to top -->
    <a href="#" class="back-to-top d-flex align-items-center justify-content-center">
        <i class="bi bi-arrow-up-short"></i>
    </a>

    <!-- Vendor JS Files -->
    <script src="/inventory_system/assets/vendor/apexcharts/apexcharts.min.js"></script>
    <script src="/inventory_system/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="/inventory_system/assets/vendor/chart.js/chart.umd.js"></script>
    <script src="/inventory_system/assets/vendor/echarts/echarts.min.js"></script>
    <script src="/inventory_system/assets/vendor/quill/quill.js"></script>
    <script src="/inventory_system/assets/vendor/simple-datatables/simple-datatables.js"></script>
    <script src="/inventory_system/assets/vendor/tinymce/tinymce.min.js"></script>
    <script src="/inventory_system/assets/vendor/php-email-form/validate.js"></script>

    <!-- Template Main JS File -->
    <script src="/inventory_system/assets/js/main.js"></script>

</body>

</html>