<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/PurchaseOrderController.php';
require_once __DIR__ . '/../controllers/ListQueryHelper.php';

Middleware::auth()->role(['admin']);

// ── Page helpers ──────────────────────────────────────────────────────────────
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function historyListUrl(array $filters, array $overrides = []): string
{
    $params = array_merge($filters, $overrides);
    if (($params['page']        ?? 1)     <= 1)      unset($params['page']);
    if (($params['search']      ?? '')    === '')     unset($params['search']);
    if (($params['status']      ?? 'all') === 'all')  unset($params['status']);
    if (($params['supplier_id'] ?? 0)     <= 0)       unset($params['supplier_id']);
    if (($params['date_from']   ?? '')    === '')      unset($params['date_from']);
    if (($params['date_to']     ?? '')    === '')      unset($params['date_to']);
    if (($params['per_page']    ?? 25)    === 25)      unset($params['per_page']);
    $q = http_build_query($params);
    return '/inventory_system/admin/purchase_receiving_history.php' . ($q !== '' ? '?' . $q : '');
}

function historyStatusBadge(string $status): string
{
    return match (strtolower(trim($status))) {
        'ordered'   => 'badge-po-ordered',
        'partial'   => 'badge-po-partial',
        'received'  => 'badge-po-received',
        'cancelled' => 'badge-po-cancelled',
        default     => 'badge-po-draft',
    };
}

// ── Data loading ──────────────────────────────────────────────────────────────
try {
    PurchaseOrderController::ensureSchema($conn);

    $filters = [
        'search'      => trim((string) ($_GET['search']      ?? '')),
        'status'      => strtolower(trim((string) ($_GET['status']      ?? 'all'))),
        'supplier_id' => (int) ($_GET['supplier_id'] ?? 0),
        'date_from'   => trim((string) ($_GET['date_from'] ?? '')),
        'date_to'     => trim((string) ($_GET['date_to']   ?? '')),
        'page'        => max(1, (int) ($_GET['page']     ?? 1)),
        'per_page'    => (int) ($_GET['per_page'] ?? 25),
    ];

    $page    = PurchaseOrderController::paginateHistory($conn, $filters);
    $filters = array_merge($filters, [
        'search'      => (string) $page['search'],
        'status'      => (string) $page['status'],
        'supplier_id' => (int)    $page['supplier_id'],
        'page'        => (int)    $page['page'],
        'per_page'    => (int)    $page['per_page'],
    ]);
    $orders    = $page['items'];
    $rowStart  = $page['total'] > 0
        ? (($page['page'] - 1) * $page['per_page']) + 1
        : 0;

    // ONE batch query for all items on this page — eliminates N+1
    $poIds      = array_map(static fn(array $r): int => (int) ($r['po_id'] ?? 0), $orders);
    $itemsBatch = PurchaseOrderController::getPurchaseOrderItemsBatch($conn, $poIds);

    // Suppliers for filter dropdown
    $suppliers  = PurchaseOrderController::supplierOptions($conn);

    // Page-level aggregate stats (derived from current page totals
    // for display; not the full-table summary — that's in statusSummary)
    $statusSummary  = PurchaseOrderController::statusSummary($conn);

} catch (Throwable $e) {
    error_log('[purchase_receiving_history.php] ' . $e->getMessage());
    $errorMsg      = 'Failed to load receiving history.';
    $orders        = [];
    $itemsBatch    = [];
    $page          = ['total' => 0, 'page' => 1, 'per_page' => 25, 'total_pages' => 1];
    $filters       = ['search' => '', 'status' => 'all', 'supplier_id' => 0, 'date_from' => '', 'date_to' => '', 'page' => 1, 'per_page' => 25];
    $rowStart      = 0;
    $suppliers     = [];
    $statusSummary = ['ordered' => 0, 'partial' => 0, 'received' => 0, 'cancelled' => 0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/../components/head.php'; ?>
    <title>Purchase Receiving History</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        /* ── Design tokens (unified across all management pages) ──────── */
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

        /* stat cards */
        .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem}
        @media(max-width:900px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
        .stat-card{background:var(--c-surface);border:1px solid var(--c-border);border-radius:var(--radius-lg);padding:1.1rem 1.25rem;display:flex;align-items:flex-start;gap:1rem;box-shadow:var(--shadow-sm)}
        .stat-icon{width:40px;height:40px;border-radius:var(--radius-md);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
        .stat-body{flex:1;min-width:0}
        .stat-label{font-size:12px;color:var(--c-text-2);font-weight:500;margin-bottom:2px}
        .stat-val{font-size:26px;font-weight:600;letter-spacing:-.5px;line-height:1.1}

        /* section card */
        .rh-card{background:var(--c-surface);border:1px solid var(--c-border);border-radius:var(--radius-xl);box-shadow:var(--shadow-sm);overflow:hidden;margin-bottom:1.5rem}
        .rh-card-head{display:flex;align-items:center;justify-content:space-between;padding:1.25rem 1.5rem 0;flex-wrap:wrap;gap:.75rem}
        .rh-card-title{font-size:16px;font-weight:600}
        .rh-card-sub{font-size:12px;color:var(--c-text-3);margin-top:2px}

        /* filter bar */
        .filter-bar{display:flex;align-items:flex-end;gap:.75rem;padding:1rem 1.5rem;border-bottom:1px solid var(--c-border);flex-wrap:wrap}
        .filter-item{display:flex;flex-direction:column;gap:4px}
        .filter-item.grow{flex:1;min-width:200px}
        .filter-label{font-size:11px;font-weight:600;letter-spacing:.04em;color:var(--c-text-3);text-transform:uppercase}
        .search-wrap{display:flex;align-items:center;gap:8px;height:36px;padding:0 12px;background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:var(--radius-md);transition:border-color .15s,box-shadow .15s}
        .search-wrap:focus-within{border-color:var(--c-accent);box-shadow:0 0 0 3px rgba(37,99,235,.1)}
        .search-wrap i{color:var(--c-text-3);font-size:15px;flex-shrink:0}
        .search-wrap input{border:none;background:none;outline:none;font-family:var(--ff-base);font-size:13px;color:var(--c-text-1);width:100%}
        .search-wrap input::placeholder{color:var(--c-text-3)}
        select.filter-select,input.filter-input{height:36px;padding:0 12px;background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:var(--radius-md);font-family:var(--ff-base);font-size:13px;color:var(--c-text-1);transition:border-color .15s,box-shadow .15s}
        select.filter-select{padding-right:32px;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%239a9691'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;appearance:none;-webkit-appearance:none;cursor:pointer}
        select.filter-select:focus,input.filter-input:focus{outline:none;border-color:var(--c-accent);box-shadow:0 0 0 3px rgba(37,99,235,.1)}

        /* buttons */
        .btn{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 16px;border-radius:var(--radius-md);font-family:var(--ff-base);font-size:13px;font-weight:500;cursor:pointer;border:1px solid transparent;transition:all .15s;white-space:nowrap;text-decoration:none}
        .btn-primary{background:var(--c-accent);color:#fff;border-color:var(--c-accent)}
        .btn-primary:hover{background:#1d4ed8;border-color:#1d4ed8}
        .btn-outline{background:var(--c-surface);color:var(--c-text-2);border-color:var(--c-border-2)}
        .btn-outline:hover{background:var(--c-surface-2);color:var(--c-text-1)}
        .btn-sm{height:28px;padding:0 10px;font-size:12px;border-radius:var(--radius-sm)}

        /* table */
        .table-wrap{overflow-x:auto}
        table.rh-table{width:100%;border-collapse:collapse}
        .rh-table thead tr{border-bottom:1px solid var(--c-border)}
        .rh-table th{padding:10px 16px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);text-align:left;white-space:nowrap;background:var(--c-surface-2)}
        .rh-table tbody tr{border-bottom:1px solid var(--c-border);transition:background .1s}
        .rh-table tbody tr:last-child{border-bottom:none}
        .rh-table tbody tr:hover{background:var(--c-surface-2)}
        .rh-table td{padding:12px 16px;font-size:13px;vertical-align:middle}
        .rh-table td.num{color:var(--c-text-3);font-size:12px;font-family:var(--ff-mono)}
        .po-number{font-family:var(--ff-mono);font-weight:600;font-size:13px}
        .po-meta{font-size:11px;color:var(--c-text-3);margin-top:2px}
        .po-qty{font-family:var(--ff-mono);font-size:13px;font-weight:500}

        /* progress bar */
        .progress-wrap{width:100%;background:var(--c-border);border-radius:99px;height:6px;overflow:hidden;min-width:80px}
        .progress-bar{height:100%;border-radius:99px;background:var(--c-green);transition:width .3s}
        .progress-bar.is-partial{background:var(--c-amber)}
        .progress-bar.is-full{background:var(--c-green)}

        /* badges */
        .badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:99px;font-size:11px;font-weight:600;letter-spacing:.02em;border:1px solid transparent}
        .badge-po-ordered  {background:var(--c-accent-bg);color:var(--c-accent);border-color:var(--c-accent-bd)}
        .badge-po-partial  {background:var(--c-amber-bg); color:var(--c-amber); border-color:var(--c-amber-bd)}
        .badge-po-received {background:var(--c-green-bg); color:var(--c-green); border-color:var(--c-green-bd)}
        .badge-po-cancelled{background:var(--c-surface-2);color:var(--c-text-3);border-color:var(--c-border)}
        .badge-po-draft    {background:var(--c-purple-bg);color:var(--c-purple);border-color:var(--c-purple-bd)}
        .badge-supplier    {background:var(--c-accent-bg);color:var(--c-accent);border-color:var(--c-accent-bd);font-size:11px}

        /* pagination */
        .pagination-bar{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.5rem;border-top:1px solid var(--c-border);flex-wrap:wrap;gap:.75rem}
        .pag-info{font-size:12px;color:var(--c-text-3)}
        .pagination{display:flex;list-style:none;margin:0;padding:0;gap:3px}
        .page-item .page-link{display:flex;align-items:center;justify-content:center;min-width:30px;height:30px;padding:0 8px;border-radius:var(--radius-sm);border:1px solid var(--c-border);background:var(--c-surface);color:var(--c-text-2);font-size:12px;text-decoration:none;transition:all .15s;font-family:var(--ff-mono)}
        .page-item .page-link:hover{background:var(--c-surface-2);color:var(--c-text-1)}
        .page-item.active .page-link{background:var(--c-accent);color:#fff;border-color:var(--c-accent)}
        .page-item.disabled .page-link{opacity:.4;pointer-events:none}

        /* empty state */
        .empty-state{text-align:center;padding:3.5rem 1rem;color:var(--c-text-3)}
        .empty-state i{font-size:40px;display:block;margin-bottom:.75rem;opacity:.4}
        .empty-state p{font-size:14px}

        /* modal */
        .modal-modern .modal-content{border:1px solid var(--c-border);border-radius:var(--radius-xl);box-shadow:var(--shadow-lg);font-family:var(--ff-base);overflow:hidden}
        .modal-modern .modal-header{border-bottom:1px solid var(--c-border);padding:1.1rem 1.5rem;background:var(--c-surface-2)}
        .modal-modern .modal-title{font-size:16px;font-weight:600;letter-spacing:-.2px}
        .modal-modern .modal-subtitle{font-size:12px;color:var(--c-text-3);margin-top:2px}
        .modal-modern .modal-body{padding:1.5rem}
        .modal-modern .modal-footer{border-top:1px solid var(--c-border);padding:1rem 1.5rem;background:var(--c-surface-2)}
        .detail-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem;margin-bottom:1rem}
        .detail-tile{background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:var(--radius-md);padding:.75rem 1rem}
        .detail-tile-label{font-size:11px;color:var(--c-text-3);font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px}
        .detail-tile-val{font-size:15px;font-weight:600;color:var(--c-text-1)}
        .detail-tile-sub{font-size:11px;color:var(--c-text-3);margin-top:2px}

        @media(max-width:768px){#main{padding:1rem}.filter-bar{flex-direction:column;align-items:stretch}.detail-grid{grid-template-columns:1fr 1fr}.stats-grid{grid-template-columns:1fr 1fr}}
    </style>
</head>
<body>

<?php
require __DIR__ . '/../components/header.php';
require __DIR__ . '/../components/sidebar.php';
?>

<main id="main" class="main">
    <div class="pagetitle">
        <h1>Purchase Receiving History</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/inventory_system/index.php">Home</a></li>
                <li class="breadcrumb-item"><a href="/inventory_system/admin/purchase_orders.php">Purchase Orders</a></li>
                <li class="breadcrumb-item active">Receiving History</li>
            </ol>
        </nav>
    </div>

    <?php if (!empty($errorMsg)): ?>
        <div class="alert alert-danger mb-3" style="border-radius:var(--radius-md);font-size:13px;" role="alert">
            <i class="bi bi-exclamation-triangle me-2" aria-hidden="true"></i><?= e($errorMsg) ?>
        </div>
    <?php endif; ?>

    <!-- ── Stat cards ──────────────────────────────────────────────────────── -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--c-accent-bg);color:var(--c-accent);">
                <i class="bi bi-bag-fill" aria-hidden="true"></i>
            </div>
            <div class="stat-body">
                <div class="stat-label">Ordered</div>
                <div class="stat-val" style="color:var(--c-accent);"><?= number_format((int) ($statusSummary['ordered'] ?? 0)) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--c-amber-bg);color:var(--c-amber);">
                <i class="bi bi-hourglass-split" aria-hidden="true"></i>
            </div>
            <div class="stat-body">
                <div class="stat-label">Partial receipts</div>
                <div class="stat-val" style="color:var(--c-amber);"><?= number_format((int) ($statusSummary['partial'] ?? 0)) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--c-green-bg);color:var(--c-green);">
                <i class="bi bi-check2-circle" aria-hidden="true"></i>
            </div>
            <div class="stat-body">
                <div class="stat-label">Fully received</div>
                <div class="stat-val" style="color:var(--c-green);"><?= number_format((int) ($statusSummary['received'] ?? 0)) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--c-teal-bg);color:var(--c-teal);">
                <i class="bi bi-box-seam" aria-hidden="true"></i>
            </div>
            <div class="stat-body">
                <div class="stat-label">Matching this filter</div>
                <div class="stat-val" style="color:var(--c-teal);"><?= number_format($page['total']) ?></div>
            </div>
        </div>
    </div>

    <!-- ── Main card ───────────────────────────────────────────────────────── -->
    <div class="rh-card">
        <div class="rh-card-head">
            <div>
                <div class="rh-card-title">Receiving timeline</div>
                <div class="rh-card-sub">
                    Track supplier deliveries, review partial receipts, and open the full line-item breakdown.
                </div>
            </div>
        </div>

        <!-- Filter bar — all server-side -->
        <form method="get" class="filter-bar">
            <div class="filter-item grow">
                <span class="filter-label">Search</span>
                <div class="search-wrap">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="text"
                           name="search"
                           id="rhSearchInput"
                           value="<?= e($filters['search']) ?>"
                           placeholder="PO number, supplier, or creator…"
                           autocomplete="off">
                </div>
            </div>
            <div class="filter-item">
                <span class="filter-label">Status</span>
                <select name="status" class="filter-select">
                    <option value="all"       <?= $filters['status'] === 'all'       ? 'selected' : '' ?>>All status</option>
                    <option value="ordered"   <?= $filters['status'] === 'ordered'   ? 'selected' : '' ?>>Ordered</option>
                    <option value="partial"   <?= $filters['status'] === 'partial'   ? 'selected' : '' ?>>Partial</option>
                    <option value="received"  <?= $filters['status'] === 'received'  ? 'selected' : '' ?>>Received</option>
                    <option value="cancelled" <?= $filters['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="filter-item grow">
                <span class="filter-label">Supplier</span>
                <select name="supplier_id" class="filter-select">
                    <option value="0">All suppliers</option>
                    <?php foreach ($suppliers as $sup): ?>
                        <option value="<?= (int) ($sup['supplier_id'] ?? 0) ?>"
                            <?= $filters['supplier_id'] === (int) ($sup['supplier_id'] ?? 0) ? 'selected' : '' ?>>
                            <?= e((string) ($sup['supplier_name'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-item">
                <span class="filter-label">From</span>
                <input type="date" name="date_from" class="filter-input" value="<?= e($filters['date_from']) ?>">
            </div>
            <div class="filter-item">
                <span class="filter-label">To</span>
                <input type="date" name="date_to" class="filter-input" value="<?= e($filters['date_to']) ?>">
            </div>
            <div class="filter-item">
                <span class="filter-label">Per page</span>
                <select name="per_page" class="filter-select">
                    <?php foreach ([10, 25, 50, 100] as $sz): ?>
                        <option value="<?= $sz ?>" <?= $filters['per_page'] === $sz ? 'selected' : '' ?>><?= $sz ?></option>
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
            <table class="rh-table" id="rhTable">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th>PO Number</th>
                        <th>Supplier</th>
                        <th>Ordered at</th>
                        <th>Received at</th>
                        <th style="text-align:right;">Lines</th>
                        <th style="text-align:right;">Ordered</th>
                        <th style="text-align:right;">Received</th>
                        <th style="min-width:120px;">Progress</th>
                        <th>Status</th>
                        <th style="text-align:center;width:80px;">Detail</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($orders)): ?>
                        <?php foreach ($orders as $idx => $order):
                            $poId       = (int) ($order['po_id'] ?? 0);
                            $status     = strtolower($order['status'] ?? 'ordered');
                            $ordTot     = max(0, (int) ($order['ordered_total']  ?? 0));
                            $recTot     = max(0, (int) ($order['received_total'] ?? 0));
                            $progress   = $ordTot > 0 ? (int) round(min(100, ($recTot / $ordTot) * 100)) : 0;
                            $isFull     = $progress >= 100;
                            $rowItems   = $itemsBatch[$poId] ?? [];
                        ?>
                            <tr>
                                <td class="num"><?= $rowStart + $idx ?></td>

                                <td>
                                    <div class="po-number"><?= e($order['po_number'] ?? '—') ?></div>
                                    <div class="po-meta"><?= e($order['created_by_username'] ?? 'System') ?></div>
                                </td>

                                <td>
                                    <span class="badge badge-supplier">
                                        <?= e($order['supplier_name'] ?? '—') ?>
                                    </span>
                                </td>

                                <td style="font-size:12px;color:var(--c-text-2);">
                                    <?= e(date('M d, Y', strtotime((string) ($order['ordered_at'] ?? 'now')))) ?>
                                    <div class="po-meta"><?= e(date('h:i A', strtotime((string) ($order['ordered_at'] ?? 'now')))) ?></div>
                                </td>

                                <td style="font-size:12px;color:var(--c-text-2);">
                                    <?php if (!empty($order['received_at'])): ?>
                                        <?= e(date('M d, Y', strtotime((string) $order['received_at']))) ?>
                                        <div class="po-meta"><?= e(date('h:i A', strtotime((string) $order['received_at']))) ?></div>
                                    <?php else: ?>
                                        <span style="color:var(--c-text-3);">Pending</span>
                                    <?php endif; ?>
                                </td>

                                <td style="text-align:right;" class="po-qty">
                                    <?= number_format((int) ($order['item_lines'] ?? 0)) ?>
                                </td>
                                <td style="text-align:right;" class="po-qty">
                                    <?= number_format($ordTot) ?>
                                </td>
                                <td style="text-align:right;" class="po-qty">
                                    <?= number_format($recTot) ?>
                                </td>

                                <td>
                                    <div class="progress-wrap" title="<?= $progress ?>% received" aria-label="<?= $progress ?>% received">
                                        <div class="progress-bar <?= $isFull ? 'is-full' : ($progress > 0 ? 'is-partial' : '') ?>"
                                             style="width:<?= $progress ?>%"></div>
                                    </div>
                                    <div style="font-size:11px;color:var(--c-text-3);margin-top:4px;"><?= $progress ?>%</div>
                                </td>

                                <td>
                                    <span class="badge <?= historyStatusBadge($status) ?>">
                                        <i class="bi bi-circle-fill" style="font-size:7px;" aria-hidden="true"></i>
                                        <?= ucfirst(e($status)) ?>
                                    </span>
                                </td>

                                <td style="text-align:center;">
                                    <!--
                                        JS reads ALL data-* attributes from this button:
                                        data-po-id, data-po-number, data-supplier, data-status,
                                        data-ordered-at, data-received-at,
                                        data-ordered, data-received, data-progress,
                                        data-created-by, data-received-by,
                                        data-items (JSON array of item objects)
                                    -->
                                    <button type="button"
                                            class="btn btn-outline btn-sm rh-detail-btn"
                                            data-po-id="<?= $poId ?>"
                                            data-po-number="<?= e($order['po_number'] ?? '') ?>"
                                            data-supplier="<?= e($order['supplier_name'] ?? '') ?>"
                                            data-status="<?= e($status) ?>"
                                            data-ordered-at="<?= e($order['ordered_at'] ?? '') ?>"
                                            data-received-at="<?= e($order['received_at'] ?? '') ?>"
                                            data-ordered="<?= $ordTot ?>"
                                            data-received="<?= $recTot ?>"
                                            data-progress="<?= $progress ?>"
                                            data-created-by="<?= e($order['created_by_username']  ?? '') ?>"
                                            data-received-by="<?= e($order['received_by_username'] ?? '') ?>"
                                            data-items="<?= e(json_encode($rowItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>"
                                            aria-label="View detail for <?= e($order['po_number'] ?? 'PO') ?>">
                                        <i class="bi bi-eye" aria-hidden="true"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="11">
                                <div class="empty-state">
                                    <i class="bi bi-box-seam" aria-hidden="true"></i>
                                    <p>No purchase orders match the current filters.</p>
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
                <?php if ($page['total'] > 0): ?>
                    Showing <?= number_format($rowStart) ?>–<?= number_format(min($rowStart + count($orders) - 1, $page['total'])) ?>
                    of <?= number_format($page['total']) ?> orders
                <?php else: ?>
                    No results
                <?php endif; ?>
            </span>
            <nav aria-label="Receiving history pagination">
                <ul class="pagination">
                    <li class="page-item <?= $page['page'] <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e(historyListUrl($filters, ['page' => $page['page'] - 1])) ?>" aria-label="Previous">
                            <i class="bi bi-chevron-left" style="font-size:11px;" aria-hidden="true"></i>
                        </a>
                    </li>
                    <?php
                    $pStart = max(1, $page['page'] - 2);
                    $pEnd   = min($page['total_pages'], $page['page'] + 2);
                    for ($pn = $pStart; $pn <= $pEnd; $pn++):
                    ?>
                        <li class="page-item <?= $pn === $page['page'] ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e(historyListUrl($filters, ['page' => $pn])) ?>"><?= $pn ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page['page'] >= $page['total_pages'] ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e(historyListUrl($filters, ['page' => $page['page'] + 1])) ?>" aria-label="Next">
                            <i class="bi bi-chevron-right" style="font-size:11px;" aria-hidden="true"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>

    </div><!-- /.rh-card -->
</main>


<!-- ═══════════════════════════════════════════════════════════════════════
     RECEIVING DETAIL MODAL
     JS element refs:
       id="rhDetailModal"       — bootstrap.Modal target
       id="rhModalTitle"        — po_number heading
       id="rhModalSubtitle"     — supplier + status subtitle
       id="rhModalMeta"         — detail-grid with 4 tiles
       id="rhModalItems"        — tbody; JS appends item rows
════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade modal-modern" id="rhDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <div class="modal-title" id="rhModalTitle">Purchase Order</div>
                    <div class="modal-subtitle" id="rhModalSubtitle">Line-item receipt breakdown</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- JS fills this with 4 detail tiles -->
                <div class="detail-grid" id="rhModalMeta"></div>

                <!-- Items table — JS builds rows into tbody -->
                <div class="table-wrap" style="border:1px solid var(--c-border);border-radius:var(--radius-lg);overflow:hidden;">
                    <table class="rh-table" style="margin:0;">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th style="text-align:right;">Ordered</th>
                                <th style="text-align:right;">Received</th>
                                <th style="text-align:right;">Remaining</th>
                                <th style="min-width:100px;">Progress</th>
                            </tr>
                        </thead>
                        <tbody id="rhModalItems"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer justify-content-end">
                <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>


<?php require __DIR__ . '/../components/js_script.php'; ?>
<script src="/inventory_system/assets/js/purchase-receiving-history.js"></script>
</body>
</html>