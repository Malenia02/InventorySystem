<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/CategoryController.php';

Middleware::auth()->role(['admin']);

$csrf_token = Middleware::generateCsrfToken();

try {
    $categoryFilters = [
        'search'   => trim((string) ($_GET['search']   ?? '')),
        'status'   => strtolower(trim((string) ($_GET['status']   ?? 'all'))),
        'page'     => max(1, (int) ($_GET['page']     ?? 1)),
        'per_page' => (int) ($_GET['per_page'] ?? 25),
    ];

    $categoryPage = CategoryController::paginate($conn, $categoryFilters);
    $categories   = $categoryPage['items'];
    $categoryFilters = [
        'search'   => (string) $categoryPage['search'],
        'status'   => (string) $categoryPage['status'],
        'page'     => (int)    $categoryPage['page'],
        'per_page' => (int)    $categoryPage['per_page'],
    ];
    $categoryRowStart = $categoryPage['total'] > 0
        ? (($categoryPage['page'] - 1) * $categoryPage['per_page']) + 1
        : 0;

    $categorySummary = CategoryController::getSummary($conn);

} catch (Throwable $e) {
    error_log('[manage_category.php] ' . $e->getMessage());
    $categories      = [];
    $pageError       = 'Failed to load category records.';
    $categoryPage    = ['total' => 0, 'page' => 1, 'per_page' => 25, 'total_pages' => 1];
    $categoryFilters = ['search' => '', 'status' => 'all', 'page' => 1, 'per_page' => 25];
    $categoryRowStart = 0;
    $categorySummary  = ['total' => 0, 'active' => 0, 'inactive' => 0];
}

function categoryListUrl(array $filters, array $overrides = []): string
{
    $params = array_merge($filters, $overrides);
    if (($params['page']     ?? 1)     <= 1)     unset($params['page']);
    if (($params['search']   ?? '')    === '')    unset($params['search']);
    if (($params['status']   ?? 'all') === 'all') unset($params['status']);
    if (($params['per_page'] ?? 25)    === 25)    unset($params['per_page']);
    $query = http_build_query($params);
    return '/inventory_system/product_management/manage_category.php' . ($query !== '' ? '?' . $query : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/../components/head.php'; ?>
    <title>Manage Categories</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        /* ── Design tokens (identical to manage_staff / manage_product) ── */
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

            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
            --radius-xl: 20px;

            --shadow-sm: 0 1px 3px rgba(0,0,0,.07), 0 1px 2px rgba(0,0,0,.04);
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
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        @media (max-width: 600px) { .stats-grid { grid-template-columns: 1fr 1fr; } }

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
        .category-card {
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        .category-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.25rem 1.5rem 0;
            flex-wrap: wrap;
            gap: .75rem;
        }
        .category-card-title { font-size: 16px; font-weight: 600; color: var(--c-text-1); }

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
        .btn-primary { background: var(--c-accent); color: #fff; border-color: var(--c-accent); }
        .btn-primary:hover { background: #1d4ed8; border-color: #1d4ed8; }
        .btn-outline  { background: var(--c-surface); color: var(--c-text-2); border-color: var(--c-border-2); }
        .btn-outline:hover { background: var(--c-surface-2); color: var(--c-text-1); }

        .btn-icon-sm {
            width: 30px; height: 30px; padding: 0;
            display: inline-flex; align-items: center; justify-content: center;
            border-radius: var(--radius-sm);
            font-size: 14px; cursor: pointer;
            border: 1px solid var(--c-border);
            background: var(--c-surface); color: var(--c-text-2);
            transition: all .15s;
        }
        .btn-icon-sm:hover            { background: var(--c-surface-2); color: var(--c-text-1); border-color: var(--c-border-2); }
        .btn-icon-sm.edit:hover       { background: var(--c-accent-bg); color: var(--c-accent); border-color: var(--c-accent-bd); }
        .btn-icon-sm.deactivate:hover { background: var(--c-red-bg);    color: var(--c-red);    border-color: var(--c-red-bd); }
        .btn-icon-sm.activate:hover   { background: var(--c-green-bg);  color: var(--c-green);  border-color: var(--c-green-bd); }

        /* ── Table ────────────────────────────────────────────── */
        .table-wrap { overflow-x: auto; }
        table.category-table { width: 100%; border-collapse: collapse; }
        .category-table thead tr { border-bottom: 1px solid var(--c-border); }
        .category-table th {
            padding: 10px 16px;
            font-size: 11px; font-weight: 600;
            text-transform: uppercase; letter-spacing: .05em;
            color: var(--c-text-3); text-align: left; white-space: nowrap;
            background: var(--c-surface-2);
        }
        .category-table tbody tr { border-bottom: 1px solid var(--c-border); transition: background .1s; }
        .category-table tbody tr:last-child { border-bottom: none; }
        .category-table tbody tr:hover { background: var(--c-surface-2); }
        .category-table td { padding: 12px 16px; font-size: 13px; vertical-align: middle; }
        .category-table td.num { color: var(--c-text-3); font-size: 12px; font-family: var(--ff-mono); }

        /* Category name + description cell */
        .cat-name { font-weight: 500; font-size: 13px; line-height: 1.2; }
        .cat-desc { font-size: 12px; color: var(--c-text-3); margin-top: 2px; }

        /* ── Badges ───────────────────────────────────────────── */
        .badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 9px; border-radius: 99px;
            font-size: 11px; font-weight: 600; letter-spacing: .02em;
            border: 1px solid transparent;
        }
        .badge-active   { background: var(--c-green-bg);  color: var(--c-green);  border-color: var(--c-green-bd); }
        .badge-inactive { background: var(--c-surface-2); color: var(--c-text-3); border-color: var(--c-border); }

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
        .page-item .page-link:hover { background: var(--c-surface-2); color: var(--c-text-1); }
        .page-item.active .page-link { background: var(--c-accent); color: #fff; border-color: var(--c-accent); }
        .page-item.disabled .page-link { opacity: .4; pointer-events: none; }

        /* ── Empty state ──────────────────────────────────────── */
        .empty-state { text-align: center; padding: 3.5rem 1rem; color: var(--c-text-3); }
        .empty-state i { font-size: 40px; display: block; margin-bottom: .75rem; opacity: .4; }
        .empty-state p { font-size: 14px; }

        /* ── Modals ───────────────────────────────────────────── */
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
        .modal-modern .modal-title { font-size: 16px; font-weight: 600; letter-spacing: -.2px; }
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
        .modal-section-title {
            font-size: 11px; font-weight: 600;
            text-transform: uppercase; letter-spacing: .07em;
            color: var(--c-text-3);
            border-bottom: 1px solid var(--c-border);
            padding-bottom: .5rem; margin-bottom: .75rem;
        }
        .req { color: var(--c-red); }

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
        <h1>Category Management</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/inventory_system/index.php">Home</a></li>
                <li class="breadcrumb-item"><a href="/inventory_system/product_management/manage_product.php">Products</a></li>
                <li class="breadcrumb-item active">Category Management</li>
            </ol>
        </nav>
    </div>

    <?php if (!empty($pageError)): ?>
        <div class="alert alert-danger mb-3" style="border-radius:var(--radius-md);font-size:13px;">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?= htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div id="categoryMessages"></div>

    <!-- ── Stat cards ──────────────────────────────────────────── -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--c-accent-bg);color:var(--c-accent);">
                <i class="bi bi-tags-fill" aria-hidden="true"></i>
            </div>
            <div class="stat-body">
                <div class="stat-label">Total categories</div>
                <div class="stat-val" id="statTotal" style="color:var(--c-accent);"><?= number_format((int)($categorySummary['total'] ?? 0)) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--c-green-bg);color:var(--c-green);">
                <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
            </div>
            <div class="stat-body">
                <div class="stat-label">Active</div>
                <div class="stat-val" id="statActive" style="color:var(--c-green);"><?= number_format((int)($categorySummary['active'] ?? 0)) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--c-red-bg);color:var(--c-red);">
                <i class="bi bi-slash-circle-fill" aria-hidden="true"></i>
            </div>
            <div class="stat-body">
                <div class="stat-label">Inactive</div>
                <div class="stat-val" id="statInactive" style="color:var(--c-red);"><?= number_format((int)($categorySummary['inactive'] ?? 0)) ?></div>
            </div>
        </div>
    </div>

    <!-- ── Main card ───────────────────────────────────────────── -->
    <div class="category-card">
        <div class="category-card-head">
            <span class="category-card-title">Category list</span>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Add category
            </button>
        </div>

        <!-- Filter bar -->
        <form method="get" class="filter-bar">
            <div class="filter-item grow">
                <span class="filter-label">Search</span>
                <div class="search-wrap">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="text" name="search"
                        value="<?= htmlspecialchars($categoryFilters['search'], ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="Category name or description…"
                        autocomplete="off">
                </div>
            </div>
            <div class="filter-item">
                <span class="filter-label">Status</span>
                <select name="status" class="filter-select">
                    <option value="all"      <?= $categoryFilters['status'] === 'all'      ? 'selected' : '' ?>>All status</option>
                    <option value="active"   <?= $categoryFilters['status'] === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $categoryFilters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="filter-item">
                <span class="filter-label">Per page</span>
                <select name="per_page" class="filter-select">
                    <?php foreach ([10, 25, 50, 100] as $size): ?>
                        <option value="<?= $size ?>" <?= $categoryFilters['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option>
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
            <table class="category-table" id="categoryTable">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th style="width:90px;text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($categories)): ?>
                        <?php foreach ($categories as $index => $cat):
                            $catId   = (int) ($cat['category_id'] ?? 0);
                            $status  = $cat['status'] ?? 'inactive';
                            $isActive = $status === 'active';
                        ?>
                            <tr id="categoryRow<?= $catId ?>">
                                <td class="num"><?= $categoryRowStart + $index ?></td>
                                <td>
                                    <div class="cat-name"><?= htmlspecialchars($cat['category_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php if (!empty($cat['description'])): ?>
                                        <div class="cat-desc"><?= htmlspecialchars($cat['description'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?= $isActive ? 'badge-active' : 'badge-inactive' ?>" id="categoryStatus<?= $catId ?>">
                                        <i class="bi <?= $isActive ? 'bi-circle-fill' : 'bi-circle' ?>" style="font-size:7px;" aria-hidden="true"></i>
                                        <?= ucfirst($status) ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="display:flex;gap:6px;justify-content:center;">
                                        <button type="button"
                                            class="btn-icon-sm edit editCategoryBtn"
                                            title="Edit category"
                                            aria-label="Edit <?= htmlspecialchars($cat['category_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-id="<?= $catId ?>"
                                            data-name="<?= htmlspecialchars($cat['category_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-description="<?= htmlspecialchars($cat['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editCategoryModal">
                                            <i class="bi bi-pencil" aria-hidden="true"></i>
                                        </button>
                                        <button type="button"
                                            class="btn-icon-sm <?= $isActive ? 'deactivate' : 'activate' ?> toggleCategoryStatusBtn"
                                            title="<?= $isActive ? 'Deactivate' : 'Reactivate' ?>"
                                            aria-label="<?= $isActive ? 'Deactivate' : 'Reactivate' ?> <?= htmlspecialchars($cat['category_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-id="<?= $catId ?>"
                                            data-name="<?= htmlspecialchars($cat['category_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-status="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="bi <?= $isActive ? 'bi-slash-circle' : 'bi-check-circle' ?>" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4">
                                <div class="empty-state">
                                    <i class="bi bi-tags"></i>
                                    <p>No categories found matching your filters.</p>
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
                <?php if ($categoryPage['total'] > 0): ?>
                    Showing <?= number_format($categoryRowStart) ?>–<?= number_format(min($categoryRowStart + count($categories) - 1, $categoryPage['total'])) ?> of <?= number_format($categoryPage['total']) ?> categories
                <?php else: ?>
                    No results
                <?php endif; ?>
            </span>
            <nav aria-label="Category pagination">
                <ul class="pagination">
                    <li class="page-item <?= $categoryPage['page'] <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(categoryListUrl($categoryFilters, ['page' => $categoryPage['page'] - 1]), ENT_QUOTES, 'UTF-8') ?>" aria-label="Previous">
                            <i class="bi bi-chevron-left" style="font-size:11px;"></i>
                        </a>
                    </li>
                    <?php
                    $pStart = max(1, $categoryPage['page'] - 2);
                    $pEnd   = min($categoryPage['total_pages'], $categoryPage['page'] + 2);
                    for ($pn = $pStart; $pn <= $pEnd; $pn++):
                    ?>
                        <li class="page-item <?= $pn === $categoryPage['page'] ? 'active' : '' ?>">
                            <a class="page-link" href="<?= htmlspecialchars(categoryListUrl($categoryFilters, ['page' => $pn]), ENT_QUOTES, 'UTF-8') ?>"><?= $pn ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $categoryPage['page'] >= $categoryPage['total_pages'] ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(categoryListUrl($categoryFilters, ['page' => $categoryPage['page'] + 1]), ENT_QUOTES, 'UTF-8') ?>" aria-label="Next">
                            <i class="bi bi-chevron-right" style="font-size:11px;"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
    </div><!-- /.category-card -->


    <!-- ════════════════════════════════════════════════════════════
         ADD CATEGORY MODAL
    ═════════════════════════════════════════════════════════════ -->
    <div class="modal fade modal-modern" id="addCategoryModal" tabindex="-1" aria-labelledby="addCategoryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form id="addCategoryForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="modal-header">
                        <div>
                            <div class="modal-title" id="addCategoryModalLabel">
                                <i class="bi bi-tags me-2" style="color:var(--c-accent);" aria-hidden="true"></i>Add new category
                            </div>
                            <div class="modal-subtitle">Create a new category for product organisation.</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="modal-section-title">Category details</div>
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Category name <span class="req">*</span></label>
                                <input type="text" class="form-control" name="category_name"
                                    required placeholder="e.g. Beverages" autocomplete="off">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description <span style="color:var(--c-text-3);font-weight:400;text-transform:none;letter-spacing:0;">(optional)</span></label>
                                <textarea class="form-control" name="description" rows="3"
                                    maxlength="1000" placeholder="Brief description of this category…"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer justify-content-end gap-2">
                        <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> Add category
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════
         EDIT CATEGORY MODAL
    ═════════════════════════════════════════════════════════════ -->
    <div class="modal fade modal-modern" id="editCategoryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form id="editCategoryForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="category_id" id="editCategoryId">

                    <div class="modal-header">
                        <div>
                            <div class="modal-title">
                                <i class="bi bi-pencil-square me-2" style="color:var(--c-amber);" aria-hidden="true"></i>Edit category
                            </div>
                            <div class="modal-subtitle">Update the category name and description.</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="modal-section-title">Category details</div>
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Category name <span class="req">*</span></label>
                                <input type="text" class="form-control" name="category_name"
                                    id="editCategoryName" required autocomplete="off">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description <span style="color:var(--c-text-3);font-weight:400;text-transform:none;letter-spacing:0;">(optional)</span></label>
                                <textarea class="form-control" name="description"
                                    id="editCategoryDescription" rows="3" maxlength="1000"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer justify-content-end gap-2">
                        <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-floppy" aria-hidden="true"></i> Save changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</main>

<?php require __DIR__ . '/../components/js_script.php'; ?>
<script src="/inventory_system/assets/js/manage_category.js"></script>

</body>
</html>