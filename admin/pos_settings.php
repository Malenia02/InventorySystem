<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/AuthController.php';
require_once __DIR__ . '/../controllers/PosConfigController.php';

Middleware::auth()->role(['admin']);

$csrfToken      = Middleware::generateCsrfToken();
$successMessage = null;
$errorMessage   = null;
$config         = [];

// ── Data loading — always try/catch so DB error ≠ white screen ───────────────
try {
    $config = PosConfigController::get($conn);
} catch (Throwable $e) {
    error_log('[pos_settings.php load] ' . $e->getMessage());
    $errorMessage = 'Failed to load current configuration.';
    $config       = [];
}

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!AuthController::validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Security token mismatch. Please refresh and try again.');
        }

        $adminPassword = (string) ($_POST['admin_password'] ?? '');
        AuthController::requireStepUpOrPassword($conn, (int) ($_SESSION['user_id'] ?? 0), $adminPassword);

        $config         = PosConfigController::save($conn, $_POST, $_FILES);
        AuthController::markStepUpVerified();
        $successMessage = 'POS configuration saved successfully.';
        $errorMessage   = null;

    } catch (Throwable $e) {
        error_log('[pos_settings.php POST] ' . $e->getMessage());
        $errorMessage = ($e instanceof InvalidArgumentException || $e instanceof RuntimeException)
            ? $e->getMessage()
            : 'Unable to save POS configuration right now.';

        // Re-fetch config so the form still shows saved values
        try {
            $config = PosConfigController::get($conn);
        } catch (Throwable $e2) {
            error_log('[pos_settings.php reload] ' . $e2->getMessage());
        }
    }
}

function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

$logoPreviewUrl = PosConfigController::logoUrl($config['logo'] ?? null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/../components/head.php'; ?>
    <title>POS &amp; Shift Settings</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        /* ── Design tokens (unified) ──────────────────────────────── */
        :root {
            --ff-base:'DM Sans',system-ui,sans-serif;
            --ff-mono:'DM Mono',monospace;
            --c-bg:#f5f4f1; --c-surface:#fff; --c-surface-2:#f9f8f6;
            --c-border:#e8e6e1; --c-border-2:#d4d1cb;
            --c-text-1:#1a1917; --c-text-2:#5a5854; --c-text-3:#9a9691;
            --c-accent:#2563eb; --c-accent-bg:#eff4ff; --c-accent-bd:#bfcffd;
            --c-green:#16a34a;  --c-green-bg:#f0fdf4;  --c-green-bd:#bbf7d0;
            --c-amber:#b45309;  --c-amber-bg:#fffbeb;  --c-amber-bd:#fde68a;
            --c-red:#dc2626;    --c-red-bg:#fef2f2;    --c-red-bd:#fecaca;
            --c-purple:#7c3aed; --c-purple-bg:#f5f3ff; --c-purple-bd:#ddd6fe;
            --radius-sm:6px; --radius-md:10px; --radius-lg:14px; --radius-xl:20px;
            --shadow-sm:0 1px 3px rgba(0,0,0,.07),0 1px 2px rgba(0,0,0,.04);
            --shadow-lg:0 12px 32px rgba(0,0,0,.10),0 4px 8px rgba(0,0,0,.05);
        }
        *,*::before,*::after{box-sizing:border-box}
        body{font-family:var(--ff-base);background:var(--c-bg);color:var(--c-text-1);font-size:14px;line-height:1.5}

        #main{padding:1.5rem 2rem 3rem}
        .pagetitle h1{font-size:22px;font-weight:600;letter-spacing:-.3px;margin-bottom:.25rem}
        .breadcrumb{display:flex;align-items:center;gap:6px;list-style:none;padding:0;margin:0 0 1.5rem;font-size:12px;color:var(--c-text-3)}
        .breadcrumb-item+.breadcrumb-item::before{content:'/';margin-right:6px;color:var(--c-border-2)}
        .breadcrumb-item a{color:var(--c-text-2);text-decoration:none}
        .breadcrumb-item a:hover{color:var(--c-accent)}
        .breadcrumb-item.active{color:var(--c-text-1)}

        /* ── Layout ─────────────────────────────────────────────── */
        .settings-layout{display:grid;grid-template-columns:1fr 280px;gap:1.5rem;align-items:start}
        @media(max-width:1100px){.settings-layout{grid-template-columns:1fr}}

        /* ── Page header card ───────────────────────────────────── */
        .page-header{
            background:var(--c-surface);border:1px solid var(--c-border);
            border-radius:var(--radius-xl);box-shadow:var(--shadow-sm);
            padding:1.5rem;margin-bottom:1.5rem;
            display:flex;align-items:flex-start;
            justify-content:space-between;flex-wrap:wrap;gap:1.25rem;
        }
        .page-eyebrow{font-size:10.5px;font-weight:700;letter-spacing:.08em;
            text-transform:uppercase;color:var(--c-accent);margin-bottom:4px}
        .page-title{font-size:20px;font-weight:600;letter-spacing:-.3px;
            color:var(--c-text-1);margin:0 0 4px}
        .page-sub{font-size:13px;color:var(--c-text-2);margin:0}
        .header-stats{display:flex;gap:1rem;flex-wrap:wrap}
        .header-stat{
            background:var(--c-surface-2);border:1px solid var(--c-border);
            border-radius:var(--radius-md);padding:.75rem 1.1rem;min-width:120px;
        }
        .header-stat span{font-size:11px;color:var(--c-text-3);display:block;margin-bottom:3px;font-weight:500}
        .header-stat strong{font-size:18px;font-weight:600;color:var(--c-text-1);font-family:var(--ff-mono)}

        /* ── Section card ───────────────────────────────────────── */
        .sc{background:var(--c-surface);border:1px solid var(--c-border);
            border-radius:var(--radius-xl);box-shadow:var(--shadow-sm);
            overflow:hidden;margin-bottom:1.5rem}
        .sc-head{padding:1.25rem 1.5rem 0}
        .sc-title{font-size:16px;font-weight:600;color:var(--c-text-1);margin-bottom:3px}
        .sc-sub{font-size:12px;color:var(--c-text-3)}
        .sc-body{padding:1.25rem 1.5rem 1.5rem}
        .sc-divider{height:1px;background:var(--c-border);margin:1.25rem 0}

        /* ── Field block ────────────────────────────────────────── */
        .field-block{margin-bottom:1.25rem}
        .field-block:last-child{margin-bottom:0}
        .field-block-title{font-size:13px;font-weight:600;color:var(--c-text-1);margin-bottom:3px}
        .field-block-sub{font-size:12px;color:var(--c-text-3);margin-bottom:.875rem}

        /* ── Form elements ──────────────────────────────────────── */
        .form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:1rem}
        .form-grid.cols-3{grid-template-columns:repeat(3,1fr)}
        .form-grid.cols-1{grid-template-columns:1fr}
        @media(max-width:700px){.form-grid,.form-grid.cols-3{grid-template-columns:1fr}}

        .form-group{display:flex;flex-direction:column;gap:.3rem}
        .form-label{font-size:11px;font-weight:600;text-transform:uppercase;
            letter-spacing:.05em;color:var(--c-text-3)}
        .form-control,.form-select{
            height:38px;padding:0 12px;
            background:var(--c-surface-2);border:1px solid var(--c-border);
            border-radius:var(--radius-md);font-family:var(--ff-base);
            font-size:13px;color:var(--c-text-1);
            transition:border-color .15s,box-shadow .15s;width:100%;
        }
        textarea.form-control{height:auto;padding:10px 12px}
        .form-control:focus,.form-select:focus{
            border-color:var(--c-accent);
            box-shadow:0 0 0 3px rgba(37,99,235,.1);outline:none;
        }
        .form-control::placeholder{color:var(--c-text-3)}
        .form-hint{font-size:11px;color:var(--c-text-3);margin-top:2px}
        .form-control[type="file"]{height:auto;padding:8px 12px;cursor:pointer}

        /* ── Logo preview ───────────────────────────────────────── */
        .logo-preview{
            display:flex;align-items:center;gap:1rem;
            background:var(--c-surface-2);border:1px solid var(--c-border);
            border-radius:var(--radius-md);padding:.875rem;
        }
        .logo-preview img{
            width:60px;height:60px;object-fit:contain;
            border-radius:var(--radius-sm);background:var(--c-surface);
            border:1px solid var(--c-border);flex-shrink:0;
        }
        .logo-preview-label{font-size:12px;color:var(--c-text-2);line-height:1.5}

        /* ── Accent section (shift rules) ───────────────────────── */
        .field-block-accent{
            background:var(--c-accent-bg);border:1px solid var(--c-accent-bd);
            border-radius:var(--radius-lg);padding:1.1rem 1.25rem;
            margin-bottom:1.25rem;
        }
        .field-block-accent .field-block-title{color:var(--c-accent)}
        .field-block-accent .form-label{color:var(--c-accent);opacity:.8}
        .field-block-accent .form-control,.field-block-accent .form-select{
            background:var(--c-surface);border-color:var(--c-accent-bd);
        }

        /* ── Confirm password row ───────────────────────────────── */
        .confirm-row{
            display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;
            padding-top:1rem;border-top:1px solid var(--c-border);
            margin-top:1.25rem;
        }
        .confirm-row .form-group{flex:1;min-width:200px}

        /* ── Buttons ────────────────────────────────────────────── */
        .btn{display:inline-flex;align-items:center;gap:6px;height:38px;padding:0 18px;
            border-radius:var(--radius-md);font-family:var(--ff-base);font-size:13px;
            font-weight:500;cursor:pointer;border:1px solid transparent;
            transition:all .15s;white-space:nowrap;text-decoration:none}
        .btn-primary{background:var(--c-accent);color:#fff;border-color:var(--c-accent)}
        .btn-primary:hover{background:#1d4ed8}
        .btn-outline{background:var(--c-surface);color:var(--c-text-2);border-color:var(--c-border-2)}
        .btn-outline:hover{background:var(--c-surface-2);color:var(--c-text-1)}
        .btn:disabled{opacity:.5;cursor:not-allowed}

        /* ── Alert ──────────────────────────────────────────────── */
        .alert{padding:.875rem 1.1rem;border-radius:var(--radius-md);font-size:13px;
            border:1px solid transparent;margin-bottom:1rem}
        .alert-success{background:var(--c-green-bg);color:var(--c-green);border-color:var(--c-green-bd)}
        .alert-danger {background:var(--c-red-bg);  color:var(--c-red);  border-color:var(--c-red-bd)}

        /* ── Side guide card ────────────────────────────────────── */
        .guide-card{background:var(--c-surface);border:1px solid var(--c-border);
            border-radius:var(--radius-xl);box-shadow:var(--shadow-sm);padding:1.25rem;
            margin-bottom:1rem;position:sticky;top:1.5rem}
        .guide-card-title{font-size:14px;font-weight:600;color:var(--c-text-1);margin-bottom:.875rem}
        .guide-item{display:flex;gap:.75rem;align-items:flex-start;margin-bottom:.875rem}
        .guide-item:last-child{margin-bottom:0}
        .guide-item-icon{
            width:30px;height:30px;border-radius:var(--radius-sm);
            background:var(--c-accent-bg);color:var(--c-accent);
            display:flex;align-items:center;justify-content:center;
            font-size:15px;flex-shrink:0;margin-top:1px;
        }
        .guide-item-text strong{display:block;font-size:13px;font-weight:600;
            color:var(--c-text-1);margin-bottom:2px}
        .guide-item-text span{font-size:12px;color:var(--c-text-2);line-height:1.5}

        @media(max-width:768px){#main{padding:1rem}}
    </style>
</head>
<body>
<?php
require __DIR__ . '/../components/header.php';
require __DIR__ . '/../components/sidebar.php';
?>

<main id="main" class="main">
    <div class="pagetitle">
        <h1>POS &amp; Shift Settings</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/inventory_system/index.php">Home</a></li>
                <li class="breadcrumb-item active">POS &amp; Shift Settings</li>
            </ol>
        </nav>
    </div>

    <!-- ── Page header ──────────────────────────────────────────────── -->
    <div class="page-header">
        <div>
            <div class="page-eyebrow">Store Controls</div>
            <div class="page-title">POS &amp; Shift Settings</div>
            <div class="page-sub">
                Manage store branding, receipt details, tax defaults, and the approval windows
                that protect shift corrections.
            </div>
        </div>
        <div class="header-stats">
            <div class="header-stat">
                <span>Cashier edit window</span>
                <strong><?= (int) ($config['shift_edit_window_hours'] ?? 8) ?> hr</strong>
            </div>
            <div class="header-stat">
                <span>Approved unlock</span>
                <strong><?= (int) ($config['shift_unlock_window_hours'] ?? 2) ?> hr</strong>
            </div>
            <div class="header-stat">
                <span>VAT rate</span>
                <strong><?= e(number_format((float) ($config['tax_rate'] ?? 12), 2)) ?>%</strong>
            </div>
        </div>
    </div>

    <div class="settings-layout">

        <!-- ── Left: form ─────────────────────────────────────────── -->
        <div>

            <?php if ($successMessage !== null): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle me-2" aria-hidden="true"></i><?= e($successMessage) ?>
                </div>
            <?php endif; ?>
            <?php if ($errorMessage !== null): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2" aria-hidden="true"></i><?= e($errorMessage) ?>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token"    value="<?= e($csrfToken) ?>">
                <input type="hidden" name="logo_current"  value="<?= e($config['logo'] ?? '') ?>">

                <!-- ── Store identity ─────────────────────────────── -->
                <div class="sc">
                    <div class="sc-head">
                        <div class="sc-title">Store Identity</div>
                        <div class="sc-sub">These values appear on receipts, branding, and store-facing pages.</div>
                    </div>
                    <div class="sc-body">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Store Name <span style="color:var(--c-red);">*</span></label>
                                <input type="text" name="store_name" class="form-control"
                                       value="<?= e($config['store_name'] ?? '') ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Store Phone</label>
                                <input type="text" name="store_phone" class="form-control"
                                       value="<?= e($config['store_phone'] ?? '') ?>"
                                       placeholder="e.g. 0917-000-1111">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Store Email</label>
                                <input type="email" name="store_email" class="form-control"
                                       value="<?= e($config['store_email'] ?? '') ?>"
                                       placeholder="e.g. store@example.com">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Upload Logo</label>
                                <input type="file" name="logo_upload" class="form-control"
                                       accept="image/jpeg,image/png,image/webp">
                                <div class="form-hint">JPG, PNG, or WEBP · Max 2 MB · Stored outside web root</div>
                            </div>
                        </div>

                        <?php if ($logoPreviewUrl !== null): ?>
                            <div style="margin-top:1rem;">
                                <div class="form-label" style="margin-bottom:.5rem;">Current Logo</div>
                                <div class="logo-preview">
                                    <img src="<?= e($logoPreviewUrl) ?>" alt="Current POS logo">
                                    <div class="logo-preview-label">
                                        This logo is used on POS receipts and branding.
                                        Upload a new file above to replace it.
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="sc-divider"></div>

                        <div class="form-group">
                            <label class="form-label">Store Address</label>
                            <textarea name="store_address" class="form-control" rows="3"
                                      placeholder="Store address for receipts and POS"><?= e($config['store_address'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- ── Store operations ───────────────────────────── -->
                <div class="sc">
                    <div class="sc-head">
                        <div class="sc-title">Store Operations</div>
                        <div class="sc-sub">Tax defaults, currency label, and operating hours.</div>
                    </div>
                    <div class="sc-body">
                        <div class="form-grid cols-3">
                            <div class="form-group">
                                <label class="form-label">VAT Rate (%) <span style="color:var(--c-red);">*</span></label>
                                <input type="number" name="tax_rate" class="form-control"
                                       value="<?= e(number_format((float) ($config['tax_rate'] ?? 12), 2, '.', '')) ?>"
                                       step="0.01" min="0" max="100" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Currency <span style="color:var(--c-red);">*</span></label>
                                <input type="text" name="currency" class="form-control"
                                       value="<?= e($config['currency'] ?? 'PHP') ?>"
                                       maxlength="10" required>
                            </div>
                            <div class="form-group">
                                <!-- spacer -->
                            </div>
                            <div class="form-group">
                                <label class="form-label">Opening Hours</label>
                                <input type="time" name="opening_hours" class="form-control"
                                       value="<?= e($config['opening_hours'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Closing Hours</label>
                                <input type="time" name="closing_hours" class="form-control"
                                       value="<?= e($config['closing_hours'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Shift management rules ─────────────────────── -->
                <div class="sc">
                    <div class="sc-head">
                        <div class="sc-title">Shift Management Rules</div>
                        <div class="sc-sub">
                            Control how long cashiers can edit a closed shift, and how long an
                            approved unlock stays open.
                        </div>
                    </div>
                    <div class="sc-body">
                        <div class="field-block-accent">
                            <div class="field-block-title">Edit &amp; Unlock Windows</div>
                            <div class="field-block-sub" style="color:var(--c-text-2);font-size:12px;margin-bottom:.875rem;">
                                After the cashier edit window expires, corrections require admin approval.
                                The approved unlock window defines how long that approval stays active.
                            </div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Cashier edit window (hours)</label>
                                    <input type="number" name="shift_edit_window_hours" class="form-control"
                                           value="<?= (int) ($config['shift_edit_window_hours'] ?? 8) ?>"
                                           min="1" max="72" required>
                                    <div class="form-hint">
                                        After this window, cashiers must request admin approval before editing a closed shift.
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Approved unlock duration (hours)</label>
                                    <input type="number" name="shift_unlock_window_hours" class="form-control"
                                           value="<?= (int) ($config['shift_unlock_window_hours'] ?? 2) ?>"
                                           min="1" max="24" required>
                                    <div class="form-hint">
                                        When an admin approves a request, the shift remains editable for this long.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Confirm + submit ───────────────────────────── -->
                <div class="sc">
                    <div class="sc-body">
                        <div class="confirm-row">
                            <div class="form-group">
                                <label class="form-label">Admin password confirmation</label>
                                <input type="password" name="admin_password" class="form-control"
                                       autocomplete="current-password"
                                       placeholder="Confirm your password to save sensitive changes">
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-floppy" aria-hidden="true"></i> Save configuration
                            </button>
                            <a href="/inventory_system/shift_edit_requests.php" class="btn btn-outline">
                                <i class="bi bi-inbox" aria-hidden="true"></i> Request center
                            </a>
                            <a href="/inventory_system/product_management/pos.php" class="btn btn-outline">
                                <i class="bi bi-cash-register" aria-hidden="true"></i> Open POS
                            </a>
                        </div>
                    </div>
                </div>

            </form>
        </div><!-- /.settings-left -->


        <!-- ── Right: guide ───────────────────────────────────────── -->
        <div>
            <div class="guide-card">
                <div class="guide-card-title">What these settings control</div>

                <div class="guide-item">
                    <div class="guide-item-icon"><i class="bi bi-shop" aria-hidden="true"></i></div>
                    <div class="guide-item-text">
                        <strong>Branding</strong>
                        <span>Store name, logo, and address on receipts and POS screens.</span>
                    </div>
                </div>

                <div class="guide-item">
                    <div class="guide-item-icon"><i class="bi bi-receipt" aria-hidden="true"></i></div>
                    <div class="guide-item-text">
                        <strong>POS Defaults</strong>
                        <span>VAT rate applied to all vatable products at checkout.</span>
                    </div>
                </div>

                <div class="guide-item">
                    <div class="guide-item-icon"><i class="bi bi-shield-lock" aria-hidden="true"></i></div>
                    <div class="guide-item-text">
                        <strong>Shift Protection</strong>
                        <span>
                            The cashier edit window prevents late corrections without oversight.
                            Approved unlocks let admins grant time-limited access.
                        </span>
                    </div>
                </div>

                <div class="guide-item">
                    <div class="guide-item-icon"><i class="bi bi-person-check" aria-hidden="true"></i></div>
                    <div class="guide-item-text">
                        <strong>Owner Control</strong>
                        <span>
                            Pairs with Shift Edit Requests so every correction is approved,
                            timed, and auditable.
                        </span>
                    </div>
                </div>

                <div style="margin-top:1rem;padding:.875rem 1rem;background:var(--c-amber-bg);border:1px solid var(--c-amber-bd);border-radius:var(--radius-md);font-size:12px;color:var(--c-amber);">
                    <i class="bi bi-lock me-1" aria-hidden="true"></i>
                    <strong>Admin password required</strong> to save any changes on this page.
                </div>
            </div>
        </div><!-- /.guide -->

    </div><!-- /.settings-layout -->
</main>

<?php require __DIR__ . '/../components/js_script.php'; ?>
</body>
</html>