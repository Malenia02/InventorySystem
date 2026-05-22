<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/SaleActionRequestController.php';
require_once __DIR__ . '/../controllers/SaleController.php';

Middleware::auth()->role(['admin']);

$requests = SaleActionRequestController::listRequests($conn, 'admin', (int) ($_SESSION['user_id'] ?? 0), 250);
$csrfToken = Middleware::generateCsrfToken();

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function reqPerson(array $row, string $prefix): string
{
    $name = trim((string) ($row[$prefix . '_first_name'] ?? '') . ' ' . (string) ($row[$prefix . '_last_name'] ?? ''));
    return $name !== '' ? $name : (string) ($row[$prefix . '_username'] ?? 'User');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/../components/head.php'; ?>
    <title>Sale Action Requests</title>
    <link rel="stylesheet" href="/inventory_system/assets/css/ops-suite.css">
</head>
<body>
<?php require __DIR__ . '/../components/header.php'; ?>
<?php require __DIR__ . '/../components/sidebar.php'; ?>

<main id="main" class="main ops-page">
    <section class="ops-hero">
        <div class="ops-hero__body">
            <div>
                <span class="ops-eyebrow">Controlled Sale Adjustments</span>
                <h1 class="ops-title">Sale Action Requests</h1>
                <p class="ops-copy">Approve or decline cashier requests for voids and item returns while keeping stock restoration under admin control.</p>
            </div>
            <div class="ops-hero__stats">
                <div class="ops-stat"><span>Total loaded</span><strong id="saleReqCount"><?= number_format(count($requests)) ?></strong><small>request records</small></div>
                <div class="ops-stat"><span>Pending</span><strong id="saleReqPending"><?= number_format(count(array_filter($requests, static fn(array $r): bool => ($r['status'] ?? '') === 'pending'))) ?></strong><small>need admin review</small></div>
                <div class="ops-stat"><span>Approved</span><strong id="saleReqApproved"><?= number_format(count(array_filter($requests, static fn(array $r): bool => ($r['status'] ?? '') === 'approved'))) ?></strong><small>already applied</small></div>
                <div class="ops-stat"><span>Declined</span><strong id="saleReqDeclined"><?= number_format(count(array_filter($requests, static fn(array $r): bool => ($r['status'] ?? '') === 'declined'))) ?></strong><small>not applied</small></div>
            </div>
        </div>
    </section>

    <div id="saleRequestFeedback" class="mb-3"></div>

    <section class="ops-filter-card mb-4">
        <div class="ops-filter-grid">
            <div class="is-wide">
                <label>Search transaction, cashier, reason, or status</label>
                <input type="search" id="saleReqSearch" class="form-control" placeholder="Search requests">
            </div>
            <div>
                <label>Status</label>
                <select id="saleReqStatus" class="form-select">
                    <option value="all">All statuses</option>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="declined">Declined</option>
                </select>
            </div>
            <div>
                <label>Action</label>
                <select id="saleReqAction" class="form-select">
                    <option value="all">All actions</option>
                    <option value="void_sale">Void sale</option>
                    <option value="return_item">Return item</option>
                </select>
            </div>
            <div>
                <label>Date from</label>
                <input type="date" id="saleReqDateFrom" class="form-control">
            </div>
            <div>
                <label>Date to</label>
                <input type="date" id="saleReqDateTo" class="form-control">
            </div>
        </div>
    </section>

    <section class="ops-table-card">
        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
            <div>
                <span class="ops-eyebrow">Review Queue</span>
                <h2 class="h4 mb-1" style="color:#012970;font-weight:800;">Cashier requests</h2>
                <p class="ops-muted mb-0">Approval applies the existing void or return logic and restores stock automatically.</p>
            </div>
            <div id="saleReqMeta" class="ops-muted small">Showing all loaded requests.</div>
        </div>
        <div class="table-responsive">
            <table class="table align-middle" id="saleActionRequestTable">
                <thead>
                    <tr>
                        <th>Transaction</th>
                        <th>Request</th>
                        <th>Cashier</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $request): ?>
                        <?php
                        $transactionNo = SaleController::transactionNumber((int) $request['sale_id'], (string) $request['sale_date']);
                        $requester = reqPerson($request, 'requester');
                        $status = strtolower((string) ($request['status'] ?? 'pending'));
                        $actionType = strtolower((string) ($request['action_type'] ?? 'void_sale'));
                        $search = strtolower($transactionNo . ' ' . $requester . ' ' . $status . ' ' . $actionType . ' ' . (string) ($request['reason'] ?? ''));
                        ?>
                        <tr
                            data-request-id="<?= (int) $request['request_id'] ?>"
                            data-status="<?= e($status) ?>"
                            data-action="<?= e($actionType) ?>"
                            data-date="<?= e(substr((string) ($request['created_at'] ?? ''), 0, 10)) ?>"
                            data-search="<?= e($search) ?>"
                        >
                            <td><strong><?= e($transactionNo) ?></strong><div class="small text-muted"><?= e(date('M d, Y h:i A', strtotime((string) $request['sale_date']))) ?></div></td>
                            <td><?= e(ucwords(str_replace('_', ' ', $actionType))) ?><?= $request['quantity'] ? '<div class="small text-muted">Qty: ' . number_format((int) $request['quantity']) . '</div>' : '' ?></td>
                            <td><?= e($requester) ?></td>
                            <td><?= e((string) ($request['reason'] ?? '')) ?></td>
                            <td><span class="ops-status <?= $status === 'approved' ? 'is-success' : ($status === 'declined' ? 'is-danger' : 'is-warning') ?>"><?= e(ucfirst($status)) ?></span></td>
                            <td class="text-end">
                                <?php if ($status === 'pending'): ?>
                                    <div class="d-inline-flex gap-2">
                                        <button type="button" class="btn btn-sm btn-outline-primary sale-req-review" data-decision="approved" data-request-id="<?= (int) $request['request_id'] ?>">Approve</button>
                                        <button type="button" class="btn btn-sm btn-outline-danger sale-req-review" data-decision="declined" data-request-id="<?= (int) $request['request_id'] ?>">Decline</button>
                                    </div>
                                <?php else: ?>
                                    <span class="ops-muted small"><?= e(!empty($request['reviewed_at']) ? date('M d, Y h:i A', strtotime((string) $request['reviewed_at'])) : '-') ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div id="saleReqEmpty" class="ops-empty d-none mt-3">No sale action requests match the current filters.</div>
    </section>
</main>

<div class="modal fade" id="saleRequestReviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content ops-preview-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="saleRequestReviewTitle">Review Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="saleRequestReviewForm" class="ops-review-form">
                    <input type="hidden" name="request_id" id="saleReviewRequestId">
                    <input type="hidden" name="decision" id="saleReviewDecision">
                    <label for="saleReviewNote">Review note</label>
                    <textarea id="saleReviewNote" class="form-control" rows="4" placeholder="Optional note for the cashier"></textarea>
                    <label for="saleReviewStepUpPassword" class="mt-3">Admin password (required if step-up expired)</label>
                    <input type="password" id="saleReviewStepUpPassword" class="form-control" autocomplete="current-password" placeholder="Enter your password for protected approval actions">
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="saleReviewSubmit">Save decision</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
window.SALE_ACTION_REQUESTS = { csrfToken: <?= json_encode($csrfToken) ?> };
</script>
<?php require __DIR__ . '/../components/js_script.php'; ?>
<script src="/inventory_system/assets/js/sale-action-requests.js"></script>
</body>
</html>
