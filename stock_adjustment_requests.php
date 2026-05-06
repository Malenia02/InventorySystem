<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap/app.php';
require_once __DIR__ . '/middleware/Middleware.php';
require_once __DIR__ . '/controllers/StockAdjustmentController.php';

Middleware::auth()->role(['admin', 'cashier', 'staff']);

$isAdmin = Middleware::is('admin');
$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
$sessionRole = (string) ($_SESSION['role'] ?? 'staff');

if ($sessionUserId <= 0) {
    header('Location: /inventory_system/login.php');
    exit;
}

StockAdjustmentController::ensureSchema($conn);
ProductController::ensureStockMovementSchema($conn);

$csrfToken = Middleware::generateCsrfToken();
$products = StockAdjustmentController::requestableProducts($conn);
$requests = StockAdjustmentController::listRequests($conn, $sessionRole, $sessionUserId, 'all', 100);
$counts = StockAdjustmentController::countByStatus($conn, $sessionRole, $sessionUserId);
$pendingCount = (int) ($counts['pending'] ?? 0);
$approvedCount = (int) ($counts['approved'] ?? 0);
$declinedCount = (int) ($counts['declined'] ?? 0);
$supplierOptions = [];

foreach ($products as $product) {
    $supplierId = (int) ($product['supplier_id'] ?? 0);
    if ($supplierId <= 0 || isset($supplierOptions[$supplierId])) {
        continue;
    }

    $supplierOptions[$supplierId] = [
        'supplier_id' => $supplierId,
        'supplier_name' => (string) ($product['supplier_name'] ?? 'Supplier'),
    ];
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function personLabel(array $row, string $prefix): string
{
    $name = trim((string) ($row[$prefix . '_first_name'] ?? '') . ' ' . (string) ($row[$prefix . '_last_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    return (string) ($row[$prefix . '_username'] ?? 'User');
}

function dateTimeText(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '-';
    }

    $timestamp = strtotime($value);
    return $timestamp !== false ? date('M d, Y g:i A', $timestamp) : '-';
}

function requestStatusClass(string $status): string
{
    return match (strtolower($status)) {
        'approved' => 'is-approved',
        'declined' => 'is-declined',
        default => 'is-pending',
    };
}

function requestDirectionLabel(string $direction): string
{
    return $direction === 'stock_in' ? 'Stock In' : 'Stock Out';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/components/head.php'; ?>
    <title>Stock Adjustment Requests</title>
    <link rel="stylesheet" href="/inventory_system/assets/css/stock-adjustment-requests.css">
</head>
<body>
<?php
require __DIR__ . '/components/header.php';
require __DIR__ . '/components/sidebar.php';
?>

<main id="main" class="main stock-request-page">
    <div class="request-hero card border-0 shadow-sm">
        <div class="request-hero__body">
            <div>
                <span class="request-kicker"><?= $isAdmin ? 'Inventory Control Desk' : 'Inventory Request Center' ?></span>
                <h1>Stock Adjustment Requests</h1>
                <p>
                    <?= $isAdmin
                        ? 'Review quantity corrections, emergency stock-outs, and inbound adjustments before they change inventory.'
                        : 'Send a stock correction request when inventory needs to be added or removed but you do not have direct control permissions.' ?>
                </p>
            </div>
            <div class="request-hero__stats">
                <div class="request-stat">
                    <span>Pending review</span>
                    <strong id="stockRequestCountPending"><?= $pendingCount ?></strong>
                </div>
                <div class="request-stat">
                    <span>Approved</span>
                    <strong id="stockRequestCountApproved"><?= $approvedCount ?></strong>
                </div>
                <div class="request-stat">
                    <span>Declined</span>
                    <strong id="stockRequestCountDeclined"><?= $declinedCount ?></strong>
                </div>
            </div>
        </div>
    </div>

    <div id="stockRequestFeedback" class="mt-3"></div>

    <div class="row g-4 mt-1">
        <div class="col-xl-5">
            <section class="request-panel card border-0 shadow-sm">
                <div class="card-body">
                    <div class="request-panel__head request-panel__head--stack">
                        <div>
                            <span class="request-panel__eyebrow"><?= $isAdmin ? 'Quick Overview' : 'New Request' ?></span>
                            <h2><?= $isAdmin ? 'Adjustment policy snapshot' : 'Submit stock adjustment request' ?></h2>
                            <p>
                                <?= $isAdmin
                                    ? 'Requests only affect stock after approval. Every review updates the activity log and leaves a clear audit trail.'
                                    : 'Choose the product, quantity, and reason. The owner/admin can approve or decline the request in real time.' ?>
                            </p>
                        </div>
                    </div>

                    <?php if (!$isAdmin): ?>
                        <form id="stockAdjustmentRequestForm" class="request-form">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                            <div class="mb-3">
                                <label class="form-label">Product</label>
                                <select name="product_id" id="stockRequestProductSelect" class="form-select" required>
                                    <option value="">Select product</option>
                                    <?php foreach ($products as $product): ?>
                                        <option
                                            value="<?= (int) ($product['product_id'] ?? 0) ?>"
                                            data-stock="<?= (int) ($product['quantity'] ?? 0) ?>"
                                            data-category="<?= e((string) ($product['category_name'] ?? 'Uncategorized')) ?>"
                                            data-supplier="<?= e((string) ($product['supplier_name'] ?? 'No supplier')) ?>"
                                            data-supplier-id="<?= (int) ($product['supplier_id'] ?? 0) ?>"
                                        >
                                            <?= e((string) ($product['product_name'] ?? 'Product')) ?> (<?= (int) ($product['quantity'] ?? 0) ?> on hand)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="request-summary-card" id="stockRequestProductSummary">
                                <span>Current stock</span>
                                <strong id="stockRequestProductQty">Choose a product</strong>
                                <small id="stockRequestProductMeta">Live product details will appear here.</small>
                            </div>

                            <div class="row g-3 mt-1">
                                <div class="col-md-6">
                                    <label class="form-label">Adjustment direction</label>
                                    <select name="direction" id="stockRequestDirection" class="form-select" required>
                                        <option value="stock_out">Stock Out</option>
                                        <option value="stock_in">Stock In</option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Quantity</label>
                                    <input type="number" name="quantity" class="form-control" min="1" required placeholder="Enter quantity">
                                </div>
                            </div>

                            <div class="mt-3">
                                <label class="form-label">Adjustment type</label>
                                <select name="adjustment_type" id="stockRequestType" class="form-select" required></select>
                            </div>

                            <div class="mt-3 d-none" id="stockRequestSupplierWrap">
                                <label class="form-label">Supplier link</label>
                                <select name="supplier_id" id="stockRequestSupplier" class="form-select">
                                    <option value="">No supplier link</option>
                                    <?php foreach ($supplierOptions as $supplier): ?>
                                        <option value="<?= (int) ($supplier['supplier_id'] ?? 0) ?>">
                                            <?= e((string) ($supplier['supplier_name'] ?? 'Supplier')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mt-3">
                                <label class="form-label">Reason</label>
                                <textarea name="reason" class="form-control" rows="3" maxlength="500" required placeholder="Explain why the stock needs to be added or removed."></textarea>
                            </div>

                            <div class="mt-3">
                                <label class="form-label">Notes (optional)</label>
                                <textarea name="notes" class="form-control" rows="2" maxlength="500" placeholder="Add context for the owner/admin reviewing this request."></textarea>
                            </div>

                            <div class="request-form__actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-send me-1"></i>Send Request
                                </button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="request-summary-grid">
                            <div class="request-summary-card">
                                <span>Immediate controls</span>
                                <strong>Manage Products</strong>
                                <small>Direct restock and stock-out remain available in product management.</small>
                            </div>
                            <div class="request-summary-card">
                                <span>Review rule</span>
                                <strong>Approve applies stock</strong>
                                <small>When you approve a request, the inventory changes instantly and the result is logged.</small>
                            </div>
                            <div class="request-summary-card">
                                <span>Best practice</span>
                                <strong>Decline with note</strong>
                                <small>Provide the cashier or staff member a clear correction note when the request should not proceed.</small>
                            </div>
                            <div class="request-summary-card">
                                <span>Audit trail</span>
                                <strong>Before / after qty</strong>
                                <small>Approved requests record the quantity impact before and after the adjustment.</small>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="col-xl-7">
            <section class="request-panel card border-0 shadow-sm">
                <div class="card-body">
                    <div class="request-panel__head">
                        <div>
                            <span class="request-panel__eyebrow"><?= $isAdmin ? 'Approval Queue' : 'My Request Timeline' ?></span>
                            <h2><?= $isAdmin ? 'Review incoming adjustment requests' : 'Track request progress' ?></h2>
                            <p><?= $isAdmin ? 'Approve or decline each adjustment without leaving the page.' : 'Your latest requests update live as soon as the owner/admin reviews them.' ?></p>
                        </div>
                        <a href="/inventory_system/admin/activity_log.php" class="btn btn-light border">
                            <i class="bi bi-clock-history me-1"></i>Activity Log
                        </a>
                    </div>

                    <div class="request-filter-row">
                        <button type="button" class="request-filter-chip is-active" data-filter="all">All</button>
                        <button type="button" class="request-filter-chip" data-filter="pending">Pending</button>
                        <button type="button" class="request-filter-chip" data-filter="approved">Approved</button>
                        <button type="button" class="request-filter-chip" data-filter="declined">Declined</button>
                    </div>

                    <div
                        id="stockRequestList"
                        class="request-list<?= $requests === [] ? ' d-none' : '' ?>"
                        data-role="<?= e(strtolower($sessionRole)) ?>"
                        data-csrf="<?= e($csrfToken) ?>"
                    >
                        <?php foreach ($requests as $request): ?>
                            <?php $status = strtolower((string) ($request['status'] ?? 'pending')); ?>
                            <article class="request-card" data-request-id="<?= (int) ($request['request_id'] ?? 0) ?>" data-status="<?= e($status) ?>">
                                <div class="request-card__top">
                                    <div>
                                        <span class="request-mini-badge <?= e(requestStatusClass($status)) ?>"><?= e(ucfirst($status)) ?></span>
                                        <h3><?= e((string) ($request['product_name'] ?? 'Product')) ?></h3>
                                        <p>
                                            <?= e(requestDirectionLabel((string) ($request['direction'] ?? 'stock_out'))) ?>
                                            <span class="mx-2">|</span>
                                            Qty: <?= (int) ($request['quantity'] ?? 0) ?>
                                            <span class="mx-2">|</span>
                                            Type: <?= e(ucwords(str_replace('_', ' ', (string) ($request['adjustment_type'] ?? 'manual_adjustment')))) ?>
                                        </p>
                                    </div>
                                    <div class="request-card__meta">
                                        <span>Requested</span>
                                        <strong><?= e(dateTimeText($request['requested_at'] ?? null)) ?></strong>
                                    </div>
                                </div>

                                <div class="request-detail-grid">
                                    <div>
                                        <span class="request-label">Reason</span>
                                        <p><?= nl2br(e((string) ($request['reason'] ?? ''))) ?></p>
                                    </div>
                                    <div>
                                        <span class="request-label">Review note</span>
                                        <p><?= nl2br(e((string) ($request['review_note'] ?? 'No review note yet.'))) ?></p>
                                    </div>
                                    <div>
                                        <span class="request-label">Product snapshot</span>
                                        <p>
                                            Category: <?= e((string) ($request['category_name'] ?? 'Uncategorized')) ?><br>
                                            Current stock: <?= (int) ($request['current_quantity'] ?? 0) ?>
                                        </p>
                                    </div>
                                    <div>
                                        <span class="request-label">People</span>
                                        <p>
                                            Requested by: <?= e(personLabel($request, 'requester')) ?><br>
                                            Reviewed by: <?= e(personLabel($request, 'reviewer')) ?>
                                        </p>
                                    </div>
                                </div>

                                <div class="request-foot">
                                    <div class="request-foot__info">
                                        <span>Applied quantity</span>
                                        <strong>
                                            <?php if ($status === 'approved'): ?>
                                                <?= (int) ($request['before_quantity'] ?? 0) ?> → <?= (int) ($request['after_quantity'] ?? 0) ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </strong>
                                    </div>

                                    <?php if ($isAdmin && $status === 'pending'): ?>
                                        <form class="request-review-form" data-request-id="<?= (int) ($request['request_id'] ?? 0) ?>">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="request_id" value="<?= (int) ($request['request_id'] ?? 0) ?>">
                                            <textarea
                                                name="review_note"
                                                class="form-control"
                                                rows="2"
                                                placeholder="Optional approval note or required decline reason."
                                            ></textarea>
                                            <div class="request-review-actions">
                                                <button type="submit" name="decision" value="approved" class="btn btn-success">
                                                    Approve &amp; Apply
                                                </button>
                                                <button type="submit" name="decision" value="declined" class="btn btn-outline-danger">
                                                    Decline
                                                </button>
                                            </div>
                                        </form>
                                    <?php else: ?>
                                        <div class="request-foot__info">
                                            <span>Reviewed at</span>
                                            <strong><?= e(dateTimeText($request['reviewed_at'] ?? null)) ?></strong>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <div id="stockRequestEmptyState" class="request-empty<?= $requests === [] ? '' : ' d-none' ?>">
                        <i class="bi bi-inboxes"></i>
                        <span><?= $isAdmin ? 'No stock adjustment requests yet.' : 'You have not submitted any stock adjustment requests yet.' ?></span>
                    </div>
                </div>
            </section>
        </div>
    </div>
</main>

<?php require __DIR__ . '/components/js_script.php'; ?>
<script src="/inventory_system/assets/js/stock-adjustment-requests.js"></script>

</body>
</html>
