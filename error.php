<?php
session_start();

// Default error code and message
$error_code = $_SESSION['error_code'] ?? 0;
$error_message = "An unexpected error occurred. Please try again later.";

// If a custom error message exists, use it
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
    unset($_SESSION['error_code']);
} else {
    // Handle common HTTP error codes
    switch ($error_code) {
        case 404:
            $error_message = "Sorry, the page you are looking for could not be found.";
            break;
        case 500:
            $error_message = "Sorry, there is a problem with the server. Please try again later.";
            break;
        case 403:
            $error_message = "You don't have permission to access this page.";
            break;
        case 401:
            $error_message = "You need to log in to access this page.";
            break;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">

    <title>Error - Inventory_System</title>

    <!-- Favicons -->
    <link href="/inventory_system/assets/img/favicon.png" rel="icon">
    <link href="/inventory_system/assets/img/apple-touch-icon.png" rel="apple-touch-icon">

    <!-- Google Fonts -->
    <link href="https://fonts.gstatic.com" rel="preconnect">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700|Nunito:300,400,600,700|Poppins:300,400,500,600,700"
        rel="stylesheet">

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
        <div class="container">
            <section class="section register min-vh-100 d-flex flex-column align-items-center justify-content-center py-4">
                <div class="container">
                    <div class="row justify-content-center">
                        <div class="col-lg-4 col-md-6 d-flex flex-column align-items-center justify-content-center">
                            <div class="d-flex justify-content-center py-4">
                                <a href="/inventory_system/index.php" class="logo d-flex align-items-center w-auto">
                                    <img src="/inventory_system/assets/img/artlogo.png" alt="">
                                    <span class="d-none d-lg-block"></span>
                                </a>
                            </div>
                            <div class="card mb-3">
                                <div class="card-body">
                                    <h5 class="card-title text-center pb-0 fs-4">Error <?php echo htmlspecialchars($error_code); ?></h5>
                                    <div>
                                        <p class="text-center small alert alert-danger"><?php echo htmlspecialchars($error_message); ?></p>
                                    </div>
                                    <button id="homeButton" class="btn btn-primary w-100"
                                        onclick="window.location.href='/inventory_system/index.php';">Reload</button>
                                    <button class="btn btn-secondary w-100 mt-2" onclick="history.back();">Go Back</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <!-- Back to top -->
    <a href="#" class="back-to-top d-flex align-items-center justify-content-center"><i
            class="bi bi-arrow-up-short"></i></a>

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
