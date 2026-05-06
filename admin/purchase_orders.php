<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/PurchaseOrderController.php';
require_once __DIR__ . '/../controllers/NotificationController.php';
require_once __DIR__ . '/../controllers/AuthController.php';

Middleware::auth()->role(['admin']);

$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
$csrfToken = Middleware::generateCsrfToken();
$successMsg = null;
$errorMsg = null;

if (isset($_SESSION['purchase_order_flash']) && is_array($_SESSION['purchase_order_flash'])) {
    $flash = $_SESSION['purchase_order_flash'];
    $successMsg = isset($flash['success']) ? (string) $flash['success'] : null;
    $errorMsg = isset($flash['error']) ? (string) $flash['error'] : null;
    unset($_SESSION['purchase_order_flash']);
}

$logConfig = [
    'table'       => $table_activity_logs,
    'col_user_id' => $activity_log_user_id,
    'col_action'  => $activity_log_action,
    'col_desc'    => $activity_log_desc,
    'col_ip'      => $activity_log_ip,
    'col_created' => $activity_log_created,
];

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money(float $amount): string
{
    return 'PHP ' . number_format($amount, 2);
}

function poStatusBadge(string $status): string
{
    return match (strtolower(trim($status))) {
        'ordered' => 'bg-primary',
        'partial' => 'bg-warning text-dark',
        'received' => 'bg-success',
        'cancelled' => 'bg-secondary',
        default => 'bg-light text-dark',
    };
}

function safeCreateAdminNotification(
    PDO $conn,
    int $userId,
    string $type,
    string $title,
    string $message,
    string $icon,
    string $color
): void {
    try {
        NotificationController::create(
            $conn,
            $userId,
            'admin',
            $type,
            $title,
            $message,
            $icon,
            $color,
            '/inventory_system/admin/purchase_orders.php'
        );
    } catch (Throwable $e) {
        error_log('[purchase_orders notification] ' . $e->getMessage());
    }
}

try {
    PurchaseOrderController::ensureSchema($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!AuthController::validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Invalid request token. Refresh the page and try again.');
        }

        if (isset($_POST['create_purchase_order'])) {
            $supplierId = (int) ($_POST['supplier_id'] ?? 0);
            $itemsInput = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];
            $items = [];

            foreach ($itemsInput as $item) {
                if (!is_array($item) || empty($item['selected'])) {
                    continue;
                }

                $items[] = [
                    'product_id' => (int) ($item['product_id'] ?? 0),
                    'quantity' => (int) ($item['quantity'] ?? 0),
                    'notes' => (string) ($item['notes'] ?? ''),
                ];
            }

            $created = PurchaseOrderController::createPurchaseOrder(
                $conn,
                $supplierId,
                $items,
                $sessionUserId,
                (string) ($_POST['notes'] ?? '')
            );

            AuthController::logActivity(
                $conn,
                $logConfig,
                $sessionUserId,
                'purchase_order_create',
                sprintf(
                    'Created purchase order %s for %s with %d item line(s).',
                    $created['po_number'],
                    $created['supplier_name'],
                    (int) $created['item_count']
                ),
                'purchase_order',
                (int) $created['po_id']
            );

            safeCreateAdminNotification(
                $conn,
                $sessionUserId,
                'purchase_order',
                'Purchase Order Created',
                sprintf('%s created for %s.', $created['po_number'], $created['supplier_name']),
                'bi-bag-check',
                'text-primary'
            );

            $_SESSION['purchase_order_flash'] = [
                'success' => sprintf('%s created successfully.', $created['po_number']),
            ];
            header('Location: /inventory_system/admin/purchase_orders.php');
            exit;
        }

        if (isset($_POST['receive_purchase_order'])) {
            $poId = (int) ($_POST['po_id'] ?? 0);
            $received = is_array($_POST['received'] ?? null) ? $_POST['received'] : [];
            $notes = (string) ($_POST['receive_notes'] ?? '');

            $result = PurchaseOrderController::receivePurchaseOrder($conn, $poId, $received, $sessionUserId, $notes);

            AuthController::logActivity(
                $conn,
                $logConfig,
                $sessionUserId,
                'purchase_order_receive',
                sprintf(
                    'Received %d line(s) / %d piece(s) for %s. Status: %s.',
                    (int) $result['received_lines'],
                    (int) $result['received_pieces'],
                    $result['po_number'],
                    ucfirst((string) $result['status'])
                ),
                'purchase_order',
                (int) $result['po_id'],
                $result['status'] === 'received' ? 'info' : 'warning'
            );

            safeCreateAdminNotification(
                $conn,
                $sessionUserId,
                'purchase_order_receive',
                'Purchase Order Received',
                sprintf('%s updated to %s.', $result['po_number'], ucfirst((string) $result['status'])),
                'bi-box-arrow-in-down',
                'text-success',
                '/inventory_system/admin/purchase_receiving_history.php?po_id=' . (int) ($result['po_id'] ?? 0)
            );

            $_SESSION['purchase_order_flash'] = [
                'success' => sprintf('%s updated successfully.', $result['po_number']),
            ];
            header('Location: /inventory_system/admin/purchase_orders.php');
            exit;
        }
    }
} catch (Throwable $e) {
    error_log('[purchase_orders.php] ' . $e->getMessage());
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $_SESSION['purchase_order_flash'] = [
            'error' => $e->getMessage(),
        ];
        header('Location: /inventory_system/admin/purchase_orders.php');
        exit;
    }

    $errorMsg = $e->getMessage();
}

$suppliers = PurchaseOrderController::supplierOptions($conn);
$lowStockRows = PurchaseOrderController::lowStockCandidates($conn);
$orderableRows = array_values(array_filter($lowStockRows, static fn(array $row): bool => (int) ($row['supplier_id'] ?? 0) > 0));
$unassignedRows = array_values(array_filter($lowStockRows, static fn(array $row): bool => (int) ($row['supplier_id'] ?? 0) <= 0));
$statusSummary = PurchaseOrderController::statusSummary($conn);
$purchaseOrders = PurchaseOrderController::listPurchaseOrders($conn, 30);
$purchaseOrderDetails = [];
foreach ($purchaseOrders as $row) {
    $detail = PurchaseOrderController::getPurchaseOrder($conn, (int) ($row['po_id'] ?? 0));
    if ($detail !== null) {
        $purchaseOrderDetails[(int) $row['po_id']] = $detail;
    }
}
$defaultSupplierId = 0;
$pageTitle = 'Purchase Orders';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require __DIR__ . '/../components/head.php'; ?>
<link href="/inventory_system/assets/css/purchase-orders.css" rel="stylesheet">
</head>
<body>
<?php require __DIR__ . '/../components/header.php'; ?>
<?php require __DIR__ . '/../components/sidebar.php'; ?>

<main id="main" class="main po-page">
    <div class="po-hero">
        <div class="po-hero-copy">
            <p class="po-eyebrow">Inventory Procurement</p>
            <h1 class="po-hero-title">Purchase Orders</h1>
            <p class="po-hero-text">
                Build supplier orders from low-stock items, track receipts, and move replenishment into inventory without leaving one screen.
            </p>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/inventory_system/index.php">Home</a></li>
                    <li class="breadcrumb-item active">Purchase Orders</li>
                </ol>
            </nav>
        </div>
        <div class="po-hero-panel">
            <div class="po-hero-stat">
                <span class="po-hero-stat-label">Open pipeline</span>
                <strong><?= number_format((int) (($statusSummary['ordered'] ?? 0) + ($statusSummary['partial'] ?? 0))) ?></strong>
                <span class="po-hero-stat-note">orders pending full receipt</span>
            </div>
            <div class="po-hero-stat">
                <span class="po-hero-stat-label">Ready to order</span>
                <strong><?= number_format(count($orderableRows)) ?></strong>
                <span class="po-hero-stat-note">low-stock products with suppliers</span>
            </div>
        </div>
    </div>

    <section class="section dashboard">
        <?php if ($successMsg !== null): ?>
            <div class="alert alert-success po-alert"><?= e($successMsg) ?></div>
        <?php endif; ?>
        <?php if ($errorMsg !== null): ?>
            <div class="alert alert-danger po-alert"><?= e($errorMsg) ?></div>
        <?php endif; ?>

        <div class="po-summary-grid mb-4">
            <div class="po-summary-tile">
                <div class="po-summary-icon bg-primary-subtle text-primary"><i class="bi bi-bag"></i></div>
                <div class="po-summary-content">
                    <div class="po-summary-kicker">Ordered</div>
                    <div class="po-summary-value"><?= number_format((int) ($statusSummary['ordered'] ?? 0)) ?></div>
                    <div class="po-summary-note">Created and waiting for first receipt</div>
                </div>
            </div>
            <div class="po-summary-tile">
                <div class="po-summary-icon bg-warning-subtle text-warning"><i class="bi bi-hourglass-split"></i></div>
                <div class="po-summary-content">
                    <div class="po-summary-kicker">Partial Receipts</div>
                    <div class="po-summary-value"><?= number_format((int) ($statusSummary['partial'] ?? 0)) ?></div>
                    <div class="po-summary-note">Orders with remaining quantities</div>
                </div>
            </div>
            <div class="po-summary-tile">
                <div class="po-summary-icon bg-success-subtle text-success"><i class="bi bi-check2-circle"></i></div>
                <div class="po-summary-content">
                    <div class="po-summary-kicker">Received</div>
                    <div class="po-summary-value"><?= number_format((int) ($statusSummary['received'] ?? 0)) ?></div>
                    <div class="po-summary-note">Completed supplier deliveries</div>
                </div>
            </div>
            <div class="po-summary-tile">
                <div class="po-summary-icon bg-info-subtle text-info"><i class="bi bi-box-seam"></i></div>
                <div class="po-summary-content">
                    <div class="po-summary-kicker">Orderable Candidates</div>
                    <div class="po-summary-value"><?= number_format(count($orderableRows)) ?></div>
                    <div class="po-summary-note">Products ready for procurement</div>
                </div>
            </div>
        </div>

        <div class="card po-card mb-4">
            <div class="card-body">
                <div class="po-order-head mb-3">
                    <div>
                        <p class="po-section-kicker">Create</p>
                        <h5 class="card-title mb-1">Build Purchase Order</h5>
                        <p class="po-muted mb-0">Select a supplier, choose low-stock products, and generate an order sheet from live recommendations.</p>
                    </div>
                </div>

                <div class="po-helper mb-3">
                    <div class="po-helper-icon"><i class="bi bi-lightbulb"></i></div>
                    <div>
                        <strong>How to test:</strong> products appear here when they are active, their quantity is at or below reorder level, and they have an assigned supplier.
                    </div>
                    <?php if ($unassignedRows !== []): ?>
                        <div class="mt-2 text-warning-emphasis">
                            <?= number_format(count($unassignedRows)) ?> low-stock product(s) are missing a supplier, so they cannot be included yet.
                        </div>
                    <?php endif; ?>
                </div>

                <form method="post" class="po-form-shell">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="create_purchase_order" value="1">

                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label po-label">Supplier</label>
                            <select class="form-select" name="supplier_id" id="poSupplierSelect" required>
                                <option value="">Select supplier</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?= (int) $supplier['supplier_id'] ?>" <?= $defaultSupplierId === (int) $supplier['supplier_id'] ? 'selected' : '' ?>>
                                        <?= e((string) ($supplier['supplier_name'] ?? 'Supplier')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label po-label">Notes</label>
                            <input type="text" class="form-control" name="notes" maxlength="1000" placeholder="Optional supplier note or ordering context">
                        </div>
                    </div>

                    <div class="po-table-shell">
                        <div class="table-responsive">
                            <table class="table align-middle po-creation-table po-modern-table">
                            <thead>
                                <tr>
                                    <th style="width:52px;">Pick</th>
                                    <th>Product</th>
                                    <th>Supplier</th>
                                    <th class="text-end">Stock</th>
                                    <th class="text-end">Reorder</th>
                                    <th class="text-end">Suggested</th>
                                    <th style="width:140px;">Order Qty</th>
                                </tr>
                            </thead>
                                <tbody id="poCandidateBody">
                                <?php if ($orderableRows === []): ?>
                                    <tr><td colspan="7" class="text-center text-muted py-5">No orderable low-stock products are available. Assign a supplier and make sure the product quantity is at or below its reorder level.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($orderableRows as $row): ?>
                                        <?php $productId = (int) ($row['product_id'] ?? 0); ?>
                                        <tr data-supplier-id="<?= (int) ($row['supplier_id'] ?? 0) ?>">
                                            <td>
                                                <input class="form-check-input po-item-checkbox" type="checkbox" name="items[<?= $productId ?>][selected]" value="1">
                                                <input type="hidden" name="items[<?= $productId ?>][product_id]" value="<?= $productId ?>">
                                            </td>
                                            <td>
                                                <div class="po-product-cell">
                                                    <div class="po-product-name"><?= e((string) ($row['product_name'] ?? '')) ?></div>
                                                    <div class="po-line-meta"><?= e((string) ($row['category_name'] ?? 'Uncategorized')) ?></div>
                                                </div>
                                            </td>
                                            <td><span class="po-chip po-chip-neutral"><?= e((string) ($row['supplier_name'] ?? 'No supplier')) ?></span></td>
                                            <td class="text-end po-number-cell"><?= number_format((int) ($row['quantity'] ?? 0)) ?></td>
                                            <td class="text-end po-number-cell"><?= number_format((int) ($row['reorder_level'] ?? 0)) ?></td>
                                            <td class="text-end po-number-cell po-emphasis"><?= number_format((int) ($row['recommended_pieces'] ?? 0)) ?></td>
                                            <td>
                                                <input
                                                    type="number"
                                                    min="1"
                                                    class="form-control form-control-sm po-qty-input"
                                                    name="items[<?= $productId ?>][quantity]"
                                                    value="<?= max(1, (int) ($row['recommended_pieces'] ?? 1)) ?>"
                                                    disabled
                                                >
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="small text-muted mt-3" id="poVisibleHint">Showing all orderable candidates.</div>

                    <div class="mt-4 d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary po-submit-btn">
                            <i class="bi bi-bag-check me-1"></i>Create Purchase Order
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card po-card po-order-list">
            <div class="card-body">
                <div class="po-order-head mb-3">
                    <div>
                        <p class="po-section-kicker">Monitor</p>
                        <h5 class="card-title mb-1">Recent Purchase Orders</h5>
                        <p class="po-muted mb-0">Track open orders, receipt progress, and which deliveries still need stock intake.</p>
                    </div>
                </div>

                <div class="po-table-shell">
                    <div class="table-responsive">
                        <table class="table align-middle po-modern-table po-order-table">
                        <thead>
                            <tr>
                                <th>PO Number</th>
                                <th>Supplier</th>
                                <th>Status</th>
                                <th class="text-end">Ordered</th>
                                <th class="text-end">Received</th>
                                <th>Date</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($purchaseOrders === []): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No purchase orders yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($purchaseOrders as $row): ?>
                                    <tr>
                                        <td>
                                            <div class="po-product-name"><?= e((string) ($row['po_number'] ?? '')) ?></div>
                                            <div class="po-line-meta"><?= e((string) ($row['created_by_username'] ?? 'System')) ?></div>
                                        </td>
                                        <td><span class="po-chip po-chip-neutral"><?= e((string) ($row['supplier_name'] ?? '')) ?></span></td>
                                        <td><span class="badge po-status-badge <?= e(poStatusBadge((string) ($row['status'] ?? 'ordered'))) ?>"><?= e(ucfirst((string) ($row['status'] ?? 'ordered'))) ?></span></td>
                                        <td class="text-end po-number-cell"><?= number_format((int) ($row['ordered_total'] ?? 0)) ?></td>
                                        <td class="text-end po-number-cell"><?= number_format((int) ($row['received_total'] ?? 0)) ?></td>
                                        <td><?= e(date('M d, Y h:i A', strtotime((string) ($row['ordered_at'] ?? $row['created_at'] ?? 'now')))) ?></td>
                                        <td class="text-end">
                                            <div class="po-action-group">
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-outline-primary me-1 po-view-btn"
                                                    data-po-id="<?= (int) ($row['po_id'] ?? 0) ?>"
                                                >
                                                    View
                                                </button>
                                            <?php if (in_array((string) ($row['status'] ?? ''), ['ordered', 'partial'], true)): ?>
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-success po-receive-btn"
                                                    data-po-id="<?= (int) ($row['po_id'] ?? 0) ?>"
                                                >
                                                    Receive
                                                </button>
                                            <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<div class="modal fade" id="purchaseOrderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content po-modal-content">
            <div class="modal-header po-modal-header">
                <div>
                    <h5 class="modal-title" id="purchaseOrderModalLabel">Purchase Order</h5>
                    <small class="text-muted" id="purchaseOrderModalSubhead">Review or receive items.</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" id="purchaseOrderReceiveForm">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="receive_purchase_order" value="1">
                <input type="hidden" name="po_id" id="poModalId" value="">
                <div class="modal-body">
                    <div class="po-modal-summary" id="poModalHeader"></div>
                    <div class="po-table-shell">
                        <div class="table-responsive">
                            <table class="table align-middle po-modern-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th class="text-end">Ordered</th>
                                    <th class="text-end">Received</th>
                                    <th class="text-end">Remaining</th>
                                    <th style="width:140px;">Receive Now</th>
                                </tr>
                            </thead>
                            <tbody id="poModalItems"></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="mt-4">
                        <label class="form-label po-label">Receiving Notes</label>
                        <textarea class="form-control" name="receive_notes" rows="3" maxlength="1000" placeholder="Optional receiving notes"></textarea>
                    </div>
                </div>
                <div class="modal-footer po-modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-success" id="poReceiveSubmitBtn">Save Receipt</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../components/footer.php'; ?>
<?php require __DIR__ . '/../components/js_script.php'; ?>
<script>
const purchaseOrderDetails = <?= json_encode($purchaseOrderDetails, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
document.addEventListener('DOMContentLoaded', () => {
    const supplierSelect = document.getElementById('poSupplierSelect');
    const rows = Array.from(document.querySelectorAll('#poCandidateBody tr[data-supplier-id]'));
    const visibleHint = document.getElementById('poVisibleHint');
    const modalEl = document.getElementById('purchaseOrderModal');
    const modal = modalEl ? new bootstrap.Modal(modalEl) : null;

    function applySupplierFilter() {
        const supplierId = String(supplierSelect?.value || '');
        let visibleCount = 0;
        rows.forEach((row) => {
            const match = supplierId === '' || row.dataset.supplierId === supplierId;
            row.style.display = match ? '' : 'none';
            if (match) {
                visibleCount += 1;
            }
            const checkbox = row.querySelector('.po-item-checkbox');
            const qtyInput = row.querySelector('.po-qty-input');
            if (!match && checkbox) {
                checkbox.checked = false;
            }
            if (qtyInput) {
                qtyInput.disabled = !match || !checkbox?.checked;
            }
        });

        if (visibleHint) {
            visibleHint.textContent = supplierId === ''
                ? `Showing all orderable candidates (${visibleCount}).`
                : `Showing ${visibleCount} candidate(s) for the selected supplier.`;
        }
    }

    supplierSelect?.addEventListener('change', applySupplierFilter);
    document.addEventListener('change', (event) => {
        const checkbox = event.target.closest('.po-item-checkbox');
        if (!checkbox) return;
        const row = checkbox.closest('tr');
        const qtyInput = row?.querySelector('.po-qty-input');
        if (qtyInput) {
            qtyInput.disabled = !checkbox.checked;
        }
    });
    applySupplierFilter();

    function openPoModal(poId, mode) {
        const detail = purchaseOrderDetails[String(poId)] || purchaseOrderDetails[poId];
        if (!detail || !modal) return;

        document.getElementById('poModalId').value = String(poId);
        document.getElementById('purchaseOrderModalLabel').textContent = detail.po_number || 'Purchase Order';
        document.getElementById('purchaseOrderModalSubhead').textContent = mode === 'receive'
            ? 'Enter the quantities received for each remaining line.'
            : 'Review current order details.';

        document.getElementById('poModalHeader').innerHTML = `
            <strong>${detail.po_number || 'PO'}</strong><br>
            Supplier: ${detail.supplier_name || '-'}<br>
            Status: ${detail.status || '-'}
        `;

        const itemsBody = document.getElementById('poModalItems');
        const submitBtn = document.getElementById('poReceiveSubmitBtn');
        itemsBody.innerHTML = '';

        (detail.items || []).forEach((item) => {
            const ordered = Number(item.ordered_quantity || 0);
            const received = Number(item.received_quantity || 0);
            const remaining = Math.max(0, ordered - received);
            const disabled = mode !== 'receive' || remaining <= 0 || ['received', 'cancelled'].includes(String(detail.status || '').toLowerCase());

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>
                    <div class="fw-semibold">${item.product_name || ''}</div>
                    <div class="small text-muted">${item.category_name || 'Uncategorized'}${item.sku ? ' | SKU ' + item.sku : ''}</div>
                </td>
                <td class="text-end">${ordered}</td>
                <td class="text-end">${received}</td>
                <td class="text-end">${remaining}</td>
                <td>
                    <input
                        type="number"
                        min="0"
                        max="${remaining}"
                        value="${disabled ? 0 : remaining}"
                        class="form-control form-control-sm"
                        name="received[${item.po_item_id}]"
                        ${disabled ? 'disabled' : ''}
                    >
                </td>
            `;
            itemsBody.appendChild(tr);
        });

        submitBtn.classList.toggle('d-none', mode !== 'receive');
        modal.show();
    }

    document.addEventListener('click', (event) => {
        const viewBtn = event.target.closest('.po-view-btn');
        if (viewBtn) {
            openPoModal(viewBtn.dataset.poId, 'view');
            return;
        }

        const receiveBtn = event.target.closest('.po-receive-btn');
        if (receiveBtn) {
            openPoModal(receiveBtn.dataset.poId, 'receive');
        }
    });
});
</script>
</body>
</html>
