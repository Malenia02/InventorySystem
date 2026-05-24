<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/CategoryController.php';
require_once __DIR__ . '/../controllers/SubcategoryController.php';
require_once __DIR__ . '/../controllers/SupplierController.php';

Middleware::auth()->role(['admin']);

$csrfToken = Middleware::generateCsrfToken();

try {
    $categories    = CategoryController::all($conn, 'active');
    $subcategories = SubcategoryController::all($conn, null, 'active');
    $suppliers     = SupplierController::all($conn);
} catch (Throwable $e) {
    error_log('[bulk_upload_products.php] ' . $e->getMessage());
    $categories    = [];
    $subcategories = [];
    $suppliers     = [];
    $dataError     = 'Failed to load categories or suppliers. Some dropdowns may be empty.';
}

// Build option HTML strings (keep ob_start pattern — it's fine here)
ob_start();
foreach ($categories as $cat): ?>
    <option value="<?= (int) ($cat['category_id'] ?? 0) ?>">
        <?= htmlspecialchars((string) ($cat['category_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
    </option>
<?php endforeach;
$categoryOptions = trim((string) ob_get_clean());

ob_start();
?>
<option value="">No subcategory</option>
<?php foreach ($subcategories as $sub): ?>
    <option
        value="<?= (int) ($sub['subcategory_id'] ?? 0) ?>"
        data-category-id="<?= (int) ($sub['category_id'] ?? 0) ?>">
        <?= htmlspecialchars((string) ($sub['subcategory_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
    </option>
<?php endforeach;
$subcategoryOptions = trim((string) ob_get_clean());

ob_start();
foreach ($suppliers as $sup): ?>
    <option value="<?= (int) ($sup['supplier_id'] ?? 0) ?>">
        <?= htmlspecialchars((string) ($sup['supplier_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
    </option>
<?php endforeach;
$supplierOptions = trim((string) ob_get_clean());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/../components/head.php'; ?>
    <title>Bulk Create Products</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        /* ── Design tokens (unified across all management pages) ─────── */
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

        /* ── Layout ─────────────────────────────────────────────────── */
        #main{padding:1.5rem 2rem 4rem}
        .pagetitle h1{font-size:22px;font-weight:600;letter-spacing:-.3px;margin-bottom:.25rem}
        .breadcrumb{display:flex;align-items:center;gap:6px;list-style:none;padding:0;margin:0 0 1.5rem;font-size:12px;color:var(--c-text-3)}
        .breadcrumb-item+.breadcrumb-item::before{content:'/';margin-right:6px;color:var(--c-border-2)}
        .breadcrumb-item a{color:var(--c-text-2);text-decoration:none}
        .breadcrumb-item a:hover{color:var(--c-accent)}
        .breadcrumb-item.active{color:var(--c-text-1)}

        /* ── Page wrapper ───────────────────────────────────────────── */
        .bulk-layout{display:grid;grid-template-columns:minmax(0,1fr) 280px;gap:1.5rem;align-items:start}
        @media(max-width:1100px){.bulk-layout{grid-template-columns:1fr}}

        /* ── Header card ────────────────────────────────────────────── */
        .page-header-card{
            background:var(--c-surface);border:1px solid var(--c-border);
            border-radius:var(--radius-xl);box-shadow:var(--shadow-sm);
            padding:1.5rem;margin-bottom:1.5rem;
            display:flex;align-items:flex-start;justify-content:space-between;
            flex-wrap:wrap;gap:1rem;
        }
        .page-header-eyebrow{font-size:11px;font-weight:700;letter-spacing:.08em;
            text-transform:uppercase;color:var(--c-accent);margin-bottom:4px}
        .page-header-title{font-size:20px;font-weight:600;letter-spacing:-.3px;
            color:var(--c-text-1);margin:0 0 4px}
        .page-header-sub{font-size:13px;color:var(--c-text-2);margin:0}

        /* ── Toolbar ────────────────────────────────────────────────── */
        .bulk-toolbar{
            background:var(--c-surface);border:1px solid var(--c-border);
            border-radius:var(--radius-lg);padding:.875rem 1.25rem;
            display:flex;align-items:center;justify-content:space-between;
            gap:.75rem;flex-wrap:wrap;margin-bottom:1.25rem;
        }
        .bulk-toolbar-left{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap}
        .bulk-card-count{
            font-size:12px;color:var(--c-text-3);
            background:var(--c-surface-2);border:1px solid var(--c-border);
            border-radius:99px;padding:3px 10px;font-family:var(--ff-mono);
        }
        .bulk-card-count span{font-weight:600;color:var(--c-accent)}

        /* ── Buttons ────────────────────────────────────────────────── */
        .btn{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 16px;
            border-radius:var(--radius-md);font-family:var(--ff-base);font-size:13px;
            font-weight:500;cursor:pointer;border:1px solid transparent;
            transition:all .15s;white-space:nowrap;text-decoration:none}
        .btn-primary{background:var(--c-accent);color:#fff;border-color:var(--c-accent)}
        .btn-primary:hover{background:#1d4ed8;border-color:#1d4ed8}
        .btn-success{background:var(--c-green);color:#fff;border-color:var(--c-green);height:40px;padding:0 20px;font-size:14px}
        .btn-success:hover{background:#15803d;border-color:#15803d}
        .btn-outline{background:var(--c-surface);color:var(--c-text-2);border-color:var(--c-border-2)}
        .btn-outline:hover{background:var(--c-surface-2);color:var(--c-text-1)}
        .btn-danger-soft{background:var(--c-red-bg);color:var(--c-red);border-color:var(--c-red-bd);height:30px;padding:0 10px;font-size:12px;border-radius:var(--radius-sm)}
        .btn-danger-soft:hover{background:var(--c-red);color:#fff}
        .btn:disabled{opacity:.55;cursor:not-allowed}

        /* ── Product cards ──────────────────────────────────────────── */
        .bulk-stack{display:flex;flex-direction:column;gap:1rem}

        .bulk-product-card{
            background:var(--c-surface);border:1px solid var(--c-border);
            border-radius:var(--radius-xl);box-shadow:var(--shadow-sm);
            overflow:hidden;transition:border-color .2s, box-shadow .2s;
        }
        .bulk-product-card.has-error{
            border-color:var(--c-red-bd);
            box-shadow:0 0 0 3px rgba(220,38,38,.08);
        }
        .bulk-product-card.is-saved{
            border-color:var(--c-green-bd);
            box-shadow:0 0 0 3px rgba(22,163,74,.08);
        }

        /* card header */
        .bulk-card-header{
            display:flex;align-items:center;justify-content:space-between;
            padding:.875rem 1.25rem;background:var(--c-surface-2);
            border-bottom:1px solid var(--c-border);gap:.75rem;flex-wrap:wrap;
        }
        .bulk-card-num{
            display:flex;align-items:center;gap:.6rem;
        }
        .bulk-card-num-badge{
            width:26px;height:26px;border-radius:50%;
            background:var(--c-accent-bg);color:var(--c-accent);
            font-size:12px;font-weight:700;
            display:flex;align-items:center;justify-content:center;
            flex-shrink:0;
        }
        .bulk-card-num-label{font-size:14px;font-weight:600;color:var(--c-text-1)}
        .bulk-card-num-sub{font-size:11px;color:var(--c-text-3)}

        /* error banner inside card */
        .bulk-card-error{
            display:none;align-items:flex-start;gap:8px;
            padding:.75rem 1.25rem;
            background:var(--c-red-bg);border-bottom:1px solid var(--c-red-bd);
            font-size:12px;color:var(--c-red);
        }
        .bulk-product-card.has-error .bulk-card-error{display:flex}

        /* card body */
        .bulk-card-body{padding:1.25rem}

        /* photo panel */
        .bulk-photo-panel{
            display:flex;flex-direction:column;align-items:center;gap:.75rem;
            background:var(--c-surface-2);border:1px solid var(--c-border);
            border-radius:var(--radius-lg);padding:1rem;height:100%;
        }
        .bulk-photo-preview{
            width:100%;max-width:180px;height:180px;
            object-fit:cover;border-radius:var(--radius-lg);
            border:1px solid var(--c-border);background:var(--c-surface);
        }
        .bulk-photo-label{font-size:11px;font-weight:600;text-transform:uppercase;
            letter-spacing:.04em;color:var(--c-text-3);margin-bottom:3px}

        /* form fields inside cards */
        .bulk-form-grid{display:grid;grid-template-columns:repeat(12,1fr);gap:.875rem}
        .bulk-col-4{grid-column:span 4}
        .bulk-col-6{grid-column:span 6}
        .bulk-col-12{grid-column:span 12}
        @media(max-width:900px){
            .bulk-col-4,.bulk-col-6{grid-column:span 6}
        }
        @media(max-width:600px){
            .bulk-col-4,.bulk-col-6{grid-column:span 12}
        }

        .form-label{
            font-size:11px;font-weight:600;text-transform:uppercase;
            letter-spacing:.04em;color:var(--c-text-3);margin-bottom:.3rem;
            display:block;
        }
        .form-control,.form-select{
            height:38px;padding:0 12px;
            background:var(--c-surface-2);border:1px solid var(--c-border);
            border-radius:var(--radius-md);font-family:var(--ff-base);
            font-size:13px;color:var(--c-text-1);
            transition:border-color .15s,box-shadow .15s;
            width:100%;
        }
        .form-control:focus,.form-select:focus{
            border-color:var(--c-accent);
            box-shadow:0 0 0 3px rgba(37,99,235,.1);outline:none;
        }
        .form-control::placeholder{color:var(--c-text-3)}
        .input-group{display:flex}
        .input-group .input-group-text{
            height:38px;padding:0 10px;
            background:var(--c-surface-2);border:1px solid var(--c-border);
            border-right:none;border-radius:var(--radius-md) 0 0 var(--radius-md);
            font-size:13px;color:var(--c-text-3);display:flex;align-items:center;
        }
        .input-group .form-control{border-radius:0 var(--radius-md) var(--radius-md) 0}
        .input-group .form-control:focus{border-color:var(--c-accent);box-shadow:none}

        /* beverage note */
        .bulk-beverage-note{
            display:none;padding:10px 12px;
            background:var(--c-amber-bg);border:1px solid var(--c-amber-bd);
            border-radius:var(--radius-md);font-size:12px;color:var(--c-amber);
        }
        .bulk-unit-note.show-note .bulk-beverage-note{display:block}

        /* section divider within card */
        .bulk-section-label{
            font-size:10px;font-weight:700;letter-spacing:.08em;
            text-transform:uppercase;color:var(--c-text-3);
            padding-bottom:6px;border-bottom:1px solid var(--c-border);
            margin-bottom:0;grid-column:span 12;
        }

        /* ── Submit row ─────────────────────────────────────────────── */
        .bulk-submit-row{
            background:var(--c-surface);border:1px solid var(--c-border);
            border-radius:var(--radius-lg);padding:1rem 1.25rem;
            display:flex;align-items:center;justify-content:space-between;
            gap:.75rem;flex-wrap:wrap;margin-top:1.25rem;
        }
        .bulk-submit-hint{font-size:12px;color:var(--c-text-3)}

        /* ── Results panel ──────────────────────────────────────────── */
        .bulk-results{margin-top:1.25rem}
        .result-banner{
            display:flex;align-items:flex-start;gap:.75rem;
            padding:1rem 1.25rem;border-radius:var(--radius-lg);
            margin-bottom:.75rem;font-size:13px;
        }
        .result-banner.success{background:var(--c-green-bg);border:1px solid var(--c-green-bd);color:var(--c-green)}
        .result-banner.warning{background:var(--c-amber-bg);border:1px solid var(--c-amber-bd);color:var(--c-amber)}
        .result-banner.danger {background:var(--c-red-bg);  border:1px solid var(--c-red-bd);  color:var(--c-red)}
        .result-banner i{font-size:16px;flex-shrink:0;margin-top:1px}
        .result-banner-body strong{display:block;font-weight:600;margin-bottom:2px}

        .error-table-wrap{
            background:var(--c-surface);border:1px solid var(--c-border);
            border-radius:var(--radius-lg);overflow:hidden;
        }
        .error-table-head{
            padding:.75rem 1rem;background:var(--c-surface-2);
            border-bottom:1px solid var(--c-border);
            font-size:12px;font-weight:600;color:var(--c-text-2);
        }
        table.error-table{width:100%;border-collapse:collapse}
        .error-table th{
            padding:8px 12px;font-size:11px;font-weight:600;
            text-transform:uppercase;letter-spacing:.04em;
            color:var(--c-text-3);background:var(--c-surface-2);
            text-align:left;border-bottom:1px solid var(--c-border);
        }
        .error-table td{
            padding:10px 12px;font-size:13px;vertical-align:top;
            border-bottom:1px solid var(--c-border);color:var(--c-text-2);
        }
        .error-table tbody tr:last-child td{border-bottom:none}
        .error-table .err-row-num{font-family:var(--ff-mono);color:var(--c-text-3)}
        .error-table .err-msg{color:var(--c-red)}

        /* ── Side panel ─────────────────────────────────────────────── */
        .side-stack{display:flex;flex-direction:column;gap:1rem;position:sticky;top:1.5rem}
        .side-card{
            background:var(--c-surface);border:1px solid var(--c-border);
            border-radius:var(--radius-lg);padding:1.1rem 1.25rem;
        }
        .side-card-title{font-size:13px;font-weight:600;color:var(--c-text-1);margin-bottom:.6rem}
        .side-card p{font-size:12px;color:var(--c-text-2);line-height:1.6;margin-bottom:.5rem}
        .side-card p:last-child{margin-bottom:0}
        .side-tip{display:flex;gap:8px;align-items:flex-start;margin-bottom:.5rem}
        .side-tip i{color:var(--c-accent);font-size:14px;flex-shrink:0;margin-top:1px}
        .side-tip p{margin:0;font-size:12px;color:var(--c-text-2);line-height:1.5}

        .empty-state{
            text-align:center;padding:3rem 1rem;
            background:var(--c-surface);border:2px dashed var(--c-border);
            border-radius:var(--radius-xl);color:var(--c-text-3);
        }
        .empty-state i{font-size:36px;display:block;margin-bottom:.75rem;opacity:.4}
        .empty-state p{font-size:14px;margin-bottom:1rem}

        @media(max-width:768px){#main{padding:1rem 1rem 3rem}}
    </style>
</head>
<body>
<?php
require __DIR__ . '/../components/header.php';
require __DIR__ . '/../components/sidebar.php';
?>

<main id="main" class="main">
    <div class="pagetitle">
        <h1>Bulk Create Products</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/inventory_system/index.php">Home</a></li>
                <li class="breadcrumb-item"><a href="/inventory_system/product_management/manage_product.php">Products</a></li>
                <li class="breadcrumb-item active">Bulk Create</li>
            </ol>
        </nav>
    </div>

    <?php if (!empty($dataError)): ?>
        <div class="alert alert-warning mb-3" style="border-radius:var(--radius-md);font-size:13px;">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($dataError, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <!-- ── Page header ──────────────────────────────────────────────── -->
    <div class="page-header-card">
        <div>
            <div class="page-header-eyebrow">Catalog Builder</div>
            <div class="page-header-title">Bulk Product Creator</div>
            <div class="page-header-sub">Add multiple product cards, fill each form, then submit them all in one action.</div>
        </div>
        <a href="/inventory_system/product_management/manage_product.php" class="btn btn-outline">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to products
        </a>
    </div>

    <!-- ── Two-column layout ─────────────────────────────────────────── -->
    <div class="bulk-layout">

        <!-- ── Left: form ────────────────────────────────────────────── -->
        <div>
            <!-- Toolbar -->
            <div class="bulk-toolbar">
                <div class="bulk-toolbar-left">
                    <span style="font-size:14px;font-weight:600;color:var(--c-text-1);">Product cards</span>
                    <span class="bulk-card-count"><span id="bulkCardCount">0</span> added</span>
                </div>
                <div style="display:flex;gap:.5rem;">
                    <button type="button" class="btn btn-outline" id="addBulkProductCardBtn">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> Add product card
                    </button>
                </div>
            </div>

            <!-- Form -->
            <form id="bulkUploadProductsForm" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                <!-- Cards render here -->
                <div id="bulkProductCards" class="bulk-stack">
                    <!-- Empty state shown when no cards -->
                    <div id="bulkEmptyState" class="empty-state">
                        <i class="bi bi-box-seam" aria-hidden="true"></i>
                        <p>No product cards yet. Click <strong>Add product card</strong> to get started.</p>
                        <button type="button" class="btn btn-primary" id="addBulkProductCardEmpty">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> Add first product
                        </button>
                    </div>
                </div>

                <!-- Submit row -->
                <div class="bulk-submit-row" id="bulkSubmitRow" style="display:none;">
                    <span class="bulk-submit-hint">
                        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                        If one product fails, the others will still be saved.
                    </span>
                    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                        <button type="button" class="btn btn-outline" id="addBulkProductCardBtnBottom">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> Add another
                        </button>
                        <button type="submit" class="btn btn-success" id="bulkUploadSubmitBtn">
                            <i class="bi bi-check2-circle" aria-hidden="true"></i> Save all products
                        </button>
                    </div>
                </div>
            </form>

            <!-- Results -->
            <div id="bulkUploadResults" class="bulk-results"></div>
        </div>

        <!-- ── Right: guide panel ────────────────────────────────────── -->
        <div class="side-stack">
            <div class="side-card">
                <div class="side-card-title">How this works</div>
                <div class="side-tip">
                    <i class="bi bi-1-circle-fill" aria-hidden="true"></i>
                    <p>Click <strong>Add product card</strong> for each product you want to create.</p>
                </div>
                <div class="side-tip">
                    <i class="bi bi-2-circle-fill" aria-hidden="true"></i>
                    <p>Fill in the details on each card. Photos are optional.</p>
                </div>
                <div class="side-tip">
                    <i class="bi bi-3-circle-fill" aria-hidden="true"></i>
                    <p>Hit <strong>Save all products</strong> to submit everything at once.</p>
                </div>
            </div>

            <div class="side-card">
                <div class="side-card-title">Tips</div>
                <div class="side-tip">
                    <i class="bi bi-tag" aria-hidden="true"></i>
                    <p>Use unique SKUs to avoid duplicate errors.</p>
                </div>
                <div class="side-tip">
                    <i class="bi bi-percent" aria-hidden="true"></i>
                    <p>Leave discount fields blank if the product is not on sale.</p>
                </div>
                <div class="side-tip">
                    <i class="bi bi-toggle-off" aria-hidden="true"></i>
                    <p>Status defaults to <strong>inactive</strong>. Switch to active when ready.</p>
                </div>
            </div>

            <div class="side-card">
                <div class="side-card-title">Piece · Box · Case guide</div>
                <p><strong>Piece</strong> — the smallest selling unit (one bottle, sachet, or pack).</p>
                <p><strong>Box</strong> — a grouped pack of pieces. Set <strong>Pieces per Box</strong> accordingly.</p>
                <p><strong>Case</strong> — a larger grouped pack. Add <strong>Boxes per Case</strong> and <strong>Case Price</strong>.</p>
                <p style="margin-top:.5rem;padding:.6rem .75rem;background:var(--c-amber-bg);border:1px solid var(--c-amber-bd);border-radius:var(--radius-sm);font-size:11px;color:var(--c-amber);margin-bottom:0;">
                    <i class="bi bi-lightbulb me-1" aria-hidden="true"></i>
                    For beverage categories, box selling fields are disabled automatically.
                </p>
            </div>
        </div>
    </div>
</main>


<!-- ══════════════════════════════════════════════════════════════════
     PRODUCT CARD TEMPLATE
     JS clones this via innerHTML with __INDEX__ → card index
══════════════════════════════════════════════════════════════════ -->
<template id="bulkProductCardTemplate">
    <div class="bulk-product-card" data-card-index="__INDEX__">

        <!-- Card header -->
        <div class="bulk-card-header">
            <div class="bulk-card-num">
                <div class="bulk-card-num-badge">__NUMBER__</div>
                <div>
                    <div class="bulk-card-num-label">Product __NUMBER__</div>
                    <div class="bulk-card-num-sub">Complete this form, then save all at the end.</div>
                </div>
            </div>
            <button type="button" class="btn btn-danger-soft remove-bulk-card-btn"
                    aria-label="Remove product __NUMBER__">
                <i class="bi bi-trash" aria-hidden="true"></i> Remove
            </button>
        </div>

        <!-- Error banner (hidden by default, shown when card has-error) -->
        <div class="bulk-card-error">
            <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
            <span class="bulk-card-error-msg">This product could not be saved. See details below.</span>
        </div>

        <!-- Card body -->
        <div class="bulk-card-body">
            <div style="display:grid;grid-template-columns:180px 1fr;gap:1.25rem;align-items:start;">

                <!-- Photo panel -->
                <div class="bulk-photo-panel">
                    <div class="bulk-photo-label">Product photo</div>
                    <img src="/inventory_system/assets/img/card.jpg"
                         alt="Preview"
                         class="bulk-photo-preview">
                    <input type="file"
                           class="form-control bulk-photo-input"
                           name="photo___INDEX__"
                           accept="image/jpeg,image/png,image/webp"
                           style="font-size:12px;">
                    <p style="font-size:11px;color:var(--c-text-3);text-align:center;margin:0;">
                        JPG, PNG, WEBP · Max 2 MB
                    </p>
                </div>

                <!-- Fields grid -->
                <div class="bulk-form-grid">

                    <!-- ─ Basic info ─ -->
                    <div class="bulk-section-label">Basic information</div>

                    <div class="bulk-col-6">
                        <label class="form-label">Product name <span style="color:var(--c-red);">*</span></label>
                        <input type="text" class="form-control"
                               name="products[__INDEX__][product_name]"
                               placeholder="e.g. Coca-Cola 1.5L" required autocomplete="off">
                    </div>
                    <div class="bulk-col-6">
                        <label class="form-label">SKU / Barcode</label>
                        <input type="text" class="form-control"
                               name="products[__INDEX__][sku]"
                               placeholder="Optional" autocomplete="off">
                    </div>
                    <div class="bulk-col-6">
                        <label class="form-label">Category <span style="color:var(--c-red);">*</span></label>
                        <select class="form-select bulk-category-select"
                                name="products[__INDEX__][category_id]" required>
                            <?= $categoryOptions ?>
                        </select>
                    </div>
                    <div class="bulk-col-6">
                        <label class="form-label">Subcategory</label>
                        <select class="form-select bulk-subcategory-select"
                                name="products[__INDEX__][subcategory_id]">
                            <?= $subcategoryOptions ?>
                        </select>
                    </div>
                    <div class="bulk-col-6">
                        <label class="form-label">Supplier</label>
                        <select class="form-select" name="products[__INDEX__][supplier_id]">
                            <option value="">No supplier</option>
                            <?= $supplierOptions ?>
                        </select>
                    </div>
                    <div class="bulk-col-6 bulk-unit-note" data-unit-note="beverage">
                        <div class="bulk-beverage-note">
                            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                            Beverage items use <strong>Piece</strong> and <strong>Case</strong>. Box fields are disabled.
                        </div>
                    </div>

                    <!-- ─ Pricing ─ -->
                    <div class="bulk-section-label">Pricing</div>

                    <div class="bulk-col-4">
                        <label class="form-label">Piece price <span style="color:var(--c-red);">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">₱</span>
                            <input type="number" class="form-control"
                                   name="products[__INDEX__][price]"
                                   step="0.01" min="0" required placeholder="0.00">
                        </div>
                    </div>
                    <div class="bulk-col-4 bulk-box-field">
                        <label class="form-label">Box price</label>
                        <div class="input-group">
                            <span class="input-group-text">₱</span>
                            <input type="number" class="form-control"
                                   name="products[__INDEX__][box_price]"
                                   step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                    <div class="bulk-col-4">
                        <label class="form-label">Case price</label>
                        <div class="input-group">
                            <span class="input-group-text">₱</span>
                            <input type="number" class="form-control"
                                   name="products[__INDEX__][case_price]"
                                   step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>

                    <!-- ─ Discounts ─ -->
                    <div class="bulk-section-label">Discounts</div>

                    <div class="bulk-col-4">
                        <label class="form-label">Piece discount %</label>
                        <div class="input-group">
                            <span class="input-group-text">%</span>
                            <input type="number" class="form-control"
                                   name="products[__INDEX__][sale_price]"
                                   step="0.01" min="0" max="100" placeholder="0">
                        </div>
                    </div>
                    <div class="bulk-col-4 bulk-box-field">
                        <label class="form-label">Box discount %</label>
                        <div class="input-group">
                            <span class="input-group-text">%</span>
                            <input type="number" class="form-control"
                                   name="products[__INDEX__][box_sale_price]"
                                   step="0.01" min="0" max="100" placeholder="0">
                        </div>
                    </div>
                    <div class="bulk-col-4">
                        <label class="form-label">Case discount %</label>
                        <div class="input-group">
                            <span class="input-group-text">%</span>
                            <input type="number" class="form-control"
                                   name="products[__INDEX__][case_sale_price]"
                                   step="0.01" min="0" max="100" placeholder="0">
                        </div>
                    </div>

                    <!-- ─ Units & inventory ─ -->
                    <div class="bulk-section-label">Units &amp; inventory</div>

                    <div class="bulk-col-4">
                        <label class="form-label">Pieces per box</label>
                        <input type="number" class="form-control"
                               name="products[__INDEX__][pieces_per_box]"
                               value="1" min="1" required>
                    </div>
                    <div class="bulk-col-4">
                        <label class="form-label">Boxes per case</label>
                        <input type="number" class="form-control"
                               name="products[__INDEX__][boxes_per_case]"
                               value="1" min="1" required>
                    </div>
                    <div class="bulk-col-4">
                        <label class="form-label">Initial qty (pcs)</label>
                        <input type="number" class="form-control"
                               name="products[__INDEX__][initial_quantity]"
                               value="0" min="0" required>
                    </div>
                    <div class="bulk-col-4">
                        <label class="form-label">Reorder level</label>
                        <input type="number" class="form-control"
                               name="products[__INDEX__][reorder_level]"
                               value="5" min="0" required>
                    </div>
                    <div class="bulk-col-4">
                        <label class="form-label">Vatable?</label>
                        <select class="form-select" name="products[__INDEX__][vatable]" required>
                            <option value="1">Yes — VAT</option>
                            <option value="0">No — Non-VAT</option>
                        </select>
                    </div>
                    <div class="bulk-col-4">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="products[__INDEX__][status]" required>
                            <option value="inactive">Inactive</option>
                            <option value="active">Active</option>
                        </select>
                    </div>
                </div><!-- /.bulk-form-grid -->
            </div>
        </div><!-- /.bulk-card-body -->
    </div><!-- /.bulk-product-card -->
</template>


<?php require __DIR__ . '/../components/js_script.php'; ?>
<script src="/inventory_system/assets/js/bulk_upload_products.js"></script>
</body>
</html>