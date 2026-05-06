<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/ShiftClosingController.php';
require_once __DIR__ . '/../controllers/StockAdjustmentController.php';

Middleware::auth()->role(['admin']);

ShiftClosingController::ensureSchema($conn);
StockAdjustmentController::ensureSchema($conn);

$shiftRequests = ShiftClosingController::listEditRequests($conn, 'admin', null, 'all', 100);
$stockRequests = StockAdjustmentController::listRequests($conn, 'admin', null, 'all', 100);

$approvalFeed = [];
foreach ($shiftRequests as $request) {
    $requester = trim((string) ($request['requester_first_name'] ?? '') . ' ' . (string) ($request['requester_last_name'] ?? '')) ?: (string) ($request['requester_username'] ?? 'Cashier');
    $target = trim((string) ($request['target_first_name'] ?? '') . ' ' . (string) ($request['target_last_name'] ?? '')) ?: (string) ($request['target_username'] ?? 'Cashier');
    $reviewer = trim((string) ($request['reviewer_first_name'] ?? '') . ' ' . (string) ($request['reviewer_last_name'] ?? '')) ?: (string) ($request['reviewer_username'] ?? '');

    $approvalFeed[] = [
        'id' => (int) ($request['request_id'] ?? 0),
        'type' => 'shift',
        'status' => (string) ($request['status'] ?? 'pending'),
        'title' => 'Shift edit request',
        'subject' => date('M d, Y', strtotime((string) ($request['shift_date'] ?? date('Y-m-d')))),
        'person' => $requester,
        'target' => $target,
        'reviewer' => $reviewer,
        'requested_at' => (string) ($request['requested_at'] ?? ''),
        'reviewed_at' => (string) ($request['reviewed_at'] ?? ''),
        'approved_until' => (string) ($request['approved_until'] ?? ''),
        'reason' => (string) ($request['request_reason'] ?? ''),
        'review_note' => (string) ($request['review_note'] ?? ''),
        'link' => '/inventory_system/shift_edit_requests.php',
        'details' => [
            'Shift date' => date('M d, Y', strtotime((string) ($request['shift_date'] ?? date('Y-m-d')))),
            'Target cashier' => $target,
            'Closed at' => !empty($request['closed_at']) ? date('M d, Y g:i A', strtotime((string) $request['closed_at'])) : '-',
            'Approved until' => !empty($request['approved_until']) ? date('M d, Y g:i A', strtotime((string) $request['approved_until'])) : '-',
        ],
    ];
}
foreach ($stockRequests as $request) {
    $requester = trim((string) ($request['requester_first_name'] ?? '') . ' ' . (string) ($request['requester_last_name'] ?? '')) ?: (string) ($request['requester_username'] ?? 'Staff');
    $reviewer = trim((string) ($request['reviewer_first_name'] ?? '') . ' ' . (string) ($request['reviewer_last_name'] ?? '')) ?: (string) ($request['reviewer_username'] ?? '');
    $direction = strtolower((string) ($request['direction'] ?? 'stock_out')) === 'stock_in' ? 'Stock In' : 'Stock Out';

    $approvalFeed[] = [
        'id' => (int) ($request['request_id'] ?? 0),
        'type' => 'stock',
        'status' => (string) ($request['status'] ?? 'pending'),
        'title' => 'Stock adjustment request',
        'subject' => (string) ($request['product_name'] ?? 'Product'),
        'person' => $requester,
        'target' => (string) ($request['product_name'] ?? 'Product'),
        'reviewer' => $reviewer,
        'requested_at' => (string) ($request['requested_at'] ?? ''),
        'reviewed_at' => (string) ($request['reviewed_at'] ?? ''),
        'reason' => (string) ($request['reason'] ?? ''),
        'review_note' => (string) ($request['review_note'] ?? ''),
        'link' => '/inventory_system/stock_adjustment_requests.php',
        'details' => [
            'Product' => (string) ($request['product_name'] ?? 'Product'),
            'Category' => (string) ($request['category_name'] ?? 'Uncategorized'),
            'Direction' => $direction,
            'Quantity' => number_format((int) ($request['quantity'] ?? 0)),
            'Adjustment type' => ucwords(str_replace('_', ' ', (string) ($request['adjustment_type'] ?? 'manual_adjustment'))),
            'Current stock' => number_format((int) ($request['current_quantity'] ?? 0)),
            'Before / after' => isset($request['before_quantity'], $request['after_quantity'])
                ? number_format((int) $request['before_quantity']) . ' -> ' . number_format((int) $request['after_quantity'])
                : '-',
            'Notes' => (string) ($request['notes'] ?? '') !== '' ? (string) $request['notes'] : '-',
        ],
    ];
}

usort($approvalFeed, static fn(array $a, array $b): int => strcmp((string) ($b['requested_at'] ?? ''), (string) ($a['requested_at'] ?? '')));

$pendingApprovals = count(array_filter($approvalFeed, static fn(array $row): bool => strtolower((string) ($row['status'] ?? '')) === 'pending'));
$approvedApprovals = count(array_filter($approvalFeed, static fn(array $row): bool => strtolower((string) ($row['status'] ?? '')) === 'approved'));
$declinedApprovals = count(array_filter($approvalFeed, static fn(array $row): bool => strtolower((string) ($row['status'] ?? '')) === 'declined'));
$shiftApprovalCount = count(array_filter($approvalFeed, static fn(array $row): bool => strtolower((string) ($row['type'] ?? '')) === 'shift'));
$stockApprovalCount = count(array_filter($approvalFeed, static fn(array $row): bool => strtolower((string) ($row['type'] ?? '')) === 'stock'));

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/../components/head.php'; ?>
    <title>Approval Center</title>
    <link rel="stylesheet" href="/inventory_system/assets/css/ops-suite.css">
</head>
<body>
<?php require __DIR__ . '/../components/header.php'; ?>
<?php require __DIR__ . '/../components/sidebar.php'; ?>

<main id="main" class="main ops-page">
    <section class="ops-hero">
        <div class="ops-hero__body">
            <div>
                <span class="ops-eyebrow">Operations Queue</span>
                <h1 class="ops-title">Unified Approval Center</h1>
                <p class="ops-copy">Monitor, preview, approve, and decline shift edit requests and stock adjustment requests from one focused review queue.</p>
            </div>
            <div class="ops-hero__stats">
                <div class="ops-stat"><span>Pending</span><strong id="approvalPendingCount"><?= number_format($pendingApprovals) ?></strong><small>needs review</small></div>
                <div class="ops-stat"><span>Approved</span><strong id="approvalApprovedCount"><?= number_format($approvedApprovals) ?></strong><small>already granted</small></div>
                <div class="ops-stat"><span>Declined</span><strong id="approvalDeclinedCount"><?= number_format($declinedApprovals) ?></strong><small>closed requests</small></div>
                <div class="ops-stat"><span>Sources</span><strong id="approvalSourceCount"><?= number_format($shiftApprovalCount) ?> / <?= number_format($stockApprovalCount) ?></strong><small>shift requests / stock requests</small></div>
            </div>
        </div>
    </section>

    <section class="ops-filter-card mb-4">
        <div class="ops-filter-grid">
            <div class="is-wide">
                <label>Search person, subject, or reason</label>
                <input type="search" id="approvalSearch" class="form-control" placeholder="Search approvals">
            </div>
            <div>
                <label>Source</label>
                <select id="approvalType" class="form-select">
                    <option value="all">All requests</option>
                    <option value="shift">Shift edit requests</option>
                    <option value="stock">Stock adjustment requests</option>
                </select>
            </div>
            <div>
                <label>Status</label>
                <select id="approvalStatus" class="form-select">
                    <option value="all">All statuses</option>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="declined">Declined</option>
                </select>
            </div>
        </div>
    </section>

    <section class="ops-panel">
        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
            <div>
                <span class="ops-eyebrow">Approval Feed</span>
                <h2 class="h4 mb-1" style="color:#012970;font-weight:800;">Latest review requests</h2>
                <p class="ops-muted mb-0">Open a request preview to review the context, then approve or decline without leaving this page.</p>
            </div>
            <div id="approvalMeta" class="ops-muted small">Showing all approval requests.</div>
        </div>

        <div class="ops-chip-row">
            <button type="button" class="ops-chip approval-chip is-active" data-status="all">All</button>
            <button type="button" class="ops-chip approval-chip" data-status="pending">Pending</button>
            <button type="button" class="ops-chip approval-chip" data-status="approved">Approved</button>
            <button type="button" class="ops-chip approval-chip" data-status="declined">Declined</button>
        </div>

        <div id="approvalFeed" class="row g-3">
            <?php foreach ($approvalFeed as $index => $item): ?>
                        <?php $status = strtolower((string) ($item['status'] ?? 'pending')); ?>
                        <div
                            class="col-lg-6 approval-card-wrap"
                            data-search="<?= e(strtolower((string) ($item['subject'] ?? '') . ' ' . (string) ($item['person'] ?? '') . ' ' . (string) ($item['reason'] ?? ''))) ?>"
                            data-type="<?= e((string) ($item['type'] ?? 'shift')) ?>"
                            data-status="<?= e($status) ?>"
                            data-id="<?= (int) ($item['id'] ?? 0) ?>"
                        >
                    <article class="ops-card ops-approval-card p-4 h-100">
                        <div class="d-flex justify-content-between gap-3 align-items-start mb-3">
                            <div>
                                <span class="ops-status <?= e(match ($status) {
                                    'approved' => 'is-success',
                                    'declined' => 'is-danger',
                                    default => 'is-warning',
                                }) ?>"><?= e(ucfirst($status)) ?></span>
                                <h3 class="h5 mt-3 mb-1" style="color:#012970;font-weight:800;"><?= e((string) ($item['title'] ?? 'Approval request')) ?></h3>
                                <p class="ops-muted mb-0"><?= e((string) ($item['subject'] ?? '')) ?></p>
                            </div>
                            <span class="ops-status is-info"><?= e(strtoupper((string) ($item['type'] ?? 'shift'))) ?></span>
                        </div>

                        <div class="ops-detail-grid mb-3">
                            <div>
                                <span class="ops-detail-label">Requested by</span>
                                <p class="mb-0"><?= e((string) ($item['person'] ?? 'User')) ?></p>
                            </div>
                            <div>
                                <span class="ops-detail-label">Requested at</span>
                                <p class="mb-0"><?= e(date('M d, Y g:i A', strtotime((string) ($item['requested_at'] ?? 'now')))) ?></p>
                            </div>
                        </div>

                        <div class="mb-3">
                            <span class="ops-detail-label">Reason</span>
                            <p class="mb-0 mt-2"><?= nl2br(e((string) ($item['reason'] ?? 'No reason provided.'))) ?></p>
                        </div>

                        <div class="d-flex justify-content-between align-items-end gap-3">
                            <div class="small ops-muted approval-card-note"><?= $status !== 'pending' ? 'Review note: ' . e((string) ($item['review_note'] ?? 'No note provided.')) : 'Open the preview to review this request.' ?></div>
                            <div class="d-flex gap-2 flex-wrap justify-content-end">
                                <span class="ops-mini-badge"><?= e($item['type'] === 'shift' ? 'Shift workflow' : 'Stock workflow') ?></span>
                                <button type="button" class="btn btn-primary approval-preview-btn" data-key="<?= e((string) ($item['type'] ?? 'shift') . ':' . (int) ($item['id'] ?? 0)) ?>">Preview</button>
                                <a href="<?= e((string) ($item['link'] ?? '#')) ?>" class="btn btn-outline-primary">Source</a>
                            </div>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>

        <div id="approvalEmpty" class="ops-empty d-none mt-3">No approval requests match the current filters.</div>
    </section>
</main>

<div class="modal fade" id="approvalReviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable ops-preview-dialog">
        <div class="modal-content ops-preview-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="approvalReviewTitle">Approval Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="approvalReviewBody"></div>
                <form id="approvalReviewForm" class="ops-review-form mt-3">
                    <input type="hidden" name="request_key" id="approvalRequestKey">
                    <input type="hidden" name="decision" id="approvalDecision">
                    <label for="approvalReviewNote">Review note</label>
                    <textarea id="approvalReviewNote" class="form-control" rows="3" maxlength="2000" placeholder="Optional for approval, required for decline"></textarea>
                    <label for="approvalStepUpPassword" class="mt-3">Admin password (required if step-up expired)</label>
                    <input type="password" id="approvalStepUpPassword" class="form-control" autocomplete="current-password" placeholder="Enter your password for protected approval actions">
                    <div id="approvalReviewFeedback" class="mt-3"></div>
                    <div class="d-flex flex-wrap justify-content-end gap-2 mt-3">
                        <button type="button" class="btn btn-outline-primary" data-bs-dismiss="modal">Close</button>
                        <button type="button" class="btn btn-outline-danger approval-decision-btn" data-decision="declined">Decline</button>
                        <button type="button" class="btn btn-primary approval-decision-btn" data-decision="approved">Approve</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
window.APPROVAL_CENTER_DATA = <?= json_encode([
    'items' => array_reduce($approvalFeed, static function (array $carry, array $item): array {
        $key = (string) ($item['type'] ?? 'shift') . ':' . (int) ($item['id'] ?? 0);
        $carry[$key] = $item;
        return $carry;
    }, []),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<?php require __DIR__ . '/../components/js_script.php'; ?>
<script src="/inventory_system/assets/js/approval-center.js"></script>
</body>
</html>
