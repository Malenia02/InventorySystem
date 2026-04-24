<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap/app.php';
require_once __DIR__ . '/middleware/Middleware.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/ShiftClosingController.php';

Middleware::auth()->role(['admin', 'cashier']);

$isAdmin = Middleware::is('admin');
$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);

if ($sessionUserId <= 0) {
    header('Location: /inventory_system/login.php');
    exit;
}

$viewUserId = $sessionUserId;
if ($isAdmin && isset($_GET['user_id']) && (int) $_GET['user_id'] > 0) {
    $viewUserId = (int) $_GET['user_id'];
}

$shiftDate = (string) ($_GET['shift_date'] ?? date('Y-m-d'));
$shift = ShiftClosingController::shiftForUser($conn, $viewUserId, $shiftDate);
$summary = ShiftClosingController::summaryForUser($conn, $viewUserId, $shiftDate);
$recentClosings = ShiftClosingController::recentClosings($conn, $isAdmin ? null : $sessionUserId, 10);
$shiftStatus = strtolower((string) ($summary['status'] ?? 'not_started'));
$isShiftStarted = $shift !== null;
$isShiftOpen = $shiftStatus === 'open';
$isShiftClosed = $shiftStatus === 'closed';
$cashiers = [];
$successMessage = null;
$errorMessage = null;
$shiftClosingSocketEvent = null;
$startShiftAnimation = false;

if (isset($_SESSION['shift_closing_flash']) && is_array($_SESSION['shift_closing_flash'])) {
    $successMessage = isset($_SESSION['shift_closing_flash']['success'])
        ? (string) $_SESSION['shift_closing_flash']['success']
        : null;
    $errorMessage = isset($_SESSION['shift_closing_flash']['error'])
        ? (string) $_SESSION['shift_closing_flash']['error']
        : null;
    $shiftClosingSocketEvent = isset($_SESSION['shift_closing_flash']['socket_event']) && is_array($_SESSION['shift_closing_flash']['socket_event'])
        ? $_SESSION['shift_closing_flash']['socket_event']
        : null;
    $startShiftAnimation = !empty($_SESSION['shift_closing_flash']['animate_start']);

    unset($_SESSION['shift_closing_flash']);
}

try {
    $cashierStmt = $conn->query("
        SELECT user_id, first_name, last_name, username, role
        FROM users
        WHERE status = 'active'
          AND role IN ('cashier', 'admin')
        ORDER BY role ASC, first_name ASC, last_name ASC, username ASC
    ");
    $cashiers = $cashierStmt ? ($cashierStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
    error_log('[shift_closing.php] ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!AuthController::validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Security token mismatch. Please refresh and try again.');
        }

        $targetUserId = $sessionUserId;
        if ($isAdmin && isset($_POST['user_id']) && (int) $_POST['user_id'] > 0) {
            $targetUserId = (int) $_POST['user_id'];
        }

        $shiftAction = strtolower(trim((string) ($_POST['shift_action'] ?? 'close')));
        if ($shiftAction === 'start') {
            $started = ShiftClosingController::startShift($conn, $targetUserId, $_POST);

            $_SESSION['shift_closing_flash'] = [
                'success' => 'Shift started successfully. Sales will now be tracked from the opening time.',
                'animate_start' => true,
                'socket_event' => [
                    'event' => 'notification_update',
                    'type' => 'shift_closing_started',
                    'target_roles' => ['admin'],
                    'target_user_ids' => [$targetUserId],
                ],
            ];

            $redirectParams = [
                'shift_date' => $started['shift_date'],
            ];

            if ($isAdmin) {
                $redirectParams['user_id'] = $targetUserId;
            }

            header('Location: /inventory_system/shift_closing.php?' . http_build_query($redirectParams));
            exit;
        }

        $saved = ShiftClosingController::closeShift($conn, $targetUserId, $_POST);

        $_SESSION['shift_closing_flash'] = [
            'success' => $saved['was_updated']
                ? 'Shift closing updated successfully.'
                : 'Shift closed successfully.',
            'socket_event' => [
                'event' => 'notification_update',
                'type' => $saved['was_updated'] ? 'shift_closing_updated' : 'shift_closing_saved',
                'target_roles' => ['admin'],
                'target_user_ids' => [$targetUserId],
            ],
        ];

        $redirectParams = [
            'shift_date' => $saved['shift_date'],
        ];

        if ($isAdmin) {
            $redirectParams['user_id'] = $targetUserId;
        }

        header('Location: /inventory_system/shift_closing.php?' . http_build_query($redirectParams));
        exit;
    } catch (Throwable $e) {
        error_log('[shift_closing.php] ' . $e->getMessage());
        $errorMessage = $e instanceof InvalidArgumentException || $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Unable to close the shift right now.';
    }
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money(float $value): string
{
    return '₱' . number_format($value, 2);
}

function userLabel(array $row): string
{
    $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
    return $name !== '' ? $name : (string) ($row['username'] ?? 'User');
}

function currency(float $value): string
{
    return 'PHP ' . number_format($value, 2);
}

function dateTimeLabel(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '-';
    }

    $timestamp = strtotime($value);
    return $timestamp !== false ? date('M d, g:i A', $timestamp) : '-';
}

function shiftStatusLabel(string $status): string
{
    return match (strtolower($status)) {
        'open' => 'Open',
        'closed' => 'Closed',
        default => 'Not started',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/components/head.php'; ?>
    <title>Shift Closing</title>
    <style>
        .shift-session-card {
            position: relative;
            overflow: hidden;
            border: 1px solid #dfe8f8;
            border-radius: 24px;
            background:
                radial-gradient(circle at top left, rgba(65, 84, 241, 0.14), transparent 34%),
                linear-gradient(135deg, #ffffff 0%, #f6f9ff 100%);
            box-shadow: 0 18px 44px rgba(15, 38, 81, 0.08);
        }

        .shift-session-card::after {
            content: "";
            position: absolute;
            inset: auto -80px -110px auto;
            width: 220px;
            height: 220px;
            border-radius: 999px;
            background: rgba(65, 84, 241, 0.08);
        }

        .shift-session-content {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(260px, 360px);
            gap: 20px;
            padding: 22px;
        }

        .shift-eyebrow {
            margin: 0 0 6px;
            color: #4966c8;
            font-size: 0.74rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .shift-session-title {
            margin: 0;
            color: #012970;
            font-size: clamp(1.35rem, 2vw, 2rem);
            font-weight: 800;
        }

        .shift-session-copy {
            max-width: 680px;
            margin: 8px 0 0;
            color: #6c7fa4;
            line-height: 1.6;
        }

        .shift-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            width: fit-content;
            padding: 7px 12px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 800;
        }

        .shift-status-pill.is-open {
            background: #e8f8ef;
            color: #10734e;
        }

        .shift-status-pill.is-closed {
            background: #eef3ff;
            color: #3650a6;
        }

        .shift-status-pill.is-pending {
            background: #fff4e7;
            color: #9a560e;
        }

        .shift-meta-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 18px;
        }

        .shift-meta-item {
            min-width: 0;
            padding: 12px;
            border: 1px solid #e5edf8;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.72);
        }

        .shift-meta-item span {
            display: block;
            color: #8a9abd;
            font-size: 0.74rem;
            font-weight: 800;
            text-transform: uppercase;
        }

        .shift-meta-item strong {
            display: block;
            margin-top: 4px;
            color: #012970;
            font-size: 0.95rem;
        }

        .shift-start-box {
            padding: 16px;
            border: 1px solid #dfe8f8;
            border-radius: 20px;
            background: #ffffff;
            box-shadow: 0 14px 30px rgba(15, 38, 81, 0.06);
        }

        .shift-start-btn {
            position: relative;
            overflow: hidden;
            min-height: 44px;
            border-radius: 14px;
            font-weight: 800;
        }

        .shift-start-btn::after {
            content: "";
            position: absolute;
            top: 0;
            left: -100%;
            width: 80%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.38), transparent);
            transition: left 0.55s ease;
        }

        .shift-start-btn:hover::after,
        .shift-start-btn.is-starting::after {
            left: 120%;
        }

        .shift-start-celebration {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: grid;
            place-items: center;
            pointer-events: none;
            opacity: 0;
            visibility: hidden;
            background: rgba(4, 20, 58, 0.18);
            transition: opacity 0.2s ease, visibility 0.2s ease;
        }

        .shift-start-celebration.show {
            opacity: 1;
            visibility: visible;
        }

        .shift-start-pop {
            min-width: min(360px, calc(100vw - 40px));
            padding: 26px;
            border-radius: 28px;
            background: #ffffff;
            text-align: center;
            box-shadow: 0 28px 80px rgba(15, 38, 81, 0.24);
            transform: translateY(16px) scale(0.94);
            animation: shiftPop 0.75s cubic-bezier(0.18, 0.9, 0.24, 1.18) forwards;
        }

        .shift-start-pop i {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 72px;
            height: 72px;
            margin-bottom: 14px;
            border-radius: 24px;
            background: #e8f8ef;
            color: #10734e;
            font-size: 2rem;
            animation: shiftPulse 1.1s ease infinite;
        }

        .shift-start-pop strong {
            display: block;
            color: #012970;
            font-size: 1.25rem;
        }

        .shift-start-pop span {
            display: block;
            margin-top: 6px;
            color: #6c7fa4;
        }

        @keyframes shiftPop {
            to {
                transform: translateY(0) scale(1);
            }
        }

        @keyframes shiftPulse {
            0%, 100% {
                box-shadow: 0 0 0 0 rgba(16, 115, 78, 0.22);
            }

            50% {
                box-shadow: 0 0 0 14px rgba(16, 115, 78, 0);
            }
        }

        @media (max-width: 991px) {
            .shift-session-content,
            .shift-meta-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <link rel="stylesheet" href="/inventory_system/assets/css/shift-closing.css">
</head>
<body>
<?php
require __DIR__ . '/components/header.php';
require __DIR__ . '/components/sidebar.php';
?>

<?php if ($startShiftAnimation): ?>
    <div class="shift-start-celebration" id="shiftStartCelebration" aria-live="polite">
        <div class="shift-start-pop">
            <i class="bi bi-check2-circle"></i>
            <strong>Shift started</strong>
            <span>The cashier session is now being tracked.</span>
        </div>
    </div>
<?php endif; ?>

<main id="main" class="main shift-closing-page">
    <section class="shift-closing-hero">
        <div>
            <p class="shift-eyebrow">Cashier control room</p>
            <h1 class="shift-closing-title">Shift Closing</h1>
            <p class="shift-closing-copy">
                Start the cashier session, monitor sales while the shift is active, then close with counted cash and variance notes.
            </p>
        </div>

        <form method="GET" class="shift-filter-card">
            <div class="shift-filter-grid">
                <label>
                    <span>Shift Date</span>
                    <input type="date" name="shift_date" class="form-control" value="<?= e($shiftDate) ?>">
                </label>

                <?php if ($isAdmin): ?>
                    <label>
                        <span>Cashier</span>
                        <select name="user_id" class="form-select">
                            <?php foreach ($cashiers as $cashier): ?>
                                <option value="<?= (int) $cashier['user_id'] ?>" <?= $viewUserId === (int) $cashier['user_id'] ? 'selected' : '' ?>>
                                    <?= e(userLabel($cashier)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>

                <div class="<?= $isAdmin ? 'is-wide' : '' ?>">
                    <button type="submit" class="btn btn-primary w-100 shift-filter-btn">
                        <i class="bi bi-funnel me-1"></i>
                        View Shift
                    </button>
                </div>
            </div>
        </form>
    </section>

    <section class="section shift-closing-shell">
        <div class="row g-4 shift-layout">
            <div class="col-12 col-xxl-8 shift-main-column">
                <div class="shift-session-card mb-4">
                    <div class="shift-session-content">
                        <div>
                            <span class="shift-status-pill <?= $isShiftOpen ? 'is-open' : ($isShiftClosed ? 'is-closed' : 'is-pending') ?>">
                                <i class="bi <?= $isShiftOpen ? 'bi-play-circle' : ($isShiftClosed ? 'bi-check2-circle' : 'bi-hourglass-split') ?>"></i>
                                <?= e(shiftStatusLabel($shiftStatus)) ?>
                            </span>
                            <p class="shift-eyebrow mt-3">Shift session</p>
                            <h2 class="shift-session-title">
                                <?= $isShiftOpen
                                    ? 'Shift is active'
                                    : ($isShiftClosed ? 'Shift already closed' : 'Start shift before selling') ?>
                            </h2>
                            <p class="shift-session-copy">
                                <?= $isShiftOpen
                                    ? 'Sales are being tracked from the opening time. Close the shift when the cashier is done and count the drawer cash.'
                                    : ($isShiftClosed
                                        ? 'This shift has a saved closing record. You can update the counted cash if you need to correct the drawer count.'
                                        : 'Opening the shift records the cashier, opening time, and starting cash so closing reports are easier to audit.') ?>
                            </p>

                            <div class="shift-meta-grid">
                                <div class="shift-meta-item">
                                    <span>Opened</span>
                                    <strong><?= e(dateTimeLabel($summary['opened_at'] ?? null)) ?></strong>
                                </div>
                                <div class="shift-meta-item">
                                    <span>Starting cash</span>
                                    <strong><?= e(currency((float) ($summary['starting_cash'] ?? 0))) ?></strong>
                                </div>
                                <div class="shift-meta-item">
                                    <span>Closed</span>
                                    <strong><?= e(dateTimeLabel($summary['closed_at'] ?? null)) ?></strong>
                                </div>
                            </div>
                        </div>

                        <div class="shift-start-box">
                            <?php if (!$isShiftStarted): ?>
                                <form method="POST" class="row g-3" id="startShiftForm">
                                    <input type="hidden" name="csrf_token" value="<?= e(Middleware::generateCsrfToken()) ?>">
                                    <input type="hidden" name="shift_action" value="start">
                                    <input type="hidden" name="shift_date" value="<?= e($shiftDate) ?>">

                                    <?php if ($isAdmin): ?>
                                        <div class="col-12">
                                            <label class="form-label">Cashier</label>
                                            <select name="user_id" class="form-select">
                                                <?php foreach ($cashiers as $cashier): ?>
                                                    <option value="<?= (int) $cashier['user_id'] ?>" <?= $viewUserId === (int) $cashier['user_id'] ? 'selected' : '' ?>>
                                                        <?= e(userLabel($cashier)) ?> (<?= e(ucfirst((string) ($cashier['role'] ?? ''))) ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    <?php endif; ?>

                                    <div class="col-12">
                                        <label class="form-label">Starting Cash</label>
                                        <input type="number" name="starting_cash" class="form-control" step="0.01" min="0" placeholder="0.00">
                                        <div class="form-text">Optional drawer amount before the first sale.</div>
                                    </div>

                                    <div class="col-12">
                                        <button type="submit" class="btn btn-success w-100 shift-start-btn" id="startShiftButton">
                                            <i class="bi bi-play-fill me-1"></i>
                                            Start Shift
                                        </button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div class="text-center py-3">
                                    <div class="shift-ready-icon">
                                        <i class="bi <?= $isShiftOpen ? 'bi-broadcast-pin' : 'bi-clipboard-check' ?>"></i>
                                    </div>
                                    <h6 class="fw-bold mb-1">
                                        <?= $isShiftOpen ? 'Ready for closing later' : 'Closing saved' ?>
                                    </h6>
                                    <p class="text-muted small mb-0">
                                        <?= $isShiftOpen
                                            ? 'This shift is actively tracking cashier sales.'
                                            : 'The drawer count and variance are already recorded.' ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm shift-panel shift-summary-card">
                    <div class="card-body">
                        <div class="shift-section-head">
                            <div>
                                <h2>Closing Summary</h2>
                                <p>Current numbers for the selected cashier and shift date.</p>
                            </div>
                            <span class="badge bg-primary"><?= e(date('M d, Y', strtotime($shiftDate))) ?></span>
                        </div>

                        <?php if ($successMessage !== null): ?>
                            <div class="alert alert-success mt-3"><?= e($successMessage) ?></div>
                        <?php endif; ?>

                        <?php if ($errorMessage !== null): ?>
                            <div class="alert alert-danger mt-3"><?= e($errorMessage) ?></div>
                        <?php endif; ?>

                        <div class="shift-metric-grid">
                            <div class="shift-metric-card">
                                <i class="bi bi-receipt shift-metric-icon"></i>
                                <span>Transactions</span>
                                <strong><?= number_format((int) ($summary['total_transactions'] ?? 0)) ?></strong>
                            </div>
                            <div class="shift-metric-card is-money">
                                <i class="bi bi-graph-up-arrow shift-metric-icon"></i>
                                <span>Total Sales</span>
                                <strong><?= e(currency((float) ($summary['total_sales'] ?? 0))) ?></strong>
                            </div>
                            <div class="shift-metric-card is-cash">
                                <i class="bi bi-cash-stack shift-metric-icon"></i>
                                <span>Expected Cash</span>
                                <strong><?= e(currency((float) ($summary['expected_cash'] ?? 0))) ?></strong>
                            </div>
                            <div class="shift-metric-card is-money">
                                <i class="bi bi-wallet2 shift-metric-icon"></i>
                                <span>Cash Sales</span>
                                <strong><?= e(currency((float) ($summary['cash_sales'] ?? 0))) ?></strong>
                            </div>
                        </div>

                        <div class="shift-work-grid mt-3">
                            <div class="shift-action-panel">
                                <h3 class="shift-action-panel-title">Payment Breakdown</h3>
                                <div class="table-responsive">
                                        <table class="table table-sm align-middle shift-payment-table">
                                            <thead>
                                                <tr>
                                                    <th>Method</th>
                                                    <th class="text-end">Sales</th>
                                                    <th class="text-end">Amount</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (!empty($summary['payment_breakdown'])): ?>
                                                    <?php foreach ($summary['payment_breakdown'] as $row): ?>
                                                        <tr>
                                                            <td><?= e(ucfirst((string) ($row['payment_method'] ?? 'Unknown'))) ?></td>
                                                            <td class="text-end"><?= number_format((int) ($row['sale_count'] ?? 0)) ?></td>
                                                            <td class="text-end"><?= e(currency((float) ($row['total_amount'] ?? 0))) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr><td colspan="3" class="text-center text-muted">No payments recorded for this date.</td></tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                </div>
                            </div>

                            <div class="shift-action-panel">
                                <h3 class="shift-action-panel-title">Close Drawer</h3>
                                    <?php if (!$isShiftStarted): ?>
                                        <div class="alert alert-warning mb-0">
                                            Start the shift first so the system can save the opening time before closing.
                                        </div>
                                    <?php else: ?>
                                        <form method="POST" class="row g-3">
                                            <input type="hidden" name="csrf_token" value="<?= e(Middleware::generateCsrfToken()) ?>">
                                            <input type="hidden" name="shift_action" value="close">
                                            <input type="hidden" name="shift_date" value="<?= e($shiftDate) ?>">
                                            <?php if ($isAdmin): ?>
                                                <div class="col-12">
                                                    <label class="form-label">Cashier</label>
                                                    <select name="user_id" class="form-select">
                                                        <?php foreach ($cashiers as $cashier): ?>
                                                            <option value="<?= (int) $cashier['user_id'] ?>" <?= $viewUserId === (int) $cashier['user_id'] ? 'selected' : '' ?>>
                                                                <?= e(userLabel($cashier)) ?> (<?= e(ucfirst((string) ($cashier['role'] ?? '')) ) ?>)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            <?php endif; ?>

                                            <div class="col-12">
                                                <label class="form-label">Counted Cash</label>
                                                <input
                                                    type="number"
                                                    name="counted_cash"
                                                    class="form-control"
                                                    step="0.01"
                                                    min="0"
                                                    placeholder="0.00"
                                                    value="<?= $isShiftClosed ? e(number_format((float) ($shift['counted_cash'] ?? 0), 2, '.', '')) : '' ?>"
                                                    required
                                                >
                                            </div>

                                            <div class="col-12">
                                                <label class="form-label">Notes</label>
                                                <textarea name="notes" class="form-control" rows="3" placeholder="Optional shift notes"><?= e((string) ($shift['notes'] ?? '')) ?></textarea>
                                            </div>

                                            <div class="col-12">
                                                <button type="submit" class="btn btn-primary w-100 shift-close-btn">
                                                    <?= $isShiftClosed ? 'Update Closing' : 'Close Shift' ?>
                                                </button>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm shift-panel shift-recent-panel">
                    <div class="card-body">
                        <div class="shift-section-head">
                            <div>
                                <h2>Recent Shifts</h2>
                                <p>Latest opening and closing records for quick audit checks.</p>
                            </div>
                        </div>
                        <div class="table-responsive shift-table-scroll">
                            <table class="table table-striped align-middle shift-modern-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>User</th>
                                        <th>Status</th>
                                        <th>Opened</th>
                                        <th>Closed</th>
                                        <th class="text-end">Sales</th>
                                        <th class="text-end">Expected Cash</th>
                                        <th class="text-end">Counted Cash</th>
                                        <th class="text-end">Variance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($recentClosings)): ?>
                                        <?php foreach ($recentClosings as $row): ?>
                                            <tr>
                                                <td><?= e(date('M d, Y', strtotime((string) ($row['shift_date'] ?? 'now')))) ?></td>
                                                <td><?= e(userLabel($row)) ?></td>
                                                <td>
                                                    <?php $rowStatus = strtolower((string) ($row['status'] ?? 'closed')); ?>
                                                    <span class="badge <?= $rowStatus === 'open' ? 'bg-success' : 'bg-secondary' ?>">
                                                        <?= e(shiftStatusLabel($rowStatus)) ?>
                                                    </span>
                                                </td>
                                                <td><?= e(dateTimeLabel($row['opened_at'] ?? null)) ?></td>
                                                <td><?= e(dateTimeLabel($row['closed_at'] ?? null)) ?></td>
                                                <td class="text-end"><?= number_format((float) ($row['total_sales'] ?? 0), 2) ?></td>
                                                <td class="text-end"><?= e(currency((float) ($row['expected_cash'] ?? 0))) ?></td>
                                                <td class="text-end"><?= e(currency((float) ($row['counted_cash'] ?? 0))) ?></td>
                                                <td class="text-end <?= ((float) ($row['variance'] ?? 0) < 0) ? 'text-danger' : 'text-success' ?>">
                                                    <?= e(currency((float) ($row['variance'] ?? 0))) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="9" class="text-center text-muted">No shift records found yet.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xxl-4 shift-side-column">
                <div class="card shadow-sm shift-panel shift-side-panel">
                    <div class="card-body">
                        <div class="shift-section-head">
                            <div>
                                <h2>Shift Snapshot</h2>
                                <p>Audit details at a glance.</p>
                            </div>
                        </div>
                        <ul class="shift-snapshot-list">
                            <li><span>Status</span><strong><?= e(shiftStatusLabel($shiftStatus)) ?></strong></li>
                            <li><span>Opened</span><strong><?= e(dateTimeLabel($summary['opened_at'] ?? null)) ?></strong></li>
                            <li><span>Closed</span><strong><?= e(dateTimeLabel($summary['closed_at'] ?? null)) ?></strong></li>
                            <li><span>Starting cash</span><strong><?= e(currency((float) ($summary['starting_cash'] ?? 0))) ?></strong></li>
                            <li><span>Total items sold</span><strong><?= number_format((int) ($summary['total_items'] ?? 0)) ?></strong></li>
                            <li><span>Total tax</span><strong><?= e(currency((float) ($summary['total_tax'] ?? 0))) ?></strong></li>
                            <li><span>Total discount</span><strong><?= e(currency((float) ($summary['total_discount'] ?? 0))) ?></strong></li>
                            <li><span>Last sale</span><strong><?= !empty($summary['last_sale_at']) ? e(date('M d, g:i A', strtotime((string) $summary['last_sale_at']))) : 'No sales yet' ?></strong></li>
                        </ul>
                    </div>
                </div>

                <div class="shift-formula-card">
                    <h3>Cash formula</h3>
                    <p>Expected cash is starting cash plus cash sales. The variance shows counted cash minus expected cash during close.</p>
                </div>
            </div>
        </div>
    </section>
</main>

<?php require __DIR__ . '/components/footer.php'; ?>
<?php require __DIR__ . '/components/js_script.php'; ?>
<script src="/inventory_system/assets/js/shift-closing.js"></script>
<?php if ($shiftClosingSocketEvent !== null): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const socketPayload = <?= json_encode($shiftClosingSocketEvent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    let attempts = 0;

    function sendShiftClosingSocketEvent() {
        if (window.socket && window.socket.readyState === WebSocket.OPEN) {
            window.socket.send(JSON.stringify(socketPayload));
            return;
        }

        attempts += 1;
        if (attempts <= 20) {
            window.setTimeout(sendShiftClosingSocketEvent, 300);
        }
    }

    sendShiftClosingSocketEvent();
});
</script>
<?php endif; ?>
</body>
</html>
