<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap/app.php';
require_once __DIR__ . '/middleware/Middleware.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/ShiftClosingController.php';
require_once __DIR__ . '/controllers/PosConfigController.php';

Middleware::auth()->role(['admin', 'cashier']);

$isAdmin = Middleware::is('admin');
$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
$sessionRole = (string) ($_SESSION['role'] ?? '');

if ($sessionUserId <= 0) {
    header('Location: /inventory_system/login.php');
    exit;
}

ShiftClosingController::ensureSchema($conn);
$csrfToken = Middleware::generateCsrfToken();
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
$selectedShiftId = max(0, (int) ($_GET['shift_closing_id'] ?? $_POST['shift_closing_id'] ?? 0));
$successMessage = null;
$errorMessage = null;

if (isset($_SESSION['shift_edit_request_flash']) && is_array($_SESSION['shift_edit_request_flash'])) {
    $successMessage = isset($_SESSION['shift_edit_request_flash']['success'])
        ? (string) $_SESSION['shift_edit_request_flash']['success']
        : null;
    $errorMessage = isset($_SESSION['shift_edit_request_flash']['error'])
        ? (string) $_SESSION['shift_edit_request_flash']['error']
        : null;
    unset($_SESSION['shift_edit_request_flash']);
}

$logConfig = [
    'table' => $table_activity_logs,
    'col_user_id' => $activity_log_user_id,
    'col_action' => $activity_log_action,
    'col_desc' => $activity_log_desc,
    'col_ip' => $activity_log_ip,
    'col_created' => $activity_log_created,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!AuthController::validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Security token mismatch. Please refresh and try again.');
        }

        if (isset($_POST['submit_shift_edit_request'])) {
            $shiftClosingId = max(0, (int) ($_POST['shift_closing_id'] ?? 0));
            $reason = (string) ($_POST['request_reason'] ?? '');
            $created = ShiftClosingController::submitEditRequest($conn, $shiftClosingId, $sessionUserId, $reason);

            AuthController::logActivity(
                $conn,
                $logConfig,
                $sessionUserId,
                'shift_edit_request_create',
                sprintf(
                    'Requested edit access for shift %s (record #%d).',
                    (string) ($created['shift_date'] ?? date('Y-m-d')),
                    (int) ($created['shift_closing_id'] ?? 0)
                ),
                'shift_closing',
                (int) ($created['shift_closing_id'] ?? 0),
                'warning'
            );

            $_SESSION['shift_edit_request_flash'] = [
                'success' => 'Shift edit request sent to the owner/admin for review.',
            ];

            header('Location: /inventory_system/shift_edit_requests.php?' . http_build_query([
                'shift_closing_id' => $shiftClosingId,
                'status' => $statusFilter,
            ]));
            exit;
        }

        if ($isAdmin && isset($_POST['review_shift_edit_request'])) {
            $requestId = max(0, (int) ($_POST['request_id'] ?? 0));
            $decision = (string) ($_POST['decision'] ?? '');
            $reviewNote = (string) ($_POST['review_note'] ?? '');
            $reviewed = ShiftClosingController::reviewEditRequest($conn, $requestId, $sessionUserId, $decision, $reviewNote);

            AuthController::logActivity(
                $conn,
                $logConfig,
                $sessionUserId,
                'shift_edit_request_' . strtolower($reviewed['decision'] ?? 'reviewed'),
                sprintf(
                    'Shift edit request #%d was %s.%s',
                    $requestId,
                    strtolower((string) ($reviewed['decision'] ?? 'reviewed')),
                    !empty($reviewed['approved_until']) ? ' Unlock until ' . (string) $reviewed['approved_until'] . '.' : ''
                ),
                'shift_closing_request',
                $requestId,
                strtolower((string) ($reviewed['decision'] ?? '')) === 'approved' ? 'info' : 'warning'
            );

            $_SESSION['shift_edit_request_flash'] = [
                'success' => strtolower((string) ($reviewed['decision'] ?? '')) === 'approved'
                    ? 'Request approved and temporary edit access has been opened.'
                    : 'Request declined successfully.',
            ];

            header('Location: /inventory_system/shift_edit_requests.php?' . http_build_query([
                'status' => $statusFilter,
                'shift_closing_id' => $selectedShiftId > 0 ? $selectedShiftId : null,
            ]));
            exit;
        }
    } catch (Throwable $e) {
        error_log('[shift_edit_requests.php] ' . $e->getMessage());
        $errorMessage = $e instanceof InvalidArgumentException || $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Unable to process the shift edit request right now.';
    }
}

$selectedShift = null;
if ($selectedShiftId > 0) {
    $stmt = $conn->prepare("
        SELECT
            sc.shift_closing_id,
            sc.user_id,
            sc.shift_date,
            sc.opened_at,
            sc.closed_at,
            sc.editable_until,
            sc.status,
            sc.total_sales,
            sc.expected_cash,
            sc.counted_cash,
            sc.variance,
            sc.notes,
            u.first_name,
            u.last_name,
            u.username,
            u.role
        FROM shift_closings sc
        LEFT JOIN users u ON u.user_id = sc.user_id
        WHERE sc.shift_closing_id = :shift_closing_id
        LIMIT 1
    ");
    $stmt->execute([':shift_closing_id' => $selectedShiftId]);
    $selectedShift = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($selectedShift !== null && !$isAdmin && (int) ($selectedShift['user_id'] ?? 0) !== $sessionUserId) {
        $selectedShift = null;
        $errorMessage = 'You can only view your own shift edit requests.';
    }
}

$selectedShiftMeta = $selectedShift !== null
    ? ShiftClosingController::closingEditMeta($selectedShift, $isAdmin)
    : null;

$allRequests = ShiftClosingController::listEditRequests($conn, $sessionRole, $sessionUserId, 'all', 100);
$requests = $statusFilter === 'all'
    ? $allRequests
    : array_values(array_filter(
        $allRequests,
        static fn(array $request): bool => strtolower((string) ($request['status'] ?? '')) === $statusFilter
    ));

$pendingCount = count(array_filter($allRequests, static fn(array $request): bool => ($request['status'] ?? '') === 'pending'));
$approvedCount = count(array_filter($allRequests, static fn(array $request): bool => ($request['status'] ?? '') === 'approved'));
$declinedCount = count(array_filter($allRequests, static fn(array $request): bool => ($request['status'] ?? '') === 'declined'));

$shiftEditWindowHours = PosConfigController::shiftEditWindowHours($conn);
$shiftUnlockWindowHours = PosConfigController::shiftUnlockWindowHours($conn);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function personLabel(array $row, string $firstKey = 'first_name', string $lastKey = 'last_name', string $usernameKey = 'username'): string
{
    $name = trim((string) ($row[$firstKey] ?? '') . ' ' . (string) ($row[$lastKey] ?? ''));
    if ($name !== '') {
        return $name;
    }

    return (string) ($row[$usernameKey] ?? 'User');
}

function requestPersonLabel(array $row, string $prefix): string
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

function requestBadgeClass(string $status): string
{
    return match (strtolower($status)) {
        'approved' => 'is-approved',
        'declined' => 'is-declined',
        default => 'is-pending',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/components/head.php'; ?>
    <title>Shift Edit Requests</title>
    <link rel="stylesheet" href="/inventory_system/assets/css/shift-edit-requests.css">
</head>
<body>
<?php
require __DIR__ . '/components/header.php';
require __DIR__ . '/components/sidebar.php';
?>

<main id="main" class="main shift-request-page">
    <div class="request-hero card border-0 shadow-sm">
        <div class="request-hero__body">
            <div>
                <span class="request-kicker"><?= $isAdmin ? 'Approval Desk' : 'Cashier Access' ?></span>
                <h1>Shift Edit Requests</h1>
                <p>
                    Manage locked shift corrections with a clear approval trail. Cashiers can request access,
                    and owners/admins can approve a timed unlock for safe updates.
                </p>
            </div>
            <div class="request-hero__stats">
                <div class="request-stat">
                    <span>Cashier edit window</span>
                    <strong><?= (int) $shiftEditWindowHours ?> hr</strong>
                </div>
                <div class="request-stat">
                    <span>Approved unlock</span>
                    <strong><?= (int) $shiftUnlockWindowHours ?> hr</strong>
                </div>
            </div>
        </div>
    </div>

    <div id="shiftRequestFeedback" class="mt-4">
        <?php if ($successMessage !== null): ?>
            <div class="alert alert-success"><?= e($successMessage) ?></div>
        <?php endif; ?>
        <?php if ($errorMessage !== null): ?>
            <div class="alert alert-danger"><?= e($errorMessage) ?></div>
        <?php endif; ?>
    </div>

    <section class="section mt-4">
        <div class="row g-4">
            <div class="col-12 col-xl-4">
                <div class="card request-panel h-100">
                    <div class="card-body">
                        <div class="request-panel__head">
                            <div>
                                <span class="request-panel__eyebrow">Selected Shift</span>
                                <h2>Request Access</h2>
                            </div>
                            <?php if ($selectedShift !== null): ?>
                                <span id="selectedShiftStateBadge" class="request-mini-badge <?= !empty($selectedShiftMeta['is_locked']) ? 'is-pending' : 'is-approved' ?>">
                                    <?= !empty($selectedShiftMeta['is_locked']) ? 'Locked' : 'Editable' ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if ($selectedShift === null): ?>
                            <div class="request-empty">
                                <i class="bi bi-journal-x"></i>
                                <strong>No shift selected yet.</strong>
                                <span>Open a locked shift from Shift Management and use the request button to prefill this page.</span>
                            </div>
                        <?php else: ?>
                            <div class="request-shift-card">
                                <div class="request-shift-card__row">
                                    <span>Cashier</span>
                                    <strong><?= e(personLabel($selectedShift)) ?></strong>
                                </div>
                                <div class="request-shift-card__row">
                                    <span>Shift date</span>
                                    <strong><?= e(date('M d, Y', strtotime((string) $selectedShift['shift_date']))) ?></strong>
                                </div>
                                <div class="request-shift-card__row">
                                    <span>Closed at</span>
                                    <strong><?= e(dateTimeText($selectedShift['closed_at'] ?? null)) ?></strong>
                                </div>
                                <div class="request-shift-card__row">
                                    <span>Editable until</span>
                                    <strong><?= e(dateTimeText($selectedShiftMeta['editable_until'] ?? null)) ?></strong>
                                </div>
                            </div>

                            <div id="selectedShiftActionArea">
                            <?php if (!$isAdmin): ?>
                                <?php if (!empty($selectedShiftMeta['is_locked'])): ?>
                                    <form method="POST" class="request-form" id="shiftEditRequestForm">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="shift_closing_id" value="<?= (int) $selectedShift['shift_closing_id'] ?>">
                                        <label class="form-label">Why do you need to update this shift?</label>
                                        <textarea
                                            name="request_reason"
                                            class="form-control"
                                            rows="5"
                                            placeholder="Example: I entered the wrong counted cash after closing and need to correct the drawer total."
                                            required
                                        ></textarea>
                                        <button type="submit" name="submit_shift_edit_request" value="1" class="btn btn-primary w-100 mt-3">
                                            Send Request to Owner/Admin
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <div class="alert alert-info mb-0">
                                        This shift is still editable, so no approval request is needed yet.
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="alert alert-info mb-0">
                                    Admin can review requests below and grant a timed unlock for the cashier.
                                </div>
                            <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-8">
                <div class="card request-panel h-100">
                    <div class="card-body">
                        <div class="request-panel__head request-panel__head--stack">
                            <div>
                                <span class="request-panel__eyebrow"><?= $isAdmin ? 'Queue' : 'History' ?></span>
                                <h2><?= $isAdmin ? 'Review Requests' : 'My Requests' ?></h2>
                            </div>
                            <div class="request-filter-row">
                                <?php foreach (['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'declined' => 'Declined'] as $value => $label): ?>
                                    <a
                                        href="/inventory_system/shift_edit_requests.php?<?= http_build_query([
                                            'status' => $value,
                                            'shift_closing_id' => $selectedShiftId > 0 ? $selectedShiftId : null,
                                        ]) ?>"
                                        class="request-filter-chip <?= $statusFilter === $value ? 'is-active' : '' ?>"
                                        data-filter="<?= e($value) ?>"
                                    >
                                        <?= e($label) ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="request-summary-grid">
                            <div class="request-summary-card">
                                <span>Pending</span>
                                <strong id="requestCountPending"><?= $pendingCount ?></strong>
                            </div>
                            <div class="request-summary-card">
                                <span>Approved</span>
                                <strong id="requestCountApproved"><?= $approvedCount ?></strong>
                            </div>
                            <div class="request-summary-card">
                                <span>Declined</span>
                                <strong id="requestCountDeclined"><?= $declinedCount ?></strong>
                            </div>
                        </div>

                        <?php if ($requests === []): ?>
                            <div class="request-empty mt-4" id="requestEmptyState">
                                <i class="bi bi-inbox"></i>
                                <strong>No requests found for this filter.</strong>
                                <span>Once requests are submitted or reviewed, they will appear here.</span>
                            </div>
                            <div class="request-list d-none" id="requestList" data-role="<?= e($sessionRole) ?>" data-csrf="<?= e($csrfToken) ?>"></div>
                        <?php else: ?>
                            <div class="request-empty mt-4 d-none" id="requestEmptyState">
                                <i class="bi bi-inbox"></i>
                                <strong>No requests found for this filter.</strong>
                                <span>Once requests are submitted or reviewed, they will appear here.</span>
                            </div>
                            <div class="request-list" id="requestList" data-role="<?= e($sessionRole) ?>" data-csrf="<?= e($csrfToken) ?>">
                                <?php foreach ($requests as $request): ?>
                                    <?php $requestStatus = strtolower((string) ($request['status'] ?? 'pending')); ?>
                                    <article class="request-card" data-request-id="<?= (int) $request['request_id'] ?>" data-status="<?= e($requestStatus) ?>">
                                        <div class="request-card__top">
                                            <div>
                                                <span class="request-mini-badge <?= e(requestBadgeClass($requestStatus)) ?>">
                                                    <?= e(ucfirst($requestStatus)) ?>
                                                </span>
                                                <h3><?= e(date('M d, Y', strtotime((string) ($request['shift_date'] ?? date('Y-m-d'))))) ?></h3>
                                                <p>
                                                    Cashier: <?= e(requestPersonLabel($request, 'requester')) ?>
                                                    <?php if ($isAdmin): ?>
                                                        <span class="mx-2">|</span>
                                                        Target: <?= e(requestPersonLabel($request, 'target')) ?>
                                                    <?php endif; ?>
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
                                                <p><?= nl2br(e((string) ($request['request_reason'] ?? ''))) ?></p>
                                            </div>
                                            <div>
                                                <span class="request-label">Review note</span>
                                                <p><?= nl2br(e((string) ($request['review_note'] ?? 'No review note yet.'))) ?></p>
                                            </div>
                                        </div>

                                        <div class="request-foot">
                                            <div class="request-foot__info">
                                                <span>Approved until</span>
                                                <strong><?= e(dateTimeText($request['approved_until'] ?? null)) ?></strong>
                                            </div>
                                            <?php if ($isAdmin && $requestStatus === 'pending'): ?>
                                                <form method="POST" class="request-review-form" data-request-id="<?= (int) $request['request_id'] ?>">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                    <input type="hidden" name="request_id" value="<?= (int) $request['request_id'] ?>">
                                                    <textarea
                                                        name="review_note"
                                                        class="form-control"
                                                        rows="2"
                                                        placeholder="Optional approval note or required decline reason."
                                                    ></textarea>
                                                    <div class="request-review-actions">
                                                        <button type="submit" name="decision" value="approved" class="btn btn-success">
                                                            Approve Unlock
                                                        </button>
                                                        <button type="submit" name="decision" value="declined" class="btn btn-outline-danger">
                                                            Decline
                                                        </button>
                                                        <input type="hidden" name="review_shift_edit_request" value="1">
                                                    </div>
                                                </form>
                                            <?php else: ?>
                                                <div class="request-foot__info">
                                                    <span>Reviewed by</span>
                                                    <strong>
                                                        <?= e(
                                                            trim(requestPersonLabel($request, 'reviewer')) !== ''
                                                                ? requestPersonLabel($request, 'reviewer')
                                                                : '-'
                                                        ) ?>
                                                    </strong>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<?php require __DIR__ . '/components/footer.php'; ?>
<?php require __DIR__ . '/components/js_script.php'; ?>
<script src="/inventory_system/assets/js/shift-edit-requests.js"></script>
</body>
</html>
