<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/PurchaseOrderController.php';

Middleware::auth()->role(['admin']);

PurchaseOrderController::ensureSchema($conn);

$orders = PurchaseOrderController::listPurchaseOrders($conn, 120);
$details = [];
$receivedPieces = 0;
$receivedOrders = 0;
$partialOrders = 0;

foreach ($orders as $order) {
    $poId = (int) ($order['po_id'] ?? 0);
    $detail = PurchaseOrderController::getPurchaseOrder($conn, $poId);
    if ($detail !== null) {
        $details[$poId] = $detail;
    }

    $receivedPieces += (int) ($order['received_total'] ?? 0);
    $status = strtolower((string) ($order['status'] ?? 'ordered'));
    if ($status === 'received') {
        $receivedOrders++;
    } elseif ($status === 'partial') {
        $partialOrders++;
    }
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/../components/head.php'; ?>
    <title>Purchase Receiving History</title>
    <link rel="stylesheet" href="/inventory_system/assets/css/ops-suite.css">
</head>
<body>
<?php require __DIR__ . '/../components/header.php'; ?>
<?php require __DIR__ . '/../components/sidebar.php'; ?>

<main id="main" class="main ops-page">
    <section class="ops-hero">
        <div class="ops-hero__body">
            <div>
                <span class="ops-eyebrow">Receiving Log</span>
                <h1 class="ops-title">Purchase Receiving History</h1>
                <p class="ops-copy">Track supplier deliveries, review partial receipts, and open the full line-item breakdown for every purchase order received into inventory.</p>
            </div>
            <div class="ops-hero__stats">
                <div class="ops-stat">
                    <span>Fully received</span>
                    <strong id="receivingReceivedCount"><?= number_format($receivedOrders) ?></strong>
                    <small>completed purchase orders</small>
                </div>
                <div class="ops-stat">
                    <span>Partial receipts</span>
                    <strong id="receivingPartialCount"><?= number_format($partialOrders) ?></strong>
                    <small>orders still waiting for stock intake</small>
                </div>
                <div class="ops-stat">
                    <span>Received pieces</span>
                    <strong id="receivingPiecesCount"><?= number_format($receivedPieces) ?></strong>
                    <small>across loaded purchase orders</small>
                </div>
                <div class="ops-stat">
                    <span>Records loaded</span>
                    <strong id="receivingHistoryCount"><?= number_format(count($orders)) ?></strong>
                    <small>purchase orders in this page</small>
                </div>
            </div>
        </div>
    </section>

    <section class="ops-filter-card mb-4">
        <div class="ops-filter-grid">
            <div class="is-wide">
                <label>Search supplier or PO number</label>
                <input type="search" id="receivingSearch" class="form-control" placeholder="Search supplier name, PO number, or creator">
            </div>
            <div>
                <label>Status</label>
                <select id="receivingStatus" class="form-select">
                    <option value="all">All statuses</option>
                    <option value="ordered">Ordered</option>
                    <option value="partial">Partial</option>
                    <option value="received">Received</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            <div>
                <label>Date from</label>
                <input type="date" id="receivingDateFrom" class="form-control">
            </div>
            <div>
                <label>Date to</label>
                <input type="date" id="receivingDateTo" class="form-control">
            </div>
        </div>
    </section>

    <section class="ops-table-card">
        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
            <div>
                <span class="ops-eyebrow">Receiving Timeline</span>
                <h2 class="h4 mb-1" style="color:#012970;font-weight:800;">Supplier Delivery Queue</h2>
                <p class="ops-muted mb-0">Open any order to see received vs ordered quantities per product.</p>
            </div>
            <div id="receivingHistoryMeta" class="ops-muted small">Showing all loaded records.</div>
        </div>

        <div class="table-responsive">
            <table class="table align-middle" id="receivingHistoryTable">
                <thead>
                    <tr>
                        <th>PO Number</th>
                        <th>Supplier</th>
                        <th>Ordered At</th>
                        <th>Received At</th>
                        <th class="text-end">Ordered</th>
                        <th class="text-end">Received</th>
                        <th>Progress</th>
                        <th class="text-end">Lines</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <?php $status = strtolower((string) ($order['status'] ?? 'ordered')); ?>
                        <tr
                            data-po-id="<?= (int) ($order['po_id'] ?? 0) ?>"
                            data-search="<?= e(strtolower((string) ($order['po_number'] ?? '') . ' ' . (string) ($order['supplier_name'] ?? '') . ' ' . (string) ($order['created_by_username'] ?? ''))) ?>"
                            data-status="<?= e($status) ?>"
                            data-date="<?= e(substr((string) ($order['received_at'] ?: $order['ordered_at'] ?: ''), 0, 10)) ?>"
                            data-ordered-total="<?= (int) ($order['ordered_total'] ?? 0) ?>"
                            data-received-total="<?= (int) ($order['received_total'] ?? 0) ?>"
                        >
                            <?php
                            $orderedTotal = max(0, (int) ($order['ordered_total'] ?? 0));
                            $receivedTotal = max(0, (int) ($order['received_total'] ?? 0));
                            $progress = $orderedTotal > 0 ? (int) round(min(100, ($receivedTotal / $orderedTotal) * 100)) : 0;
                            ?>
                            <td><strong><?= e((string) ($order['po_number'] ?? 'PO')) ?></strong></td>
                            <td><?= e((string) ($order['supplier_name'] ?? 'Supplier')) ?></td>
                            <td><?= e(date('M d, Y g:i A', strtotime((string) ($order['ordered_at'] ?? 'now')))) ?></td>
                            <td><?= !empty($order['received_at']) ? e(date('M d, Y g:i A', strtotime((string) $order['received_at']))) : '<span class="text-muted">Pending</span>' ?></td>
                            <td class="text-end"><?= number_format($orderedTotal) ?></td>
                            <td class="text-end"><?= number_format($receivedTotal) ?></td>
                            <td>
                                <div class="ops-progress" aria-label="Receiving progress">
                                    <span style="width: <?= $progress ?>%"></span>
                                </div>
                                <div class="small ops-muted mt-2"><?= $progress ?>% received</div>
                            </td>
                            <td class="text-end"><?= number_format((int) ($order['item_lines'] ?? 0)) ?></td>
                            <td>
                                <span class="ops-status <?= e(match ($status) {
                                    'received' => 'is-success',
                                    'partial' => 'is-warning',
                                    'cancelled' => 'is-danger',
                                    default => 'is-info',
                                }) ?>">
                                    <?= e(ucfirst($status)) ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary receiving-detail-btn" data-po-id="<?= (int) ($order['po_id'] ?? 0) ?>">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="receivingHistoryEmpty" class="ops-empty d-none mt-3">
            No purchase orders match the current filters.
        </div>
    </section>
</main>

<div class="modal fade" id="receivingHistoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable ops-preview-dialog">
        <div class="modal-content ops-preview-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="receivingHistoryModalLabel">Receiving Detail</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="receivingHistoryModalBody"></div>
        </div>
    </div>
</div>

<script>
window.PURCHASE_RECEIVING_HISTORY = <?= json_encode([
    'orders' => $orders,
    'details' => $details,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<?php require __DIR__ . '/../components/js_script.php'; ?>
<script src="/inventory_system/assets/js/purchase-receiving-history.js"></script>
</body>
</html>
