<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/PurchaseOrderController.php';
require_once __DIR__ . '/../controllers/NotificationController.php';
require_once __DIR__ . '/../controllers/AuthController.php';
require_once __DIR__ . '/../controllers/ListQueryHelper.php';

Middleware::auth()->role(['admin']);

$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
$csrf_token    = Middleware::generateCsrfToken();

// ── Flash messages ────────────────────────────────────────────────────────────
$successMsg = null;
$errorMsg   = null;
if (isset($_SESSION['purchase_order_flash']) && is_array($_SESSION['purchase_order_flash'])) {
    $flash      = $_SESSION['purchase_order_flash'];
    $successMsg = isset($flash['success']) ? (string) $flash['success'] : null;
    $errorMsg   = isset($flash['error'])   ? (string) $flash['error']   : null;
    unset($_SESSION['purchase_order_flash']);
}

// ── Page helpers ──────────────────────────────────────────────────────────────
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function buildLogConfig(): array
{
    return [
        'table'       => $GLOBALS['table_activity_logs']  ?? 'activity_logs',
        'col_user_id' => $GLOBALS['activity_log_user_id'] ?? 'user_id',
        'col_action'  => $GLOBALS['activity_log_action']  ?? 'action',
        'col_desc'    => $GLOBALS['activity_log_desc']    ?? 'description',
        'col_ip'      => $GLOBALS['activity_log_ip']      ?? 'ip_address',
        'col_created' => $GLOBALS['activity_log_created'] ?? 'created_at',
    ];
}

function notify(PDO $conn, int $userId, string $type, string $title, string $message, string $icon = 'bi-bag', string $color = 'text-primary'): void
{
    try {
        NotificationController::create(
            $conn, $userId, 'admin', $type, $title, $message, $icon, $color,
            '/inventory_system/admin/purchase_orders.php'
        );
    } catch (Throwable $e) {
        error_log('[purchase_orders notify] ' . $e->getMessage());
    }
}

function poListUrl(array $filters, array $overrides = []): string
{
    $params = array_merge($filters, $overrides);
    if (($params['page']        ?? 1)     <= 1)     unset($params['page']);
    if (($params['status']      ?? 'all') === 'all') unset($params['status']);
    if (($params['supplier_id'] ?? 0)     <= 0)      unset($params['supplier_id']);
    if (($params['date_from']   ?? '')    === '')     unset($params['date_from']);
    if (($params['date_to']     ?? '')    === '')     unset($params['date_to']);
    if (($params['per_page']    ?? 25)    === 25)     unset($params['per_page']);
    $q = http_build_query($params);
    return '/inventory_system/admin/purchase_orders.php' . ($q !== '' ? '?' . $q : '');
}

// JS expects these exact badge class names
function poStatusBadge(string $status): string
{
    return match (strtolower(trim($status))) {
        'ordered'   => 'badge-po-ordered',
        'partial'   => 'badge-po-partial',
        'received'  => 'badge-po-received',
        'cancelled' => 'badge-po-cancelled',
        default     => 'badge-po-draft',
    };
}

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        PurchaseOrderController::ensureSchema($conn);

        if (!AuthController::validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Invalid request token. Refresh the page and try again.');
        }

        $logConfig = buildLogConfig();

        // ── Create purchase order ─────────────────────────────────────────────
        if (isset($_POST['create_purchase_order'])) {
            $supplierId = (int) ($_POST['supplier_id'] ?? 0);
            $itemsInput = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];
            $items      = [];

            foreach ($itemsInput as $item) {
                if (!is_array($item) || empty($item['selected'])) {
                    continue;
                }
                $items[] = [
                    'product_id' => (int)    ($item['product_id'] ?? 0),
                    'quantity'   => (int)    ($item['quantity']   ?? 0),
                    'notes'      => (string) ($item['notes']      ?? ''),
                ];
            }

            $created = PurchaseOrderController::createPurchaseOrder(
                $conn, $supplierId, $items, $sessionUserId, (string) ($_POST['notes'] ?? '')
            );

            AuthController::logActivity(
                $conn, $logConfig, $sessionUserId, 'purchase_order_create',
                sprintf('Created %s for %s with %d line(s).', $created['po_number'], $created['supplier_name'], (int) $created['item_count']),
                'purchase_order', (int) $created['po_id']
            );

            notify($conn, $sessionUserId, 'purchase_order', 'Purchase Order Created',
                sprintf('%s created for %s.', $created['po_number'], $created['supplier_name']),
                'bi-bag-check', 'text-primary');

            $_SESSION['purchase_order_flash'] = ['success' => $created['po_number'] . ' created successfully.'];
            header('Location: /inventory_system/admin/purchase_orders.php');
            exit;
        }

        // ── Receive purchase order ────────────────────────────────────────────
        if (isset($_POST['receive_purchase_order'])) {
            $poId     = (int) ($_POST['po_id'] ?? 0);
            $received = is_array($_POST['received'] ?? null) ? $_POST['received'] : [];
            $notes    = (string) ($_POST['receive_notes'] ?? '');

            $result = PurchaseOrderController::receivePurchaseOrder(
                $conn, $poId, $received, $sessionUserId, $notes
            );

            AuthController::logActivity(
                $conn, $logConfig, $sessionUserId, 'purchase_order_receive',
                sprintf('Received %d line(s)/%d pcs for %s. Status: %s.',
                    (int) $result['received_lines'], (int) $result['received_pieces'],
                    $result['po_number'], ucfirst((string) $result['status'])),
                'purchase_order', (int) $result['po_id']
            );

            notify($conn, $sessionUserId, 'purchase_order_receive', 'Purchase Order Received',
                sprintf('%s updated to %s.', $result['po_number'], ucfirst((string) $result['status'])),
                'bi-box-arrow-in-down', 'text-success');

            $_SESSION['purchase_order_flash'] = ['success' => $result['po_number'] . ' updated successfully.'];
            header('Location: /inventory_system/admin/purchase_orders.php'
                . ($_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
            exit;
        }

        // ── Cancel purchase order ─────────────────────────────────────────────
        if (isset($_POST['cancel_purchase_order'])) {
            $poId   = (int) ($_POST['po_id']          ?? 0);
            $reason = trim((string) ($_POST['cancel_reason'] ?? ''));

            $result = PurchaseOrderController::cancelPurchaseOrder(
                $conn, $poId, $sessionUserId, $reason
            );

            AuthController::logActivity(
                $conn, $logConfig, $sessionUserId, 'purchase_order_cancel',
                sprintf('Cancelled %s (was: %s). Supplier: %s.%s',
                    $result['po_number'],
                    ucfirst($result['previous_status']),
                    $result['supplier_name'],
                    $reason !== '' ? ' Reason: ' . $reason : ''),
                'purchase_order', $poId
            );

            notify($conn, $sessionUserId, 'purchase_order_cancel', 'Purchase Order Cancelled',
                sprintf('%s has been cancelled.', $result['po_number']),
                'bi-x-circle', 'text-danger');

            $_SESSION['purchase_order_flash'] = [
                'success' => $result['po_number'] . ' has been cancelled.',
            ];
            header('Location: /inventory_system/admin/purchase_orders.php'
                . ($_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
            exit;
        }

    } catch (Throwable $e) {
        error_log('[purchase_orders.php POST] ' . $e->getMessage());
        $_SESSION['purchase_order_flash'] = ['error' => $e->getMessage()];
        header('Location: /inventory_system/admin/purchase_orders.php');
        exit;
    }
}

// ── Data loading ──────────────────────────────────────────────────────────────
try {
    PurchaseOrderController::ensureSchema($conn);

    $poFilters = [
        'status'      => strtolower(trim((string) ($_GET['status']      ?? 'all'))),
        'supplier_id' => (int) ($_GET['supplier_id'] ?? 0),
        'date_from'   => trim((string) ($_GET['date_from'] ?? '')),
        'date_to'     => trim((string) ($_GET['date_to']   ?? '')),
        'page'        => max(1, (int) ($_GET['page']     ?? 1)),
        'per_page'    => (int) ($_GET['per_page'] ?? 25),
    ];

    $poPage    = PurchaseOrderController::paginate($conn, $poFilters);
    $poFilters = array_merge($poFilters, [
        'status'      => (string) $poPage['status'],
        'supplier_id' => (int)    $poPage['supplier_id'],
        'page'        => (int)    $poPage['page'],
        'per_page'    => (int)    $poPage['per_page'],
    ]);
    $poItems    = $poPage['items'];
    $poRowStart = $poPage['total'] > 0
        ? (($poPage['page'] - 1) * $poPage['per_page']) + 1
        : 0;

    // ONE batch query for all items on this page — no N+1 loop
    // JS reads items from data-items attribute on each button
    $poIds      = array_map(static fn(array $r): int => (int) ($r['po_id'] ?? 0), $poItems);
    $itemsBatch = PurchaseOrderController::getPurchaseOrderItemsBatch($conn, $poIds);

    $statusSummary  = PurchaseOrderController::statusSummary($conn);
    $suppliers      = PurchaseOrderController::supplierOptions($conn);
    $lowStockRows   = PurchaseOrderController::lowStockCandidates($conn);
    $orderableRows  = array_values(array_filter($lowStockRows, static fn(array $r): bool => (int) ($r['supplier_id'] ?? 0) > 0));
    $unassignedRows = array_values(array_filter($lowStockRows, static fn(array $r): bool => (int) ($r['supplier_id'] ?? 0) <= 0));

} catch (Throwable $e) {
    error_log('[purchase_orders.php load] ' . $e->getMessage());
    $errorMsg       = 'Failed to load purchase order data: ' . $e->getMessage();
    $poItems        = [];
    $itemsBatch     = [];
    $poPage         = ['total' => 0, 'page' => 1, 'per_page' => 25, 'total_pages' => 1];
    $poFilters      = ['status' => 'all', 'supplier_id' => 0, 'date_from' => '', 'date_to' => '', 'page' => 1, 'per_page' => 25];
    $poRowStart     = 0;
    $statusSummary  = ['ordered' => 0, 'partial' => 0, 'received' => 0, 'cancelled' => 0];
    $suppliers      = [];
    $orderableRows  = [];
    $unassignedRows = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/../components/head.php'; ?>
    <title>Purchase Orders</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        /* ── Design tokens (unified with manage_staff / product / category) ── */
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

        /* ── Layout ────────────────────────────────────────────────────── */
        #main{padding:1.5rem 2rem 3rem}
        .pagetitle h1{font-size:22px;font-weight:600;letter-spacing:-.3px;margin-bottom:.25rem}
        .breadcrumb{display:flex;align-items:center;gap:6px;list-style:none;padding:0;margin:0 0 1.5rem;font-size:12px;color:var(--c-text-3)}
        .breadcrumb-item+.breadcrumb-item::before{content:'/';margin-right:6px;color:var(--c-border-2)}
        .breadcrumb-item a{color:var(--c-text-2);text-decoration:none}
        .breadcrumb-item a:hover{color:var(--c-accent)}
        .breadcrumb-item.active{color:var(--c-text-1)}

        /* ── Stat cards ─────────────────────────────────────────────────── */
        .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem}
        @media(max-width:900px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
        .stat-card{background:var(--c-surface);border:1px solid var(--c-border);border-radius:var(--radius-lg);padding:1.1rem 1.25rem;display:flex;align-items:flex-start;gap:1rem;box-shadow:var(--shadow-sm)}
        .stat-icon{width:40px;height:40px;border-radius:var(--radius-md);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
        .stat-body{flex:1;min-width:0}
        .stat-label{font-size:12px;color:var(--c-text-2);font-weight:500;margin-bottom:2px}
        .stat-val{font-size:26px;font-weight:600;letter-spacing:-.5px;line-height:1.1}

        /* ── Section card ───────────────────────────────────────────────── */
        .po-card{background:var(--c-surface);border:1px solid var(--c-border);border-radius:var(--radius-xl);box-shadow:var(--shadow-sm);overflow:hidden;margin-bottom:1.5rem}

        /* ── Create section header (accordion toggle) ───────────────────── */
        /* JS reads: id="createPoToggle", id="createPoBody"                 */
        /* JS toggles .collapsed class and aria-expanded on createPoToggle  */
        .po-card-head{display:flex;align-items:center;justify-content:space-between;padding:1.25rem 1.5rem;flex-wrap:wrap;gap:.75rem}
        .po-card-head.section-toggle{cursor:pointer;user-select:none}
        .po-card-title{font-size:16px;font-weight:600}
        .po-card-sub{font-size:12px;color:var(--c-text-3);margin-top:2px}
        .toggle-icon{transition:transform .2s;flex-shrink:0}
        .section-toggle.collapsed .toggle-icon{transform:rotate(-90deg)}

        /* ── Filter bar ─────────────────────────────────────────────────── */
        .filter-bar{display:flex;align-items:flex-end;gap:.75rem;padding:1rem 1.5rem;border-bottom:1px solid var(--c-border);flex-wrap:wrap}
        .filter-item{display:flex;flex-direction:column;gap:4px}
        .filter-item.grow{flex:1;min-width:160px}
        .filter-label{font-size:11px;font-weight:600;letter-spacing:.04em;color:var(--c-text-3);text-transform:uppercase}
        select.filter-select,input.filter-input{height:36px;padding:0 12px;background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:var(--radius-md);font-family:var(--ff-base);font-size:13px;color:var(--c-text-1);transition:border-color .15s,box-shadow .15s}
        select.filter-select{padding-right:32px;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%239a9691'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;appearance:none;-webkit-appearance:none;cursor:pointer}
        select.filter-select:focus,input.filter-input:focus{outline:none;border-color:var(--c-accent);box-shadow:0 0 0 3px rgba(37,99,235,.1)}

        /* ── Buttons ────────────────────────────────────────────────────── */
        .btn{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 16px;border-radius:var(--radius-md);font-family:var(--ff-base);font-size:13px;font-weight:500;cursor:pointer;border:1px solid transparent;transition:all .15s;white-space:nowrap;text-decoration:none}
        .btn-primary{background:var(--c-accent);color:#fff;border-color:var(--c-accent)}
        .btn-primary:hover{background:#1d4ed8;border-color:#1d4ed8}
        .btn-success{background:var(--c-green);color:#fff;border-color:var(--c-green)}
        .btn-success:hover{background:#15803d;border-color:#15803d}
        .btn-outline{background:var(--c-surface);color:var(--c-text-2);border-color:var(--c-border-2)}
        .btn-outline:hover{background:var(--c-surface-2);color:var(--c-text-1)}
        .btn-sm{height:28px;padding:0 10px;font-size:12px;border-radius:var(--radius-sm)}

        /* ── Tables ─────────────────────────────────────────────────────── */
        .table-wrap{overflow-x:auto}
        table.po-table{width:100%;border-collapse:collapse}
        .po-table thead tr{border-bottom:1px solid var(--c-border)}
        .po-table th{padding:10px 16px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);text-align:left;white-space:nowrap;background:var(--c-surface-2)}
        .po-table tbody tr{border-bottom:1px solid var(--c-border);transition:background .1s}
        .po-table tbody tr:last-child{border-bottom:none}
        .po-table tbody tr:hover{background:var(--c-surface-2)}
        .po-table td{padding:12px 16px;font-size:13px;vertical-align:middle}
        .po-table td.num{color:var(--c-text-3);font-size:12px;font-family:var(--ff-mono)}
        .po-number{font-family:var(--ff-mono);font-weight:600;font-size:13px}
        .po-meta{font-size:11px;color:var(--c-text-3);margin-top:2px}
        .po-qty{font-family:var(--ff-mono);font-size:13px;font-weight:500}

        /* ── Status badges — JS references these class names ────────────── */
        .badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:99px;font-size:11px;font-weight:600;letter-spacing:.02em;border:1px solid transparent}
        .badge-po-ordered  {background:var(--c-accent-bg);color:var(--c-accent);border-color:var(--c-accent-bd)}
        .badge-po-partial  {background:var(--c-amber-bg); color:var(--c-amber); border-color:var(--c-amber-bd)}
        .badge-po-received {background:var(--c-green-bg); color:var(--c-green); border-color:var(--c-green-bd)}
        .badge-po-cancelled{background:var(--c-surface-2);color:var(--c-text-3);border-color:var(--c-border)}
        .badge-po-draft    {background:var(--c-purple-bg);color:var(--c-purple);border-color:var(--c-purple-bd)}
        .badge-supplier    {background:var(--c-accent-bg);color:var(--c-accent);border-color:var(--c-accent-bd)}

        /* ── Create form area ───────────────────────────────────────────── */
        .create-form-wrap{padding:0 1.5rem 1.5rem}
        .helper-tip{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;background:var(--c-accent-bg);border:1px solid var(--c-accent-bd);border-radius:var(--radius-md);font-size:12px;color:var(--c-text-2);margin-bottom:1rem}
        .helper-tip i{color:var(--c-accent);font-size:15px;flex-shrink:0;margin-top:1px}

        /* ── Candidate table inputs ─────────────────────────────────────── */
        /* JS looks for: .po-item-checkbox, .qty-input, tr[data-supplier-id] */
        .qty-input{height:32px;padding:0 8px;background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:var(--radius-sm);font-family:var(--ff-mono);font-size:13px;width:90px;transition:border-color .15s}
        .qty-input:focus{outline:none;border-color:var(--c-accent)}
        .qty-input:disabled{opacity:.4;cursor:not-allowed}

        /* ── Pagination ─────────────────────────────────────────────────── */
        .pagination-bar{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.5rem;border-top:1px solid var(--c-border);flex-wrap:wrap;gap:.75rem}
        .pag-info{font-size:12px;color:var(--c-text-3)}
        .pagination{display:flex;list-style:none;margin:0;padding:0;gap:3px}
        .page-item .page-link{display:flex;align-items:center;justify-content:center;min-width:30px;height:30px;padding:0 8px;border-radius:var(--radius-sm);border:1px solid var(--c-border);background:var(--c-surface);color:var(--c-text-2);font-size:12px;text-decoration:none;transition:all .15s;font-family:var(--ff-mono)}
        .page-item .page-link:hover{background:var(--c-surface-2);color:var(--c-text-1)}
        .page-item.active .page-link{background:var(--c-accent);color:#fff;border-color:var(--c-accent)}
        .page-item.disabled .page-link{opacity:.4;pointer-events:none}

        /* ── Empty state ────────────────────────────────────────────────── */
        .empty-state{text-align:center;padding:3.5rem 1rem;color:var(--c-text-3)}
        .empty-state i{font-size:40px;display:block;margin-bottom:.75rem;opacity:.4}
        .empty-state p{font-size:14px}

        /* ── Modal (modal IDs referenced by JS) ────────────────────────── */
        /* poModal, poModalTitle, poModalSubtitle, poModalId               */
        /* poModalSummary, poModalItems, poModalNotesWrap                  */
        /* poReceiveSubmitBtn, poReceiveForm                               */
        .modal-modern .modal-content{border:1px solid var(--c-border);border-radius:var(--radius-xl);box-shadow:var(--shadow-lg);font-family:var(--ff-base);overflow:hidden}
        .modal-modern .modal-header{border-bottom:1px solid var(--c-border);padding:1.1rem 1.5rem;background:var(--c-surface-2)}
        .modal-modern .modal-title{font-size:16px;font-weight:600;letter-spacing:-.2px}
        .modal-modern .modal-subtitle{font-size:12px;color:var(--c-text-3);margin-top:2px}
        .modal-modern .modal-body{padding:1.5rem}
        .modal-modern .modal-footer{border-top:1px solid var(--c-border);padding:1rem 1.5rem;background:var(--c-surface-2)}
        .modal-modern .form-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);margin-bottom:.3rem;display:block}
        .modal-modern .form-control{height:38px;padding:0 12px;background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:var(--radius-md);font-family:var(--ff-base);font-size:13px;color:var(--c-text-1);transition:border-color .15s,box-shadow .15s}
        .modal-modern textarea.form-control{height:auto;padding:10px 12px}
        .modal-modern .form-control:focus{border-color:var(--c-accent);box-shadow:0 0 0 3px rgba(37,99,235,.1);outline:none}

        /* ── PO detail summary tiles (built by JS into #poModalSummary) ── */
        .po-detail-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;margin-bottom:1rem}
        .po-detail-tile{background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:var(--radius-md);padding:.75rem 1rem}
        .po-detail-tile-label{font-size:11px;color:var(--c-text-3);font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px}
        .po-detail-tile-val{font-size:15px;font-weight:600;color:var(--c-text-1)}

        @media(max-width:768px){
            #main{padding:1rem}
            .filter-bar{flex-direction:column;align-items:stretch}
            .po-detail-grid{grid-template-columns:1fr 1fr}
            .stats-grid{grid-template-columns:1fr 1fr}
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
        <h1>Purchase Orders</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/inventory_system/index.php">Home</a></li>
                <li class="breadcrumb-item active">Purchase Orders</li>
            </ol>
        </nav>
    </div>

    <?php if ($successMsg !== null): ?>
        <div class="alert alert-success mb-3" style="border-radius:var(--radius-md);font-size:13px;" role="alert">
            <i class="bi bi-check-circle me-2" aria-hidden="true"></i><?= e($successMsg) ?>
        </div>
    <?php endif; ?>
    <?php if ($errorMsg !== null): ?>
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
                <div class="stat-val" id="statOrdered" style="color:var(--c-accent);">
                    <?= number_format((int) ($statusSummary['ordered'] ?? 0)) ?>
                </div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--c-amber-bg);color:var(--c-amber);">
                <i class="bi bi-hourglass-split" aria-hidden="true"></i>
            </div>
            <div class="stat-body">
                <div class="stat-label">Partial</div>
                <div class="stat-val" id="statPartial" style="color:var(--c-amber);">
                    <?= number_format((int) ($statusSummary['partial'] ?? 0)) ?>
                </div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--c-green-bg);color:var(--c-green);">
                <i class="bi bi-check2-circle" aria-hidden="true"></i>
            </div>
            <div class="stat-body">
                <div class="stat-label">Received</div>
                <div class="stat-val" id="statReceived" style="color:var(--c-green);">
                    <?= number_format((int) ($statusSummary['received'] ?? 0)) ?>
                </div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--c-teal-bg);color:var(--c-teal);">
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            </div>
            <div class="stat-body">
                <div class="stat-label">Orderable candidates</div>
                <div class="stat-val" id="statCandidates" style="color:var(--c-teal);">
                    <?= number_format(count($orderableRows)) ?>
                </div>
            </div>
        </div>
    </div>


    <!-- ═══════════════════════════════════════════════════════════════════════
         CREATE PURCHASE ORDER — collapsible section
         JS refs: id="createPoToggle" (toggle), id="createPoBody" (content)
                  class="section-toggle" (accordion trigger)
                  class="toggle-icon" (chevron that rotates)
    ════════════════════════════════════════════════════════════════════════ -->
    <div class="po-card">

        <!-- Toggle header — JS binds click to id="createPoToggle" -->
        <div class="po-card-head section-toggle"
             id="createPoToggle"
             aria-expanded="true"
             aria-controls="createPoBody"
             role="button"
             tabindex="0">
            <div>
                <div class="po-card-title">
                    <i class="bi bi-plus-circle me-2" style="color:var(--c-accent);" aria-hidden="true"></i>
                    Create Purchase Order
                </div>
                <div class="po-card-sub">
                    Select a supplier and low-stock products to generate a new order.
                </div>
            </div>
            <!-- JS toggles transform on this element when collapsed -->
            <i class="bi bi-chevron-down toggle-icon" style="color:var(--c-text-3);font-size:16px;" aria-hidden="true"></i>
        </div>

        <!-- Collapsible body — JS shows/hides via style.display -->
        <div id="createPoBody">
            <div class="create-form-wrap">

                <?php if (!empty($unassignedRows)): ?>
                    <div class="helper-tip">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        <span>
                            <?= number_format(count($unassignedRows)) ?> low-stock product(s) have no supplier assigned
                            and cannot be included in a purchase order.
                        </span>
                    </div>
                <?php endif; ?>

                <!--
                    Create form — JS finds this via:
                    document.querySelector('input[name="create_purchase_order"]')?.closest("form")
                    Supplier select: [name="supplier_id"]   → id="poSupplierSelect"
                    Submit button:   id="createPoBtn"
                -->
                <form method="post">
                    <input type="hidden" name="csrf_token"            value="<?= e($csrf_token) ?>">
                    <input type="hidden" name="create_purchase_order"  value="1">

                    <div class="row g-3 mb-3" style="padding-top:.25rem;">
                        <div class="col-md-4">
                            <label class="form-label"
                                   style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--c-text-3);">
                                Supplier
                            </label>
                            <!--
                                JS refs: id="poSupplierSelect"
                                JS reads: supplierSelect.value
                                JS filters rows: row.dataset.supplierId === supplierId
                            -->
                            <select class="form-select"
                                    name="supplier_id"
                                    id="poSupplierSelect"
                                    required
                                    style="height:38px;border-radius:var(--radius-md);border-color:var(--c-border);font-family:var(--ff-base);font-size:13px;">
                                <option value="">Select supplier…</option>
                                <?php foreach ($suppliers as $sup): ?>
                                    <option value="<?= (int) ($sup['supplier_id'] ?? 0) ?>">
                                        <?= e((string) ($sup['supplier_name'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label"
                                   style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--c-text-3);">
                                Order notes
                            </label>
                            <input type="text"
                                   class="form-control"
                                   name="notes"
                                   maxlength="1000"
                                   placeholder="Optional supplier note or ordering context…"
                                   style="height:38px;border-radius:var(--radius-md);border-color:var(--c-border);font-family:var(--ff-base);font-size:13px;">
                        </div>
                    </div>

                    <!-- Candidate table wrapper -->
                    <div class="table-wrap"
                         style="border:1px solid var(--c-border);border-radius:var(--radius-lg);overflow:hidden;margin-bottom:.75rem;">
                        <table class="po-table" style="margin:0;">
                            <thead>
                                <tr>
                                    <th style="width:46px;">Pick</th>
                                    <th>Product</th>
                                    <th>Supplier</th>
                                    <th style="text-align:right;">Stock</th>
                                    <th style="text-align:right;">Reorder at</th>
                                    <th style="text-align:right;">Suggested</th>
                                    <th style="width:110px;">Order qty</th>
                                </tr>
                            </thead>
                            <!--
                                JS refs: id="poCandidateBody"
                                JS filters: tr[data-supplier-id]
                                JS checks:  .po-item-checkbox
                                JS enables: .qty-input
                            -->
                            <tbody id="poCandidateBody">
                                <?php if (empty($orderableRows)): ?>
                                    <tr>
                                        <td colspan="7">
                                            <div class="empty-state">
                                                <i class="bi bi-bag-x" aria-hidden="true"></i>
                                                <p>No orderable low-stock products found.
                                                   Assign a supplier and ensure quantity is at or below reorder level.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($orderableRows as $row):
                                        $pid = (int) ($row['product_id'] ?? 0);
                                    ?>
                                        <!--
                                            data-supplier-id — JS compares against poSupplierSelect.value
                                            to show/hide rows on supplier change
                                        -->
                                        <tr data-supplier-id="<?= (int) ($row['supplier_id'] ?? 0) ?>">
                                            <td>
                                                <!--
                                                    class="po-item-checkbox" — JS listens for change
                                                    to enable/disable the .qty-input in the same row
                                                -->
                                                <input class="form-check-input po-item-checkbox"
                                                       type="checkbox"
                                                       name="items[<?= $pid ?>][selected]"
                                                       value="1"
                                                       aria-label="Select <?= e((string) ($row['product_name'] ?? '')) ?>">
                                                <input type="hidden"
                                                       name="items[<?= $pid ?>][product_id]"
                                                       value="<?= $pid ?>">
                                            </td>
                                            <td>
                                                <div style="font-weight:500;font-size:13px;">
                                                    <?= e((string) ($row['product_name'] ?? '')) ?>
                                                </div>
                                                <div style="font-size:11px;color:var(--c-text-3);">
                                                    <?= e((string) ($row['category_name'] ?? 'Uncategorized')) ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge badge-supplier">
                                                    <?= e((string) ($row['supplier_name'] ?? '—')) ?>
                                                </span>
                                            </td>
                                            <td style="text-align:right;font-family:var(--ff-mono);font-weight:600;">
                                                <?= number_format((int) ($row['quantity'] ?? 0)) ?>
                                            </td>
                                            <td style="text-align:right;font-family:var(--ff-mono);color:var(--c-text-3);">
                                                <?= number_format((int) ($row['reorder_level'] ?? 0)) ?>
                                            </td>
                                            <td style="text-align:right;font-family:var(--ff-mono);font-weight:600;color:var(--c-amber);">
                                                <?= number_format((int) ($row['recommended_pieces'] ?? 0)) ?>
                                            </td>
                                            <td>
                                                <!--
                                                    class="qty-input" — JS enables/disables based on checkbox state
                                                    starts disabled; JS enables when checkbox checked
                                                -->
                                                <input type="number"
                                                       min="1"
                                                       class="qty-input"
                                                       name="items[<?= $pid ?>][quantity]"
                                                       value="<?= max(1, (int) ($row['recommended_pieces'] ?? 1)) ?>"
                                                       disabled
                                                       aria-label="Order quantity for <?= e((string) ($row['product_name'] ?? '')) ?>">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- JS refs: id="poVisibleHint" — updated by applySupplierFilter() -->
                    <div id="poVisibleHint"
                         style="font-size:11px;color:var(--c-text-3);margin-bottom:1rem;">
                        <?= number_format(count($orderableRows)) ?> orderable candidate(s) shown.
                    </div>

                    <div style="display:flex;justify-content:flex-end;">
                        <!-- JS refs: id="createPoBtn" — disabled + spinner on submit -->
                        <button type="submit" class="btn btn-primary" id="createPoBtn">
                            <i class="bi bi-bag-check" aria-hidden="true"></i>
                            Create purchase order
                        </button>
                    </div>
                </form>

            </div><!-- /.create-form-wrap -->
        </div><!-- #createPoBody -->
    </div><!-- /.po-card (create) -->


    <!-- ═══════════════════════════════════════════════════════════════════════
         ORDER LIST — paginated + filterable
         JS refs: id="poTable" — click delegation for .po-view-btn / .po-receive-btn
    ════════════════════════════════════════════════════════════════════════ -->
    <div class="po-card">
        <div class="po-card-head">
            <div>
                <div class="po-card-title">Purchase order list</div>
                <div class="po-card-sub">
                    <?= number_format($poPage['total']) ?> total order(s)
                </div>
            </div>
        </div>

        <!-- Filter bar -->
        <form method="get" class="filter-bar">
            <div class="filter-item">
                <span class="filter-label">Status</span>
                <select name="status" class="filter-select">
                    <option value="all"       <?= $poFilters['status'] === 'all'       ? 'selected' : '' ?>>All status</option>
                    <option value="ordered"   <?= $poFilters['status'] === 'ordered'   ? 'selected' : '' ?>>Ordered</option>
                    <option value="partial"   <?= $poFilters['status'] === 'partial'   ? 'selected' : '' ?>>Partial</option>
                    <option value="received"  <?= $poFilters['status'] === 'received'  ? 'selected' : '' ?>>Received</option>
                    <option value="cancelled" <?= $poFilters['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="filter-item grow">
                <span class="filter-label">Supplier</span>
                <select name="supplier_id" class="filter-select">
                    <option value="0">All suppliers</option>
                    <?php foreach ($suppliers as $sup): ?>
                        <option value="<?= (int) ($sup['supplier_id'] ?? 0) ?>"
                            <?= $poFilters['supplier_id'] === (int) ($sup['supplier_id'] ?? 0) ? 'selected' : '' ?>>
                            <?= e((string) ($sup['supplier_name'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-item">
                <span class="filter-label">From</span>
                <input type="date" name="date_from" class="filter-input"
                       value="<?= e($poFilters['date_from']) ?>">
            </div>
            <div class="filter-item">
                <span class="filter-label">To</span>
                <input type="date" name="date_to" class="filter-input"
                       value="<?= e($poFilters['date_to']) ?>">
            </div>
            <div class="filter-item">
                <span class="filter-label">Per page</span>
                <select name="per_page" class="filter-select">
                    <?php foreach ([10, 25, 50, 100] as $sz): ?>
                        <option value="<?= $sz ?>" <?= $poFilters['per_page'] === $sz ? 'selected' : '' ?>>
                            <?= $sz ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-item">
                <span class="filter-label">&nbsp;</span>
                <button type="submit" class="btn btn-primary">Apply</button>
            </div>
        </form>

        <!-- Table — JS binds click delegation to id="poTable" -->
        <div class="table-wrap">
            <table class="po-table" id="poTable">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th>PO Number</th>
                        <th>Supplier</th>
                        <th>Status</th>
                        <th style="text-align:right;">Lines</th>
                        <th style="text-align:right;">Ordered</th>
                        <th style="text-align:right;">Received</th>
                        <th>Date</th>
                        <th style="text-align:center;width:210px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($poItems)): ?>
                        <?php foreach ($poItems as $idx => $row):
                            $poId   = (int) ($row['po_id'] ?? 0);
                            $status = strtolower($row['status'] ?? 'ordered');
                            $canRec    = in_array($status, ['ordered', 'partial'], true);
                            $canCancel = in_array($status, ['ordered', 'partial'], true);

                            // Items for this PO — embedded as JSON in data-items
                            // JS parses: JSON.parse(btn.dataset.items || "[]")
                            // JS reads fields: ordered_quantity, received_quantity,
                            //                  po_item_id, product_name, category_name, sku
                            $rowItems = $itemsBatch[$poId] ?? [];
                        ?>
                            <tr id="poRow<?= $poId ?>">
                                <td class="num"><?= $poRowStart + $idx ?></td>

                                <td>
                                    <div class="po-number"><?= e($row['po_number'] ?? '—') ?></div>
                                    <div class="po-meta"><?= e($row['created_by_username'] ?? 'System') ?></div>
                                </td>

                                <td>
                                    <span class="badge badge-supplier">
                                        <?= e($row['supplier_name'] ?? '—') ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="badge <?= poStatusBadge($status) ?>">
                                        <i class="bi bi-circle-fill" style="font-size:7px;" aria-hidden="true"></i>
                                        <?= ucfirst(e($status)) ?>
                                    </span>
                                </td>

                                <td style="text-align:right;" class="po-qty">
                                    <?= number_format((int) ($row['item_lines'] ?? 0)) ?>
                                </td>
                                <td style="text-align:right;" class="po-qty">
                                    <?= number_format((int) ($row['ordered_total'] ?? 0)) ?>
                                </td>
                                <td style="text-align:right;" class="po-qty">
                                    <?= number_format((int) ($row['received_total'] ?? 0)) ?>
                                </td>

                                <td style="font-size:12px;color:var(--c-text-2);">
                                    <?= e(date('M d, Y', strtotime((string) ($row['ordered_at'] ?? $row['created_at'] ?? 'now')))) ?>
                                    <div class="po-meta">
                                        <?= e(date('h:i A', strtotime((string) ($row['ordered_at'] ?? $row['created_at'] ?? 'now')))) ?>
                                    </div>
                                </td>

                                <td>
                                    <div style="display:flex;gap:5px;justify-content:center;">
                                        <!--
                                            View button — JS reads ALL these data-* attributes:
                                            data-po-id, data-po-number, data-supplier,
                                            data-status, data-ordered, data-received, data-items
                                        -->
                                        <button type="button"
                                                class="btn btn-outline btn-sm po-view-btn"
                                                data-po-id="<?= $poId ?>"
                                                data-po-number="<?= e($row['po_number'] ?? '') ?>"
                                                data-supplier="<?= e($row['supplier_name'] ?? '') ?>"
                                                data-status="<?= e($status) ?>"
                                                data-ordered="<?= (int) ($row['ordered_total'] ?? 0) ?>"
                                                data-received="<?= (int) ($row['received_total'] ?? 0) ?>"
                                                data-items="<?= e(json_encode($rowItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>"
                                                aria-label="View <?= e($row['po_number'] ?? 'PO') ?>">
                                            <i class="bi bi-eye" aria-hidden="true"></i> View
                                        </button>

                                        <?php if ($canRec): ?>
                                            <!--
                                                Receive button — same data-* as view button
                                                JS opens modal in "receive" mode
                                            -->
                                            <button type="button"
                                                    class="btn btn-success btn-sm po-receive-btn"
                                                    data-po-id="<?= $poId ?>"
                                                    data-po-number="<?= e($row['po_number'] ?? '') ?>"
                                                    data-supplier="<?= e($row['supplier_name'] ?? '') ?>"
                                                    data-status="<?= e($status) ?>"
                                                    data-ordered="<?= (int) ($row['ordered_total'] ?? 0) ?>"
                                                    data-received="<?= (int) ($row['received_total'] ?? 0) ?>"
                                                    data-items="<?= e(json_encode($rowItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>"
                                                    aria-label="Receive <?= e($row['po_number'] ?? 'PO') ?>">
                                                <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Receive
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($canCancel): ?>
                                            <!--
                                                Cancel button — triggers Swal confirm in JS,
                                                then posts to id="poCancelForm" with po_id + reason.
                                                JS reads: data-po-id, data-po-number
                                            -->
                                            <button type="button"
                                                    class="btn btn-sm po-cancel-btn"
                                                    style="background:var(--c-red-bg);color:var(--c-red);border:1px solid var(--c-red-bd);"
                                                    data-po-id="<?= $poId ?>"
                                                    data-po-number="<?= e($row['po_number'] ?? '') ?>"
                                                    aria-label="Cancel <?= e($row['po_number'] ?? 'PO') ?>">
                                                <i class="bi bi-x-circle" aria-hidden="true"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9">
                                <div class="empty-state">
                                    <i class="bi bi-bag" aria-hidden="true"></i>
                                    <p>No purchase orders found for the selected filters.</p>
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
                <?php if ($poPage['total'] > 0): ?>
                    Showing <?= number_format($poRowStart) ?>–<?= number_format(min($poRowStart + count($poItems) - 1, $poPage['total'])) ?>
                    of <?= number_format($poPage['total']) ?> orders
                <?php else: ?>
                    No results
                <?php endif; ?>
            </span>
            <nav aria-label="Purchase order pagination">
                <ul class="pagination">
                    <li class="page-item <?= $poPage['page'] <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link"
                           href="<?= e(poListUrl($poFilters, ['page' => $poPage['page'] - 1])) ?>"
                           aria-label="Previous">
                            <i class="bi bi-chevron-left" style="font-size:11px;" aria-hidden="true"></i>
                        </a>
                    </li>
                    <?php
                    $pStart = max(1, $poPage['page'] - 2);
                    $pEnd   = min($poPage['total_pages'], $poPage['page'] + 2);
                    for ($pn = $pStart; $pn <= $pEnd; $pn++):
                    ?>
                        <li class="page-item <?= $pn === $poPage['page'] ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e(poListUrl($poFilters, ['page' => $pn])) ?>">
                                <?= $pn ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $poPage['page'] >= $poPage['total_pages'] ? 'disabled' : '' ?>">
                        <a class="page-link"
                           href="<?= e(poListUrl($poFilters, ['page' => $poPage['page'] + 1])) ?>"
                           aria-label="Next">
                            <i class="bi bi-chevron-right" style="font-size:11px;" aria-hidden="true"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>

    </div><!-- /.po-card (list) -->

</main>


<!-- ═══════════════════════════════════════════════════════════════════════════
     PO DETAIL / RECEIVE MODAL
     JS element refs (ALL must exist with these exact IDs):
       id="poModal"           — bootstrap.Modal target
       id="poModalTitle"      — filled with po_number
       id="poModalSubtitle"   — filled with mode label
       id="poModalId"         — hidden input: po_id for form POST
       id="poModalSummary"    — JS injects po-detail-tile divs here
       id="poModalItems"      — JS injects <tr> rows here
       id="poModalNotesWrap"  — shown in receive mode, hidden in view mode
       id="poReceiveSubmitBtn"— shown/hidden by mode; disabled on submit
       id="poReceiveForm"     — the form that posts receive_purchase_order
════════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade modal-modern" id="poModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header">
                <div>
                    <!-- JS: poModalTitle.textContent = poNumber -->
                    <div class="modal-title" id="poModalTitle">Purchase Order</div>
                    <!-- JS: poModalSubtitle.textContent = mode label -->
                    <div class="modal-subtitle" id="poModalSubtitle">Order details</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- JS: poReceiveForm — submit handler + validation -->
            <form method="post" id="poReceiveForm">
                <input type="hidden" name="csrf_token"             value="<?= e($csrf_token) ?>">
                <input type="hidden" name="receive_purchase_order"  value="1">
                <!-- JS: poModalId.value = poId from button data-po-id -->
                <input type="hidden" name="po_id" id="poModalId"   value="">

                <div class="modal-body">

                    <!--
                        JS: poModalSummary — innerHTML replaced with 3 po-detail-tile divs:
                        Supplier tile, Status tile, Progress tile
                        CSS classes used by JS-generated HTML:
                        .po-detail-grid, .po-detail-tile, .po-detail-tile-label, .po-detail-tile-val
                    -->
                    <div class="po-detail-grid" id="poModalSummary"></div>

                    <!-- Item rows table — JS builds <tr> rows into id="poModalItems" -->
                    <div class="table-wrap"
                         style="border:1px solid var(--c-border);border-radius:var(--radius-lg);overflow:hidden;margin-bottom:1rem;">
                        <table class="po-table" style="margin:0;">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th style="text-align:right;">Ordered</th>
                                    <th style="text-align:right;">Received</th>
                                    <th style="text-align:right;">Remaining</th>
                                    <th style="width:110px;">Receive now</th>
                                </tr>
                            </thead>
                            <!-- JS appends <tr> elements here -->
                            <tbody id="poModalItems"></tbody>
                        </table>
                    </div>

                    <!--
                        JS: poModalNotesWrap — style.display toggled:
                        "" in receive mode, "none" in view mode
                    -->
                    <div id="poModalNotesWrap">
                        <label class="form-label">Receiving notes</label>
                        <textarea class="form-control"
                                  name="receive_notes"
                                  rows="3"
                                  maxlength="1000"
                                  placeholder="Optional notes about this receipt…"></textarea>
                    </div>

                </div><!-- /.modal-body -->

                <div class="modal-footer justify-content-end gap-2">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Close</button>
                    <!--
                        JS: poReceiveSubmitBtn
                        - style.display toggled: "" receive, "none" view
                        - disabled + spinner on form submit
                        - reset on modal close
                    -->
                    <button type="submit"
                            class="btn btn-success"
                            id="poReceiveSubmitBtn"
                            style="display:none;">
                        <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Save receipt
                    </button>
                </div>

            </form><!-- #poReceiveForm -->

        </div><!-- /.modal-content -->
    </div><!-- /.modal-dialog -->
</div><!-- #poModal -->


<!-- Hidden cancel form — JS fills po_id + cancel_reason then submits -->
<form method="post" id="poCancelForm" style="display:none;">
    <input type="hidden" name="csrf_token"            value="<?= e($csrf_token) ?>">
    <input type="hidden" name="cancel_purchase_order"  value="1">
    <input type="hidden" name="po_id"          id="poCancelPoId"    value="">
    <input type="hidden" name="cancel_reason"  id="poCancelReason"  value="">
</form>

<?php require __DIR__ . '/../components/js_script.php'; ?>
<script src="/inventory_system/assets/js/purchase_orders.js"></script>

</body>
</html>