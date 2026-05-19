<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/CategoryController.php';
require_once __DIR__ . '/../controllers/SubcategoryController.php';

Middleware::auth()->role(['admin']);

$csrf_token = Middleware::generateCsrfToken();

try {
    $categories = CategoryController::all($conn, null, 500);

    $subcategoryFilters = [
        'search'      => trim((string) ($_GET['search']      ?? '')),
        'status'      => strtolower(trim((string) ($_GET['status']      ?? 'all'))),
        'category_id' => (int) ($_GET['category_id'] ?? 0),
        'page'        => max(1, (int) ($_GET['page']     ?? 1)),
        'per_page'    => (int) ($_GET['per_page'] ?? 25),
    ];

    $subcategoryPage = SubcategoryController::paginate($conn, $subcategoryFilters);
    $subcategories   = $subcategoryPage['items'];
    $subcategoryFilters = [
        'search'      => (string) $subcategoryPage['search'],
        'status'      => (string) $subcategoryPage['status'],
        'category_id' => (int)    $subcategoryPage['category_id'],
        'page'        => (int)    $subcategoryPage['page'],
        'per_page'    => (int)    $subcategoryPage['per_page'],
    ];
    $subcategoryRowStart = $subcategoryPage['total'] > 0
        ? (($subcategoryPage['page'] - 1) * $subcategoryPage['per_page']) + 1
        : 0;

    $subcategorySummary = SubcategoryController::getSummary($conn);

} catch (Throwable $e) {
    error_log('[manage_subcategory.php] ' . $e->getMessage());
    $categories          = [];
    $subcategories       = [];
    $pageError           = 'Failed to load subcategory records.';
    $subcategoryPage     = ['total' => 0, 'page' => 1, 'per_page' => 25, 'total_pages' => 1];
    $subcategoryFilters  = ['search' => '', 'status' => 'all', 'category_id' => 0, 'page' => 1, 'per_page' => 25];
    $subcategoryRowStart = 0;
    $subcategorySummary  = ['total' => 0, 'active' => 0, 'inactive' => 0, 'categories_used' => 0];
}

function subcategoryListUrl(array $filters, array $overrides = []): string
{
    $params = array_merge($filters, $overrides);
    if (($params['page']        ?? 1)     <= 1)     unset($params['page']);
    if (($params['search']      ?? '')    === '')    unset($params['search']);
    if (($params['status']      ?? 'all') === 'all') unset($params['status']);
    if (($params['category_id'] ?? 0)     <= 0)      unset($params['category_id']);
    if (($params['per_page']    ?? 25)    === 25)     unset($params['per_page']);
    $query = http_build_query($params);
    return '/inventory_system/product_management/manage_subcategory.php' . ($query !== '' ? '?' . $query : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/../components/head.php'; ?>
    <title>Manage Subcategories</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
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
            --c-teal:#0d9488;   --c-teal-bg:#f0fdfa;   --c-teal-bd:#99f6e4;
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

        .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem}
        @media(max-width:900px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
        .stat-card{background:var(--c-surface);border:1px solid var(--c-border);border-radius:var(--radius-lg);padding:1.1rem 1.25rem;display:flex;align-items:flex-start;gap:1rem;box-shadow:var(--shadow-sm)}
        .stat-icon{width:40px;height:40px;border-radius:var(--radius-md);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
        .stat-body{flex:1;min-width:0}
        .stat-label{font-size:12px;color:var(--c-text-2);font-weight:500;margin-bottom:2px}
        .stat-val{font-size:26px;font-weight:600;letter-spacing:-.5px;line-height:1.1}

        .subcat-card{background:var(--c-surface);border:1px solid var(--c-border);border-radius:var(--radius-xl);box-shadow:var(--shadow-sm);overflow:hidden}
        .subcat-card-head{display:flex;align-items:center;justify-content:space-between;padding:1.25rem 1.5rem 0;flex-wrap:wrap;gap:.75rem}
        .subcat-card-title{font-size:16px;font-weight:600}

        .filter-bar{display:flex;align-items:flex-end;gap:.75rem;padding:1rem 1.5rem;border-bottom:1px solid var(--c-border);flex-wrap:wrap}
        .filter-item{display:flex;flex-direction:column;gap:4px}
        .filter-item.grow{flex:1;min-width:180px}
        .filter-label{font-size:11px;font-weight:600;letter-spacing:.04em;color:var(--c-text-3);text-transform:uppercase}
        .search-wrap{display:flex;align-items:center;gap:8px;height:36px;padding:0 12px;background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:var(--radius-md);transition:border-color .15s,box-shadow .15s}
        .search-wrap:focus-within{border-color:var(--c-accent);box-shadow:0 0 0 3px rgba(37,99,235,.1)}
        .search-wrap i{color:var(--c-text-3);font-size:15px;flex-shrink:0}
        .search-wrap input{border:none;background:none;outline:none;font-family:var(--ff-base);font-size:13px;color:var(--c-text-1);width:100%}
        .search-wrap input::placeholder{color:var(--c-text-3)}
        select.filter-select{height:36px;padding:0 32px 0 12px;background:var(--c-surface-2) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%239a9691'/%3E%3C/svg%3E") no-repeat right 10px center;border:1px solid var(--c-border);border-radius:var(--radius-md);color:var(--c-text-1);font-family:var(--ff-base);font-size:13px;appearance:none;-webkit-appearance:none;cursor:pointer;transition:border-color .15s,box-shadow .15s}
        select.filter-select:focus{outline:none;border-color:var(--c-accent);box-shadow:0 0 0 3px rgba(37,99,235,.1)}

        .btn{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 16px;border-radius:var(--radius-md);font-family:var(--ff-base);font-size:13px;font-weight:500;cursor:pointer;border:1px solid transparent;transition:all .15s;white-space:nowrap;text-decoration:none}
        .btn-primary{background:var(--c-accent);color:#fff;border-color:var(--c-accent)}
        .btn-primary:hover{background:#1d4ed8;border-color:#1d4ed8}
        .btn-outline{background:var(--c-surface);color:var(--c-text-2);border-color:var(--c-border-2)}
        .btn-outline:hover{background:var(--c-surface-2);color:var(--c-text-1)}
        .btn-icon-sm{width:30px;height:30px;padding:0;display:inline-flex;align-items:center;justify-content:center;border-radius:var(--radius-sm);font-size:14px;cursor:pointer;border:1px solid var(--c-border);background:var(--c-surface);color:var(--c-text-2);transition:all .15s}
        .btn-icon-sm:hover              {background:var(--c-surface-2);color:var(--c-text-1);border-color:var(--c-border-2)}
        .btn-icon-sm.edit:hover         {background:var(--c-accent-bg);color:var(--c-accent);border-color:var(--c-accent-bd)}
        .btn-icon-sm.deactivate:hover   {background:var(--c-red-bg);color:var(--c-red);border-color:var(--c-red-bd)}
        .btn-icon-sm.activate:hover     {background:var(--c-green-bg);color:var(--c-green);border-color:var(--c-green-bd)}

        .table-wrap{overflow-x:auto}
        table.subcat-table{width:100%;border-collapse:collapse}
        .subcat-table thead tr{border-bottom:1px solid var(--c-border)}
        .subcat-table th{padding:10px 16px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);text-align:left;white-space:nowrap;background:var(--c-surface-2)}
        .subcat-table tbody tr{border-bottom:1px solid var(--c-border);transition:background .1s}
        .subcat-table tbody tr:last-child{border-bottom:none}
        .subcat-table tbody tr:hover{background:var(--c-surface-2)}
        .subcat-table td{padding:12px 16px;font-size:13px;vertical-align:middle}
        .subcat-table td.num{color:var(--c-text-3);font-size:12px;font-family:var(--ff-mono)}

        .subcat-cell{display:flex;align-items:center;gap:10px}
        .subcat-icon{width:34px;height:34px;border-radius:var(--radius-md);display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;background:var(--c-purple-bg);color:var(--c-purple)}
        .subcat-name{font-weight:500;font-size:13px}
        .cat-pill{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:99px;font-size:11px;font-weight:500;background:var(--c-accent-bg);color:var(--c-accent);border:1px solid var(--c-accent-bd)}

        .badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:99px;font-size:11px;font-weight:600;letter-spacing:.02em;border:1px solid transparent}
        .badge-active  {background:var(--c-green-bg);color:var(--c-green);border-color:var(--c-green-bd)}
        .badge-inactive{background:var(--c-surface-2);color:var(--c-text-3);border-color:var(--c-border)}

        .pagination-bar{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.5rem;border-top:1px solid var(--c-border);flex-wrap:wrap;gap:.75rem}
        .pag-info{font-size:12px;color:var(--c-text-3)}
        .pagination{display:flex;list-style:none;margin:0;padding:0;gap:3px}
        .page-item .page-link{display:flex;align-items:center;justify-content:center;min-width:30px;height:30px;padding:0 8px;border-radius:var(--radius-sm);border:1px solid var(--c-border);background:var(--c-surface);color:var(--c-text-2);font-size:12px;text-decoration:none;transition:all .15s;font-family:var(--ff-mono)}
        .page-item .page-link:hover{background:var(--c-surface-2);color:var(--c-text-1)}
        .page-item.active .page-link{background:var(--c-accent);color:#fff;border-color:var(--c-accent)}
        .page-item.disabled .page-link{opacity:.4;pointer-events:none}

        .empty-state{text-align:center;padding:3.5rem 1rem;color:var(--c-text-3)}
        .empty-state i{font-size:40px;display:block;margin-bottom:.75rem;opacity:.4}
        .empty-state p{font-size:14px}

        .modal-modern .modal-content{border:1px solid var(--c-border);border-radius:var(--radius-xl);box-shadow:var(--shadow-lg);font-family:var(--ff-base);overflow:hidden}
        .modal-modern .modal-header{border-bottom:1px solid var(--c-border);padding:1.1rem 1.5rem;background:var(--c-surface-2)}
        .modal-modern .modal-title{font-size:16px;font-weight:600;letter-spacing:-.2px}
        .modal-modern .modal-subtitle{font-size:12px;color:var(--c-text-3);margin-top:2px}
        .modal-modern .modal-body{padding:1.5rem}
        .modal-modern .modal-footer{border-top:1px solid var(--c-border);padding:1rem 1.5rem;background:var(--c-surface-2)}
        .modal-modern .form-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);margin-bottom:.3rem;display:block}
        .modal-modern .form-control,
        .modal-modern .form-select{height:38px;padding:0 12px;background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:var(--radius-md);font-family:var(--ff-base);font-size:13px;color:var(--c-text-1);transition:border-color .15s,box-shadow .15s}
        .modal-modern textarea.form-control{height:auto;padding:10px 12px}
        .modal-modern .form-control:focus,
        .modal-modern .form-select:focus{border-color:var(--c-accent);box-shadow:0 0 0 3px rgba(37,99,235,.1);outline:none}
        .modal-modern .form-control::placeholder{color:var(--c-text-3)}
        .req{color:var(--c-red)}

        @media(max-width:768px){#main{padding:1rem}.filter-bar{flex-direction:column;align-items:stretch}}
    </style>
</head>
<body>

<?php
require __DIR__ . '/../components/header.php';
require __DIR__ . '/../components/sidebar.php';
?>

<main id="main" class="main">
    <div class="pagetitle">
        <h1>Subcategory Management</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/inventory_system/index.php">Home</a></li>
                <li class="breadcrumb-item"><a href="/inventory_system/product_management/manage_product.php">Products</a></li>
                <li class="breadcrumb-item"><a href="/inventory_system/product_management/manage_category.php">Categories</a></li>
                <li class="breadcrumb-item active">Subcategory Management</li>
            </ol>
        </nav>
    </div>

    <?php if (!empty($pageError)): ?>
        <div class="alert alert-danger mb-3" style="border-radius:var(--radius-md);font-size:13px;">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?= htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div id="subcategoryMessages"></div>

    <!-- ── Stat cards ─────────────────────────────────────────────── -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--c-purple-bg);color:var(--c-purple);">
                <i class="bi bi-diagram-3-fill" aria-hidden="true"></i>
            </div>
            <div class="stat-body">
                <div class="stat-label">Total subcategories</div>
                <div class="stat-val" id="statSubTotal" style="color:var(--c-purple);"><?= number_format((int)($subcategorySummary['total'] ?? 0)) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--c-green-bg);color:var(--c-green);">
                <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
            </div>
            <div class="stat-body">
                <div class="stat-label">Active</div>
                <div class="stat-val" id="statSubActive" style="color:var(--c-green);"><?= number_format((int)($subcategorySummary['active'] ?? 0)) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--c-red-bg);color:var(--c-red);">
                <i class="bi bi-slash-circle-fill" aria-hidden="true"></i>
            </div>
            <div class="stat-body">
                <div class="stat-label">Inactive</div>
                <div class="stat-val" id="statSubInactive" style="color:var(--c-red);"><?= number_format((int)($subcategorySummary['inactive'] ?? 0)) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--c-teal-bg);color:var(--c-teal);">
                <i class="bi bi-tags-fill" aria-hidden="true"></i>
            </div>
            <div class="stat-body">
                <div class="stat-label">Categories covered</div>
                <div class="stat-val" id="statSubCatsUsed" style="color:var(--c-teal);"><?= number_format((int)($subcategorySummary['categories_used'] ?? 0)) ?></div>
            </div>
        </div>
    </div>

    <!-- ── Main card ──────────────────────────────────────────────── -->
    <div class="subcat-card">
        <div class="subcat-card-head">
            <span class="subcat-card-title">Subcategory list</span>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSubcategoryModal">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Add subcategory
            </button>
        </div>

        <!-- Filter bar -->
        <form method="get" class="filter-bar">
            <div class="filter-item grow">
                <span class="filter-label">Search</span>
                <div class="search-wrap">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="text" name="search"
                        value="<?= htmlspecialchars($subcategoryFilters['search'], ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="Subcategory name, category, description…" autocomplete="off">
                </div>
            </div>
            <div class="filter-item">
                <span class="filter-label">Category</span>
                <select name="category_id" class="filter-select">
                    <option value="0">All categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int) ($cat['category_id'] ?? 0) ?>"
                            <?= $subcategoryFilters['category_id'] === (int) ($cat['category_id'] ?? 0) ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) ($cat['category_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-item">
                <span class="filter-label">Status</span>
                <select name="status" class="filter-select">
                    <option value="all"      <?= $subcategoryFilters['status'] === 'all'      ? 'selected' : '' ?>>All status</option>
                    <option value="active"   <?= $subcategoryFilters['status'] === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $subcategoryFilters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="filter-item">
                <span class="filter-label">Per page</span>
                <select name="per_page" class="filter-select">
                    <?php foreach ([10, 25, 50, 100] as $size): ?>
                        <option value="<?= $size ?>" <?= $subcategoryFilters['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option>
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
            <table class="subcat-table" id="subcategoryTable">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th>Subcategory</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th style="width:90px;text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($subcategories)): ?>
                        <?php foreach ($subcategories as $index => $sub):
                            $subId    = (int) ($sub['subcategory_id'] ?? 0);
                            $status   = $sub['status'] ?? 'inactive';
                            $isActive = $status === 'active';
                            $desc     = $sub['description'] ?? '';
                        ?>
                            <tr id="subcategoryRow<?= $subId ?>">
                                <td class="num"><?= $subcategoryRowStart + $index ?></td>
                                <td>
                                    <div class="subcat-cell">
                                        <div class="subcat-icon">
                                            <i class="bi bi-diagram-3" aria-hidden="true"></i>
                                        </div>
                                        <div>
                                            <div class="subcat-name"><?= htmlspecialchars($sub['subcategory_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="cat-pill">
                                        <i class="bi bi-tag-fill" style="font-size:10px;" aria-hidden="true"></i>
                                        <?= htmlspecialchars($sub['category_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td style="color:var(--c-text-2);font-size:12px;max-width:240px;">
                                    <?= $desc !== ''
                                        ? htmlspecialchars($desc, ENT_QUOTES, 'UTF-8')
                                        : '<span style="color:var(--c-text-3)">—</span>' ?>
                                </td>
                                <td>
                                    <span class="badge <?= $isActive ? 'badge-active' : 'badge-inactive' ?>" id="subcategoryStatus<?= $subId ?>">
                                        <i class="bi <?= $isActive ? 'bi-circle-fill' : 'bi-circle' ?>" style="font-size:7px;" aria-hidden="true"></i>
                                        <?= ucfirst($status) ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="display:flex;gap:6px;justify-content:center;">
                                        <button type="button"
                                            class="btn-icon-sm edit editSubcategoryBtn"
                                            title="Edit subcategory"
                                            aria-label="Edit <?= htmlspecialchars($sub['subcategory_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-id="<?= $subId ?>"
                                            data-name="<?= htmlspecialchars($sub['subcategory_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-category="<?= (int) ($sub['category_id'] ?? 0) ?>"
                                            data-description="<?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?>"
                                            data-bs-toggle="modal" data-bs-target="#editSubcategoryModal">
                                            <i class="bi bi-pencil" aria-hidden="true"></i>
                                        </button>

                                        <button type="button"
                                            class="btn-icon-sm <?= $isActive ? 'deactivate' : 'activate' ?> toggleSubcategoryStatusBtn"
                                            title="<?= $isActive ? 'Deactivate' : 'Reactivate' ?>"
                                            aria-label="<?= $isActive ? 'Deactivate' : 'Reactivate' ?> <?= htmlspecialchars($sub['subcategory_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-id="<?= $subId ?>"
                                            data-name="<?= htmlspecialchars($sub['subcategory_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-status="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="bi <?= $isActive ? 'bi-slash-circle' : 'bi-check-circle' ?>" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <i class="bi bi-diagram-3"></i>
                                    <p>No subcategories found matching your filters.</p>
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
                <?php if ($subcategoryPage['total'] > 0): ?>
                    Showing <?= number_format($subcategoryRowStart) ?>–<?= number_format(min($subcategoryRowStart + count($subcategories) - 1, $subcategoryPage['total'])) ?> of <?= number_format($subcategoryPage['total']) ?> subcategories
                <?php else: ?>
                    No results
                <?php endif; ?>
            </span>
            <nav aria-label="Subcategory pagination">
                <ul class="pagination">
                    <li class="page-item <?= $subcategoryPage['page'] <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(subcategoryListUrl($subcategoryFilters, ['page' => $subcategoryPage['page'] - 1]), ENT_QUOTES, 'UTF-8') ?>" aria-label="Previous">
                            <i class="bi bi-chevron-left" style="font-size:11px;" aria-hidden="true"></i>
                        </a>
                    </li>
                    <?php
                    $pStart = max(1, $subcategoryPage['page'] - 2);
                    $pEnd   = min($subcategoryPage['total_pages'], $subcategoryPage['page'] + 2);
                    for ($pn = $pStart; $pn <= $pEnd; $pn++):
                    ?>
                        <li class="page-item <?= $pn === $subcategoryPage['page'] ? 'active' : '' ?>">
                            <a class="page-link" href="<?= htmlspecialchars(subcategoryListUrl($subcategoryFilters, ['page' => $pn]), ENT_QUOTES, 'UTF-8') ?>"><?= $pn ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $subcategoryPage['page'] >= $subcategoryPage['total_pages'] ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(subcategoryListUrl($subcategoryFilters, ['page' => $subcategoryPage['page'] + 1]), ENT_QUOTES, 'UTF-8') ?>" aria-label="Next">
                            <i class="bi bi-chevron-right" style="font-size:11px;" aria-hidden="true"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
    </div><!-- /.subcat-card -->


    <!-- ════════════════════════════════════════════════════════════
         ADD SUBCATEGORY MODAL
    ═════════════════════════════════════════════════════════════ -->
    <div class="modal fade modal-modern" id="addSubcategoryModal" tabindex="-1" aria-labelledby="addSubcategoryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form id="addSubcategoryForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="modal-header">
                        <div>
                            <div class="modal-title" id="addSubcategoryModalLabel">
                                <i class="bi bi-diagram-3 me-2" style="color:var(--c-purple);" aria-hidden="true"></i>Add new subcategory
                            </div>
                            <div class="modal-subtitle">Create a subcategory under one of your existing categories.</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Category <span class="req">*</span></label>
                                <select class="form-select" name="category_id" required>
                                    <option value="">Select a category…</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= (int) ($cat['category_id'] ?? 0) ?>">
                                            <?= htmlspecialchars((string) ($cat['category_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Subcategory name <span class="req">*</span></label>
                                <input type="text" class="form-control" name="subcategory_name" required
                                    placeholder="e.g. Soft Drinks" autocomplete="off">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="3"
                                    placeholder="Optional description…" maxlength="1000"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer justify-content-end gap-2">
                        <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> Add subcategory
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════
         EDIT SUBCATEGORY MODAL
    ═════════════════════════════════════════════════════════════ -->
    <div class="modal fade modal-modern" id="editSubcategoryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form id="editSubcategoryForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="subcategory_id" id="editSubcategoryId">

                    <div class="modal-header">
                        <div>
                            <div class="modal-title">
                                <i class="bi bi-pencil-square me-2" style="color:var(--c-amber);" aria-hidden="true"></i>Edit subcategory
                            </div>
                            <div class="modal-subtitle">Update the selected subcategory details.</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Category <span class="req">*</span></label>
                                <select class="form-select" name="category_id" id="editSubcategoryCategory" required>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= (int) ($cat['category_id'] ?? 0) ?>">
                                            <?= htmlspecialchars((string) ($cat['category_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Subcategory name <span class="req">*</span></label>
                                <input type="text" class="form-control" name="subcategory_name"
                                    id="editSubcategoryName" required autocomplete="off">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description"
                                    id="editSubcategoryDescription" rows="3" maxlength="1000"></textarea>
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
<script src="/inventory_system/assets/js/manage_subcategory.js"></script>

</body>
</html>