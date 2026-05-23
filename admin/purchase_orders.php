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
    <link rel="stylesheet" href="/inventory_system/assets/css/purchase_orders.css">
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
            <i class="bi bi-chevron-down toggle-icon" style="color:var(--c-text-3);font-size:16px;" aria-hidden="true"></i>
        </div>

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

                
                <form method="post">
                    <input type="hidden" name="csrf_token"            value="<?= e($csrf_token) ?>">
                    <input type="hidden" name="create_purchase_order"  value="1">

                    <div class="row g-3 mb-3" style="padding-top:.25rem;">
                        <div class="col-md-4">
                            <label class="form-label"
                                   style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--c-text-3);">
                                Supplier
                            </label>
                            
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
                                        
                                        <tr data-supplier-id="<?= (int) ($row['supplier_id'] ?? 0) ?>">
                                            <td>
                                               
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

            </div>
        </div>
    </div>


   
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
                        <th style="text-align:center;width:170px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($poItems)): ?>
                        <?php foreach ($poItems as $idx => $row):
                            $poId   = (int) ($row['po_id'] ?? 0);
                            $status = strtolower($row['status'] ?? 'ordered');
                            $canRec = in_array($status, ['ordered', 'partial'], true);

                           
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

    </div>

</main>



<div class="modal fade modal-modern" id="poModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header">
                <div>
                    <div class="modal-title" id="poModalTitle">Purchase Order</div>
                    <div class="modal-subtitle" id="poModalSubtitle">Order details</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form method="post" id="poReceiveForm">
                <input type="hidden" name="csrf_token"             value="<?= e($csrf_token) ?>">
                <input type="hidden" name="receive_purchase_order"  value="1">
                <!-- JS: poModalId.value = poId from button data-po-id -->
                <input type="hidden" name="po_id" id="poModalId"   value="">

                <div class="modal-body">

                   
                    <div class="po-detail-grid" id="poModalSummary"></div>

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
                   
                    <button type="submit"
                            class="btn btn-success"
                            id="poReceiveSubmitBtn"
                            style="display:none;">
                        <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Save receipt
                    </button>
                </div>

            </form>

        </div>
    </div>
</div>


<?php require __DIR__ . '/../components/js_script.php'; ?>
<script src="/inventory_system/assets/js/purchase_orders.js"></script>

</body>
</html>