<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap/app.php';
require_once __DIR__ . '/middleware/Middleware.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/ShiftClosingController.php';
require_once __DIR__ . '/controllers/PosConfigController.php';

Middleware::auth()->role(['admin', 'cashier']);

$isAdmin       = Middleware::is('admin');
$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);

if ($sessionUserId <= 0) {
    header('Location: /inventory_system/login.php');
    exit;
}

$viewUserId = $sessionUserId;
if ($isAdmin && isset($_GET['user_id']) && (int) $_GET['user_id'] > 0) {
    $viewUserId = (int) $_GET['user_id'];
}

$shiftDate     = trim((string) ($_GET['shift_date'] ?? date('Y-m-d')));
$todayDate     = date('Y-m-d');
$isTodayView   = $shiftDate === $todayDate;

// ── Flash messages ────────────────────────────────────────────────────────────
$successMessage         = null;
$errorMessage           = null;
$shiftSocketEvent       = null;
$startShiftAnimation    = false;

if (isset($_SESSION['shift_closing_flash']) && is_array($_SESSION['shift_closing_flash'])) {
    $flash                  = $_SESSION['shift_closing_flash'];
    $successMessage         = isset($flash['success'])      ? (string) $flash['success']      : null;
    $errorMessage           = isset($flash['error'])        ? (string) $flash['error']         : null;
    $shiftSocketEvent       = isset($flash['socket_event']) && is_array($flash['socket_event'])
                              ? $flash['socket_event'] : null;
    $startShiftAnimation    = !empty($flash['animate_start']);
    unset($_SESSION['shift_closing_flash']);
}

// ── Data loading (all wrapped in try/catch — DB error must never be a white screen) ──
$shift             = null;
$summary           = [];
$recentClosings    = [];
$cashiers          = [];
$closingEditMeta   = ['is_locked' => false, 'can_edit' => true, 'editable_until' => null, 'requires_admin_override' => false];
$dataError         = null;

try {
    ShiftClosingController::ensureSchema($conn);

    $shift          = ShiftClosingController::shiftForUser($conn, $viewUserId, $shiftDate);
    $summary        = ShiftClosingController::summaryForUser($conn, $viewUserId, $shiftDate);
    $recentClosings = ShiftClosingController::recentClosings($conn, $isAdmin ? null : $sessionUserId, 10);
    $closingEditMeta = ShiftClosingController::closingEditMeta($shift, $isAdmin);

    $cashierStmt = $conn->query("
        SELECT user_id, first_name, last_name, username, role
        FROM users
        WHERE status = 'active' AND role IN ('cashier','admin')
        ORDER BY role ASC, first_name ASC, last_name ASC, username ASC
    ");
    $cashiers = $cashierStmt ? ($cashierStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
    error_log('[shift_closing.php load] ' . $e->getMessage());
    $dataError = 'Failed to load shift data. Please refresh the page.';
}

$shiftStatus        = strtolower((string) ($summary['status'] ?? 'not_started'));
$isShiftStarted     = $shift !== null;
$isShiftOpen        = $shiftStatus === 'open';
$isShiftClosed      = $shiftStatus === 'closed';
$shiftEditLocked    = (bool) ($closingEditMeta['is_locked']  ?? false);
$canEditShiftClosing = (bool) ($closingEditMeta['can_edit']  ?? true);
$editableUntil      = (string) ($closingEditMeta['editable_until'] ?? '');
$shiftEditWindowHours = 8;
try {
    $shiftEditWindowHours = PosConfigController::shiftEditWindowHours($conn);
} catch (Throwable $e) {
    error_log('[shift_closing.php config] ' . $e->getMessage());
}

// ── POST handler ──────────────────────────────────────────────────────────────
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
                'success'      => 'Shift started successfully.',
                'animate_start' => true,
                'socket_event' => [
                    'event'            => 'notification_update',
                    'type'             => 'shift_closing_started',
                    'target_roles'     => ['admin'],
                    'target_user_ids'  => [$targetUserId],
                ],
            ];
            $params = ['shift_date' => $started['shift_date']];
            if ($isAdmin) $params['user_id'] = $targetUserId;
            header('Location: /inventory_system/shift_closing.php?' . http_build_query($params));
            exit;
        }

        $saved = ShiftClosingController::closeShift($conn, $targetUserId, $_POST);
        $_SESSION['shift_closing_flash'] = [
            'success' => !empty($saved['was_override'])
                ? 'Shift closing updated with admin override.'
                : ($saved['was_updated'] ? 'Shift closing updated.' : 'Shift closed successfully.'),
            'socket_event' => [
                'event'           => 'notification_update',
                'type'            => $saved['was_updated'] ? 'shift_closing_updated' : 'shift_closing_saved',
                'target_roles'    => ['admin'],
                'target_user_ids' => [$targetUserId],
            ],
        ];
        $params = ['shift_date' => $saved['shift_date']];
        if ($isAdmin) $params['user_id'] = $targetUserId;
        header('Location: /inventory_system/shift_closing.php?' . http_build_query($params));
        exit;

    } catch (Throwable $e) {
        error_log('[shift_closing.php POST] ' . $e->getMessage());
        $errorMessage = ($e instanceof InvalidArgumentException || $e instanceof RuntimeException)
            ? $e->getMessage()
            : 'Unable to save the shift right now.';
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function e(?string $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function currency(float $v): string { return 'PHP ' . number_format($v, 2); }
function dtLabel(?string $v): string {
    $v = trim((string) $v);
    if ($v === '') return '—';
    $ts = strtotime($v);
    return $ts !== false ? date('M d, g:i A', $ts) : '—';
}
function userLabel(array $row): string {
    $n = trim((string)($row['first_name']??'') . ' ' . (string)($row['last_name']??''));
    return $n !== '' ? $n : (string)($row['username']??'User');
}
function shiftStatusLabel(string $s): string {
    return match(strtolower($s)) { 'open' => 'Open', 'closed' => 'Closed', default => 'Not started' };
}

$csrfToken = Middleware::generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/components/head.php'; ?>
    <title>Shift Management</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        /* ── Design tokens (unified) ──────────────────────────────────── */
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

        /* ── Layout ─────────────────────────────────────────────────── */
        .shift-layout{display:grid;grid-template-columns:1fr 320px;gap:1.5rem;align-items:start}
        @media(max-width:1100px){.shift-layout{grid-template-columns:1fr}}

        /* ── Section card ───────────────────────────────────────────── */
        .sc{background:var(--c-surface);border:1px solid var(--c-border);border-radius:var(--radius-xl);box-shadow:var(--shadow-sm);overflow:hidden;margin-bottom:1.5rem}
        .sc-head{display:flex;align-items:flex-start;justify-content:space-between;padding:1.25rem 1.5rem 0;flex-wrap:wrap;gap:.75rem}
        .sc-title{font-size:16px;font-weight:600;color:var(--c-text-1)}
        .sc-sub{font-size:12px;color:var(--c-text-3);margin-top:2px}
        .sc-body{padding:1.25rem 1.5rem 1.5rem}

        /* ── Status pill ────────────────────────────────────────────── */
        .status-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 11px;border-radius:99px;font-size:11.5px;font-weight:600;border:1px solid transparent}
        .status-pill.is-open    {background:var(--c-green-bg);color:var(--c-green);border-color:var(--c-green-bd)}
        .status-pill.is-closed  {background:var(--c-accent-bg);color:var(--c-accent);border-color:var(--c-accent-bd)}
        .status-pill.is-pending {background:var(--c-amber-bg);color:var(--c-amber);border-color:var(--c-amber-bd)}

        /* ── Session hero card ──────────────────────────────────────── */
        .session-hero{display:grid;grid-template-columns:1fr 280px;gap:1.5rem;align-items:start;padding:1.5rem}
        @media(max-width:768px){.session-hero{grid-template-columns:1fr}}
        .session-eyebrow{font-size:10.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--c-accent);margin-bottom:4px}
        .session-title{font-size:20px;font-weight:600;letter-spacing:-.3px;color:var(--c-text-1);margin:0 0 6px}
        .session-copy{font-size:13px;color:var(--c-text-2);margin:0 0 1rem;line-height:1.6}
        .session-meta{display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem}
        @media(max-width:600px){.session-meta{grid-template-columns:1fr 1fr}}
        .meta-tile{background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:var(--radius-md);padding:.75rem 1rem}
        .meta-tile-label{font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);margin-bottom:3px}
        .meta-tile-val{font-size:14px;font-weight:600;color:var(--c-text-1);font-family:var(--ff-mono)}

        /* ── Start shift box ────────────────────────────────────────── */
        .start-box{background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:var(--radius-lg);padding:1.25rem}
        .start-box .form-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);margin-bottom:.3rem;display:block}
        .start-box .form-control,.start-box .form-select{height:38px;padding:0 12px;background:var(--c-surface);border:1px solid var(--c-border);border-radius:var(--radius-md);font-family:var(--ff-base);font-size:13px;color:var(--c-text-1);width:100%;margin-bottom:.75rem;transition:border-color .15s,box-shadow .15s}
        .start-box .form-control:focus,.start-box .form-select:focus{border-color:var(--c-accent);box-shadow:0 0 0 3px rgba(37,99,235,.1);outline:none}
        .start-box .form-text{font-size:11px;color:var(--c-text-3);margin-top:-6px;margin-bottom:.75rem}

        /* ── Metric cards ───────────────────────────────────────────── */
        .metric-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.25rem}
        @media(max-width:900px){.metric-grid{grid-template-columns:repeat(2,1fr)}}
        .metric-card{background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:var(--radius-lg);padding:1rem 1.1rem}
        .metric-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);margin-bottom:4px}
        .metric-val{font-size:20px;font-weight:600;letter-spacing:-.3px;font-family:var(--ff-mono)}

        /* ── Work grid (payment + close form) ───────────────────────── */
        .work-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-top:1.25rem}
        @media(max-width:900px){.work-grid{grid-template-columns:1fr}}
        .work-panel{background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:var(--radius-lg);padding:1.1rem 1.25rem}
        .work-panel-title{font-size:13px;font-weight:600;color:var(--c-text-1);margin-bottom:.875rem}

        /* ── Close form fields ──────────────────────────────────────── */
        .close-form .form-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);margin-bottom:.3rem;display:block}
        .close-form .form-control,.close-form .form-select{height:38px;padding:0 12px;background:var(--c-surface);border:1px solid var(--c-border);border-radius:var(--radius-md);font-family:var(--ff-base);font-size:13px;color:var(--c-text-1);width:100%;margin-bottom:.75rem;transition:border-color .15s,box-shadow .15s}
        .close-form textarea.form-control{height:auto;padding:10px 12px}
        .close-form .form-control:focus,.close-form .form-select:focus{border-color:var(--c-accent);box-shadow:0 0 0 3px rgba(37,99,235,.1);outline:none}

        /* ── Buttons ────────────────────────────────────────────────── */
        .btn{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 16px;border-radius:var(--radius-md);font-family:var(--ff-base);font-size:13px;font-weight:500;cursor:pointer;border:1px solid transparent;transition:all .15s;white-space:nowrap;text-decoration:none}
        .btn-primary{background:var(--c-accent);color:#fff;border-color:var(--c-accent)}
        .btn-primary:hover{background:#1d4ed8}
        .btn-success{background:var(--c-green);color:#fff;border-color:var(--c-green)}
        .btn-success:hover{background:#15803d}
        .btn-outline{background:var(--c-surface);color:var(--c-text-2);border-color:var(--c-border-2)}
        .btn-outline:hover{background:var(--c-surface-2);color:var(--c-text-1)}
        .btn-full{width:100%;justify-content:center;height:40px}
        .btn:disabled{opacity:.5;cursor:not-allowed}

        /* ── Filter card ────────────────────────────────────────────── */
        .filter-card{background:var(--c-surface);border:1px solid var(--c-border);border-radius:var(--radius-lg);padding:1rem 1.25rem;margin-bottom:1.5rem}
        .filter-grid{display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end}
        .filter-item{display:flex;flex-direction:column;gap:4px}
        .filter-item.grow{flex:1;min-width:160px}
        .filter-label{font-size:11px;font-weight:600;letter-spacing:.04em;color:var(--c-text-3);text-transform:uppercase}
        .filter-control{height:36px;padding:0 12px;background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:var(--radius-md);font-family:var(--ff-base);font-size:13px;color:var(--c-text-1);transition:border-color .15s}
        .filter-control:focus{border-color:var(--c-accent);box-shadow:0 0 0 3px rgba(37,99,235,.1);outline:none}

        /* ── Table ──────────────────────────────────────────────────── */
        .table-wrap{overflow-x:auto}
        table.rh-table{width:100%;border-collapse:collapse}
        .rh-table thead tr{border-bottom:1px solid var(--c-border)}
        .rh-table th{padding:9px 14px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);text-align:left;white-space:nowrap;background:var(--c-surface-2)}
        .rh-table tbody tr{border-bottom:1px solid var(--c-border);transition:background .1s}
        .rh-table tbody tr:last-child{border-bottom:none}
        .rh-table tbody tr:hover{background:var(--c-surface-2)}
        .rh-table td{padding:11px 14px;font-size:13px;vertical-align:middle}
        .rh-table td.mono{font-family:var(--ff-mono);font-size:12.5px}
        .variance-pos{color:var(--c-green);font-weight:600}
        .variance-neg{color:var(--c-red);font-weight:600}

        /* ── Badge ──────────────────────────────────────────────────── */
        .badge{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:99px;font-size:11px;font-weight:600;border:1px solid transparent}
        .badge-open  {background:var(--c-green-bg);color:var(--c-green);border-color:var(--c-green-bd)}
        .badge-closed{background:var(--c-surface-2);color:var(--c-text-3);border-color:var(--c-border)}

        /* ── Alert ──────────────────────────────────────────────────── */
        .alert{padding:.875rem 1.1rem;border-radius:var(--radius-md);font-size:13px;border:1px solid transparent;margin-bottom:.875rem}
        .alert-success{background:var(--c-green-bg);color:var(--c-green);border-color:var(--c-green-bd)}
        .alert-danger {background:var(--c-red-bg);  color:var(--c-red);  border-color:var(--c-red-bd)}
        .alert-warning{background:var(--c-amber-bg);color:var(--c-amber);border-color:var(--c-amber-bd)}
        .alert-info   {background:var(--c-accent-bg);color:var(--c-accent);border-color:var(--c-accent-bd)}

        /* ── Snapshot list (sidebar) ─────────────────────────────────── */
        .snapshot-list{list-style:none;padding:0;margin:0}
        .snapshot-list li{display:flex;align-items:baseline;justify-content:space-between;padding:.6rem 0;border-bottom:1px solid var(--c-border);font-size:13px}
        .snapshot-list li:last-child{border-bottom:none}
        .snapshot-list li span{color:var(--c-text-3);font-size:12px}
        .snapshot-list li strong{color:var(--c-text-1);font-family:var(--ff-mono);font-size:12.5px}

        /* ── Formula card ───────────────────────────────────────────── */
        .formula-card{background:var(--c-accent-bg);border:1px solid var(--c-accent-bd);border-radius:var(--radius-lg);padding:1rem 1.25rem;margin-top:1rem;font-size:12.5px;color:var(--c-text-2);line-height:1.6}
        .formula-card strong{display:block;font-size:13px;color:var(--c-accent);margin-bottom:4px}

        /* ══════════════════════════════════════════════════════════════
           ANIMATION SYSTEM
        ══════════════════════════════════════════════════════════════ */

        /* ── Page entry — cards fade + slide up on load ─────────────── */
        @keyframes fadeUp {
            from { opacity:0; transform:translateY(14px); }
            to   { opacity:1; transform:translateY(0); }
        }
        .sc { animation: fadeUp .35s ease both; }
        .sc:nth-child(1) { animation-delay:.04s }
        .sc:nth-child(2) { animation-delay:.10s }
        .sc:nth-child(3) { animation-delay:.16s }

        /* ── Metric cards count-up shimmer ──────────────────────────── */
        @keyframes metricIn {
            from { opacity:0; transform:translateY(6px) scale(.97); }
            to   { opacity:1; transform:translateY(0)   scale(1); }
        }
        .metric-card {
            animation: metricIn .4s ease both;
            transition: box-shadow .2s, border-color .2s, transform .2s;
        }
        .metric-card:nth-child(1){animation-delay:.18s}
        .metric-card:nth-child(2){animation-delay:.24s}
        .metric-card:nth-child(3){animation-delay:.30s}
        .metric-card:nth-child(4){animation-delay:.36s}
        .metric-card:hover {
            border-color: var(--c-border-2);
            box-shadow: 0 4px 14px rgba(0,0,0,.08);
            transform: translateY(-2px);
        }

        /* ── Start button — shimmer sweep on hover ───────────────────── */
        #startShiftButton {
            position: relative;
            overflow: hidden;
            transition: background .2s, transform .15s, box-shadow .2s;
        }
        #startShiftButton::after {
            content: '';
            position: absolute;
            top: 0; left: -100%;
            width: 60%;
            height: 100%;
            background: linear-gradient(90deg,
                transparent,
                rgba(255,255,255,.35),
                transparent);
            transition: left .55s ease;
        }
        #startShiftButton:hover::after,
        #startShiftButton.is-starting::after {
            left: 140%;
        }
        #startShiftButton:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(22,163,74,.3);
        }
        #startShiftButton:active { transform: translateY(0); }

        /* ── Close button — pulse border on hover ───────────────────── */
        #closeShiftSubmitBtn {
            position: relative;
            overflow: hidden;
            transition: background .2s, transform .15s, box-shadow .2s;
        }
        #closeShiftSubmitBtn::after {
            content: '';
            position: absolute;
            top: 0; left: -100%;
            width: 60%;
            height: 100%;
            background: linear-gradient(90deg,
                transparent,
                rgba(255,255,255,.3),
                transparent);
            transition: left .5s ease;
        }
        #closeShiftSubmitBtn:hover::after,
        #closeShiftSubmitBtn.is-submitting::after {
            left: 140%;
        }
        #closeShiftSubmitBtn:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(37,99,235,.25);
        }
        #closeShiftSubmitBtn:active { transform: translateY(0); }

        /* ── Form fields — lift on focus ────────────────────────────── */
        .start-box  .form-control:focus,
        .start-box  .form-select:focus,
        .close-form .form-control:focus,
        .close-form .form-select:focus {
            transform: translateY(-1px);
            box-shadow: 0 0 0 3px rgba(37,99,235,.1),
                        0 3px 8px rgba(0,0,0,.06);
        }

        /* ── Submit progress bar (injected by JS, runs during save) ─── */
        .shift-progress-bar {
            position: fixed;
            top: 0; left: 0;
            height: 3px;
            background: linear-gradient(90deg,
                var(--c-accent),
                var(--c-green),
                var(--c-accent));
            background-size: 200% 100%;
            border-radius: 0 3px 3px 0;
            z-index: 10000;
            width: 0%;
            opacity: 0;
            transition: width .4s ease, opacity .2s;
        }
        .shift-progress-bar.running {
            opacity: 1;
            width: 85%;
            animation: progressShimmer 1.4s linear infinite;
        }
        .shift-progress-bar.done {
            width: 100% !important;
            opacity: 0;
            transition: width .2s ease, opacity .4s ease .2s;
        }
        @keyframes progressShimmer {
            0%   { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        /* ── Status pill — live pulse when open ─────────────────────── */
        .status-pill.is-open {
            animation: pillPulseGreen 2.5s ease infinite;
        }
        @keyframes pillPulseGreen {
            0%,100% { box-shadow: 0 0 0 0 rgba(22,163,74,.0); }
            50%     { box-shadow: 0 0 0 5px rgba(22,163,74,.12); }
        }

        /* ── Start-shift celebration overlay ────────────────────────── */
        .shift-celebration {
            position: fixed; inset: 0; z-index: 9999;
            display: flex; align-items: center; justify-content: center;
            background: rgba(0,0,0,.35);
            backdrop-filter: blur(3px);
            -webkit-backdrop-filter: blur(3px);
            opacity: 0; visibility: hidden;
            transition: opacity .25s, visibility .25s;
            pointer-events: none;
        }
        .shift-celebration.show { opacity:1; visibility:visible; }

        .shift-pop {
            background: var(--c-surface);
            border: 1px solid var(--c-green-bd);
            border-radius: var(--radius-xl);
            padding: 2.25rem 2rem;
            text-align: center;
            box-shadow: 0 28px 64px rgba(0,0,0,.18);
            transform: translateY(24px) scale(.9);
            animation: shiftPop .65s cubic-bezier(.18,.9,.24,1.18) forwards;
            max-width: 340px; width: 90vw;
        }
        .shift-pop-icon {
            width: 68px; height: 68px;
            border-radius: var(--radius-lg);
            background: var(--c-green-bg);
            color: var(--c-green);
            font-size: 30px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 1rem;
            animation: shiftPulse 1.2s ease infinite;
        }
        .shift-pop strong { display:block; font-size:18px; font-weight:700; color:var(--c-text-1); margin-bottom:6px }
        .shift-pop span   { font-size:13px; color:var(--c-text-2); line-height:1.5 }
        .shift-pop-bar {
            height: 3px;
            background: var(--c-border);
            border-radius: 99px;
            margin-top: 1.25rem;
            overflow: hidden;
        }
        .shift-pop-bar::after {
            content: '';
            display: block;
            height: 100%;
            width: 0%;
            background: var(--c-green);
            border-radius: 99px;
            animation: popBarFill 2s ease forwards;
        }
        @keyframes shiftPop   { to { transform: translateY(0) scale(1); } }
        @keyframes shiftPulse { 0%,100% { box-shadow: 0 0 0 0 rgba(22,163,74,.2); } 50% { box-shadow: 0 0 0 14px rgba(22,163,74,0); } }
        @keyframes popBarFill { from { width:0% } to { width:100% } }

        /* ── Close shift celebration (blue accent) ───────────────────── */
        .shift-pop.is-close {
            border-color: var(--c-accent-bd);
        }
        .shift-pop.is-close .shift-pop-icon {
            background: var(--c-accent-bg);
            color: var(--c-accent);
            animation: shiftPulseBlue 1.2s ease infinite;
        }
        .shift-pop.is-close .shift-pop-bar::after {
            background: var(--c-accent);
        }
        @keyframes shiftPulseBlue { 0%,100% { box-shadow: 0 0 0 0 rgba(37,99,235,.2); } 50% { box-shadow: 0 0 0 14px rgba(37,99,235,0); } }

        /* ── Row highlight in recent shifts table after save ─────────── */
        @keyframes rowHighlight {
            0%   { background: rgba(22,163,74,.15); }
            100% { background: transparent; }
        }
        .rh-table tbody tr.just-saved {
            animation: rowHighlight 2s ease forwards;
        }

        /* ── Payment breakdown table ─────────────────────────────────── */
        .pay-table{width:100%;border-collapse:collapse;font-size:13px}
        .pay-table th{padding:7px 10px;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);text-align:left;border-bottom:1px solid var(--c-border)}
        .pay-table td{padding:9px 10px;border-bottom:1px solid var(--c-border)}
        .pay-table tbody tr:last-child td{border-bottom:none}
        .pay-table td.text-right{text-align:right;font-family:var(--ff-mono)}

        /* ── Modal ───────────────────────────────────────────────────── */
        .modal-modern .modal-content{border:1px solid var(--c-border);border-radius:var(--radius-xl);box-shadow:var(--shadow-lg);font-family:var(--ff-base)}
        .modal-modern .modal-header{border-bottom:1px solid var(--c-border);padding:1.1rem 1.5rem;background:var(--c-surface-2)}
        .modal-modern .modal-title{font-size:16px;font-weight:600}
        .modal-modern .modal-body{padding:1.5rem}

        /* ── Sales summary chips (inside modal) ──────────────────────── */
        .sales-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem;margin-bottom:1.25rem}
        @media(max-width:600px){.sales-summary{grid-template-columns:1fr 1fr}}
        .sales-chip{background:var(--c-surface-2);border:1px solid var(--c-border);border-radius:var(--radius-md);padding:.75rem 1rem}
        .sales-chip span{font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);display:block;margin-bottom:3px}
        .sales-chip strong{font-size:15px;font-weight:600;color:var(--c-text-1);font-family:var(--ff-mono)}

        /* State placeholders inside modals */
        .modal-state{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:3rem 1rem;gap:.75rem;color:var(--c-text-3);text-align:center}
        .modal-state i{font-size:32px;opacity:.4}

        @media(max-width:768px){#main{padding:1rem}}
    </style>
</head>
<body>
<?php
require __DIR__ . '/components/header.php';
require __DIR__ . '/components/sidebar.php';
?>

<?php if ($startShiftAnimation): ?>
    <div class="shift-celebration" id="shiftStartCelebration" aria-live="polite">
        <div class="shift-pop">
            <div class="shift-pop-icon"><i class="bi bi-check2-circle"></i></div>
            <strong>Shift started!</strong>
            <span>The cashier session is now being tracked.</span>
        </div>
    </div>
<?php endif; ?>

<main id="main" class="main shift-closing-page">

    <div class="pagetitle">
        <h1>Shift Management</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/inventory_system/index.php">Home</a></li>
                <li class="breadcrumb-item active">Shift Management</li>
            </ol>
        </nav>
    </div>

    <?php if ($dataError !== null): ?>
        <div class="alert alert-danger"><?= e($dataError) ?></div>
    <?php endif; ?>

    <!-- ── Filter card ─────────────────────────────────────────────── -->
    <div class="filter-card">
        <form method="GET" id="shiftFilterForm" class="filter-grid">
            <div class="filter-item">
                <span class="filter-label">Shift date</span>
                <input type="date" name="shift_date" class="filter-control" value="<?= e($shiftDate) ?>">
            </div>
            <?php if ($isAdmin): ?>
                <div class="filter-item grow">
                    <span class="filter-label">Cashier</span>
                    <select name="user_id" class="filter-control">
                        <?php foreach ($cashiers as $c): ?>
                            <option value="<?= (int)$c['user_id'] ?>" <?= $viewUserId === (int)$c['user_id'] ? 'selected' : '' ?>>
                                <?= e(userLabel($c)) ?> (<?= e(ucfirst((string)($c['role']??''))) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="filter-item">
                <span class="filter-label">&nbsp;</span>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-funnel" aria-hidden="true"></i> View shift
                </button>
            </div>
        </form>
    </div>

    <!-- ── Two-column layout ───────────────────────────────────────── -->
    <div class="shift-layout">

        <!-- ── Left: main content ──────────────────────────────────── -->
        <div>

            <!-- Session hero card -->
            <div class="sc">
                <div class="session-hero">
                    <div>
                        <span class="status-pill <?= $isShiftOpen ? 'is-open' : ($isShiftClosed ? 'is-closed' : 'is-pending') ?>">
                            <i class="bi <?= $isShiftOpen ? 'bi-broadcast-pin' : ($isShiftClosed ? 'bi-check2-circle' : 'bi-hourglass-split') ?>" aria-hidden="true"></i>
                            <?= e(shiftStatusLabel($shiftStatus)) ?>
                        </span>

                        <p class="session-eyebrow" style="margin-top:.875rem;">Shift session</p>
                        <h2 class="session-title">
                            <?= $isShiftOpen ? 'Shift is active'
                              : ($isShiftClosed ? 'Shift already closed'
                              : 'Start shift before selling') ?>
                        </h2>
                        <p class="session-copy">
                            <?= $isShiftOpen
                              ? 'Sales are being tracked. Close the shift when the cashier is done and count the drawer cash.'
                              : ($isShiftClosed
                              ? 'This shift has a saved closing. You can update the counted cash if needed.'
                              : 'Opening the shift records the cashier, opening time, and starting cash.') ?>
                        </p>

                        <div class="session-meta">
                            <div class="meta-tile">
                                <div class="meta-tile-label">Opened</div>
                                <div class="meta-tile-val"><?= e(dtLabel($summary['opened_at'] ?? null)) ?></div>
                            </div>
                            <div class="meta-tile">
                                <div class="meta-tile-label">Starting cash</div>
                                <div class="meta-tile-val"><?= e(currency((float)($summary['starting_cash']??0))) ?></div>
                            </div>
                            <div class="meta-tile">
                                <div class="meta-tile-label">Closed</div>
                                <div class="meta-tile-val"><?= e(dtLabel($summary['closed_at'] ?? null)) ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Start shift box -->
                    <div class="start-box">
                        <?php if (!$isShiftStarted && !$isTodayView): ?>
                            <div class="alert alert-warning" style="margin-bottom:0;">
                                You can only start a shift for today. Switch the date back to
                                <?= e(date('M d, Y')) ?> to open a new session.
                            </div>
                        <?php elseif (!$isShiftStarted): ?>
                            <form method="POST" id="startShiftForm">
                                <input type="hidden" name="csrf_token"   value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="shift_action" value="start">
                                <input type="hidden" name="shift_date"   value="<?= e($shiftDate) ?>">

                                <?php if ($isAdmin): ?>
                                    <label class="form-label">Cashier</label>
                                    <select name="user_id" class="form-select" style="height:38px;border-radius:var(--radius-md);border-color:var(--c-border);font-family:var(--ff-base);font-size:13px;width:100%;margin-bottom:.75rem;">
                                        <?php foreach ($cashiers as $c): ?>
                                            <option value="<?= (int)$c['user_id'] ?>" <?= $viewUserId===(int)$c['user_id']?'selected':'' ?>>
                                                <?= e(userLabel($c)) ?> (<?= e(ucfirst((string)($c['role']??''))) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>

                                <label class="form-label">Starting cash</label>
                                <input type="number" name="starting_cash" class="form-control"
                                       step="0.01" min="0" placeholder="0.00">
                                <div class="form-text">Optional drawer amount before the first sale.</div>

                                <button type="submit" class="btn btn-success btn-full" id="startShiftButton">
                                    <i class="bi bi-play-fill" aria-hidden="true"></i> Start Shift
                                </button>
                            </form>
                        <?php else: ?>
                            <div style="text-align:center;padding:1.5rem 0;">
                                <div style="width:48px;height:48px;border-radius:var(--radius-lg);background:var(--c-<?= $isShiftOpen?'green':'accent' ?>-bg);color:var(--c-<?= $isShiftOpen?'green':'accent' ?>);font-size:22px;display:flex;align-items:center;justify-content:center;margin:0 auto .875rem;">
                                    <i class="bi <?= $isShiftOpen?'bi-broadcast-pin':'bi-clipboard-check' ?>" aria-hidden="true"></i>
                                </div>
                                <div style="font-size:14px;font-weight:600;margin-bottom:4px;">
                                    <?= $isShiftOpen ? 'Ready for closing later' : 'Closing saved' ?>
                                </div>
                                <div style="font-size:12px;color:var(--c-text-3);">
                                    <?= $isShiftOpen
                                      ? 'This shift is actively tracking cashier sales.'
                                      : 'The drawer count and variance are recorded.' ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div><!-- /.sc session hero -->


            <!-- Closing summary card -->
            <div class="sc">
                <div class="sc-head">
                    <div>
                        <div class="sc-title">Closing Summary</div>
                        <div class="sc-sub"><?= e(date('M d, Y', strtotime($shiftDate))) ?></div>
                    </div>
                    <?php if ($isShiftStarted): ?>
                        <button type="button" class="btn btn-outline"
                                id="viewShiftSalesBtn"
                                data-shift-date="<?= e($shiftDate) ?>"
                                data-user-id="<?= (int)$viewUserId ?>">
                            <i class="bi bi-list-ul" aria-hidden="true"></i> View sales
                        </button>
                    <?php endif; ?>
                </div>
                <div class="sc-body">

                    <!-- Flash messages -->
                    <div id="shiftActionFeedback">
                        <?php if ($successMessage !== null): ?>
                            <div class="alert alert-success"><?= e($successMessage) ?></div>
                        <?php endif; ?>
                        <?php if ($errorMessage !== null): ?>
                            <div class="alert alert-danger"><?= e($errorMessage) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Metric cards -->
                    <div class="metric-grid">
                        <div class="metric-card">
                            <div class="metric-label">Transactions</div>
                            <div class="metric-val"><?= number_format((int)($summary['total_transactions']??0)) ?></div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-label">Total Sales</div>
                            <div class="metric-val" style="color:var(--c-accent);"><?= e(currency((float)($summary['total_sales']??0))) ?></div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-label">Expected Cash</div>
                            <div class="metric-val" style="color:var(--c-green);"><?= e(currency((float)($summary['expected_cash']??0))) ?></div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-label">Cash Sales</div>
                            <div class="metric-val"><?= e(currency((float)($summary['cash_sales']??0))) ?></div>
                        </div>
                    </div>

                    <!-- Payment breakdown + close drawer -->
                    <div class="work-grid">
                        <!-- Payment breakdown -->
                        <div class="work-panel">
                            <div class="work-panel-title">Payment Breakdown</div>
                            <table class="pay-table">
                                <thead>
                                    <tr>
                                        <th>Method</th>
                                        <th class="text-right">Sales</th>
                                        <th class="text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($summary['payment_breakdown'])): ?>
                                        <?php foreach ($summary['payment_breakdown'] as $row): ?>
                                            <tr>
                                                <td><?= e(ucfirst((string)($row['payment_method']??'Unknown'))) ?></td>
                                                <td class="text-right"><?= number_format((int)($row['sale_count']??0)) ?></td>
                                                <td class="text-right"><?= e(currency((float)($row['total_amount']??0))) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="3" style="text-align:center;color:var(--c-text-3);padding:1.5rem 0;">No payments recorded.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Close drawer form -->
                        <div class="work-panel">
                            <div class="work-panel-title">Close Drawer</div>

                            <?php if (!$isShiftStarted): ?>
                                <div class="alert alert-warning" style="margin-bottom:0;">
                                    Start the shift first so the system can record the opening time.
                                </div>

                            <?php elseif ($isShiftClosed && !$canEditShiftClosing): ?>
                                <div class="alert alert-warning">
                                    This shift is locked.
                                    <?php if ($editableUntil !== ''): ?>
                                        It was editable until <?= e(date('M d, g:i A', strtotime($editableUntil))) ?>.
                                    <?php endif; ?>
                                    Ask the owner/admin to approve any correction.
                                </div>
                                <?php if (!$isAdmin && !empty($shift['shift_closing_id'])): ?>
                                    <a href="/inventory_system/shift_edit_requests.php?shift_closing_id=<?= (int)$shift['shift_closing_id'] ?>"
                                       class="btn btn-outline btn-full">
                                        <i class="bi bi-send" aria-hidden="true"></i> Request edit access
                                    </a>
                                <?php endif; ?>

                            <?php else: ?>
                                <form method="POST" id="closeShiftForm" class="close-form">
                                    <input type="hidden" name="csrf_token"      value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="shift_action"    value="close">
                                    <input type="hidden" name="shift_date"      value="<?= e($shiftDate) ?>">
                                    <input type="hidden" name="_actor_user_id"  value="<?= (int)$sessionUserId ?>">
                                    <input type="hidden" name="_actor_role"     value="<?= e($isAdmin?'admin':'cashier') ?>">

                                    <?php if ($isAdmin): ?>
                                        <label class="form-label">Cashier</label>
                                        <select name="user_id" class="form-select">
                                            <?php foreach ($cashiers as $c): ?>
                                                <option value="<?= (int)$c['user_id'] ?>" <?= $viewUserId===(int)$c['user_id']?'selected':'' ?>>
                                                    <?= e(userLabel($c)) ?> (<?= e(ucfirst((string)($c['role']??''))) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>

                                    <label class="form-label">Counted cash <span style="color:var(--c-red);">*</span></label>
                                    <input type="number" name="counted_cash" class="form-control"
                                           step="0.01" min="0" placeholder="0.00" required
                                           value="<?= $isShiftClosed ? e(number_format((float)($shift['counted_cash']??0),2,'.','' )) : '' ?>">

                                    <label class="form-label">Notes</label>
                                    <textarea name="notes" class="form-control" rows="3" placeholder="Optional shift notes"><?= e((string)($shift['notes']??'')) ?></textarea>

                                    <?php if ($isShiftClosed && $shiftEditLocked && $isAdmin): ?>
                                        <div class="alert alert-info">
                                            Outside the <?= (int)$shiftEditWindowHours ?>-hour edit window.
                                            Admin override required.
                                        </div>
                                        <label class="form-label">Override reason <span style="color:var(--c-red);">*</span></label>
                                        <textarea name="override_reason" class="form-control" rows="3"
                                                  placeholder="Explain why this locked shift needs updating." required></textarea>
                                    <?php endif; ?>

                                    <button type="submit" class="btn btn-primary btn-full" id="closeShiftSubmitBtn">
                                        <?= $isShiftClosed ? 'Update Closing' : 'Close Shift' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div><!-- /.sc closing summary -->


            <!-- Recent shifts table -->
            <div class="sc">
                <div class="sc-head">
                    <div>
                        <div class="sc-title">Recent Shifts</div>
                        <div class="sc-sub">Latest opening and closing records</div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="rh-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Cashier</th>
                                <th>Status</th>
                                <th>Opened</th>
                                <th>Closed</th>
                                <th style="text-align:right;">Sales</th>
                                <th style="text-align:right;">Expected</th>
                                <th style="text-align:right;">Counted</th>
                                <th style="text-align:right;">Variance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recentClosings)): ?>
                                <?php foreach ($recentClosings as $row):
                                    $rs  = strtolower((string)($row['status']??'closed'));
                                    $var = (float)($row['variance']??0);
                                ?>
                                    <tr>
                                        <td><?= e(date('M d, Y', strtotime((string)($row['shift_date']??'now')))) ?></td>
                                        <td><?= e(userLabel($row)) ?></td>
                                        <td>
                                            <span class="badge <?= $rs==='open'?'badge-open':'badge-closed' ?>">
                                                <?= e(shiftStatusLabel($rs)) ?>
                                            </span>
                                        </td>
                                        <td class="mono"><?= e(dtLabel($row['opened_at']??null)) ?></td>
                                        <td class="mono"><?= e(dtLabel($row['closed_at']??null)) ?></td>
                                        <td class="mono" style="text-align:right;"><?= e(currency((float)($row['total_sales']??0))) ?></td>
                                        <td class="mono" style="text-align:right;"><?= e(currency((float)($row['expected_cash']??0))) ?></td>
                                        <td class="mono" style="text-align:right;"><?= e(currency((float)($row['counted_cash']??0))) ?></td>
                                        <td class="mono <?= $var < 0 ? 'variance-neg' : 'variance-pos' ?>" style="text-align:right;">
                                            <?= e(currency($var)) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="9" style="text-align:center;padding:2.5rem;color:var(--c-text-3);">No shift records found yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div><!-- /.shift-main -->


        <!-- ── Right: sidebar ──────────────────────────────────────── -->
        <div>
            <!-- Snapshot card -->
            <div class="sc">
                <div class="sc-head">
                    <div>
                        <div class="sc-title">Shift Snapshot</div>
                        <div class="sc-sub">Audit details at a glance</div>
                    </div>
                </div>
                <div class="sc-body">
                    <ul class="snapshot-list">
                        <li><span>Status</span>       <strong><?= e(shiftStatusLabel($shiftStatus)) ?></strong></li>
                        <li><span>Opened</span>        <strong><?= e(dtLabel($summary['opened_at']??null)) ?></strong></li>
                        <li><span>Closed</span>        <strong><?= e(dtLabel($summary['closed_at']??null)) ?></strong></li>
                        <li><span>Edit window</span>   <strong><?= $editableUntil !== '' ? e(date('M d, g:i A', strtotime($editableUntil))) : '—' ?></strong></li>
                        <li><span>Starting cash</span> <strong><?= e(currency((float)($summary['starting_cash']??0))) ?></strong></li>
                        <li><span>Items sold</span>    <strong><?= number_format((int)($summary['total_items']??0)) ?></strong></li>
                        <li><span>Tax collected</span> <strong><?= e(currency((float)($summary['total_tax']??0))) ?></strong></li>
                        <li><span>Discounts</span>     <strong><?= e(currency((float)($summary['total_discount']??0))) ?></strong></li>
                        <li><span>Lock state</span>    <strong><?= $shiftEditLocked ? 'Locked' : 'Editable' ?></strong></li>
                        <li><span>Last sale</span>     <strong><?= !empty($summary['last_sale_at']) ? e(date('M d, g:i A', strtotime((string)$summary['last_sale_at']))) : 'No sales' ?></strong></li>
                    </ul>

                    <div class="formula-card">
                        <strong>Cash formula</strong>
                        Expected cash = Starting cash + Cash sales.
                        Variance = Counted cash − Expected cash.
                    </div>
                </div>
            </div>
        </div><!-- /.sidebar -->

    </div><!-- /.shift-layout -->
</main>


<!-- ── Shift sales modal ────────────────────────────────────────────── -->
<div class="modal fade modal-modern" id="shiftSalesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <div style="font-size:10.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--c-accent);margin-bottom:3px;">Shift drill-down</div>
                    <h5 class="modal-title">Shift Sales Breakdown</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="shiftSalesModalBody">
                <div class="modal-state">
                    <span class="spinner-border text-primary" role="status" aria-hidden="true"></span>
                    <strong>Loading shift sales…</strong>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Sale detail modal ─────────────────────────────────────────────── -->
<div class="modal fade modal-modern" id="shiftSaleDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <div style="font-size:10.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--c-accent);margin-bottom:3px;">Transaction view</div>
                    <h5 class="modal-title" id="shiftSaleDetailsModalLabel">Sale Details</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="shiftSaleDetailsModalBody">
                <div class="modal-state">
                    <span class="spinner-border text-primary" role="status" aria-hidden="true"></span>
                    <strong>Loading sale details…</strong>
                </div>
            </div>
        </div>
    </div>
</div>


<?php require __DIR__ . '/components/js_script.php'; ?>
<script src="/inventory_system/assets/js/shift-closing.js"></script>

<?php if ($shiftSocketEvent !== null): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const payload = <?= json_encode($shiftSocketEvent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    let attempts = 0;
    (function trySend() {
        if (window.socket && window.socket.readyState === WebSocket.OPEN) {
            window.socket.send(JSON.stringify(payload));
        } else if (++attempts <= 20) {
            setTimeout(trySend, 300);
        }
    })();
});
</script>
<?php endif; ?>
</body>
</html>