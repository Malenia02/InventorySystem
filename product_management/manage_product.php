<?php
/**
 * manage_product.php — Admin product management
 * Redesigned to match the manage_staff design system.
 */

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/ProductController.php';
require_once __DIR__ . '/../controllers/CategoryController.php';
require_once __DIR__ . '/../controllers/SubcategoryController.php';
require_once __DIR__ . '/../controllers/SupplierController.php';
require_once __DIR__ . '/../controllers/StockAdjustmentController.php';

Middleware::auth()->role('admin');

$csrf_token = Middleware::generateCsrfToken();

try {
    $categories           = CategoryController::all($conn);
    $subcategories        = SubcategoryController::all($conn);
    $suppliers            = SupplierController::all($conn);
    $pendingStockRequests = StockAdjustmentController::pendingCount($conn);

    $productFilters = [
        'search'      => trim((string) ($_GET['search']      ?? '')),
        'status'      => strtolower(trim((string) ($_GET['status']      ?? 'all'))),
        'category_id' => (int) ($_GET['category_id'] ?? 0),
        'supplier_id' => (int) ($_GET['supplier_id'] ?? 0),
        'page'        => max(1, (int) ($_GET['page']     ?? 1)),
        'per_page'    => (int) ($_GET['per_page'] ?? 25),
    ];
    $productPage = ProductController::paginate($conn, $productFilters);
    $products    = $productPage['items'];
    $productFilters = [
        'search'      => (string) $productPage['search'],
        'status'      => (string) $productPage['status'],
        'category_id' => (int)    $productPage['category_id'],
        'supplier_id' => (int)    $productPage['supplier_id'],
        'page'        => (int)    $productPage['page'],
        'per_page'    => (int)    $productPage['per_page'],
    ];
    $productRowStart = $productPage['total'] > 0
        ? (($productPage['page'] - 1) * $productPage['per_page']) + 1
        : 0;

    // Summary counts
    $productSummary = ProductController::getSummary($conn);

} catch (Throwable $e) {
    error_log('[manage_product.php] ' . $e->getMessage());
    $products             = [];
    $pageError            = 'Failed to load product records.';
    $productPage          = ['total' => 0, 'page' => 1, 'per_page' => 25, 'total_pages' => 1];
    $productFilters       = ['search' => '', 'status' => 'all', 'category_id' => 0, 'supplier_id' => 0, 'page' => 1, 'per_page' => 25];
    $productRowStart      = 0;
    $productSummary       = ['total' => 0, 'active' => 0, 'inactive' => 0, 'low_stock' => 0];
    $categories           = [];
    $subcategories        = [];
    $suppliers            = [];
    $pendingStockRequests = 0;
}

function productListUrl(array $filters, array $overrides = []): string
{
    $params = array_merge($filters, $overrides);
    if (($params['page']        ?? 1)     <= 1)     unset($params['page']);
    if (($params['search']      ?? '')    === '')    unset($params['search']);
    if (($params['status']      ?? 'all') === 'all') unset($params['status']);
    if (($params['category_id'] ?? 0)     <= 0)      unset($params['category_id']);
    if (($params['supplier_id'] ?? 0)     <= 0)      unset($params['supplier_id']);
    if (($params['per_page']    ?? 25)    === 25)     unset($params['per_page']);
    $query = http_build_query($params);
    return '/inventory_system/product_management/manage_product.php' . ($query !== '' ? '?' . $query : '');
}

function renderSubcategoryOptions(array $subcategories): string
{
    $html = '<option value="">No subcategory</option>';
    foreach ($subcategories as $s) {
        $html .= sprintf(
            '<option value="%d" data-category-id="%d">%s</option>',
            (int) ($s['subcategory_id'] ?? 0),
            (int) ($s['category_id']    ?? 0),
            htmlspecialchars((string) ($s['subcategory_name'] ?? '-'), ENT_QUOTES, 'UTF-8')
        );
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/../components/head.php'; ?>
    <title>Manage Products</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        /* ── Design tokens (identical to manage_staff) ─────────── */
        :root {
            --ff-base: 'DM Sans', system-ui, sans-serif;
            --ff-mono: 'DM Mono', monospace;

            --c-bg:        #f5f4f1;
            --c-surface:   #ffffff;
            --c-surface-2: #f9f8f6;
            --c-border:    #e8e6e1;
            --c-border-2:  #d4d1cb;

            --c-text-1:    #1a1917;
            --c-text-2:    #5a5854;
            --c-text-3:    #9a9691;

            --c-accent:    #2563eb;
            --c-accent-bg: #eff4ff;
            --c-accent-bd: #bfcffd;

            --c-green:     #16a34a;
            --c-green-bg:  #f0fdf4;
            --c-green-bd:  #bbf7d0;

            --c-amber:     #b45309;
            --c-amber-bg:  #fffbeb;
            --c-amber-bd:  #fde68a;

            --c-red:       #dc2626;
            --c-red-bg:    #fef2f2;
            --c-red-bd:    #fecaca;

            --c-purple:    #7c3aed;
            --c-purple-bg: #f5f3ff;
            --c-purple-bd: #ddd6fe;

            --c-teal:      #0d9488;
            --c-teal-bg:   #f0fdfa;
            --c-teal-bd:   #99f6e4;

            --c-orange:    #ea580c;
            --c-orange-bg: #fff7ed;
            --c-orange-bd: #fed7aa;

            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
            --radius-xl: 20px;

            --shadow-sm: 0 1px 3px rgba(0,0,0,.07), 0 1px 2px rgba(0,0,0,.04);
            --shadow-md: 0 4px 12px rgba(0,0,0,.08), 0 2px 4px rgba(0,0,0,.04);
            --shadow-lg: 0 12px 32px rgba(0,0,0,.10), 0 4px 8px rgba(0,0,0,.05);
        }

        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: var(--ff-base);
            background: var(--c-bg);
            color: var(--c-text-1);
            font-size: 14px;
            line-height: 1.5;
        }

        /* ── Page layout ──────────────────────────────────────── */
        #main { padding: 1.5rem 2rem 3rem; }

        .pagetitle h1 {
            font-size: 22px;
            font-weight: 600;
            letter-spacing: -.3px;
            margin-bottom: .25rem;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 6px;
            list-style: none;
            padding: 0;
            margin: 0 0 1.5rem;
            font-size: 12px;
            color: var(--c-text-3);
        }
        .breadcrumb-item + .breadcrumb-item::before { content: '/'; margin-right: 6px; color: var(--c-border-2); }
        .breadcrumb-item a { color: var(--c-text-2); text-decoration: none; }
        .breadcrumb-item a:hover { color: var(--c-accent); }
        .breadcrumb-item.active { color: var(--c-text-1); }

        /* ── Stat cards ───────────────────────────────────────── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        @media (max-width: 900px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 520px) { .stats-grid { grid-template-columns: 1fr 1fr; } }

        .stat-card {
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            border-radius: var(--radius-lg);
            padding: 1.1rem 1.25rem;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            box-shadow: var(--shadow-sm);
        }
        .stat-icon {
            width: 40px; height: 40px;
            border-radius: var(--radius-md);
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; flex-shrink: 0;
        }
        .stat-body { flex: 1; min-width: 0; }
        .stat-label { font-size: 12px; color: var(--c-text-2); font-weight: 500; margin-bottom: 2px; }
        .stat-val   { font-size: 26px; font-weight: 600; letter-spacing: -.5px; line-height: 1.1; }

        /* ── Main card ────────────────────────────────────────── */
        .product-card {
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        .product-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.25rem 1.5rem 0;
            flex-wrap: wrap;
            gap: .75rem;
        }
        .product-card-title { font-size: 16px; font-weight: 600; color: var(--c-text-1); }

        /* ── Action buttons row ───────────────────────────────── */
        .action-row {
            display: flex;
            gap: .5rem;
            padding: .75rem 1.5rem 0;
            flex-wrap: wrap;
        }

        /* ── Filter bar ───────────────────────────────────────── */
        .filter-bar {
            display: flex;
            align-items: flex-end;
            gap: .75rem;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--c-border);
            flex-wrap: wrap;
        }
        .filter-item { display: flex; flex-direction: column; gap: 4px; }
        .filter-item.grow { flex: 1; min-width: 180px; }
        .filter-label { font-size: 11px; font-weight: 600; letter-spacing: .04em; color: var(--c-text-3); text-transform: uppercase; }

        .search-wrap {
            display: flex; align-items: center; gap: 8px;
            height: 36px; padding: 0 12px;
            background: var(--c-surface-2);
            border: 1px solid var(--c-border);
            border-radius: var(--radius-md);
            transition: border-color .15s, box-shadow .15s;
        }
        .search-wrap:focus-within { border-color: var(--c-accent); box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
        .search-wrap i { color: var(--c-text-3); font-size: 15px; flex-shrink: 0; }
        .search-wrap input {
            border: none; background: none; outline: none;
            font-family: var(--ff-base); font-size: 13px; color: var(--c-text-1); width: 100%;
        }
        .search-wrap input::placeholder { color: var(--c-text-3); }

        select.filter-select {
            height: 36px; padding: 0 32px 0 12px;
            background: var(--c-surface-2) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%239a9691'/%3E%3C/svg%3E") no-repeat right 10px center;
            border: 1px solid var(--c-border);
            border-radius: var(--radius-md);
            color: var(--c-text-1);
            font-family: var(--ff-base); font-size: 13px;
            appearance: none; -webkit-appearance: none; cursor: pointer;
            transition: border-color .15s, box-shadow .15s;
        }
        select.filter-select:focus { outline: none; border-color: var(--c-accent); box-shadow: 0 0 0 3px rgba(37,99,235,.1); }

        /* ── Buttons ──────────────────────────────────────────── */
        .btn {
            display: inline-flex; align-items: center; gap: 6px;
            height: 36px; padding: 0 16px;
            border-radius: var(--radius-md);
            font-family: var(--ff-base); font-size: 13px; font-weight: 500;
            cursor: pointer; border: 1px solid transparent;
            transition: all .15s; white-space: nowrap; text-decoration: none;
        }
        .btn-primary   { background: var(--c-accent);   color: #fff;           border-color: var(--c-accent); }
        .btn-primary:hover { background: #1d4ed8; border-color: #1d4ed8; }
        .btn-outline   { background: var(--c-surface);  color: var(--c-text-2); border-color: var(--c-border-2); }
        .btn-outline:hover { background: var(--c-surface-2); color: var(--c-text-1); }
        .btn-outline-green { background: var(--c-green-bg); color: var(--c-green); border-color: var(--c-green-bd); }
        .btn-outline-green:hover { background: #dcfce7; }
        .btn-sm { height: 30px; padding: 0 12px; font-size: 12px; border-radius: var(--radius-sm); }

        .btn-badge {
            position: relative;
        }
        .btn-badge .badge-count {
            position: absolute;
            top: -6px; right: -6px;
            background: var(--c-amber);
            color: #fff;
            font-size: 10px;
            font-weight: 600;
            min-width: 18px; height: 18px;
            border-radius: 99px;
            display: flex; align-items: center; justify-content: center;
            padding: 0 4px;
        }

        .btn-icon-sm {
            width: 30px; height: 30px; padding: 0;
            display: inline-flex; align-items: center; justify-content: center;
            border-radius: var(--radius-sm);
            font-size: 14px; cursor: pointer;
            border: 1px solid var(--c-border);
            background: var(--c-surface); color: var(--c-text-2);
            transition: all .15s;
        }
        .btn-icon-sm:hover                { background: var(--c-surface-2); color: var(--c-text-1); border-color: var(--c-border-2); }
        .btn-icon-sm.edit:hover           { background: var(--c-accent-bg);  color: var(--c-accent); border-color: var(--c-accent-bd); }
        .btn-icon-sm.restock:hover        { background: var(--c-green-bg);   color: var(--c-green);  border-color: var(--c-green-bd); }
        .btn-icon-sm.stockout:hover       { background: var(--c-amber-bg);   color: var(--c-amber);  border-color: var(--c-amber-bd); }
        .btn-icon-sm.deactivate:hover     { background: var(--c-red-bg);     color: var(--c-red);    border-color: var(--c-red-bd); }
        .btn-icon-sm.activate:hover       { background: var(--c-green-bg);   color: var(--c-green);  border-color: var(--c-green-bd); }

        /* ── Table ────────────────────────────────────────────── */
        .table-wrap { overflow-x: auto; }
        table.product-table { width: 100%; border-collapse: collapse; }
        .product-table thead tr { border-bottom: 1px solid var(--c-border); }
        .product-table th {
            padding: 10px 12px;
            font-size: 11px; font-weight: 600;
            text-transform: uppercase; letter-spacing: .05em;
            color: var(--c-text-3); text-align: left;
            white-space: nowrap;
            background: var(--c-surface-2);
        }
        .product-table tbody tr { border-bottom: 1px solid var(--c-border); transition: background .1s; }
        .product-table tbody tr:last-child { border-bottom: none; }
        .product-table tbody tr:hover { background: var(--c-surface-2); }
        .product-table td { padding: 10px 12px; font-size: 13px; vertical-align: middle; }
        .product-table td.num { color: var(--c-text-3); font-size: 12px; font-family: var(--ff-mono); }

        /* ── Product thumbnail ────────────────────────────────── */
        .product-thumb {
            width: 42px; height: 42px;
            border-radius: var(--radius-md);
            object-fit: cover;
            border: 1px solid var(--c-border);
            background: var(--c-surface-2);
            flex-shrink: 0;
        }
        .product-cell  { display: flex; align-items: center; gap: 10px; }
        .product-name  { font-weight: 500; font-size: 13px; line-height: 1.2; }
        .product-sku   { font-size: 11px; color: var(--c-text-3); font-family: var(--ff-mono); }

        /* ── Badges ───────────────────────────────────────────── */
        .badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 9px; border-radius: 99px;
            font-size: 11px; font-weight: 600; letter-spacing: .02em;
            border: 1px solid transparent;
        }
        .badge-active   { background: var(--c-green-bg);  color: var(--c-green);  border-color: var(--c-green-bd); }
        .badge-inactive { background: var(--c-surface-2); color: var(--c-text-3); border-color: var(--c-border); }
        .badge-low      { background: var(--c-amber-bg);  color: var(--c-amber);  border-color: var(--c-amber-bd); }
        .badge-vat      { background: var(--c-teal-bg);   color: var(--c-teal);   border-color: var(--c-teal-bd); }
        .badge-novat    { background: var(--c-surface-2); color: var(--c-text-3); border-color: var(--c-border); }

        /* ── Price display ────────────────────────────────────── */
        .price-primary { font-weight: 600; font-family: var(--ff-mono); font-size: 13px; }
        .price-meta    { font-size: 11px; color: var(--c-text-3); font-family: var(--ff-mono); }
        .discount-chip {
            display: inline-block;
            background: var(--c-red-bg);
            color: var(--c-red);
            border: 1px solid var(--c-red-bd);
            border-radius: 4px;
            font-size: 10px;
            font-weight: 700;
            padding: 1px 5px;
            font-family: var(--ff-mono);
        }

        /* ── Qty pill: low stock turns amber ──────────────────── */
        .qty-cell { font-family: var(--ff-mono); font-size: 13px; }
        .qty-low  { color: var(--c-amber); font-weight: 600; }

        /* ── Pagination ───────────────────────────────────────── */
        .pagination-bar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 1rem 1.5rem; border-top: 1px solid var(--c-border);
            flex-wrap: wrap; gap: .75rem;
        }
        .pag-info { font-size: 12px; color: var(--c-text-3); }
        .pagination { display: flex; list-style: none; margin: 0; padding: 0; gap: 3px; }
        .page-item .page-link {
            display: flex; align-items: center; justify-content: center;
            min-width: 30px; height: 30px; padding: 0 8px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--c-border);
            background: var(--c-surface); color: var(--c-text-2);
            font-size: 12px; text-decoration: none;
            transition: all .15s; font-family: var(--ff-mono);
        }
        .page-item .page-link:hover           { background: var(--c-surface-2); color: var(--c-text-1); }
        .page-item.active .page-link          { background: var(--c-accent); color: #fff; border-color: var(--c-accent); }
        .page-item.disabled .page-link        { opacity: .4; pointer-events: none; }

        /* ── Empty state ──────────────────────────────────────── */
        .empty-state { text-align: center; padding: 3.5rem 1rem; color: var(--c-text-3); }
        .empty-state i { font-size: 40px; display: block; margin-bottom: .75rem; opacity: .4; }
        .empty-state p { font-size: 14px; }

        /* ── Modals (same language as manage_staff) ───────────── */
        .modal-modern .modal-content {
            border: 1px solid var(--c-border);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-lg);
            font-family: var(--ff-base);
            overflow: hidden;
        }
        .modal-modern .modal-header {
            border-bottom: 1px solid var(--c-border);
            padding: 1.1rem 1.5rem;
            background: var(--c-surface-2);
        }
        .modal-modern .modal-title {
            font-size: 16px; font-weight: 600; letter-spacing: -.2px;
        }
        .modal-modern .modal-subtitle { font-size: 12px; color: var(--c-text-3); margin-top: 2px; }
        .modal-modern .modal-body  { padding: 1.5rem; }
        .modal-modern .modal-footer {
            border-top: 1px solid var(--c-border);
            padding: 1rem 1.5rem;
            background: var(--c-surface-2);
        }
        .modal-modern .form-label {
            font-size: 11px; font-weight: 600;
            text-transform: uppercase; letter-spacing: .05em;
            color: var(--c-text-3); margin-bottom: .3rem; display: block;
        }
        .modal-modern .form-control,
        .modal-modern .form-select {
            height: 38px; padding: 0 12px;
            background: var(--c-surface-2);
            border: 1px solid var(--c-border);
            border-radius: var(--radius-md);
            font-family: var(--ff-base); font-size: 13px; color: var(--c-text-1);
            transition: border-color .15s, box-shadow .15s;
        }
        .modal-modern textarea.form-control { height: auto; padding: 10px 12px; }
        .modal-modern .form-control:focus,
        .modal-modern .form-select:focus {
            border-color: var(--c-accent);
            box-shadow: 0 0 0 3px rgba(37,99,235,.1);
            outline: none;
        }
        .modal-modern .form-control::placeholder { color: var(--c-text-3); }
        .modal-modern .input-group-text {
            background: var(--c-surface-2); border: 1px solid var(--c-border);
            border-radius: var(--radius-md); color: var(--c-text-3);
            font-size: 13px; padding: 0 10px;
        }
        .modal-modern .input-group > .form-control { border-radius: 0 var(--radius-md) var(--radius-md) 0 !important; }
        .modal-modern .input-group > .input-group-text:first-child { border-radius: var(--radius-md) 0 0 var(--radius-md) !important; border-right: 0; }
        .modal-modern .input-group > .btn { border-radius: 0 var(--radius-md) var(--radius-md) 0 !important; height: 38px; }

        .modal-section-title {
            font-size: 11px; font-weight: 600;
            text-transform: uppercase; letter-spacing: .07em;
            color: var(--c-text-3);
            border-bottom: 1px solid var(--c-border);
            padding-bottom: .5rem; margin-bottom: .75rem;
        }

        .photo-panel {
            background: var(--c-surface-2);
            border: 1px solid var(--c-border);
            border-radius: var(--radius-lg);
            padding: 1.25rem 1rem;
            display: flex; flex-direction: column;
            align-items: center; gap: .75rem;
            height: 100%;
        }
        .preview-image {
            width: 140px; height: 140px;
            border-radius: var(--radius-lg);
            object-fit: cover;
            border: 1px solid var(--c-border);
            box-shadow: var(--shadow-sm);
        }

        /* required asterisk */
        .req { color: var(--c-red); }

        /* unit note alert */
        .unit-note {
            background: var(--c-amber-bg);
            border: 1px solid var(--c-amber-bd);
            border-radius: var(--radius-md);
            color: var(--c-amber);
            font-size: 12px;
            padding: .5rem .75rem;
        }

        @media (max-width: 768px) {
            #main { padding: 1rem; }
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-item.grow { width: 100%; }
        }
    </style>
</head>
<body>

<?php
require __DIR__ . '/../components/header.php';
require __DIR__ . '/../components/sidebar.php';
?>

<main id="main" class="main">
    <div class="pagetitle">
        <h1>Product Management</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/inventory_system/index.php">Home</a></li>
                <li class="breadcrumb-item active">Product Management</li>
            </ol>
        </nav>
    </div>

    <?php if (!empty($pageError)): ?>
        <div class="alert alert-danger mb-3" style="border-radius:var(--radius-md);font-size:13px;">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?= htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div id="productMessages"></div>

    <!-- ── Stat cards ──────────────────────────────────────────── -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--c-accent-bg);color:var(--c-accent);">
                <i class="bi bi-box-seam-fill" aria-hidden="true"></i>
            </div>
            <div class="stat-body">
                <div class="stat-label">Total products</div>
                <div class="stat-val" id="statTotal" style="color:var(--c-accent);"><?= number_format((int)($productSummary['total'] ?? 0)) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--c-green-bg);color:var(--c-green);">
                <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
            </div>
            <div class="stat-body">
                <div class="stat-label">Active</div>
                <div class="stat-val" id="statActive" style="color:var(--c-green);"><?= number_format((int)($productSummary['active'] ?? 0)) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--c-amber-bg);color:var(--c-amber);">
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            </div>
            <div class="stat-body">
                <div class="stat-label">Low stock</div>
                <div class="stat-val" id="statLowStock" style="color:var(--c-amber);"><?= number_format((int)($productSummary['low_stock'] ?? 0)) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--c-red-bg);color:var(--c-red);">
                <i class="bi bi-slash-circle-fill" aria-hidden="true"></i>
            </div>
            <div class="stat-body">
                <div class="stat-label">Inactive</div>
                <div class="stat-val" id="statInactive" style="color:var(--c-red);"><?= number_format((int)($productSummary['inactive'] ?? 0)) ?></div>
            </div>
        </div>
    </div>

    <!-- ── Main card ───────────────────────────────────────────── -->
    <div class="product-card">
        <div class="product-card-head">
            <span class="product-card-title">Product list</span>
        </div>

        <!-- Action buttons -->
        <div class="action-row">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
                <i class="bi bi-plus-lg"></i> Add product
            </button>
            <a href="/inventory_system/stock_adjustment_requests.php" class="btn btn-outline btn-badge">
                <i class="bi bi-clipboard-check"></i> Stock requests
                <?php if ($pendingStockRequests > 0): ?>
                    <span class="badge-count"><?= (int) $pendingStockRequests ?></span>
                <?php endif; ?>
            </a>
            <a href="/inventory_system/product_management/bulk_upload_products.php" class="btn btn-outline">
                <i class="bi bi-upload"></i> Bulk create
            </a>
        </div>

        <!-- Filter bar -->
        <form method="get" class="filter-bar">
            <div class="filter-item grow">
                <span class="filter-label">Search</span>
                <div class="search-wrap">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search"
                        value="<?= htmlspecialchars($productFilters['search'], ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="Product name, SKU, category, supplier…"
                        autocomplete="off">
                </div>
            </div>
            <div class="filter-item">
                <span class="filter-label">Status</span>
                <select name="status" class="filter-select">
                    <option value="all"      <?= $productFilters['status'] === 'all'      ? 'selected' : '' ?>>All status</option>
                    <option value="active"   <?= $productFilters['status'] === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $productFilters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="filter-item">
                <span class="filter-label">Category</span>
                <select name="category_id" class="filter-select">
                    <option value="0">All categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int) $cat['category_id'] ?>" <?= $productFilters['category_id'] === (int) $cat['category_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) $cat['category_name'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-item">
                <span class="filter-label">Supplier</span>
                <select name="supplier_id" class="filter-select">
                    <option value="0">All suppliers</option>
                    <?php foreach ($suppliers as $sup): ?>
                        <option value="<?= (int) $sup['supplier_id'] ?>" <?= $productFilters['supplier_id'] === (int) $sup['supplier_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) $sup['supplier_name'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-item">
                <span class="filter-label">Per page</span>
                <select name="per_page" class="filter-select">
                    <?php foreach ([10, 25, 50, 100] as $size): ?>
                        <option value="<?= $size ?>" <?= $productFilters['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-item">
                <span class="filter-label">&nbsp;</span>
                <button type="submit" class="btn btn-primary">Apply</button>
            </div>
        </form>

        <!-- Table -->
        <div class="table-wrap">
            <table class="product-table" id="productsTable">
                <thead>
                    <tr>
                        <th style="width:46px;">#</th>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Supplier</th>
                        <th>Qty</th>
                        <th>Piece price</th>
                        <th>Box / case</th>
                        <th>Discounts</th>
                        <th>VAT</th>
                        <th>Reorder</th>
                        <th>Status</th>
                        <th style="width:130px;text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($products)): ?>
                        <?php foreach ($products as $index => $p):
                            $productId = (int) ($p['product_id'] ?? 0);
                            $status    = $p['status'] ?? 'inactive';
                            $isActive  = $status === 'active';
                            $photo     = !empty($p['photo']) ? $p['photo'] : '/inventory_system/assets/img/card.jpg';
                            $qty       = (int) ($p['quantity']     ?? 0);
                            $reorder   = (int) ($p['reorder_level'] ?? 5);
                            $isLow     = $qty <= $reorder && $qty >= 0;

                            $discountParts = [];
                            if (!empty($p['sale_price']))      $discountParts[] = 'Pc: '   . number_format((float)$p['sale_price'],      2) . '%';
                            if (!empty($p['box_sale_price']))  $discountParts[] = 'Box: '  . number_format((float)$p['box_sale_price'],  2) . '%';
                            if (!empty($p['case_sale_price'])) $discountParts[] = 'Case: ' . number_format((float)$p['case_sale_price'], 2) . '%';

                            $vatable = !empty($p['vatable']);
                        ?>
                            <tr id="productRow<?= $productId ?>">
                                <td class="num"><?= $productRowStart + $index ?></td>

                                <!-- Product: thumb + name + SKU -->
                                <td>
                                    <div class="product-cell">
                                        <img src="<?= htmlspecialchars($photo, ENT_QUOTES, 'UTF-8') ?>"
                                             alt="<?= htmlspecialchars($p['product_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                             class="product-thumb"
                                             onerror="this.src='/inventory_system/assets/img/card.jpg';this.onerror=null;">
                                        <div>
                                            <div class="product-name"><?= htmlspecialchars($p['product_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></div>
                                            <?php if (!empty($p['sku'])): ?>
                                                <div class="product-sku"><?= htmlspecialchars($p['sku'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>

                                <!-- Category / subcategory -->
                                <td>
                                    <div style="font-size:13px;"><?= htmlspecialchars($p['category_name']    ?? '-', ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php if (!empty($p['subcategory_name'])): ?>
                                        <div style="font-size:11px;color:var(--c-text-3);"><?= htmlspecialchars($p['subcategory_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </td>

                                <!-- Supplier -->
                                <td style="color:var(--c-text-2);font-size:13px;"><?= htmlspecialchars($p['supplier_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>

                                <!-- Quantity -->
                                <td class="qty-cell <?= $isLow ? 'qty-low' : '' ?>">
                                    <?= number_format($qty) ?>
                                    <?php if ($isLow): ?>
                                        <span class="badge badge-low ms-1" style="font-size:10px;">Low</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Piece price -->
                                <td><span class="price-primary">₱<?= number_format((float)($p['price'] ?? 0), 2) ?></span></td>

                                <!-- Box / case price -->
                                <td>
                                    <?php if (!empty($p['box_price'])): ?>
                                        <div class="price-meta">Box ₱<?= number_format((float)$p['box_price'], 2) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($p['case_price'])): ?>
                                        <div class="price-meta">Case ₱<?= number_format((float)$p['case_price'], 2) ?></div>
                                    <?php endif; ?>
                                    <?php if (empty($p['box_price']) && empty($p['case_price'])): ?>
                                        <span style="color:var(--c-text-3);">—</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Discounts -->
                                <td>
                                    <?php if ($discountParts !== []): ?>
                                        <div style="display:flex;flex-direction:column;gap:2px;">
                                            <?php foreach ($discountParts as $dp): ?>
                                                <span class="discount-chip"><?= htmlspecialchars($dp, ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:var(--c-text-3);">—</span>
                                    <?php endif; ?>
                                </td>

                                <!-- VAT -->
                                <td>
                                    <span class="badge <?= $vatable ? 'badge-vat' : 'badge-novat' ?>">
                                        <?= $vatable ? 'VAT' : 'No VAT' ?>
                                    </span>
                                </td>

                                <!-- Reorder level -->
                                <td style="font-family:var(--ff-mono);font-size:12px;color:var(--c-text-3);"><?= $reorder ?></td>

                                <!-- Status -->
                                <td>
                                    <span class="badge <?= $isActive ? 'badge-active' : 'badge-inactive' ?>" id="productStatus<?= $productId ?>">
                                        <i class="bi <?= $isActive ? 'bi-circle-fill' : 'bi-circle' ?>" style="font-size:7px;"></i>
                                        <?= ucfirst($status) ?>
                                    </span>
                                </td>

                                <!-- Actions -->
                                <td>
                                    <div style="display:flex;gap:5px;justify-content:center;">
                                        <button type="button"
                                            class="btn-icon-sm edit editProductBtn"
                                            title="Edit product"
                                            aria-label="Edit <?= htmlspecialchars($p['product_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-id="<?= $productId ?>"
                                            data-name="<?= htmlspecialchars($p['product_name']   ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-category="<?= (int)($p['category_id']   ?? 0) ?>"
                                            data-subcategory="<?= (int)($p['subcategory_id'] ?? 0) ?>"
                                            data-supplier="<?= (int)($p['supplier_id']   ?? 0) ?>"
                                            data-sku="<?= htmlspecialchars($p['sku']            ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-price="<?= (float)($p['price']          ?? 0) ?>"
                                            data-box_price="<?= htmlspecialchars((string)($p['box_price']       ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            data-case_price="<?= htmlspecialchars((string)($p['case_price']      ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            data-sale_price="<?= htmlspecialchars((string)($p['sale_price']      ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            data-box_sale_price="<?= htmlspecialchars((string)($p['box_sale_price']  ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            data-case_sale_price="<?= htmlspecialchars((string)($p['case_sale_price'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            data-vatable="<?= (int)($p['vatable']        ?? 0) ?>"
                                            data-pieces_per_box="<?= (int)($p['pieces_per_box'] ?? 1) ?>"
                                            data-boxes_per_case="<?= (int)($p['boxes_per_case'] ?? 1) ?>"
                                            data-reorder="<?= $reorder ?>"
                                            data-photo="<?= htmlspecialchars($photo, ENT_QUOTES, 'UTF-8') ?>"
                                            data-bs-toggle="modal" data-bs-target="#editProductModal">
                                            <i class="bi bi-pencil" aria-hidden="true"></i>
                                        </button>

                                        <button type="button"
                                            class="btn-icon-sm restock restock-btn"
                                            title="Restock"
                                            aria-label="Restock <?= htmlspecialchars($p['product_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-id="<?= $productId ?>"
                                            data-name="<?= htmlspecialchars($p['product_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i>
                                        </button>

                                        <button type="button"
                                            class="btn-icon-sm stockout stockout-btn"
                                            title="Stock out"
                                            aria-label="Stock out <?= htmlspecialchars($p['product_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-id="<?= $productId ?>"
                                            data-name="<?= htmlspecialchars($p['product_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="bi bi-box-arrow-up" aria-hidden="true"></i>
                                        </button>

                                        <button type="button"
                                            class="btn-icon-sm <?= $isActive ? 'deactivate' : 'activate' ?> toggleProductStatusBtn"
                                            title="<?= $isActive ? 'Deactivate' : 'Reactivate' ?>"
                                            aria-label="<?= $isActive ? 'Deactivate' : 'Reactivate' ?> <?= htmlspecialchars($p['product_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-id="<?= $productId ?>"
                                            data-name="<?= htmlspecialchars($p['product_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-status="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="bi <?= $isActive ? 'bi-slash-circle' : 'bi-check-circle' ?>" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="12">
                                <div class="empty-state">
                                    <i class="bi bi-box-seam"></i>
                                    <p>No products found matching your filters.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="pagination-bar">
            <span class="pag-info">
                <?php if ($productPage['total'] > 0): ?>
                    Showing <?= number_format($productRowStart) ?>–<?= number_format(min($productRowStart + count($products) - 1, $productPage['total'])) ?> of <?= number_format($productPage['total']) ?> products
                <?php else: ?>
                    No results
                <?php endif; ?>
            </span>
            <nav aria-label="Product pagination">
                <ul class="pagination">
                    <li class="page-item <?= $productPage['page'] <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(productListUrl($productFilters, ['page' => $productPage['page'] - 1]), ENT_QUOTES, 'UTF-8') ?>" aria-label="Previous">
                            <i class="bi bi-chevron-left" style="font-size:11px;"></i>
                        </a>
                    </li>
                    <?php
                    $pStart = max(1, $productPage['page'] - 2);
                    $pEnd   = min($productPage['total_pages'], $productPage['page'] + 2);
                    for ($pn = $pStart; $pn <= $pEnd; $pn++):
                    ?>
                        <li class="page-item <?= $pn === $productPage['page'] ? 'active' : '' ?>">
                            <a class="page-link" href="<?= htmlspecialchars(productListUrl($productFilters, ['page' => $pn]), ENT_QUOTES, 'UTF-8') ?>"><?= $pn ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $productPage['page'] >= $productPage['total_pages'] ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(productListUrl($productFilters, ['page' => $productPage['page'] + 1]), ENT_QUOTES, 'UTF-8') ?>" aria-label="Next">
                            <i class="bi bi-chevron-right" style="font-size:11px;"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
    </div><!-- /.product-card -->


    <!-- ════════════════════════════════════════════════════════════
         ADD PRODUCT MODAL
    ═════════════════════════════════════════════════════════════ -->
    <div class="modal fade modal-modern" id="addProductModal" tabindex="-1" aria-labelledby="addProductModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <form id="addProductForm" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="modal-header">
                        <div>
                            <div class="modal-title" id="addProductModalLabel">
                                <i class="bi bi-plus-lg me-2" style="color:var(--c-accent);"></i>Add new product
                            </div>
                            <div class="modal-subtitle">Create a new product record and set its initial stock.</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-4">
                            <!-- Photo -->
                            <div class="col-lg-3">
                                <div class="photo-panel">
                                    <img id="addProductPhotoPreview" src="/inventory_system/assets/img/card.jpg" alt="Preview" class="preview-image">
                                    <div style="width:100%">
                                        <label class="form-label">Photo (optional)</label>
                                        <input type="file" class="form-control" name="photo" accept="image/*">
                                    </div>
                                </div>
                            </div>

                            <!-- Fields -->
                            <div class="col-lg-9">
                                <div class="modal-section-title">Basic information</div>
                                <div class="row g-3">
                                    <div class="col-md-8">
                                        <label class="form-label">Product name <span class="req">*</span></label>
                                        <input type="text" class="form-control" name="product_name" required placeholder="e.g. Coca-Cola 1.5L">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">SKU / Barcode</label>
                                        <input type="text" class="form-control" name="sku" placeholder="Optional">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Category <span class="req">*</span></label>
                                        <select class="form-select" name="category_id" id="addProductCategory" required>
                                            <?php foreach ($categories as $cat): ?>
                                                <option value="<?= (int) $cat['category_id'] ?>"><?= htmlspecialchars((string) $cat['category_name'], ENT_QUOTES, 'UTF-8') ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Subcategory</label>
                                        <select class="form-select" name="subcategory_id" id="addProductSubcategory">
                                            <?= renderSubcategoryOptions($subcategories) ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Supplier <span class="req">*</span></label>
                                        <div class="input-group">
                                            <select class="form-select" name="supplier_id" id="addProductSupplierSelect" required>
                                                <?php foreach ($suppliers as $sup): ?>
                                                    <option value="<?= (int) $sup['supplier_id'] ?>"><?= htmlspecialchars((string) $sup['supplier_name'], ENT_QUOTES, 'UTF-8') ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="btn btn-outline"
                                                data-bs-toggle="modal" data-bs-target="#supplierModal"
                                                data-target-select="addProductSupplierSelect" style="height:38px;border-radius:0 var(--radius-md) var(--radius-md) 0 !important;">
                                                + Add
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="modal-section-title mt-4">Pricing &amp; stock</div>
                                <div class="row g-3">
                                    <div class="col-12 product-unit-note d-none" data-unit-note="beverage">
                                        <div class="unit-note">
                                            <i class="bi bi-info-circle me-1"></i>
                                            Beverage items use <strong>Piece</strong> and <strong>Case</strong> in POS. Box fields are disabled for this category.
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Piece price <span class="req">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text">₱</span>
                                            <input type="number" class="form-control" name="price" step="0.01" min="0" required placeholder="0.00">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Pieces per box <span class="req">*</span></label>
                                        <input type="number" class="form-control" name="pieces_per_box" value="1" min="1" required>
                                    </div>
                                    <div class="col-md-4 product-box-field">
                                        <label class="form-label">Box price</label>
                                        <div class="input-group">
                                            <span class="input-group-text">₱</span>
                                            <input type="number" class="form-control" name="box_price" step="0.01" min="0" placeholder="Optional">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Boxes per case <span class="req">*</span></label>
                                        <input type="number" class="form-control" name="boxes_per_case" value="1" min="1" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Case price</label>
                                        <div class="input-group">
                                            <span class="input-group-text">₱</span>
                                            <input type="number" class="form-control" name="case_price" step="0.01" min="0" placeholder="Optional">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Piece discount %</label>
                                        <div class="input-group">
                                            <span class="input-group-text">%</span>
                                            <input type="number" class="form-control" name="sale_price" step="0.01" min="0" max="100" placeholder="0">
                                        </div>
                                    </div>
                                    <div class="col-md-4 product-box-field">
                                        <label class="form-label">Box discount %</label>
                                        <div class="input-group">
                                            <span class="input-group-text">%</span>
                                            <input type="number" class="form-control" name="box_sale_price" step="0.01" min="0" max="100" placeholder="0">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Case discount %</label>
                                        <div class="input-group">
                                            <span class="input-group-text">%</span>
                                            <input type="number" class="form-control" name="case_sale_price" step="0.01" min="0" max="100" placeholder="0">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Initial quantity (pieces) <span class="req">*</span></label>
                                        <input type="number" class="form-control" name="initial_quantity" value="0" min="0" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Reorder level</label>
                                        <input type="number" class="form-control" name="reorder_level" value="5" min="0">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Vatable? <span class="req">*</span></label>
                                        <select class="form-select" name="vatable" required>
                                            <option value="1">Yes</option>
                                            <option value="0">No</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer justify-content-end gap-2">
                        <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-lg"></i> Add product
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════
         EDIT PRODUCT MODAL
    ═════════════════════════════════════════════════════════════ -->
    <div class="modal fade modal-modern" id="editProductModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <form id="editProductForm" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="product_id" id="editProductId">

                    <div class="modal-header">
                        <div>
                            <div class="modal-title">
                                <i class="bi bi-pencil-square me-2" style="color:var(--c-amber);"></i>Edit product
                            </div>
                            <div class="modal-subtitle">Update product details, pricing, and supplier.</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-4">
                            <div class="col-lg-3">
                                <div class="photo-panel">
                                    <img id="editProductPhotoPreview" src="/inventory_system/assets/img/card.jpg" alt="Preview" class="preview-image">
                                    <div style="width:100%">
                                        <label class="form-label">New photo</label>
                                        <input type="file" class="form-control" name="photo" accept="image/*">
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-9">
                                <div class="modal-section-title">Basic information</div>
                                <div class="row g-3">
                                    <div class="col-md-8">
                                        <label class="form-label">Product name <span class="req">*</span></label>
                                        <input type="text" class="form-control" name="product_name" id="editProductName" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">SKU / Barcode</label>
                                        <input type="text" class="form-control" name="sku" id="editProductSku">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Category <span class="req">*</span></label>
                                        <select class="form-select" name="category_id" id="editProductCategory" required>
                                            <?php foreach ($categories as $cat): ?>
                                                <option value="<?= (int) $cat['category_id'] ?>"><?= htmlspecialchars((string) $cat['category_name'], ENT_QUOTES, 'UTF-8') ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Subcategory</label>
                                        <select class="form-select" name="subcategory_id" id="editProductSubcategory">
                                            <?= renderSubcategoryOptions($subcategories) ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Supplier <span class="req">*</span></label>
                                        <div class="input-group">
                                            <select class="form-select" name="supplier_id" id="editProductSupplierSelect" required>
                                                <?php foreach ($suppliers as $sup): ?>
                                                    <option value="<?= (int) $sup['supplier_id'] ?>"><?= htmlspecialchars((string) $sup['supplier_name'], ENT_QUOTES, 'UTF-8') ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="btn btn-outline"
                                                data-bs-toggle="modal" data-bs-target="#supplierModal"
                                                data-target-select="editProductSupplierSelect" style="height:38px;border-radius:0 var(--radius-md) var(--radius-md) 0 !important;">
                                                + Add
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="modal-section-title mt-4">Pricing &amp; stock settings</div>
                                <div class="row g-3">
                                    <div class="col-12 product-unit-note d-none" data-unit-note="beverage">
                                        <div class="unit-note">
                                            <i class="bi bi-info-circle me-1"></i>
                                            Beverage items use <strong>Piece</strong> and <strong>Case</strong> in POS. Box fields are disabled.
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Piece price <span class="req">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text">₱</span>
                                            <input type="number" class="form-control" name="price" id="editProductPrice" step="0.01" min="0" required>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Pieces per box <span class="req">*</span></label>
                                        <input type="number" class="form-control" name="pieces_per_box" id="editProductPiecesPerBox" min="1" required>
                                    </div>
                                    <div class="col-md-4 product-box-field">
                                        <label class="form-label">Box price</label>
                                        <div class="input-group">
                                            <span class="input-group-text">₱</span>
                                            <input type="number" class="form-control" name="box_price" id="editProductBoxPrice" step="0.01" min="0">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Boxes per case <span class="req">*</span></label>
                                        <input type="number" class="form-control" name="boxes_per_case" id="editProductBoxesPerCase" min="1" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Case price</label>
                                        <div class="input-group">
                                            <span class="input-group-text">₱</span>
                                            <input type="number" class="form-control" name="case_price" id="editProductCasePrice" step="0.01" min="0">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Piece discount %</label>
                                        <div class="input-group">
                                            <span class="input-group-text">%</span>
                                            <input type="number" class="form-control" name="sale_price" id="editProductSalePrice" step="0.01" min="0" max="100">
                                        </div>
                                    </div>
                                    <div class="col-md-4 product-box-field">
                                        <label class="form-label">Box discount %</label>
                                        <div class="input-group">
                                            <span class="input-group-text">%</span>
                                            <input type="number" class="form-control" name="box_sale_price" id="editProductBoxSalePrice" step="0.01" min="0" max="100">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Case discount %</label>
                                        <div class="input-group">
                                            <span class="input-group-text">%</span>
                                            <input type="number" class="form-control" name="case_sale_price" id="editProductCaseSalePrice" step="0.01" min="0" max="100">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Reorder level</label>
                                        <input type="number" class="form-control" name="reorder_level" id="editProductReorderLevel" min="0" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Vatable? <span class="req">*</span></label>
                                        <select class="form-select" name="vatable" id="editProductVatable" required>
                                            <option value="1">Yes</option>
                                            <option value="0">No</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Status</label>
                                        <div class="form-control" style="background:var(--c-surface-2);color:var(--c-text-3);font-size:12px;cursor:default;">
                                            Managed via status button
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer justify-content-end gap-2">
                        <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-floppy"></i> Save changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════
         RESTOCK MODAL
    ═════════════════════════════════════════════════════════════ -->
    <div class="modal fade modal-modern" id="restockModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form id="restockForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" id="restockProductId" name="product_id">

                    <div class="modal-header">
                        <div>
                            <div class="modal-title">
                                <i class="bi bi-box-arrow-in-down me-2" style="color:var(--c-green);"></i>Restock product
                            </div>
                            <div class="modal-subtitle">Add inventory to an existing active product.</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Product</label>
                                <input type="text" id="restockProductName" class="form-control" readonly style="background:var(--c-surface-2);color:var(--c-text-2);">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Quantity to add <span class="req">*</span></label>
                                <input type="number" name="quantity" class="form-control" required min="1" placeholder="Enter quantity">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Adjustment type <span class="req">*</span></label>
                                <select name="adjustment_type" class="form-select" required>
                                    <option value="delivery_received">Delivery received</option>
                                    <option value="manual_restock">Manual restock</option>
                                    <option value="count_correction">Count correction</option>
                                    <option value="customer_return">Customer return</option>
                                    <option value="purchase_receive">Purchase order receipt</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Supplier (optional)</label>
                                <select name="supplier_id" class="form-select">
                                    <option value="">No supplier link</option>
                                    <?php foreach ($suppliers as $sup): ?>
                                        <option value="<?= (int) ($sup['supplier_id'] ?? 0) ?>">
                                            <?= htmlspecialchars((string) ($sup['supplier_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Notes (optional)</label>
                                <textarea name="notes" class="form-control" rows="3" maxlength="500"
                                    placeholder="e.g. Delivery received from supplier, emergency refill…"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer justify-content-end gap-2">
                        <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" style="background:var(--c-green);border-color:var(--c-green);">
                            <i class="bi bi-plus-lg"></i> Confirm restock
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════
         STOCK OUT MODAL
    ═════════════════════════════════════════════════════════════ -->
    <div class="modal fade modal-modern" id="stockOutModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form id="stockOutForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" id="stockOutProductId" name="product_id">

                    <div class="modal-header">
                        <div>
                            <div class="modal-title">
                                <i class="bi bi-box-arrow-up me-2" style="color:var(--c-amber);"></i>Stock out product
                            </div>
                            <div class="modal-subtitle">Remove inventory due to damage, expiry, loss, or other reasons.</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Product</label>
                                <input type="text" id="stockOutProductName" class="form-control" readonly style="background:var(--c-surface-2);color:var(--c-text-2);">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Quantity to remove <span class="req">*</span></label>
                                <input type="number" name="quantity" class="form-control" required min="1" placeholder="Enter quantity">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Adjustment type <span class="req">*</span></label>
                                <select name="adjustment_type" class="form-select" required>
                                    <option value="">Select adjustment type</option>
                                    <option value="Damaged">Damaged</option>
                                    <option value="Expired">Expired</option>
                                    <option value="Lost">Lost</option>
                                    <option value="Returned to supplier">Returned to supplier</option>
                                    <option value="Broken packaging">Broken packaging</option>
                                    <option value="Count correction">Count correction</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Specific reason (optional)</label>
                                <input type="text" id="customStockOutReason" class="form-control" maxlength="500" placeholder="Enter a specific reason if needed">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Notes (optional)</label>
                                <textarea name="notes" class="form-control" rows="3" maxlength="500" placeholder="Add more details for the stock-out record"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer justify-content-end gap-2">
                        <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" style="background:var(--c-amber);border-color:var(--c-amber);">
                            <i class="bi bi-check2-circle"></i> Confirm stock out
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════
         SUPPLIER MODAL
    ═════════════════════════════════════════════════════════════ -->
    <div class="modal fade modal-modern" id="supplierModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form id="supplierForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="modal-header">
                        <div>
                            <div class="modal-title">
                                <i class="bi bi-truck me-2" style="color:var(--c-teal);"></i>Add new supplier
                            </div>
                            <div class="modal-subtitle">Create a supplier record for product assignment.</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div id="supplierMessage"></div>
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Supplier name <span class="req">*</span></label>
                                <input type="text" class="form-control" id="supplier_name" name="supplier_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Contact person</label>
                                <input type="text" class="form-control" id="contact_person" name="contact_person">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone</label>
                                <input type="text" class="form-control" id="phone" name="phone">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Address</label>
                                <textarea class="form-control" id="address" name="address" rows="3"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer justify-content-end gap-2">
                        <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="saveSupplierBtn"
                            style="background:var(--c-teal);border-color:var(--c-teal);">
                            <i class="bi bi-floppy"></i> Add supplier
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</main>

<?php require __DIR__ . '/../components/js_script.php'; ?>
<script src="/inventory_system/assets/js/manage_product.js"></script>

</body>
</html>