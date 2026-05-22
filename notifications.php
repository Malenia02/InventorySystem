<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap/app.php';
require_once __DIR__ . '/middleware/Middleware.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/NotificationController.php';

Middleware::auth();

$pageTitle = 'Notification Center';
$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
$sessionRole = strtolower((string) ($_SESSION['role'] ?? 'staff'));
$isAdmin = $sessionRole === 'admin';

if ($sessionUserId <= 0) {
    header('Location: /inventory_system/login.php');
    exit;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function notificationCenterUrl(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);

    foreach ($query as $key => $value) {
        if ($value === null || $value === '' || $value === 'all' || $value === 0) {
            unset($query[$key]);
        }
    }

    return '/inventory_system/notifications.php' . ($query ? '?' . http_build_query($query) : '');
}

function notificationActorLabel(array $actor): string
{
    $name = trim((string) ($actor['first_name'] ?? '') . ' ' . (string) ($actor['last_name'] ?? ''));
    return $name !== '' ? $name : (string) ($actor['username'] ?? 'User');
}

function notificationCategoryMeta(string $category): array
{
    return match ($category) {
        'sales' => [
            'label' => 'Sales',
            'icon' => 'bi-receipt-cutoff',
            'class' => 'is-sales',
        ],
        'shift' => [
            'label' => 'Shift',
            'icon' => 'bi-journal-check',
            'class' => 'is-shift',
        ],
        'inventory' => [
            'label' => 'Inventory',
            'icon' => 'bi-box-seam',
            'class' => 'is-inventory',
        ],
        'security' => [
            'label' => 'Security',
            'icon' => 'bi-shield-lock',
            'class' => 'is-security',
        ],
        default => [
            'label' => 'System',
            'icon' => 'bi-bell',
            'class' => 'is-system',
        ],
    };
}

$filters = [
    'search' => trim((string) ($_GET['q'] ?? '')),
    'category' => strtolower(trim((string) ($_GET['category'] ?? 'all'))),
    'status' => strtolower(trim((string) ($_GET['status'] ?? 'all'))),
    'period' => strtolower(trim((string) ($_GET['period'] ?? 'all'))),
    'actor_id' => $isAdmin ? (int) ($_GET['actor_id'] ?? 0) : 0,
];

$allowedCategories = ['all', 'sales', 'shift', 'inventory', 'security', 'system'];
$allowedStatuses = ['all', 'unread', 'previous'];
$allowedPeriods = ['all', 'today', 'week', 'month'];

if (!in_array($filters['category'], $allowedCategories, true)) {
    $filters['category'] = 'all';
}

if (!in_array($filters['status'], $allowedStatuses, true)) {
    $filters['status'] = 'all';
}

if (!in_array($filters['period'], $allowedPeriods, true)) {
    $filters['period'] = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!AuthController::validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Security token mismatch. Please refresh and try again.');
        }

        NotificationController::markSeen($conn, $sessionUserId);
        $_SESSION['notification_center_flash'] = 'Notifications marked as read.';
        header('Location: ' . notificationCenterUrl());
        exit;
    } catch (Throwable $e) {
        error_log('[notifications.php] ' . $e->getMessage());
        $_SESSION['notification_center_error'] = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Unable to update notifications right now.';
        header('Location: ' . notificationCenterUrl());
        exit;
    }
}

$flashMessage = isset($_SESSION['notification_center_flash'])
    ? (string) $_SESSION['notification_center_flash']
    : null;
$errorMessage = isset($_SESSION['notification_center_error'])
    ? (string) $_SESSION['notification_center_error']
    : null;
unset($_SESSION['notification_center_flash'], $_SESSION['notification_center_error']);

$center = NotificationController::getNotificationCenter($conn, $sessionUserId, $sessionRole, $filters, 80);
$items = $center['items'];
$stats = $center['stats'];
$actors = $center['actors'];
$csrfToken = Middleware::generateCsrfToken();

$categoryTabs = [
    'all' => ['label' => 'All', 'icon' => 'bi-grid'],
    'sales' => ['label' => 'Sales', 'icon' => 'bi-receipt'],
    'shift' => ['label' => 'Shift Management', 'icon' => 'bi-journal-check'],
    'inventory' => ['label' => 'Inventory', 'icon' => 'bi-box-seam'],
    'security' => ['label' => 'Security', 'icon' => 'bi-shield-lock'],
    'system' => ['label' => 'System', 'icon' => 'bi-bell'],
];
?>
<!DOCTYPE html>
<html lang="en">
<?php require __DIR__ . '/components/head.php'; ?>
<link rel="stylesheet" href="/inventory_system/assets/css/notification-center.css">
<body>
<?php
require __DIR__ . '/components/header.php';
require __DIR__ . '/components/sidebar.php';
?>

<main id="main" class="main notification-center-page">
    <div class="notification-hero">
        <div class="notification-hero-main">
            <p class="notification-eyebrow"><?= $isAdmin ? 'Team activity inbox' : 'Personal activity inbox' ?></p>
            <h1>Notification Center</h1>
            <p class="notification-hero-copy">
                <?= $isAdmin
                    ? 'Monitor cashier sales, shift management activity, inventory events, and system alerts in one focused workspace.'
                    : 'Review your own sales, shift activity, and notifications without seeing other cashier records.' ?>
            </p>
        </div>
        <div class="notification-hero-side">
            <div class="notification-hero-mini">
                <span class="notification-hero-mini-label">Unread now</span>
                <strong><?= number_format((int) ($stats['unread'] ?? 0)) ?></strong>
                <small><?= $isAdmin ? 'alerts across your visible team feed' : 'alerts in your personal activity feed' ?></small>
            </div>
            <form method="POST" class="notification-mark-read-form">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <button type="submit" class="notification-primary-action">
                    <i class="bi bi-check2-circle"></i>
                    Mark all as read
                </button>
            </form>
        </div>
    </div>

    <?php if ($flashMessage !== null): ?>
        <div class="alert alert-success"><?= e($flashMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage !== null): ?>
        <div class="alert alert-danger"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <section class="notification-stats-grid" id="notificationStatsGrid">
        <a href="<?= e(notificationCenterUrl(['status' => 'all', 'category' => 'all'])) ?>" class="notification-stat-card" data-stat-filter="total">
            <span class="notification-stat-icon is-system"><i class="bi bi-bell"></i></span>
            <span class="notification-stat-copy">
                <strong data-stat-value="total"><?= number_format((int) ($stats['total'] ?? 0)) ?></strong>
                <small>Total visible</small>
            </span>
        </a>
        <a href="<?= e(notificationCenterUrl(['status' => 'unread'])) ?>" class="notification-stat-card" data-stat-filter="unread">
            <span class="notification-stat-icon is-security"><i class="bi bi-lightning-charge"></i></span>
            <span class="notification-stat-copy">
                <strong data-stat-value="unread"><?= number_format((int) ($stats['unread'] ?? 0)) ?></strong>
                <small>Unread</small>
            </span>
        </a>
        <a href="<?= e(notificationCenterUrl(['category' => 'sales'])) ?>" class="notification-stat-card" data-stat-filter="sales">
            <span class="notification-stat-icon is-sales"><i class="bi bi-receipt-cutoff"></i></span>
            <span class="notification-stat-copy">
                <strong data-stat-value="sales"><?= number_format((int) ($stats['sales'] ?? 0)) ?></strong>
                <small>Sales activity</small>
            </span>
        </a>
        <a href="<?= e(notificationCenterUrl(['category' => 'shift'])) ?>" class="notification-stat-card" data-stat-filter="shift">
            <span class="notification-stat-icon is-shift"><i class="bi bi-journal-check"></i></span>
            <span class="notification-stat-copy">
                <strong data-stat-value="shift"><?= number_format((int) ($stats['shift'] ?? 0)) ?></strong>
                <small>Shift closings</small>
            </span>
        </a>
        <a href="<?= e(notificationCenterUrl(['category' => 'inventory'])) ?>" class="notification-stat-card" data-stat-filter="inventory">
            <span class="notification-stat-icon is-inventory"><i class="bi bi-box-seam"></i></span>
            <span class="notification-stat-copy">
                <strong data-stat-value="inventory"><?= number_format((int) ($stats['inventory'] ?? 0)) ?></strong>
                <small>Inventory events</small>
            </span>
        </a>
    </section>

    <section class="notification-layout">
        <aside class="notification-filter-panel">
            <div class="notification-filter-card">
                <div class="notification-card-head">
                    <div>
                        <p class="notification-card-kicker">Categories</p>
                        <h2>Filters</h2>
                    </div>
                </div>
                <div class="notification-category-list" id="notificationCategoryList">
                    <?php foreach ($categoryTabs as $key => $tab): ?>
                        <a
                            href="<?= e(notificationCenterUrl(['category' => $key])) ?>"
                            class="notification-category-link <?= $filters['category'] === $key ? 'active' : '' ?>"
                            data-category="<?= e($key) ?>"
                        >
                            <i class="bi <?= e($tab['icon']) ?>"></i>
                            <span><?= e($tab['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <form method="GET" class="notification-filter-card notification-filter-form" id="notificationFilterForm">
                <div class="notification-card-head">
                    <div>
                        <p class="notification-card-kicker">Search</p>
                        <h2>Refine</h2>
                    </div>
                </div>
                <label>
                    <span>Search</span>
                    <input type="search" name="q" class="form-control" value="<?= e($filters['search']) ?>" placeholder="Title, message, cashier">
                </label>

                <label>
                    <span>Status</span>
                    <select name="status" class="form-select">
                        <option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="unread" <?= $filters['status'] === 'unread' ? 'selected' : '' ?>>Unread</option>
                        <option value="previous" <?= $filters['status'] === 'previous' ? 'selected' : '' ?>>Previous</option>
                    </select>
                </label>

                <label>
                    <span>Date</span>
                    <select name="period" class="form-select">
                        <option value="all" <?= $filters['period'] === 'all' ? 'selected' : '' ?>>All time</option>
                        <option value="today" <?= $filters['period'] === 'today' ? 'selected' : '' ?>>Today</option>
                        <option value="week" <?= $filters['period'] === 'week' ? 'selected' : '' ?>>Last 7 days</option>
                        <option value="month" <?= $filters['period'] === 'month' ? 'selected' : '' ?>>Last 30 days</option>
                    </select>
                </label>

                <input type="hidden" name="category" value="<?= e($filters['category']) ?>" id="notificationCategoryInput">

                <?php if ($isAdmin): ?>
                    <label>
                        <span>Cashier / User</span>
                        <select name="actor_id" class="form-select">
                            <option value="0">All users</option>
                            <?php foreach ($actors as $actor): ?>
                                <option value="<?= (int) $actor['user_id'] ?>" <?= $filters['actor_id'] === (int) $actor['user_id'] ? 'selected' : '' ?>>
                                    <?= e(notificationActorLabel($actor)) ?><?= !empty($actor['role']) ? ' (' . e(ucfirst((string) $actor['role'])) . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>

                <div class="notification-filter-actions">
                    <button type="submit" class="btn btn-primary w-100">Apply Filters</button>
                    <a href="/inventory_system/notifications.php" class="btn btn-outline-secondary w-100" id="notificationResetFilters">Reset</a>
                </div>
            </form>
        </aside>

        <div class="notification-feed-panel">
            <div class="notification-feed-head">
                <div>
                    <p class="notification-card-kicker">Live feed</p>
                    <h2>Activity Feed</h2>
                    <p id="notificationFeedCount"><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?> shown</p>
                </div>
                <span class="notification-feed-pill" id="notificationFeedPill">
                    <?= $filters['status'] === 'unread' ? 'Unread only' : ($filters['status'] === 'previous' ? 'Previous' : 'Live feed') ?>
                </span>
            </div>

            <div id="notificationFeedContent">
            <?php if (empty($items)): ?>
                <div class="notification-empty-state">
                    <i class="bi bi-inboxes"></i>
                    <strong>No notifications found</strong>
                    <span>Try changing the search, status, or category filter.</span>
                </div>
            <?php else: ?>
                <div class="notification-feed-list">
                    <?php foreach ($items as $item): ?>
                        <?php $meta = notificationCategoryMeta((string) ($item['category'] ?? 'system')); ?>
                        <?php $link = trim((string) ($item['link'] ?? '')); ?>
                        <?php $tag = $link !== '' ? 'a' : 'article'; ?>
                        <<?= $tag ?>
                            class="notification-feed-item <?= e($meta['class']) ?> <?= !empty($item['is_unread']) ? 'is-unread' : '' ?>"
                            <?= $link !== '' ? 'href="' . e($link) . '"' : '' ?>
                        >
                            <div class="notification-feed-icon">
                                <i class="bi <?= e((string) ($item['icon'] ?? $meta['icon'])) ?>"></i>
                            </div>
                            <div class="notification-feed-copy">
                                <div class="notification-feed-top">
                                    <span class="notification-type-chip"><?= e($meta['label']) ?></span>
                                    <?php if (!empty($item['is_unread'])): ?>
                                        <span class="notification-unread-dot">Unread</span>
                                    <?php endif; ?>
                                    <time><?= e((string) ($item['time_ago'] ?? '')) ?></time>
                                </div>
                                <h3><?= e((string) ($item['title'] ?? 'Notification')) ?></h3>
                                <p><?= e((string) ($item['message'] ?? '')) ?></p>
                                <div class="notification-feed-meta">
                                    <span><i class="bi bi-person"></i> <?= e((string) ($item['actor_name'] ?? 'System')) ?></span>
                                    <span><i class="bi bi-clock"></i> <?= e(date('M d, Y h:i A', strtotime((string) ($item['time'] ?? 'now')))) ?></span>
                                </div>
                            </div>
                        </<?= $tag ?>>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            </div>
        </div>
    </section>
</main>

<?php require __DIR__ . '/components/footer.php'; ?>
<?php require __DIR__ . '/components/js_script.php'; ?>
<script src="/inventory_system/assets/js/notification-center.js"></script>
</body>
</html>
