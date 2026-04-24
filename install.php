<?php
declare(strict_types=1);

define('INSTALL_CONTEXT', true);

require_once __DIR__ . '/config/config.php';

$pageTitle = 'System Setup';
$appUrlDefault = (app_is_https() ? 'https://' : 'http://')
    . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . '/inventory_system';

$errors         = [];
$successMessage = '';
$publicInstallNotice = '';
$setupKeySuccess     = '';
$canCreateSetupKey   = !app_has_setup_token() && app_is_loopback_request();
$setupKeyForm        = [
    'new_setup_access_key'         => '',
    'new_setup_access_key_confirm' => '',
];

function install_store_logo_upload(?array $file): ?string
{
    if (
        !is_array($file)
        || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Store logo upload failed. Please choose another image.');
    }

    $tmpFile = (string) ($file['tmp_name'] ?? '');
    if ($tmpFile === '' || !is_uploaded_file($tmpFile)) {
        throw new RuntimeException('The uploaded store logo is invalid.');
    }

    $fileSize = (int) ($file['size'] ?? 0);
    if ($fileSize <= 0 || $fileSize > 2097152) {
        throw new RuntimeException('Store logo must be 2MB or smaller.');
    }

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $mimeType = mime_content_type($tmpFile);
    if (!is_string($mimeType) || !array_key_exists($mimeType, $allowedMimeTypes)) {
        throw new RuntimeException('Store logo must be a JPG, PNG, or WEBP image.');
    }

    if (getimagesize($tmpFile) === false) {
        throw new RuntimeException('Store logo must be a valid image file.');
    }

    $uploadDir = rtrim(app_secure_storage_dir(), '/\\') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'pos-config' . DIRECTORY_SEPARATOR;
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Unable to create the secure logo upload folder.');
    }

    $fileName = 'logo_' . bin2hex(random_bytes(12)) . '.' . $allowedMimeTypes[$mimeType];
    $targetPath = $uploadDir . $fileName;

    if (!move_uploaded_file($tmpFile, $targetPath)) {
        throw new RuntimeException('Unable to save the uploaded store logo.');
    }

    return '/inventory_system/media.php?asset=' . rawurlencode('pos-config/' . $fileName);
}

function install_delete_uploaded_logo(?string $logoUrl): void
{
    $logoUrl = trim((string) $logoUrl);
    if ($logoUrl === '' || !str_starts_with($logoUrl, '/inventory_system/media.php')) {
        return;
    }

    $query = parse_url($logoUrl, PHP_URL_QUERY);
    if (!is_string($query) || $query === '') {
        return;
    }

    parse_str($query, $params);
    $asset = trim((string) ($params['asset'] ?? ''));
    if ($asset === '' || str_contains($asset, '..') || !str_starts_with($asset, 'pos-config/')) {
        return;
    }

    $baseDir = rtrim(app_secure_storage_dir(), '/\\') . DIRECTORY_SEPARATOR . 'uploads';
    $path = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $asset);
    $realBase = realpath($baseDir);
    $realDir = realpath(dirname($path));

    if ($realBase !== false && $realDir !== false && str_starts_with($realDir, $realBase) && is_file($path)) {
        @unlink($path);
    }
}

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
        ? 'Installer access is protected. Enter the one-time setup key to continue.'
        : 'Installer access is protected. Create the first setup access key from this device before completing setup.';
}

$form = [
    'setup_access_key'   => '',
    'db_host'            => (string) ($_POST['db_host']   ?? env_value('DB_HOST', '127.0.0.1')),
    'db_port'            => (string) ($_POST['db_port']   ?? env_value('DB_PORT', '3306')),
    'db_name'            => (string) ($_POST['db_name']   ?? env_value('DB_NAME', 'inventory_system')),
    'db_user'            => (string) ($_POST['db_user']   ?? env_value('DB_USER', 'root')),
    'db_pass'            => (string) ($_POST['db_pass']   ?? env_value('DB_PASS', '')),
    'app_url'            => (string) ($_POST['app_url']   ?? env_value('APP_URL', $appUrlDefault)),
    'store_name'         => trim((string) ($_POST['store_name']      ?? 'StockWise Store')),
    'store_address'      => trim((string) ($_POST['store_address']   ?? '')),
    'store_phone'        => trim((string) ($_POST['store_phone']     ?? '')),
    'store_email'        => trim((string) ($_POST['store_email']     ?? '')),
    'opening_hours'      => trim((string) ($_POST['opening_hours']   ?? '08:00')),
    'closing_hours'      => trim((string) ($_POST['closing_hours']   ?? '21:00')),
    'tax_rate'           => trim((string) ($_POST['tax_rate']        ?? '12')),
    'currency'           => trim((string) ($_POST['currency']        ?? 'PHP')),
    'admin_first_name'   => trim((string) ($_POST['admin_first_name'] ?? '')),
    'admin_last_name'    => trim((string) ($_POST['admin_last_name']  ?? '')),
    'admin_username'     => trim((string) ($_POST['admin_username']   ?? '')),
    'admin_email'        => trim((string) ($_POST['admin_email']      ?? '')),
];

// ── POST handler (unchanged logic) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['install_csrf'] ?? '');
    if (!hash_equals((string) ($_SESSION['install_csrf'] ?? ''), $csrf)) {
        $errors[] = 'The setup session expired. Please refresh the page and try again.';
    }

    $postAction = trim((string) ($_POST['form_action'] ?? 'install'));
    $setupKeyForm['new_setup_access_key']         = (string) ($_POST['new_setup_access_key']         ?? '');
    $setupKeyForm['new_setup_access_key_confirm'] = (string) ($_POST['new_setup_access_key_confirm'] ?? '');

    if ($errors === [] && $postAction === 'create_setup_key') {
        if (!$canCreateSetupKey) {
            $errors[] = 'Setup access key creation is allowed only from localhost before a key exists.';
        } elseif ($setupKeyForm['new_setup_access_key'] === '') {
            $errors[] = 'Please enter a setup access key.';
        } elseif ($setupKeyForm['new_setup_access_key'] !== $setupKeyForm['new_setup_access_key_confirm']) {
            $errors[] = 'Setup access key confirmation does not match.';
        } else {
            try {
                app_write_setup_token_hash($setupKeyForm['new_setup_access_key']);
                $setupKeySuccess     = 'Setup access key created successfully. You can now continue with the installer.';
                $publicInstallNotice = 'Installer access is protected. Enter the one-time setup key to continue.';
                $canCreateSetupKey   = false;
            } catch (Throwable $e) {
                $errors[] = $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'Unable to create the setup access key right now.';
            }
        }
    }

    $form['setup_access_key'] = (string) ($_POST['setup_access_key'] ?? '');
    $adminPassword        = (string) ($_POST['admin_password']         ?? '');
    $adminPasswordConfirm = (string) ($_POST['admin_password_confirm'] ?? '');

    if ($errors === [] && $postAction !== 'create_setup_key' && app_public_install_requires_token()) {
        if (!app_has_setup_token()) {
            $errors[] = 'Installer access requires a setup token. Create the first setup access key on localhost before continuing.';
        } elseif (!app_validate_setup_token($form['setup_access_key'])) {
            $errors[] = 'Installer access denied.';
        }
    }

    if ($postAction === 'create_setup_key') {
        $adminPassword = $adminPasswordConfirm = '';
    }

    if ($postAction !== 'create_setup_key') {
        if ($form['db_host'] === '' || $form['db_port'] === '' || $form['db_name'] === '' || $form['db_user'] === '')
            $errors[] = 'Database host, port, name, and username are required.';

        if ($form['db_port'] !== '' && !ctype_digit($form['db_port']))
            $errors[] = 'Database port must contain digits only.';

        if ($form['db_name'] !== '' && preg_match('/^[A-Za-z0-9_]+$/', $form['db_name']) !== 1)
            $errors[] = 'Database name may only contain letters, numbers, and underscores.';

        if ($form['store_name'] === '')
            $errors[] = 'Store name is required.';

        if ($form['tax_rate'] === '' || !is_numeric($form['tax_rate']) || (float) $form['tax_rate'] < 0)
            $errors[] = 'Tax rate must be a valid non-negative number.';

        if ($form['admin_first_name'] === '' || $form['admin_last_name'] === '' || $form['admin_username'] === '')
            $errors[] = 'Admin first name, last name, and username are required.';

        if ($adminPassword === '' || strlen($adminPassword) < 8)
            $errors[] = 'Admin password must be at least 8 characters.';

        if ($adminPassword !== $adminPasswordConfirm)
            $errors[] = 'Admin password confirmation does not match.';

        if ($form['admin_email'] !== '' && !filter_var($form['admin_email'], FILTER_VALIDATE_EMAIL))
            $errors[] = 'Admin email must be a valid email address.';

        if ($form['store_email'] !== '' && !filter_var($form['store_email'], FILTER_VALIDATE_EMAIL))
            $errors[] = 'Store email must be a valid email address.';

        if (
            isset($_FILES['store_logo'])
            && is_array($_FILES['store_logo'])
            && (int) ($_FILES['store_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
            && (int) ($_FILES['store_logo']['size'] ?? 0) > 2097152
        ) {
            $errors[] = 'Store logo must be 2MB or smaller.';
        }
    }

    if ($errors === [] && $postAction !== 'create_setup_key') {
        $uploadedLogo = null;
        try {
            $uploadedLogo = install_store_logo_upload($_FILES['store_logo'] ?? null);
            $serverConn  = app_create_database_connection($form['db_host'], $form['db_port'], $form['db_user'], $form['db_pass']);
            $quotedDbName = str_replace('`', '``', $form['db_name']);
            $serverConn->exec("CREATE DATABASE IF NOT EXISTS `{$quotedDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

            $dbConn     = app_create_database_connection($form['db_host'], $form['db_port'], $form['db_user'], $form['db_pass'], $form['db_name']);
            $tableCount = app_database_table_count($serverConn, $form['db_name']);

            if ($tableCount === 0) {
                app_import_sql_file($dbConn, DATABASE_PATH . '/schema.sql');
            } else {
                foreach (['users', 'pos_config', 'categories', 'products', 'sales', 'sale_items'] as $t) {
                    if (!app_has_table($dbConn, $t)) {
                        throw new RuntimeException('The selected database already has tables, but it is missing required table "' . $t . '". Please use an empty database.');
                    }
                }
            }

            $dbConn->beginTransaction();

            $dbConn->exec('DELETE FROM pos_config');
            $dbConn->prepare('
                INSERT INTO pos_config (store_name,store_address,store_phone,store_email,opening_hours,closing_hours,tax_rate,currency,logo)
                VALUES (:store_name,:store_address,:store_phone,:store_email,:opening_hours,:closing_hours,:tax_rate,:currency,:logo)
            ')->execute([
                ':store_name'    => $form['store_name'],
                ':store_address' => $form['store_address'] !== '' ? $form['store_address'] : null,
                ':store_phone'   => $form['store_phone']   !== '' ? $form['store_phone']   : null,
                ':store_email'   => $form['store_email']   !== '' ? $form['store_email']   : null,
                ':opening_hours' => $form['opening_hours'] !== '' ? $form['opening_hours'] : null,
                ':closing_hours' => $form['closing_hours'] !== '' ? $form['closing_hours'] : null,
                ':tax_rate'      => (float) $form['tax_rate'],
                ':currency'      => $form['currency']      !== '' ? strtoupper($form['currency']) : 'PHP',
                ':logo'          => $uploadedLogo,
            ]);

            $existingUserStmt = $dbConn->prepare('
                SELECT COUNT(*) FROM users
                WHERE username = :username OR (:email_check IS NOT NULL AND email = :email_value)
            ');
            $existingUserStmt->execute([
                ':username'    => $form['admin_username'],
                ':email_check' => $form['admin_email'] !== '' ? $form['admin_email'] : null,
                ':email_value' => $form['admin_email'] !== '' ? $form['admin_email'] : null,
            ]);

            if ((int) $existingUserStmt->fetchColumn() > 0) {
                throw new RuntimeException('An account with that admin username or email already exists.');
            }

            $dbConn->prepare('
                INSERT INTO users (first_name,last_name,email,username,password,role,status)
                VALUES (:first_name,:last_name,:email,:username,:password,:role,:status)
            ')->execute([
                ':first_name' => $form['admin_first_name'],
                ':last_name'  => $form['admin_last_name'],
                ':email'      => $form['admin_email'] !== '' ? $form['admin_email'] : null,
                ':username'   => $form['admin_username'],
                ':password'   => password_hash($adminPassword, PASSWORD_DEFAULT),
                ':role'       => 'admin',
                ':status'     => 'active',
            ]);

            $dbConn->commit();

            app_write_env_file(BASE_PATH, [
                'APP_INSTALLED'        => 'true',
                'APP_URL'              => rtrim($form['app_url'], '/'),
                'APP_SETUP_TOKEN_HASH' => '',
                'DB_HOST'              => $form['db_host'],
                'DB_PORT'              => $form['db_port'],
                'DB_NAME'              => $form['db_name'],
                'DB_USER'              => $form['db_user'],
                'DB_PASS'              => $form['db_pass'],
            ]);

            app_clear_setup_token(BASE_PATH);
            app_lock_installation(BASE_PATH);

            $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
            $_SESSION['setup_success'] = 'Setup completed. You can now log in with your admin account.';
            safe_redirect('/inventory_system/login.php');
        } catch (Throwable $e) {
            if (isset($dbConn) && $dbConn instanceof PDO && $dbConn->inTransaction()) {
                $dbConn->rollBack();
            }
            if ($uploadedLogo !== null) {
                install_delete_uploaded_logo($uploadedLogo);
            }
            error_log('[install.php] ' . $e->getMessage());
            $errors[] = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Setup could not be completed right now. Please check your database details and try again.';
        }
    }
}

// ── Determine which step to show (based on first error section) ──
// We pass the active step hint back via hidden input so the JS can
// restore the correct step on validation failure.
$activeStep = max(0, min(3, (int) ($_POST['active_step'] ?? 0)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require __DIR__ . '/components/head.php'; ?>
<link rel="stylesheet" href="/inventory_system/assets/css/install.css">
</head>
<body class="install-page">
<main class="install-main">
<div class="install-shell">

  <!-- ═══════════════════════════════════════
       SIDEBAR
       ═══════════════════════════════════════ -->
  <aside class="install-sidebar">

    <div class="install-logo">
      <div class="install-logo-icon">
        <i class="bi bi-grid-2x2-fill"></i>
      </div>
      <div>
        <div class="install-logo-name">StockWise</div>
        <div class="install-logo-ver">v1.0 · First run setup</div>
      </div>
    </div>

    <nav class="install-steps" id="installSteps">

      <div class="install-step" data-step="0">
        <div class="install-step-num" id="isn0">
          <span>1</span>
          <i class="bi bi-check-lg"></i>
        </div>
        <div class="install-step-info">
          <div class="install-step-label">Database</div>
          <div class="install-step-desc">Connection &amp; credentials</div>
        </div>
      </div>
      <div class="install-step-line"></div>

      <div class="install-step" data-step="1">
        <div class="install-step-num" id="isn1">
          <span>2</span>
          <i class="bi bi-check-lg"></i>
        </div>
        <div class="install-step-info">
          <div class="install-step-label">Store profile</div>
          <div class="install-step-desc">Name, hours &amp; VAT</div>
        </div>
      </div>
      <div class="install-step-line"></div>

      <div class="install-step" data-step="2">
        <div class="install-step-num" id="isn2">
          <span>3</span>
          <i class="bi bi-check-lg"></i>
        </div>
        <div class="install-step-info">
          <div class="install-step-label">Admin account</div>
          <div class="install-step-desc">Name, username &amp; password</div>
        </div>
      </div>
      <div class="install-step-line"></div>

      <div class="install-step" data-step="3">
        <div class="install-step-num" id="isn3">
          <span>4</span>
          <i class="bi bi-check-lg"></i>
        </div>
        <div class="install-step-info">
          <div class="install-step-label">Review &amp; install</div>
          <div class="install-step-desc">Confirm and complete</div>
        </div>
      </div>

    </nav>

    <div class="install-sidebar-footer">
      <p>Settings are written to <code><?= htmlspecialchars(app_external_env_path(), ENT_QUOTES, 'UTF-8') ?></code>.<br>The installer locks itself after completion.</p>
    </div>

  </aside>

  <!-- ═══════════════════════════════════════
       MAIN AREA
       ═══════════════════════════════════════ -->
  <div class="install-main-area">

    <!-- Top bar -->
    <div class="install-topbar">
      <div class="install-step-badge" id="topStepBadge">Step 1 of 4</div>
      <div class="install-step-title" id="topStepTitle">Database configuration</div>
      <div class="install-step-sub" id="topStepSub">Connect to your MySQL database. The application database will be created automatically.</div>
      <div class="install-progress">
        <div class="install-progress-fill" id="progressFill" style="width:25%"></div>
      </div>
    </div>

    <!-- Scrollable body -->
    <div class="install-content" id="installBody">

      <!-- Global alerts -->
      <?php if ($errors !== []): ?>
        <div class="install-alert install-alert--danger">
          <i class="bi bi-exclamation-circle-fill"></i>
          <div>
            <?php foreach ($errors as $err): ?>
              <div><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($setupKeySuccess !== ''): ?>
        <div class="install-alert install-alert--success">
          <i class="bi bi-check-circle-fill"></i>
          <div><?= htmlspecialchars($setupKeySuccess, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
      <?php endif; ?>

      <?php if ($publicInstallNotice !== ''): ?>
        <div class="install-alert install-alert--warning">
          <i class="bi bi-shield-lock-fill"></i>
          <div><?= htmlspecialchars($publicInstallNotice, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
      <?php endif; ?>

      <!-- ── Create setup key form (localhost only) ── -->
      <?php if ($canCreateSetupKey): ?>
        <form method="post" action="" class="mb-0" id="setupKeyForm">
          <input type="hidden" name="install_csrf"  value="<?= htmlspecialchars((string) $_SESSION['install_csrf'], ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="form_action"   value="create_setup_key">
          <input type="hidden" name="active_step"   value="0">

          <div class="install-section">
            <div class="install-section-label">Create first setup access key</div>
            <div class="install-field-grid install-field-grid--two">
              <div class="install-field-group">
                <label class="install-label">New setup access key</label>
                <input type="password" class="install-input" name="new_setup_access_key"
                       value="<?= htmlspecialchars($setupKeyForm['new_setup_access_key'], ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
                <div class="install-hint">Only available from this same machine before any key exists.</div>
              </div>
              <div class="install-field-group">
                <label class="install-label">Confirm setup access key</label>
                <input type="password" class="install-input" name="new_setup_access_key_confirm"
                       value="<?= htmlspecialchars($setupKeyForm['new_setup_access_key_confirm'], ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
              </div>
            </div>
            <div class="install-footer-actions" style="margin-top:1rem;border:0;padding:0">
              <div></div>
              <button type="submit" class="install-btn-next">Create key <i class="bi bi-arrow-right"></i></button>
            </div>
          </div>
        </form>
      <?php endif; ?>

      <!-- ── Main install form ── -->
      <form method="post" action="" id="installForm" enctype="multipart/form-data">
        <input type="hidden" name="install_csrf" value="<?= htmlspecialchars((string) $_SESSION['install_csrf'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="form_action"  value="install">
        <input type="hidden" name="active_step"  id="activeStepInput" value="<?= $activeStep ?>">

        <!-- ────────────────────────────────────
             STEP 0 — Database
             ──────────────────────────────────── -->
        <div class="install-step-panel" id="panel0">

          <?php if (app_public_install_requires_token()): ?>
            <div class="install-section">
              <div class="install-section-label">Setup access key</div>
              <div class="install-field-group">
                <label class="install-label">One-time setup key</label>
                <input type="password" class="install-input" name="setup_access_key"
                       value="<?= htmlspecialchars($form['setup_access_key'], ENT_QUOTES, 'UTF-8') ?>"
                       required autocomplete="off">
                <div class="install-hint">The hashed setup key is stored in <code><?= htmlspecialchars(app_setup_token_path(), ENT_QUOTES, 'UTF-8') ?></code>.</div>
              </div>
            </div>
          <?php endif; ?>

          <div class="install-section">
            <div class="install-section-label">Database connection</div>
            <div class="install-field-grid install-field-grid--two">
              <div class="install-field-group">
                <label class="install-label">Host</label>
                <input type="text" class="install-input" name="db_host"
                       value="<?= htmlspecialchars($form['db_host'], ENT_QUOTES, 'UTF-8') ?>" required>
                <div class="install-hint">Use <code>127.0.0.1</code> for local XAMPP</div>
              </div>
              <div class="install-field-group">
                <label class="install-label">Port</label>
                <input type="text" class="install-input" name="db_port"
                       value="<?= htmlspecialchars($form['db_port'], ENT_QUOTES, 'UTF-8') ?>" required>
                <div class="install-hint">Default MySQL port is <code>3306</code></div>
              </div>
              <div class="install-field-group">
                <label class="install-label">Database name</label>
                <input type="text" class="install-input" name="db_name"
                       value="<?= htmlspecialchars($form['db_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                <div class="install-hint">Created automatically if it does not exist</div>
              </div>
              <div class="install-field-group">
                <label class="install-label">Username</label>
                <input type="text" class="install-input" name="db_user"
                       value="<?= htmlspecialchars($form['db_user'], ENT_QUOTES, 'UTF-8') ?>" required>
                <div class="install-hint">Often <code>root</code> for local MySQL</div>
              </div>
              <div class="install-field-group install-field-group--span">
                <label class="install-label">Password</label>
                <input type="password" class="install-input" name="db_pass"
                       value="<?= htmlspecialchars($form['db_pass'], ENT_QUOTES, 'UTF-8') ?>">
                <div class="install-hint">Leave blank if your local MySQL account has no password</div>
              </div>
              <div class="install-field-group install-field-group--span">
                <label class="install-label">Application URL</label>
                <input type="text" class="install-input" name="app_url"
                       value="<?= htmlspecialchars($form['app_url'], ENT_QUOTES, 'UTF-8') ?>" required>
                <div class="install-hint">Full browser path, e.g. <code>http://localhost/inventory_system</code></div>
              </div>
            </div>
          </div>

        </div><!-- /#panel0 -->

        <!-- ────────────────────────────────────
             STEP 1 — Store profile
             ──────────────────────────────────── -->
        <div class="install-step-panel" id="panel1">

          <div class="install-section">
            <div class="install-section-label">Store details</div>
            <div class="install-logo-upload">
              <div class="install-logo-preview" id="storeLogoPreview">
                <i class="bi bi-shop-window"></i>
                <img src="" alt="Store logo preview" hidden>
              </div>
              <div class="install-logo-upload-copy">
                <label class="install-label" for="storeLogoInput">Store logo</label>
                <p>Upload the client logo once during setup. It will appear on POS receipts and store branding areas.</p>
                <div class="install-logo-actions">
                  <label class="install-logo-pick" for="storeLogoInput">
                    <i class="bi bi-cloud-arrow-up"></i>
                    Choose logo
                  </label>
                  <span id="storeLogoName">JPG, PNG, or WEBP up to 2MB</span>
                </div>
                <input type="file" id="storeLogoInput" name="store_logo" accept="image/jpeg,image/png,image/webp">
              </div>
            </div>
            <div class="install-field-grid install-field-grid--two">
              <div class="install-field-group">
                <label class="install-label">Store name</label>
                <input type="text" class="install-input" name="store_name"
                       value="<?= htmlspecialchars($form['store_name'], ENT_QUOTES, 'UTF-8') ?>" required>
              </div>
              <div class="install-field-group">
                <label class="install-label">Store email</label>
                <input type="email" class="install-input" name="store_email"
                       value="<?= htmlspecialchars($form['store_email'], ENT_QUOTES, 'UTF-8') ?>">
              </div>
              <div class="install-field-group">
                <label class="install-label">Phone</label>
                <input type="text" class="install-input" name="store_phone"
                       value="<?= htmlspecialchars($form['store_phone'], ENT_QUOTES, 'UTF-8') ?>">
              </div>
              <div class="install-field-group">
                <label class="install-label">Currency code</label>
                <input type="text" class="install-input" name="currency"
                       value="<?= htmlspecialchars($form['currency'], ENT_QUOTES, 'UTF-8') ?>">
              </div>
              <div class="install-field-group install-field-group--span">
                <label class="install-label">Store address</label>
                <textarea class="install-input install-textarea" name="store_address"><?= htmlspecialchars($form['store_address'], ENT_QUOTES, 'UTF-8') ?></textarea>
              </div>
            </div>
          </div>

          <div class="install-section">
            <div class="install-section-label">Operating hours &amp; tax</div>
            <div class="install-field-grid install-field-grid--three">
              <div class="install-field-group">
                <label class="install-label">Opening time</label>
                <input type="time" class="install-input" name="opening_hours"
                       value="<?= htmlspecialchars($form['opening_hours'], ENT_QUOTES, 'UTF-8') ?>">
              </div>
              <div class="install-field-group">
                <label class="install-label">Closing time</label>
                <input type="time" class="install-input" name="closing_hours"
                       value="<?= htmlspecialchars($form['closing_hours'], ENT_QUOTES, 'UTF-8') ?>">
              </div>
              <div class="install-field-group">
                <label class="install-label">VAT rate (%)</label>
                <input type="number" step="0.01" min="0" class="install-input" name="tax_rate"
                       value="<?= htmlspecialchars($form['tax_rate'], ENT_QUOTES, 'UTF-8') ?>">
              </div>
            </div>
          </div>

        </div><!-- /#panel1 -->

        <!-- ────────────────────────────────────
             STEP 2 — Admin account
             ──────────────────────────────────── -->
        <div class="install-step-panel" id="panel2">

          <div class="install-section">
            <div class="install-section-label">Personal information</div>
            <div class="install-field-grid install-field-grid--two">
              <div class="install-field-group">
                <label class="install-label">First name</label>
                <input type="text" class="install-input" name="admin_first_name"
                       value="<?= htmlspecialchars($form['admin_first_name'], ENT_QUOTES, 'UTF-8') ?>" required>
              </div>
              <div class="install-field-group">
                <label class="install-label">Last name</label>
                <input type="text" class="install-input" name="admin_last_name"
                       value="<?= htmlspecialchars($form['admin_last_name'], ENT_QUOTES, 'UTF-8') ?>" required>
              </div>
              <div class="install-field-group">
                <label class="install-label">Username</label>
                <input type="text" class="install-input" name="admin_username"
                       value="<?= htmlspecialchars($form['admin_username'], ENT_QUOTES, 'UTF-8') ?>" required>
                <div class="install-hint">Used to log in to the system</div>
              </div>
              <div class="install-field-group">
                <label class="install-label">Email</label>
                <input type="email" class="install-input" name="admin_email"
                       value="<?= htmlspecialchars($form['admin_email'], ENT_QUOTES, 'UTF-8') ?>">
              </div>
            </div>
          </div>

          <div class="install-section">
            <div class="install-section-label">Password</div>
            <div class="install-field-grid install-field-grid--two">
              <div class="install-field-group">
                <label class="install-label">Password</label>
                <input type="password" class="install-input" name="admin_password"
                       id="adminPasswordInput" required autocomplete="new-password">
                <div class="install-pw-bar">
                  <div class="install-pw-fill" id="pwStrengthFill"></div>
                </div>
                <div class="install-hint" id="pwStrengthLabel">Minimum 8 characters</div>
              </div>
              <div class="install-field-group">
                <label class="install-label">Confirm password</label>
                <input type="password" class="install-input" name="admin_password_confirm" required>
              </div>
            </div>
          </div>

        </div><!-- /#panel2 -->

        <!-- ────────────────────────────────────
             STEP 3 — Review & install
             ──────────────────────────────────── -->
        <div class="install-step-panel" id="panel3">

          <div class="install-alert install-alert--warning">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div>The installer will be permanently locked after this step. Review your settings carefully before proceeding.</div>
          </div>

          <div class="install-section">
            <div class="install-section-label">Database</div>
            <div class="install-review-table">
              <div class="install-review-row"><span>Host</span><strong><?= htmlspecialchars($form['db_host'] . ':' . $form['db_port'], ENT_QUOTES, 'UTF-8') ?></strong></div>
              <div class="install-review-row"><span>Database</span><strong><?= htmlspecialchars($form['db_name'], ENT_QUOTES, 'UTF-8') ?></strong></div>
              <div class="install-review-row"><span>Username</span><strong><?= htmlspecialchars($form['db_user'], ENT_QUOTES, 'UTF-8') ?></strong></div>
              <div class="install-review-row"><span>App URL</span><strong><?= htmlspecialchars($form['app_url'], ENT_QUOTES, 'UTF-8') ?></strong></div>
            </div>
          </div>

          <div class="install-section">
            <div class="install-section-label">Store profile</div>
            <div class="install-review-table">
              <div class="install-review-row"><span>Store name</span><strong><?= htmlspecialchars($form['store_name'],    ENT_QUOTES, 'UTF-8') ?></strong></div>
              <div class="install-review-row"><span>Logo</span>       <strong id="reviewStoreLogo">Optional upload</strong></div>
              <div class="install-review-row"><span>VAT rate</span>  <strong><?= htmlspecialchars($form['tax_rate'],      ENT_QUOTES, 'UTF-8') ?>%</strong></div>
              <div class="install-review-row"><span>Currency</span>  <strong><?= htmlspecialchars(strtoupper($form['currency']), ENT_QUOTES, 'UTF-8') ?></strong></div>
              <div class="install-review-row"><span>Hours</span>     <strong><?= htmlspecialchars($form['opening_hours'] . ' – ' . $form['closing_hours'], ENT_QUOTES, 'UTF-8') ?></strong></div>
            </div>
          </div>

          <div class="install-section">
            <div class="install-section-label">Admin account</div>
            <div class="install-review-table">
              <div class="install-review-row"><span>Full name</span>  <strong><?= htmlspecialchars(trim($form['admin_first_name'] . ' ' . $form['admin_last_name']), ENT_QUOTES, 'UTF-8') ?></strong></div>
              <div class="install-review-row"><span>Username</span>   <strong><?= htmlspecialchars($form['admin_username'], ENT_QUOTES, 'UTF-8') ?></strong></div>
              <div class="install-review-row"><span>Email</span>      <strong><?= htmlspecialchars($form['admin_email'] ?: '—', ENT_QUOTES, 'UTF-8') ?></strong></div>
            </div>
          </div>

          <div class="install-confirm-check">
            <input type="checkbox" id="confirmInstall" name="confirm_install" value="1">
            <label for="confirmInstall">I have reviewed the settings above and want to complete the setup</label>
          </div>

        </div><!-- /#panel3 -->

        <!-- Footer actions (inside form so submit works) -->
        <div class="install-footer-actions" id="footerActions">
          <button type="button" class="install-btn-back" id="btnBack">
            <i class="bi bi-arrow-left"></i> Back
          </button>
          <button type="button" class="install-btn-next" id="btnNext">
            Continue <i class="bi bi-arrow-right"></i>
          </button>
        </div>

      </form><!-- /#installForm -->

    </div><!-- /.install-content -->

  </div><!-- /.install-main-area -->
</div><!-- /.install-shell -->
</main>

<?php require __DIR__ . '/components/js_script.php'; ?>
<script src="/inventory_system/assets/js/install.js"></script>
</body>
</html>
