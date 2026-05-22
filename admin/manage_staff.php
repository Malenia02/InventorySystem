<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/staffController.php';

Middleware::auth()->role(['admin']);

$csrf_token = Middleware::generateCsrfToken();

try {
    $staffFilters = [
        'search'   => trim((string) ($_GET['search'] ?? '')),
        'status'   => strtolower(trim((string) ($_GET['status'] ?? 'all'))),
        'role'     => strtolower(trim((string) ($_GET['role'] ?? 'all'))),
        'page'     => max(1, (int) ($_GET['page'] ?? 1)),
        'per_page' => (int) ($_GET['per_page'] ?? 25),
    ];

    $staffPage = StaffController::paginate($conn, $staffFilters);
    $staffs    = $staffPage['items'];
    $staffFilters = [
        'search'   => (string) $staffPage['search'],
        'status'   => (string) $staffPage['status'],
        'role'     => (string) $staffPage['role'],
        'page'     => (int)    $staffPage['page'],
        'per_page' => (int)    $staffPage['per_page'],
    ];
    $staffRowStart = $staffPage['total'] > 0
        ? (($staffPage['page'] - 1) * $staffPage['per_page']) + 1
        : 0;

    // Summary counts for stat cards
    $staffSummary = StaffController::getSummary($conn);

} catch (Throwable $e) {
    error_log('[manage_staff.php] ' . $e->getMessage());
    $staffs       = [];
    $pageError    = 'Failed to load staff records.';
    $staffPage    = ['total' => 0, 'page' => 1, 'per_page' => 25, 'total_pages' => 1];
    $staffFilters = ['search' => '', 'status' => 'all', 'role' => 'all', 'page' => 1, 'per_page' => 25];
    $staffRowStart = 0;
    $staffSummary  = ['total' => 0, 'active' => 0, 'inactive' => 0, 'admin' => 0];
}

function staffListUrl(array $filters, array $overrides = []): string
{
    $params = array_merge($filters, $overrides);
    if (($params['page']     ?? 1)     <= 1)     unset($params['page']);
    if (($params['search']   ?? '')    === '')    unset($params['search']);
    if (($params['status']   ?? 'all') === 'all') unset($params['status']);
    if (($params['role']     ?? 'all') === 'all') unset($params['role']);
    if (($params['per_page'] ?? 25)    === 25)    unset($params['per_page']);
    $query = http_build_query($params);
    return '/inventory_system/admin/manage_staff.php' . ($query !== '' ? '?' . $query : '');
}

function staffInitials(string $first, string $last): string
{
    return strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/../components/head.php'; ?>
    <title>Manage Staff</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        /* ── Design tokens ─────────────────────────────────────── */
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

            --radius-sm:   6px;
            --radius-md:   10px;
            --radius-lg:   14px;
            --radius-xl:   20px;

            --shadow-sm:   0 1px 3px rgba(0,0,0,.07), 0 1px 2px rgba(0,0,0,.04);
            --shadow-md:   0 4px 12px rgba(0,0,0,.08), 0 2px 4px rgba(0,0,0,.04);
            --shadow-lg:   0 12px 32px rgba(0,0,0,.10), 0 4px 8px rgba(0,0,0,.05);
        }

        /* ── Reset & base ─────────────────────────────────────── */
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

        .breadcrumb-item + .breadcrumb-item::before {
            content: '/';
            margin-right: 6px;
            color: var(--c-border-2);
        }

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
            width: 40px;
            height: 40px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .stat-body { flex: 1; min-width: 0; }
        .stat-label { font-size: 12px; color: var(--c-text-2); font-weight: 500; margin-bottom: 2px; }
        .stat-val { font-size: 26px; font-weight: 600; letter-spacing: -.5px; line-height: 1.1; }

        /* ── Main card ────────────────────────────────────────── */
        .staff-card {
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .staff-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.25rem 1.5rem 0;
            flex-wrap: wrap;
            gap: .75rem;
        }

        .staff-card-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--c-text-1);
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
            display: flex;
            align-items: center;
            gap: 8px;
            height: 36px;
            padding: 0 12px;
            background: var(--c-surface-2);
            border: 1px solid var(--c-border);
            border-radius: var(--radius-md);
            transition: border-color .15s, box-shadow .15s;
        }

        .search-wrap:focus-within {
            border-color: var(--c-accent);
            box-shadow: 0 0 0 3px rgba(37,99,235,.1);
        }

        .search-wrap i { color: var(--c-text-3); font-size: 15px; flex-shrink: 0; }

        .search-wrap input {
            border: none;
            background: none;
            outline: none;
            font-family: var(--ff-base);
            font-size: 13px;
            color: var(--c-text-1);
            width: 100%;
        }

        .search-wrap input::placeholder { color: var(--c-text-3); }

        select.filter-select {
            height: 36px;
            padding: 0 32px 0 12px;
            background: var(--c-surface-2) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%239a9691'/%3E%3C/svg%3E") no-repeat right 10px center;
            border: 1px solid var(--c-border);
            border-radius: var(--radius-md);
            color: var(--c-text-1);
            font-family: var(--ff-base);
            font-size: 13px;
            appearance: none;
            -webkit-appearance: none;
            cursor: pointer;
            transition: border-color .15s, box-shadow .15s;
        }

        select.filter-select:focus {
            outline: none;
            border-color: var(--c-accent);
            box-shadow: 0 0 0 3px rgba(37,99,235,.1);
        }

        /* ── Buttons ──────────────────────────────────────────── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            height: 36px;
            padding: 0 16px;
            border-radius: var(--radius-md);
            font-family: var(--ff-base);
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all .15s;
            white-space: nowrap;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--c-accent);
            color: #fff;
            border-color: var(--c-accent);
        }

        .btn-primary:hover { background: #1d4ed8; border-color: #1d4ed8; }

        .btn-outline {
            background: var(--c-surface);
            color: var(--c-text-2);
            border-color: var(--c-border-2);
        }

        .btn-outline:hover { background: var(--c-surface-2); color: var(--c-text-1); }

        .btn-icon-sm {
            width: 30px;
            height: 30px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-sm);
            font-size: 15px;
            cursor: pointer;
            border: 1px solid var(--c-border);
            background: var(--c-surface);
            color: var(--c-text-2);
            transition: all .15s;
        }

        .btn-icon-sm:hover { background: var(--c-surface-2); color: var(--c-text-1); border-color: var(--c-border-2); }
        .btn-icon-sm.edit:hover  { background: var(--c-accent-bg); color: var(--c-accent); border-color: var(--c-accent-bd); }
        .btn-icon-sm.deactivate:hover { background: var(--c-red-bg); color: var(--c-red); border-color: var(--c-red-bd); }
        .btn-icon-sm.activate:hover   { background: var(--c-green-bg); color: var(--c-green); border-color: var(--c-green-bd); }

        /* ── Table ────────────────────────────────────────────── */
        .table-wrap { overflow-x: auto; }

        table.staff-table {
            width: 100%;
            border-collapse: collapse;
        }

        .staff-table thead tr {
            border-bottom: 1px solid var(--c-border);
        }

        .staff-table th {
            padding: 10px 16px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: var(--c-text-3);
            text-align: left;
            white-space: nowrap;
            background: var(--c-surface-2);
        }

        .staff-table tbody tr {
            border-bottom: 1px solid var(--c-border);
            transition: background .1s;
        }

        .staff-table tbody tr:last-child { border-bottom: none; }
        .staff-table tbody tr:hover { background: var(--c-surface-2); }

        .staff-table td {
            padding: 12px 16px;
            font-size: 13px;
            vertical-align: middle;
        }

        .staff-table td.num { color: var(--c-text-3); font-size: 12px; font-family: var(--ff-mono); }

        /* ── Avatar ───────────────────────────────────────────── */
        .avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
        }

        .avatar-initials {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
            flex-shrink: 0;
            letter-spacing: .01em;
        }

        .staff-cell { display: flex; align-items: center; gap: 10px; }
        .staff-name  { font-weight: 500; font-size: 13px; line-height: 1.2; }
        .staff-email { font-size: 12px; color: var(--c-text-3); }

        /* ── Badges ───────────────────────────────────────────── */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .02em;
            border: 1px solid transparent;
        }

        .badge-active   { background: var(--c-green-bg);  color: var(--c-green);  border-color: var(--c-green-bd); }
        .badge-inactive { background: var(--c-surface-2); color: var(--c-text-3); border-color: var(--c-border); }
        .badge-admin    { background: var(--c-purple-bg);  color: var(--c-purple); border-color: var(--c-purple-bd); }
        .badge-staff    { background: var(--c-accent-bg);  color: var(--c-accent); border-color: var(--c-accent-bd); }
        .badge-cashier  { background: var(--c-teal-bg);    color: var(--c-teal);   border-color: var(--c-teal-bd); }

        /* ── Pagination ───────────────────────────────────────── */
        .pagination-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--c-border);
            flex-wrap: wrap;
            gap: .75rem;
        }

        .pag-info { font-size: 12px; color: var(--c-text-3); }

        .pagination {
            display: flex;
            list-style: none;
            margin: 0;
            padding: 0;
            gap: 3px;
        }

        .page-item .page-link {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 30px;
            height: 30px;
            padding: 0 8px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--c-border);
            background: var(--c-surface);
            color: var(--c-text-2);
            font-size: 12px;
            text-decoration: none;
            transition: all .15s;
            font-family: var(--ff-mono);
        }

        .page-item .page-link:hover { background: var(--c-surface-2); color: var(--c-text-1); }
        .page-item.active .page-link { background: var(--c-accent); color: #fff; border-color: var(--c-accent); }
        .page-item.disabled .page-link { opacity: .4; pointer-events: none; }

        /* ── Empty state ──────────────────────────────────────── */
        .empty-state {
            text-align: center;
            padding: 3.5rem 1rem;
            color: var(--c-text-3);
        }

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

        .modal-modern .modal-title {
            font-size: 16px;
            font-weight: 600;
            letter-spacing: -.2px;
        }

        .modal-modern .modal-subtitle {
            font-size: 12px;
            color: var(--c-text-3);
            margin-top: 2px;
        }

        .modal-modern .modal-body { padding: 1.5rem; }

        .modal-modern .modal-footer {
            border-top: 1px solid var(--c-border);
            padding: 1rem 1.5rem;
            background: var(--c-surface-2);
        }

        .modal-modern .form-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: var(--c-text-3);
            margin-bottom: .3rem;
            display: block;
        }

        .modal-modern .form-control,
        .modal-modern .form-select {
            height: 38px;
            padding: 0 12px;
            background: var(--c-surface-2);
            border: 1px solid var(--c-border);
            border-radius: var(--radius-md);
            font-family: var(--ff-base);
            font-size: 13px;
            color: var(--c-text-1);
            transition: border-color .15s, box-shadow .15s;
        }

        .modal-modern .form-control:focus,
        .modal-modern .form-select:focus {
            border-color: var(--c-accent);
            box-shadow: 0 0 0 3px rgba(37,99,235,.1);
            outline: none;
        }

        .modal-modern .form-control::placeholder { color: var(--c-text-3); }

        .modal-section-title {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: var(--c-text-3);
            border-bottom: 1px solid var(--c-border);
            padding-bottom: .5rem;
            margin-bottom: .75rem;
        }

        .photo-panel {
            background: var(--c-surface-2);
            border: 1px solid var(--c-border);
            border-radius: var(--radius-lg);
            padding: 1.25rem 1rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: .75rem;
            height: 100%;
        }

        .preview-image {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--c-border);
            box-shadow: var(--shadow-sm);
        }

        /* ── Alert messages ───────────────────────────────────── */
        #staffMessages .alert {
            border-radius: var(--radius-md);
            font-size: 13px;
            border: 1px solid;
            padding: .75rem 1rem;
        }

        /* ── Spinner overlay ──────────────────────────────────── */
        .spinner-border-sm {
            width: 14px;
            height: 14px;
            border-width: 2px;
        }

        /* ── Responsive ───────────────────────────────────────── */
        @media (max-width: 768px) {
            #main { padding: 1rem; }
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-item.grow { width: 100%; }
        }

        /* ── Avatar color map (for PHP-rendered rows) ─────────── */
        .av-0 { background:#dbeafe; color:#1d4ed8; }
        .av-1 { background:#d1fae5; color:#065f46; }
        .av-2 { background:#ede9fe; color:#5b21b6; }
        .av-3 { background:#fef3c7; color:#92400e; }
        .av-4 { background:#fce7f3; color:#9d174d; }
        .av-5 { background:#ccfbf1; color:#134e4a; }
        .av-6 { background:#fee2e2; color:#991b1b; }
        .av-7 { background:#e0e7ff; color:#3730a3; }
    </style>
</head>
<body>

<?php
require __DIR__ . '/../components/header.php';
require __DIR__ . '/../components/sidebar.php';
?>

<main id="main" class="main">
    <div class="pagetitle">
        <h1>Staff Management</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="/inventory_system/index.php">Home</a>
                </li>
                <li class="breadcrumb-item active">Staff Management</li>
            </ol>
        </nav>
    </div>

    <?php if (!empty($pageError)): ?>
        <div class="alert alert-danger mb-3" style="border-radius:var(--radius-md);font-size:13px;">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?= htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div id="staffMessages"></div>

    <!-- Stat cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--c-accent-bg);color:var(--c-accent);">
                <i class="bi bi-people-fill"></i>
            </div>
            <div class="stat-body">
                <div class="stat-label">Total staff</div>
                <div class="stat-val" style="color:var(--c-accent);"><?= number_format((int)($staffSummary['total'] ?? 0)) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--c-green-bg);color:var(--c-green);">
                <i class="bi bi-person-check-fill"></i>
            </div>
            <div class="stat-body">
                <div class="stat-label">Active</div>
                <div class="stat-val" style="color:var(--c-green);"><?= number_format((int)($staffSummary['active'] ?? 0)) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--c-amber-bg);color:var(--c-amber);">
                <i class="bi bi-person-dash-fill"></i>
            </div>
            <div class="stat-body">
                <div class="stat-label">Inactive</div>
                <div class="stat-val" style="color:var(--c-amber);"><?= number_format((int)($staffSummary['inactive'] ?? 0)) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--c-purple-bg);color:var(--c-purple);">
                <i class="bi bi-shield-fill-check"></i>
            </div>
            <div class="stat-body">
                <div class="stat-label">Admins</div>
                <div class="stat-val" style="color:var(--c-purple);"><?= number_format((int)($staffSummary['admin'] ?? 0)) ?></div>
            </div>
        </div>
    </div>

    <!-- Main staff card -->
    <div class="staff-card">
        <div class="staff-card-head">
            <span class="staff-card-title">Staff list</span>
            <button
                type="button"
                class="btn btn-primary"
                data-bs-toggle="modal"
                data-bs-target="#addStaffModal"
            >
                <i class="bi bi-plus-lg"></i> Add staff
            </button>
        </div>

        <!-- Filter bar -->
        <form method="get" class="filter-bar">
            <div class="filter-item grow">
                <span class="filter-label">Search</span>
                <div class="search-wrap">
                    <i class="bi bi-search"></i>
                    <input
                        type="text"
                        name="search"
                        value="<?= htmlspecialchars($staffFilters['search'], ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="Name, username, email…"
                        autocomplete="off"
                    >
                </div>
            </div>
            <div class="filter-item">
                <span class="filter-label">Status</span>
                <select name="status" class="filter-select">
                    <option value="all"      <?= $staffFilters['status'] === 'all'      ? 'selected' : '' ?>>All status</option>
                    <option value="active"   <?= $staffFilters['status'] === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $staffFilters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="filter-item">
                <span class="filter-label">Role</span>
                <select name="role" class="filter-select">
                    <option value="all"     <?= $staffFilters['role'] === 'all'     ? 'selected' : '' ?>>All roles</option>
                    <option value="admin"   <?= $staffFilters['role'] === 'admin'   ? 'selected' : '' ?>>Admin</option>
                    <option value="staff"   <?= $staffFilters['role'] === 'staff'   ? 'selected' : '' ?>>Staff</option>
                    <option value="cashier" <?= $staffFilters['role'] === 'cashier' ? 'selected' : '' ?>>Cashier</option>
                </select>
            </div>
            <div class="filter-item">
                <span class="filter-label">Per page</span>
                <select name="per_page" class="filter-select">
                    <?php foreach ([10, 25, 50, 100] as $size): ?>
                        <option value="<?= $size ?>" <?= $staffFilters['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option>
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
            <table class="staff-table" id="staffTable">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th>Staff member</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th style="width:90px; text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($staffs)): ?>
                        <?php foreach ($staffs as $index => $staff):
                            $userId  = (int)($staff['user_id'] ?? 0);
                            $status  = $staff['status'] ?? 'inactive';
                            $photo   = !empty($staff['photo']) ? $staff['photo'] : null;
                            $first   = $staff['first_name'] ?? '';
                            $last    = $staff['last_name']  ?? '';
                            $initials = staffInitials($first, $last);
                            $avClass  = 'av-' . ($index % 8);
                        ?>
                            <tr id="staffRow<?= $userId ?>">
                                <td class="num"><?= $staffRowStart + $index ?></td>
                                <td>
                                    <div class="staff-cell">
                                        <?php if ($photo): ?>
                                            <img
                                                src="<?= htmlspecialchars($photo, ENT_QUOTES, 'UTF-8') ?>"
                                                alt="<?= htmlspecialchars($first . ' ' . $last, ENT_QUOTES, 'UTF-8') ?>"
                                                class="avatar"
                                                onerror="this.outerHTML='<div class=\'avatar-initials <?= $avClass ?>\'><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>';this.onerror=null;"
                                            >
                                        <?php else: ?>
                                            <div class="avatar-initials <?= $avClass ?>"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="staff-name"><?= htmlspecialchars(trim($first . ' ' . $last), ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="staff-email"><?= htmlspecialchars($staff['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td style="color:var(--c-text-2);font-family:var(--ff-mono);font-size:12px;">
                                    <?= htmlspecialchars($staff['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <?php
                                    $roleMap = ['admin' => 'badge-admin', 'staff' => 'badge-staff', 'cashier' => 'badge-cashier'];
                                    $roleClass = $roleMap[$staff['role'] ?? ''] ?? 'badge-staff';
                                    $roleIcons = ['admin' => 'bi-shield-check', 'staff' => 'bi-person', 'cashier' => 'bi-cash-stack'];
                                    $roleIcon  = $roleIcons[$staff['role'] ?? ''] ?? 'bi-person';
                                    ?>
                                    <span class="badge <?= $roleClass ?>">
                                        <i class="bi <?= $roleIcon ?>"></i>
                                        <?= htmlspecialchars(ucfirst($staff['role'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= $status === 'active' ? 'badge-active' : 'badge-inactive' ?>" id="staffStatus<?= $userId ?>">
                                        <i class="bi <?= $status === 'active' ? 'bi-circle-fill' : 'bi-circle' ?>" style="font-size:7px;"></i>
                                        <?= ucfirst($status) ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="display:flex;gap:6px;justify-content:center;">
                                        <button
                                            type="button"
                                            class="btn-icon-sm edit editStaffBtn"
                                            data-id="<?= $userId ?>"
                                            data-username="<?= htmlspecialchars($staff['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-firstname="<?= htmlspecialchars($first, ENT_QUOTES, 'UTF-8') ?>"
                                            data-lastname="<?= htmlspecialchars($last, ENT_QUOTES, 'UTF-8') ?>"
                                            data-email="<?= htmlspecialchars($staff['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-photo="<?= htmlspecialchars($photo ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-role="<?= htmlspecialchars($staff['role'] ?? 'staff', ENT_QUOTES, 'UTF-8') ?>"
                                            title="Edit staff"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editStaffModal"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </button>

                                        <button
                                            type="button"
                                            class="btn-icon-sm <?= $status === 'active' ? 'deactivate' : 'activate' ?> toggleStatusBtn"
                                            data-id="<?= $userId ?>"
                                            data-name="<?= htmlspecialchars(trim($first . ' ' . $last), ENT_QUOTES, 'UTF-8') ?>"
                                            data-status="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"
                                            title="<?= $status === 'active' ? 'Deactivate' : 'Reactivate' ?>"
                                        >
                                            <i class="bi <?= $status === 'active' ? 'bi-slash-circle' : 'bi-check-circle' ?>"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <i class="bi bi-people"></i>
                                    <p>No staff found matching your filters.</p>
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
                <?php if ($staffPage['total'] > 0): ?>
                    Showing <?= number_format($staffRowStart) ?>–<?= number_format(min($staffRowStart + count($staffs) - 1, $staffPage['total'])) ?> of <?= number_format($staffPage['total']) ?> staff
                <?php else: ?>
                    No results
                <?php endif; ?>
            </span>
            <nav aria-label="Staff pagination">
                <ul class="pagination">
                    <li class="page-item <?= $staffPage['page'] <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(staffListUrl($staffFilters, ['page' => $staffPage['page'] - 1]), ENT_QUOTES, 'UTF-8') ?>" aria-label="Previous">
                            <i class="bi bi-chevron-left" style="font-size:11px;"></i>
                        </a>
                    </li>
                    <?php
                    $staffStartPage = max(1, $staffPage['page'] - 2);
                    $staffEndPage   = min($staffPage['total_pages'], $staffPage['page'] + 2);
                    for ($p = $staffStartPage; $p <= $staffEndPage; $p++):
                    ?>
                        <li class="page-item <?= $p === $staffPage['page'] ? 'active' : '' ?>">
                            <a class="page-link" href="<?= htmlspecialchars(staffListUrl($staffFilters, ['page' => $p]), ENT_QUOTES, 'UTF-8') ?>"><?= $p ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $staffPage['page'] >= $staffPage['total_pages'] ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(staffListUrl($staffFilters, ['page' => $staffPage['page'] + 1]), ENT_QUOTES, 'UTF-8') ?>" aria-label="Next">
                            <i class="bi bi-chevron-right" style="font-size:11px;"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
    </div><!-- /.staff-card -->

    <!-- ══════════════════════════════════════════════════════════
         ADD STAFF MODAL
    ═══════════════════════════════════════════════════════════ -->
    <div class="modal fade modal-modern" id="addStaffModal" tabindex="-1" aria-labelledby="addStaffModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <form id="addStaffForm" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="modal-header">
                        <div>
                            <div class="modal-title" id="addStaffModalLabel">
                                <i class="bi bi-person-plus me-2" style="color:var(--c-accent);"></i>Add new staff
                            </div>
                            <div class="modal-subtitle">Create a new team member account and assign their role.</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-4">
                            <!-- Photo -->
                            <div class="col-lg-3">
                                <div class="photo-panel">
                                    <img id="addStaffPhotoPreview" src="/inventory_system/assets/img/default-user.png" alt="Preview" class="preview-image">
                                    <div style="width:100%">
                                        <label class="form-label">Photo (optional)</label>
                                        <input type="file" class="form-control" name="photo" accept="image/*" id="addStaffPhotoInput">
                                    </div>
                                </div>
                            </div>

                            <!-- Fields -->
                            <div class="col-lg-9">
                                <div class="modal-section-title">Basic information</div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">First name <span style="color:var(--c-red)">*</span></label>
                                        <input type="text" class="form-control" name="firstname" autocomplete="given-name" required placeholder="Juan">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Last name <span style="color:var(--c-red)">*</span></label>
                                        <input type="text" class="form-control" name="lastname" autocomplete="family-name" required placeholder="dela Cruz">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Email <span style="color:var(--c-red)">*</span></label>
                                        <input type="email" class="form-control" name="email" autocomplete="email" required placeholder="juan@store.com">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Username <span style="color:var(--c-red)">*</span></label>
                                        <input type="text" class="form-control" name="username" autocomplete="username" required placeholder="jdelacruz">
                                    </div>

                                    <div class="col-12 mt-1">
                                        <div class="modal-section-title">Access settings</div>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Password <span style="color:var(--c-red)">*</span></label>
                                        <input type="password" class="form-control" name="password" autocomplete="new-password" required placeholder="Min. 8 characters">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Role <span style="color:var(--c-red)">*</span></label>
                                        <select class="form-select" name="role" required>
                                            <option value="staff" selected>Staff</option>
                                            <option value="admin">Admin</option>
                                            <option value="cashier">Cashier</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer justify-content-end gap-2">
                        <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-person-plus"></i> Add staff
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         EDIT STAFF MODAL
    ═══════════════════════════════════════════════════════════ -->
    <div class="modal fade modal-modern" id="editStaffModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <form id="editStaffForm" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="staff_id" id="editStaffId">

                    <div class="modal-header">
                        <div>
                            <div class="modal-title">
                                <i class="bi bi-pencil-square me-2" style="color:var(--c-amber);"></i>Edit staff
                            </div>
                            <div class="modal-subtitle">Update account details, role, or photo.</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-4">
                            <div class="col-lg-3">
                                <div class="photo-panel">
                                    <img id="editStaffPhotoPreview" src="/inventory_system/assets/img/default-user.png" alt="Preview" class="preview-image">
                                    <div style="width:100%">
                                        <label class="form-label">New photo</label>
                                        <input type="file" class="form-control" name="photo" accept="image/*" id="editStaffPhotoInput">
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-9">
                                <div class="modal-section-title">Basic information</div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">First name <span style="color:var(--c-red)">*</span></label>
                                        <input type="text" class="form-control" name="firstname" id="editFirstname" autocomplete="given-name" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Last name <span style="color:var(--c-red)">*</span></label>
                                        <input type="text" class="form-control" name="lastname" id="editLastname" autocomplete="family-name" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Email <span style="color:var(--c-red)">*</span></label>
                                        <input type="email" class="form-control" name="email" id="editEmail" autocomplete="email" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Username <span style="color:var(--c-red)">*</span></label>
                                        <input type="text" class="form-control" name="username" id="editUsername" autocomplete="username" required>
                                    </div>

                                    <div class="col-12 mt-1">
                                        <div class="modal-section-title">Access settings</div>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">New password <span style="color:var(--c-text-3);font-weight:400;text-transform:none;letter-spacing:0;">(leave blank to keep)</span></label>
                                        <input type="password" class="form-control" name="password" id="editPassword" autocomplete="new-password" placeholder="Leave blank to keep current">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Role <span style="color:var(--c-red)">*</span></label>
                                        <select class="form-select" name="role" id="editRole" required>
                                            <option value="staff">Staff</option>
                                            <option value="admin">Admin</option>
                                            <option value="cashier">Cashier</option>
                                        </select>
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

</main>

<?php require __DIR__ . '/../components/footer.php'; ?>
<?php require __DIR__ . '/../components/js_script.php'; ?>
<script src="/inventory_system/assets/js/manage_staff.js"></script>

</body>
</html>
