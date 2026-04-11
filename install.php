<?php
declare(strict_types=1);

define('INSTALL_CONTEXT', true);

require_once __DIR__ . '/config/config.php';

$pageTitle = 'System Setup';
$appUrlDefault = (app_is_https() ? 'https://' : 'http://')
    . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . '/inventory_system';

$errors = [];
$successMessage = '';
$publicInstallNotice = '';

if (empty($_SESSION['install_csrf']) || !is_string($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}

if (app_is_install_locked()) {
    app_redirect_install_blocked('Installer access is disabled because setup has already been completed for this system.');
}

if (app_has_database_env()) {
    try {
        $existingConn = app_create_database_connection($dbservername, $dbport, $dbusername, $dbpassword, $dbname);
        if (app_is_fully_installed($existingConn)) {
            app_redirect_install_blocked('Installer access is disabled because the application is already installed.');
        }
    } catch (Throwable $e) {
        error_log('[install.php existing-check] ' . $e->getMessage());
    }
}

if (app_public_install_requires_token()) {
    $publicInstallNotice = app_has_setup_token()
        ? 'Public installer access is protected. Enter the one-time setup key to continue.'
        : 'Public installer access is protected. Create config/setup.token with the SHA-256 hash of a one-time setup key before completing setup.';
}

$form = [
    'setup_access_key' => '',
    'db_host' => (string) ($_POST['db_host'] ?? env_value('DB_HOST', '127.0.0.1')),
    'db_port' => (string) ($_POST['db_port'] ?? env_value('DB_PORT', '3306')),
    'db_name' => (string) ($_POST['db_name'] ?? env_value('DB_NAME', 'inventory_system')),
    'db_user' => (string) ($_POST['db_user'] ?? env_value('DB_USER', 'root')),
    'db_pass' => (string) ($_POST['db_pass'] ?? env_value('DB_PASS', '')),
    'app_url' => (string) ($_POST['app_url'] ?? env_value('APP_URL', $appUrlDefault)),
    'store_name' => trim((string) ($_POST['store_name'] ?? 'StockWise Store')),
    'store_address' => trim((string) ($_POST['store_address'] ?? '')),
    'store_phone' => trim((string) ($_POST['store_phone'] ?? '')),
    'store_email' => trim((string) ($_POST['store_email'] ?? '')),
    'opening_hours' => trim((string) ($_POST['opening_hours'] ?? '08:00')),
    'closing_hours' => trim((string) ($_POST['closing_hours'] ?? '21:00')),
    'tax_rate' => trim((string) ($_POST['tax_rate'] ?? '12')),
    'currency' => trim((string) ($_POST['currency'] ?? 'PHP')),
    'admin_first_name' => trim((string) ($_POST['admin_first_name'] ?? '')),
    'admin_last_name' => trim((string) ($_POST['admin_last_name'] ?? '')),
    'admin_username' => trim((string) ($_POST['admin_username'] ?? '')),
    'admin_email' => trim((string) ($_POST['admin_email'] ?? '')),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['install_csrf'] ?? '');
    if (!hash_equals((string) ($_SESSION['install_csrf'] ?? ''), $csrf)) {
        $errors[] = 'The setup session expired. Please refresh the page and try again.';
    }

    $form['setup_access_key'] = (string) ($_POST['setup_access_key'] ?? '');

    $adminPassword = (string) ($_POST['admin_password'] ?? '');
    $adminPasswordConfirm = (string) ($_POST['admin_password_confirm'] ?? '');

    if (app_public_install_requires_token()) {
        if (!app_has_setup_token()) {
            $errors[] = 'Public installer access requires a setup token. Create config/setup.token with a SHA-256 hash of your one-time setup key before using the public installer.';
        } elseif (!app_validate_setup_token($form['setup_access_key'])) {
            $errors[] = 'The setup access key is invalid.';
        }
    }

    if ($form['db_host'] === '' || $form['db_port'] === '' || $form['db_name'] === '' || $form['db_user'] === '') {
        $errors[] = 'Database host, port, name, and username are required.';
    }

    if ($form['store_name'] === '') {
        $errors[] = 'Store name is required.';
    }

    if ($form['tax_rate'] === '' || !is_numeric($form['tax_rate']) || (float) $form['tax_rate'] < 0) {
        $errors[] = 'Tax rate must be a valid non-negative number.';
    }

    if ($form['admin_first_name'] === '' || $form['admin_last_name'] === '' || $form['admin_username'] === '') {
        $errors[] = 'Admin first name, last name, and username are required.';
    }

    if ($adminPassword === '' || strlen($adminPassword) < 8) {
        $errors[] = 'Admin password must be at least 8 characters.';
    }

    if ($adminPassword !== $adminPasswordConfirm) {
        $errors[] = 'Admin password confirmation does not match.';
    }

    if ($form['admin_email'] !== '' && !filter_var($form['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Admin email must be a valid email address.';
    }

    if ($form['store_email'] !== '' && !filter_var($form['store_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Store email must be a valid email address.';
    }

    if ($errors === []) {
        try {
            $serverConn = app_create_database_connection(
                $form['db_host'],
                $form['db_port'],
                $form['db_user'],
                $form['db_pass']
            );

            $quotedDbName = str_replace('`', '``', $form['db_name']);
            $serverConn->exec("CREATE DATABASE IF NOT EXISTS `{$quotedDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

            $dbConn = app_create_database_connection(
                $form['db_host'],
                $form['db_port'],
                $form['db_user'],
                $form['db_pass'],
                $form['db_name']
            );

            $tableCount = app_database_table_count($serverConn, $form['db_name']);
            if ($tableCount === 0) {
                app_import_sql_file($dbConn, DATABASE_PATH . '/schema.sql');
            } else {
                $requiredTables = ['users', 'pos_config', 'categories', 'products', 'sales', 'sale_items'];
                foreach ($requiredTables as $requiredTable) {
                    if (!app_has_table($dbConn, $requiredTable)) {
                        throw new RuntimeException(
                            'The selected database already has tables, but it is missing required table "' . $requiredTable . '". Please use an empty database.'
                        );
                    }
                }
            }

            $dbConn->beginTransaction();

            $dbConn->exec('DELETE FROM pos_config');
            $configStmt = $dbConn->prepare('
                INSERT INTO pos_config (
                    store_name,
                    store_address,
                    store_phone,
                    store_email,
                    opening_hours,
                    closing_hours,
                    tax_rate,
                    currency
                ) VALUES (
                    :store_name,
                    :store_address,
                    :store_phone,
                    :store_email,
                    :opening_hours,
                    :closing_hours,
                    :tax_rate,
                    :currency
                )
            ');
            $configStmt->execute([
                ':store_name' => $form['store_name'],
                ':store_address' => $form['store_address'] !== '' ? $form['store_address'] : null,
                ':store_phone' => $form['store_phone'] !== '' ? $form['store_phone'] : null,
                ':store_email' => $form['store_email'] !== '' ? $form['store_email'] : null,
                ':opening_hours' => $form['opening_hours'] !== '' ? $form['opening_hours'] : null,
                ':closing_hours' => $form['closing_hours'] !== '' ? $form['closing_hours'] : null,
                ':tax_rate' => (float) $form['tax_rate'],
                ':currency' => $form['currency'] !== '' ? strtoupper($form['currency']) : 'PHP',
            ]);

            $existingUserStmt = $dbConn->prepare('
                SELECT COUNT(*)
                FROM users
                WHERE username = :username
                   OR (:email_check IS NOT NULL AND email = :email_value)
            ');
            $existingUserStmt->execute([
                ':username' => $form['admin_username'],
                ':email_check' => $form['admin_email'] !== '' ? $form['admin_email'] : null,
                ':email_value' => $form['admin_email'] !== '' ? $form['admin_email'] : null,
            ]);

            if ((int) $existingUserStmt->fetchColumn() > 0) {
                throw new RuntimeException('An account with that admin username or email already exists.');
            }

            $userStmt = $dbConn->prepare('
                INSERT INTO users (
                    first_name,
                    last_name,
                    email,
                    username,
                    password,
                    role,
                    status
                ) VALUES (
                    :first_name,
                    :last_name,
                    :email,
                    :username,
                    :password,
                    :role,
                    :status
                )
            ');
            $userStmt->execute([
                ':first_name' => $form['admin_first_name'],
                ':last_name' => $form['admin_last_name'],
                ':email' => $form['admin_email'] !== '' ? $form['admin_email'] : null,
                ':username' => $form['admin_username'],
                ':password' => password_hash($adminPassword, PASSWORD_DEFAULT),
                ':role' => 'admin',
                ':status' => 'active',
            ]);

            $dbConn->commit();

            app_write_env_file(BASE_PATH, [
                'APP_INSTALLED' => 'true',
                'APP_URL' => rtrim($form['app_url'], '/'),
                'APP_SETUP_TOKEN_HASH' => '',
                'DB_HOST' => $form['db_host'],
                'DB_PORT' => $form['db_port'],
                'DB_NAME' => $form['db_name'],
                'DB_USER' => $form['db_user'],
                'DB_PASS' => $form['db_pass'],
            ]);

            app_clear_setup_token(BASE_PATH);
            app_lock_installation(BASE_PATH);

            $_ENV['DB_HOST'] = $form['db_host'];
            $_ENV['DB_PORT'] = $form['db_port'];
            $_ENV['DB_NAME'] = $form['db_name'];
            $_ENV['DB_USER'] = $form['db_user'];
            $_ENV['DB_PASS'] = $form['db_pass'];
            $_ENV['APP_URL'] = rtrim($form['app_url'], '/');
            $_ENV['APP_INSTALLED'] = 'true';

            $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
            $_SESSION['setup_success'] = 'Setup completed. You can now log in with your admin account.';
            safe_redirect('/inventory_system/login.php');
        } catch (Throwable $e) {
            if (isset($dbConn) && $dbConn instanceof PDO && $dbConn->inTransaction()) {
                $dbConn->rollBack();
            }
            error_log('[install.php] ' . $e->getMessage());
            $errors[] = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Setup could not be completed right now. Please check your database details and try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require __DIR__ . '/components/head.php'; ?>
<style>
    body {
        min-height: 100vh;
        background:
            radial-gradient(circle at top left, rgba(184, 130, 42, 0.14), transparent 28%),
            linear-gradient(180deg, #f5f4f0 0%, #eeebe5 100%);
    }

    .setup-shell {
        width: min(100%, 1180px);
        margin: 0 auto;
    }

    .setup-card {
        border: 1px solid rgba(215, 204, 190, 0.9);
        border-radius: 28px;
        background: rgba(255, 255, 255, 0.94);
        box-shadow: 0 28px 70px rgba(38, 32, 24, 0.12);
        overflow: hidden;
    }

    .setup-brand {
        background:
            linear-gradient(180deg, rgba(184, 130, 42, 0.12), rgba(184, 130, 42, 0.03)),
            #fbfaf7;
        border-right: 1px solid rgba(215, 204, 190, 0.75);
        padding: 2.5rem;
        height: 100%;
    }

    .setup-brand .eyebrow {
        display: inline-flex;
        align-items: center;
        gap: .45rem;
        padding: .45rem .75rem;
        border-radius: 999px;
        background: rgba(184, 130, 42, 0.1);
        color: #8c601d;
        font-size: .75rem;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
    }

    .setup-brand h1 {
        margin-top: 1rem;
        margin-bottom: .85rem;
        color: #1c1a17;
        font-size: clamp(2rem, 3vw, 2.8rem);
        line-height: 1.05;
    }

    .setup-brand p {
        color: #6b6560;
        max-width: 30rem;
        margin-bottom: 1.2rem;
    }

    .setup-points {
        display: grid;
        gap: .85rem;
        margin-top: 1.5rem;
    }

    .setup-point {
        padding: .95rem 1rem;
        border-radius: 18px;
        background: #fff;
        border: 1px solid rgba(226, 221, 214, 0.95);
    }

    .setup-point strong {
        display: block;
        color: #1c1a17;
        margin-bottom: .2rem;
    }

    .setup-point span {
        color: #6b6560;
        font-size: .95rem;
    }

    .setup-form {
        padding: 2.3rem 2.2rem;
    }

    .setup-section {
        padding: 1.2rem 1.25rem;
        border: 1px solid rgba(226, 221, 214, 0.95);
        border-radius: 20px;
        background: #fcfbf8;
        margin-bottom: 1rem;
    }

    .setup-section h2 {
        font-size: 1.05rem;
        margin-bottom: .9rem;
        color: #1c1a17;
    }

    .setup-help {
        color: #8b847b;
        font-size: .88rem;
        margin-top: .4rem;
    }

    .setup-submit {
        border: 0;
        border-radius: 16px;
        padding: .95rem 1.2rem;
        background: linear-gradient(135deg, #b8822a, #d39a37);
        color: #fff;
        font-weight: 700;
        box-shadow: 0 18px 32px rgba(184, 130, 42, 0.18);
    }

    @media (max-width: 991.98px) {
        .setup-brand {
            border-right: 0;
            border-bottom: 1px solid rgba(215, 204, 190, 0.75);
            padding: 2rem 1.5rem;
        }

        .setup-form {
            padding: 1.6rem 1.2rem;
        }
    }
</style>
</head>
<body>
<main class="py-4 py-lg-5">
    <div class="container setup-shell">
        <div class="setup-card">
            <div class="row g-0">
                <div class="col-lg-5">
                    <div class="setup-brand">
                        <span class="eyebrow"><i class="bi bi-sliders"></i> First Run Setup</span>
                        <h1>Set up the store once, then hand it off with confidence.</h1>
                        <p>This setup creates the database structure, saves the POS store profile, and prepares the first admin account in one pass.</p>

                        <div class="setup-points">
                            <div class="setup-point">
                                <strong>1. Database Connection</strong>
                                <span>Point the system to MySQL and create the application database automatically.</span>
                            </div>
                            <div class="setup-point">
                                <strong>2. POS Store Profile</strong>
                                <span>Save the store name, receipt details, VAT rate, and basic operating hours.</span>
                            </div>
                            <div class="setup-point">
                                <strong>3. Admin Account</strong>
                                <span>Create the first administrator who will manage products, users, and reports.</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="setup-form">
                        <div class="mb-4">
                            <h2 class="h3 mb-1">Inventory System Setup</h2>
                            <p class="text-muted mb-0">Fill in the essentials below. After setup, the app will redirect to the login page.</p>
                        </div>

                        <?php if ($errors !== []): ?>
                            <div class="alert alert-danger">
                                <ul class="mb-0 ps-3">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if ($successMessage !== ''): ?>
                            <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>

                        <?php if ($publicInstallNotice !== ''): ?>
                            <div class="alert alert-warning">
                                <?= htmlspecialchars($publicInstallNotice, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>

                        <form method="post" action="">
                            <input type="hidden" name="install_csrf" value="<?= htmlspecialchars((string) $_SESSION['install_csrf'], ENT_QUOTES, 'UTF-8') ?>">

                            <?php if (app_public_install_requires_token()): ?>
                                <div class="setup-section">
                                    <h2>Setup Access Key</h2>
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label">One-Time Setup Key</label>
                                            <input type="password" class="form-control" name="setup_access_key" value="<?= htmlspecialchars($form['setup_access_key'], ENT_QUOTES, 'UTF-8') ?>" required autocomplete="off">
                                            <div class="setup-help">
                                                Public installer access is locked behind a separate setup key. Store only its SHA-256 hash in <code>config/setup.token</code>.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="setup-section">
                                <h2>Database Configuration</h2>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Database Host</label>
                                        <input type="text" class="form-control" name="db_host" value="<?= htmlspecialchars($form['db_host'], ENT_QUOTES, 'UTF-8') ?>" required>
                                        <div class="setup-help">Use <code>127.0.0.1</code> when MySQL is on the same computer.</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Database Port</label>
                                        <input type="text" class="form-control" name="db_port" value="<?= htmlspecialchars($form['db_port'], ENT_QUOTES, 'UTF-8') ?>" required>
                                        <div class="setup-help">Use <code>3306</code> for most XAMPP MySQL installations.</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Database Name</label>
                                        <input type="text" class="form-control" name="db_name" value="<?= htmlspecialchars($form['db_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                                        <div class="setup-help">This database will be created automatically if it does not exist yet.</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Database Username</label>
                                        <input type="text" class="form-control" name="db_user" value="<?= htmlspecialchars($form['db_user'], ENT_QUOTES, 'UTF-8') ?>" required>
                                        <div class="setup-help">For local XAMPP, this is often <code>root</code>.</div>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Database Password</label>
                                        <input type="password" class="form-control" name="db_pass" value="<?= htmlspecialchars($form['db_pass'], ENT_QUOTES, 'UTF-8') ?>">
                                        <div class="setup-help">Leave this blank only if the local MySQL account has no password.</div>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Application URL</label>
                                        <input type="text" class="form-control" name="app_url" value="<?= htmlspecialchars($form['app_url'], ENT_QUOTES, 'UTF-8') ?>" required>
                                        <div class="setup-help">Use the full browser path for this system, for example <code>http://localhost/inventory_system</code>.</div>
                                    </div>
                                </div>
                            </div>

                            <div class="setup-section">
                                <h2>POS Store Configuration</h2>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Store Name</label>
                                        <input type="text" class="form-control" name="store_name" value="<?= htmlspecialchars($form['store_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Store Email</label>
                                        <input type="email" class="form-control" name="store_email" value="<?= htmlspecialchars($form['store_email'], ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Store Phone</label>
                                        <input type="text" class="form-control" name="store_phone" value="<?= htmlspecialchars($form['store_phone'], ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Opening</label>
                                        <input type="time" class="form-control" name="opening_hours" value="<?= htmlspecialchars($form['opening_hours'], ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Closing</label>
                                        <input type="time" class="form-control" name="closing_hours" value="<?= htmlspecialchars($form['closing_hours'], ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">VAT Rate (%)</label>
                                        <input type="number" step="0.01" min="0" class="form-control" name="tax_rate" value="<?= htmlspecialchars($form['tax_rate'], ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Currency</label>
                                        <input type="text" class="form-control" name="currency" value="<?= htmlspecialchars($form['currency'], ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Store Address</label>
                                        <textarea class="form-control" name="store_address" rows="3"><?= htmlspecialchars($form['store_address'], ENT_QUOTES, 'UTF-8') ?></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="setup-section mb-4">
                                <h2>Create Admin Account</h2>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">First Name</label>
                                        <input type="text" class="form-control" name="admin_first_name" value="<?= htmlspecialchars($form['admin_first_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Last Name</label>
                                        <input type="text" class="form-control" name="admin_last_name" value="<?= htmlspecialchars($form['admin_last_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Username</label>
                                        <input type="text" class="form-control" name="admin_username" value="<?= htmlspecialchars($form['admin_username'], ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Email</label>
                                        <input type="email" class="form-control" name="admin_email" value="<?= htmlspecialchars($form['admin_email'], ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Password</label>
                                        <input type="password" class="form-control" name="admin_password" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Confirm Password</label>
                                        <input type="password" class="form-control" name="admin_password_confirm" required>
                                    </div>
                                </div>
                            </div>

                            <button type="submit" class="setup-submit w-100">Complete Setup</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
</body>
</html>
