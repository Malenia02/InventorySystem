<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/Middleware.php';

Middleware::auth()->role(['admin', 'staff', 'cashier']);

$isAdmin       = Middleware::is('admin');
$sessionUserId = (int)($_SESSION['user_id'] ?? 0);

if ($sessionUserId <= 0) {
    header('Location: /inventory_system/login.php');
    exit;
}

require __DIR__ . '/../components/head.php';

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function actionBadge(string $action): string
{
    return match (true) {
        str_contains($action, 'login_success') => 'bg-success',
        str_contains($action, 'login_failed')  => 'bg-danger',
        str_contains($action, 'blocked')       => 'bg-dark text-white',
        str_contains($action, 'delete')        => 'bg-warning text-dark',
        str_contains($action, 'update')        => 'bg-info text-dark',
        str_contains($action, 'logout')        => 'bg-secondary',
        str_contains($action, 'create'),
        str_contains($action, 'add')           => 'bg-primary',
        default                                => 'bg-primary',
    };
}

function timeAgo(?string $timestamp): string
{
    if (!$timestamp) {
        return '—';
    }

    $diff = time() - strtotime($timestamp);

    if ($diff < 60) {
        return 'Just now';
    }

    $tokens = [
        31536000 => 'year',
        2592000  => 'month',
        604800   => 'week',
        86400    => 'day',
        3600     => 'hour',
        60       => 'minute',
    ];

    foreach ($tokens as $unit => $label) {
        if ($diff >= $unit) {
            $count = (int) floor($diff / $unit);
            return $count . ' ' . $label . ($count > 1 ? 's' : '') . ' ago';
        }
    }

    return 'Just now';
}

function normalizeDate(?string $date, bool $endOfDay = false): ?string
{
    if (!$date) {
        return null;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt) {
        return null;
    }

    return $endOfDay
        ? $dt->format('Y-m-d 23:59:59')
        : $dt->format('Y-m-d 00:00:00');
}

function maskIp(?string $ip, bool $isAdmin): string
{
    $ip = trim((string)$ip);

    if ($ip === '') {
        return '—';
    }

    if ($isAdmin) {
        return $ip;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);
        $parts[3] = 'xxx';
        return implode('.', $parts);
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return preg_replace('/:[a-f0-9]{1,4}$/i', ':xxxx', $ip) ?? 'Hidden';
    }

    return 'Hidden';
}

// ------------------------------
// Filters
// ------------------------------
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = (int)($_GET['per_page'] ?? 25);
$allowedPerPage = [10, 25, 50, 100];
$perPage    = in_array($perPage, $allowedPerPage, true) ? $perPage : 25;

$action     = trim((string)($_GET['action'] ?? ''));
$search     = trim((string)($_GET['search'] ?? ''));
$dateFrom   = normalizeDate($_GET['date_from'] ?? null, false);
$dateTo     = normalizeDate($_GET['date_to'] ?? null, true);
$offset     = ($page - 1) * $perPage;

$where = [];
$params = [];

if (!$isAdmin) {
    $where[] = "al.{$activity_log_user_id} = :session_user_id";
    $params[':session_user_id'] = $sessionUserId;
}

if ($action !== '') {
    $where[] = "al.{$activity_log_action} = :action";
    $params[':action'] = $action;
}

if ($search !== '') {
    $where[] = "(
        al.{$activity_log_action} LIKE :search
        OR al.{$activity_log_desc} LIKE :search
        OR u.first_name LIKE :search
        OR u.last_name LIKE :search
        OR u.username LIKE :search
    )";
    $params[':search'] = '%' . $search . '%';
}

if ($dateFrom !== null) {
    $where[] = "al.{$activity_log_created} >= :date_from";
    $params[':date_from'] = $dateFrom;
}

if ($dateTo !== null) {
    $where[] = "al.{$activity_log_created} <= :date_to";
    $params[':date_to'] = $dateTo;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$activityLogs = [];
$totalRows = 0;
$errorMsg = null;
$actionOptions = [];

try {
    $countSql = "
        SELECT COUNT(*) 
        FROM {$table_activity_logs} al
        LEFT JOIN {$table_users} u
            ON al.{$activity_log_user_id} = u.{$user_id}
        {$whereSql}
    ";

    $countStmt = $conn->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalRows = (int)$countStmt->fetchColumn();

    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $dataSql = "
        SELECT
            al.{$activity_log_id} AS id,
            al.{$activity_log_action} AS action,
            al.{$activity_log_desc} AS description,
            al.{$activity_log_ip} AS ip_address,
            al.{$activity_log_created} AS created_at,
            u.first_name,
            u.last_name,
            u.username,
            u.role
        FROM {$table_activity_logs} al
        LEFT JOIN {$table_users} u
            ON al.{$activity_log_user_id} = u.{$user_id}
        {$whereSql}
        ORDER BY al.{$activity_log_created} DESC, al.{$activity_log_id} DESC
        LIMIT :limit OFFSET :offset
    ";

    $dataStmt = $conn->prepare($dataSql);
    foreach ($params as $key => $value) {
        $dataStmt->bindValue($key, $value);
    }
    $dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $dataStmt->execute();

    $activityLogs = $dataStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $actionSql = "
        SELECT DISTINCT {$activity_log_action} AS action
        FROM {$table_activity_logs}
        ORDER BY {$activity_log_action} ASC
    ";
    $actionStmt = $conn->query($actionSql);
    $actionOptions = $actionStmt ? ($actionStmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];

} catch (Throwable $e) {
    error_log('[activity_log.php] ' . $e->getMessage());
    $errorMsg = 'Failed to load activity logs.';
    $totalPages = 1;
}

function buildPageUrl(int $targetPage): string
{
    $query = $_GET;
    $query['page'] = $targetPage;
    return '?' . http_build_query($query);
}
?>
<!DOCTYPE html>
<html lang="en">
<body>

<?php
require __DIR__ . '/../components/header.php';
require __DIR__ . '/../components/sidebar.php';
?>

<main id="main" class="main">
    <div class="pagetitle">
        <h1>Activity Logs</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="/inventory_system/index.php">Home</a>
                </li>
                <li class="breadcrumb-item active">Activity Logs</li>
            </ol>
        </nav>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <h5 class="card-title mb-0">
                                <?= $isAdmin ? 'All Activity Logs' : 'My Activity Logs' ?>
                            </h5>
                            <div class="text-muted small">
                                Total: <?= number_format($totalRows) ?>
                            </div>
                        </div>

                        <?php if ($errorMsg !== null): ?>
                            <div class="alert alert-danger mt-3"><?= e($errorMsg) ?></div>
                        <?php endif; ?>

                        <form method="GET" class="row g-3 mb-3 mt-1">
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input
                                    type="text"
                                    name="search"
                                    class="form-control"
                                    value="<?= e($search) ?>"
                                    placeholder="Action, description, or user"
                                >
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Action</label>
                                <select name="action" class="form-select">
                                    <option value="">All actions</option>
                                    <?php foreach ($actionOptions as $opt): ?>
                                        <option value="<?= e($opt) ?>" <?= $action === $opt ? 'selected' : '' ?>>
                                            <?= e(ucwords(str_replace('_', ' ', $opt))) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">From</label>
                                <input
                                    type="date"
                                    name="date_from"
                                    class="form-control"
                                    value="<?= e($_GET['date_from'] ?? '') ?>"
                                >
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">To</label>
                                <input
                                    type="date"
                                    name="date_to"
                                    class="form-control"
                                    value="<?= e($_GET['date_to'] ?? '') ?>"
                                >
                            </div>

                            <div class="col-md-1">
                                <label class="form-label">Rows</label>
                                <select name="per_page" class="form-select">
                                    <?php foreach ($allowedPerPage as $size): ?>
                                        <option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>>
                                            <?= $size ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2 d-flex align-items-end gap-2">
                                <button type="submit" class="btn btn-primary w-100">Filter</button>
                                <a href="/inventory_system/admin/activity_log.php" class="btn btn-outline-secondary w-100">Reset</a>
                            </div>
                        </form>

                        <div class="table-responsive">
                            <table class="table table-striped table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <?php if ($isAdmin): ?>
                                            <th>User</th>
                                            <th>Role</th>
                                        <?php endif; ?>
                                        <th>Action</th>
                                        <th>Description</th>
                                        <th>IP Address</th>
                                        <th>Date &amp; Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($activityLogs)): ?>
                                        <?php foreach ($activityLogs as $index => $log): ?>
                                            <?php
                                            $userName = trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? ''));
                                            $userName = $userName !== '' ? $userName : (($log['username'] ?? '') !== '' ? $log['username'] : 'System');
                                            $actionTitle = ucwords(str_replace('_', ' ', (string)$log['action']));
                                            $badgeClass = actionBadge((string)$log['action']);
                                            $rowNumber = $offset + $index + 1;
                                            ?>
                                            <tr>
                                                <td><?= $rowNumber ?></td>

                                                <?php if ($isAdmin): ?>
                                                    <td><?= e($userName) ?></td>
                                                    <td>
                                                        <?php if (!empty($log['role'])): ?>
                                                            <span class="badge bg-info text-dark">
                                                                <?= e(ucfirst((string)$log['role'])) ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endif; ?>

                                                <td>
                                                    <span class="badge <?= e($badgeClass) ?>">
                                                        <?= e($actionTitle) ?>
                                                    </span>
                                                </td>

                                                <td style="min-width: 280px;">
                                                    <?= e($log['description'] ?? '—') ?>
                                                </td>

                                                <td class="text-muted small">
                                                    <?= e(maskIp($log['ip_address'] ?? null, $isAdmin)) ?>
                                                </td>

                                                <td class="text-muted small" style="white-space: nowrap;">
                                                    <strong><?= e(timeAgo($log['created_at'] ?? null)) ?></strong><br>
                                                    <span><?= e(date('M j, Y g:i A', strtotime((string)$log['created_at']))) ?></span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="<?= $isAdmin ? 7 : 5 ?>" class="text-center py-4 text-muted">
                                                No activity logs found.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if (($totalPages ?? 1) > 1): ?>
                            <nav class="mt-3">
                                <ul class="pagination mb-0">
                                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                        <a class="page-link" href="<?= $page <= 1 ? '#' : e(buildPageUrl($page - 1)) ?>">Previous</a>
                                    </li>

                                    <?php
                                    $start = max(1, $page - 2);
                                    $end   = min($totalPages, $page + 2);
                                    for ($i = $start; $i <= $end; $i++):
                                    ?>
                                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                            <a class="page-link" href="<?= e(buildPageUrl($i)) ?>"><?= $i ?></a>
                                        </li>
                                    <?php endfor; ?>

                                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                        <a class="page-link" href="<?= $page >= $totalPages ? '#' : e(buildPageUrl($page + 1)) ?>">Next</a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<?php require __DIR__ . '/../components/footer.php'; ?>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/components/js_script.php'; ?>

</body>
</html>