<?php
/**
 * components/header.php
 * Role-based notifications + seen tracking + real session profile.
 */
require_once __DIR__ . '/../controllers/NotificationController.php';
require_once __DIR__ . '/../middleware/Middleware.php';

$csrfToken = Middleware::generateCsrfToken();

$sessionUserId   = (int)($_SESSION['user_id'] ?? 0);
$sessionRole     = $_SESSION['role'] ?? 'staff';
$sessionPhoto    = $_SESSION['photo'] ?? '';
$sessionFullName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));

if (empty($sessionFullName)) {
    $sessionFullName = $_SESSION['username'] ?? 'User';
}

$profilePhoto = !empty($sessionPhoto)
    ? htmlspecialchars($sessionPhoto)
    : '/inventory_system/assets/img/profile-img.jpg';

$notifications = [];
$unreadNotifications = [];
$previousNotifications = [];
$notifCount    = 0;

if ($sessionUserId && isset($conn)) {
    $feed = NotificationController::getNotificationFeed($conn, $sessionUserId, $sessionRole, 20);
    $notifications = $feed['all'];
    $unreadNotifications = $feed['unread'];
    $previousNotifications = $feed['previous'];
    $notifCount    = NotificationController::getUnreadCount($conn, $sessionUserId, $sessionRole);
}

function renderNotificationItems(array $items, string $emptyTitle, string $emptyText): string
{
    if (empty($items)) {
        return '
            <div class="notif-empty-state">
                <i class="bi bi-bell-slash"></i>
                <strong>' . htmlspecialchars($emptyTitle, ENT_QUOTES, 'UTF-8') . '</strong>
                <span>' . htmlspecialchars($emptyText, ENT_QUOTES, 'UTF-8') . '</span>
            </div>
        ';
    }

    $html = '';

    foreach ($items as $notif) {
        $link = trim((string)($notif['link'] ?? ''));
        $wrapperTag = $link !== '' ? 'a' : 'div';
        $wrapperAttrs = $link !== ''
            ? ' href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" class="notification-item notification-link"'
            : ' class="notification-item"';

        $html .= '
            <' . $wrapperTag . $wrapperAttrs . '>
                <div class="notification-icon-wrap">
                    <i class="bi ' . htmlspecialchars((string)($notif['icon'] ?? 'bi-bell'), ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars((string)($notif['color'] ?? 'text-primary'), ENT_QUOTES, 'UTF-8') . '"></i>
                </div>
                <div class="notification-copy">
                    <h4>' . htmlspecialchars((string)($notif['title'] ?? 'Notification'), ENT_QUOTES, 'UTF-8') . '</h4>
                    <p>' . htmlspecialchars((string)($notif['message'] ?? ''), ENT_QUOTES, 'UTF-8') . '</p>
                    <span>' . htmlspecialchars((string)($notif['time_ago'] ?? NotificationController::timeAgo($notif['time'] ?? null)), ENT_QUOTES, 'UTF-8') . '</span>
                </div>
            </' . $wrapperTag . '>
        ';
    }

    return $html;
}
?>

<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">

<header id="header" class="header fixed-top d-flex align-items-center">

    <div class="d-flex align-items-center justify-content-between">
        <a href="/inventory_system/index.php" class="logo d-flex align-items-center">
            <img src="<?= HOSTURL ?>/assets/img/logo.png" alt="">
            <span class="d-none d-lg-block">POS</span>
        </a>
        <i class="bi bi-list toggle-sidebar-btn"></i>
    </div>

    <div class="search-bar">
        <form class="search-form d-flex align-items-center" method="POST" action="#">
            <input type="text" name="query" placeholder="Search" title="Enter search keyword">
            <button type="submit" title="Search">
                <i class="bi bi-search"></i>
            </button>
        </form>
    </div>

    <nav class="header-nav ms-auto">
        <ul class="d-flex align-items-center">

            <li class="nav-item d-block d-lg-none">
                <a class="nav-link nav-icon search-bar-toggle" href="#">
                    <i class="bi bi-search"></i>
                </a>
            </li>

            <li class="nav-item dropdown" id="notifDropdown" data-bs-auto-close="outside">
                <a class="nav-link nav-icon" href="#" data-bs-toggle="dropdown" id="notifBell">
                    <i class="bi bi-bell"></i>

                    <?php if ($notifCount > 0): ?>
                        <span class="badge bg-danger badge-number" id="notifBadge">
                            <?= $notifCount > 99 ? '99+' : $notifCount ?>
                        </span>
                    <?php else: ?>
                        <span class="badge bg-danger badge-number d-none" id="notifBadge"></span>
                    <?php endif; ?>
                </a>

                <ul
                    class="dropdown-menu dropdown-menu-end dropdown-menu-arrow notifications"
                    id="notifList"
                    style="min-width:380px; max-height:520px; overflow-y:auto;"
                >
                    <li class="notif-shell">
                        <div class="notif-topbar">
                            <div>
                                <h6>Notifications</h6>
                                <small>
                                    <?= count($notifications) ?> total
                                    <?php if ($notifCount > 0): ?>
                                        • <?= $notifCount ?> unread
                                    <?php endif; ?>
                                </small>
                            </div>
                            <div class="notif-topbar-actions">
                                <?php if ($notifCount > 0): ?>
                                    <button type="button" class="btn btn-link btn-sm notif-mark-read" id="notifMarkReadBtn">
                                        Mark all as read
                                    </button>
                                <?php endif; ?>
                                <a href="/inventory_system/admin/activity_log.php" class="btn btn-link btn-sm">View all</a>
                            </div>
                        </div>

                        <div class="notif-tabs" id="notifTabs">
                            <button type="button" class="notif-tab active" data-view="all">
                                All
                            </button>
                            <button type="button" class="notif-tab" data-view="unread">
                                Unread<?= !empty($unreadNotifications) ? ' (' . count($unreadNotifications) . ')' : '' ?>
                            </button>
                            <button type="button" class="notif-tab" data-view="previous">
                                Previous<?= !empty($previousNotifications) ? ' (' . count($previousNotifications) . ')' : '' ?>
                            </button>
                        </div>

                        <div class="notif-panels" id="notifPanels">
                            <div class="notif-panel active" data-panel="all">
                                <?= renderNotificationItems(
                                    $notifications,
                                    'No notifications yet',
                                    'New activity will appear here as your team works.'
                                ) ?>
                            </div>

                            <div class="notif-panel" data-panel="unread">
                                <?= renderNotificationItems(
                                    $unreadNotifications,
                                    'No unread notifications',
                                    'You are all caught up right now.'
                                ) ?>
                            </div>

                            <div class="notif-panel" data-panel="previous">
                                <?= renderNotificationItems(
                                    $previousNotifications,
                                    'No previous notifications',
                                    'Older activity will appear here after you read it.'
                                ) ?>
                            </div>
                        </div>

                        <div class="dropdown-footer">
                            <a href="/inventory_system/admin/activity_log.php">Show all activity</a>
                        </div>
                    </li>
                </ul>
            </li>

            <li class="nav-item dropdown pe-3">
                <a class="nav-link nav-profile d-flex align-items-center pe-0" href="#" data-bs-toggle="dropdown">
                    <img
                        src="<?= $profilePhoto ?>"
                        alt="Profile"
                        class="rounded-circle"
                        style="width:32px;height:32px;object-fit:cover;"
                        onerror="this.src='/inventory_system/assets/img/profile-img.jpg';this.onerror=null;"
                    >
                    <span class="d-none d-md-block dropdown-toggle ps-2">
                        <?= htmlspecialchars($sessionFullName) ?>
                    </span>
                </a>

                <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow profile">
                    <li class="dropdown-header">
                        <h6><?= htmlspecialchars($sessionFullName) ?></h6>
                        <span><?= ucfirst(htmlspecialchars($sessionRole)) ?></span>
                    </li>
                    <li><hr class="dropdown-divider"></li>

                    <li>
                        <a class="dropdown-item d-flex align-items-center" href="/inventory_system/profile.php">
                            <i class="bi bi-person"></i><span>My Profile</span>
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>

                    <li>
                        <a class="dropdown-item d-flex align-items-center" href="/inventory_system/settings.php">
                            <i class="bi bi-gear"></i><span>Account Settings</span>
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>

                    <li>
                        <form method="POST" action="/inventory_system/logout.php" class="m-0">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" class="dropdown-item d-flex align-items-center border-0 bg-transparent w-100">
                                <i class="bi bi-box-arrow-right"></i><span>Sign Out</span>
                            </button>
                        </form>
                    </li>
                </ul>
            </li>

        </ul>
    </nav>

</header>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const notifBadge = document.getElementById('notifBadge');
    const notifList = document.getElementById('notifList');
    const notifDropdown = document.getElementById('notifDropdown');
    let currentNotifView = 'all';

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str ?? '';
        return div.innerHTML;
    }

    function renderNotificationItems(items, emptyTitle, emptyText) {
        if (!Array.isArray(items) || items.length === 0) {
            return `
                <div class="notif-empty-state">
                    <i class="bi bi-bell-slash"></i>
                    <strong>${escapeHtml(emptyTitle)}</strong>
                    <span>${escapeHtml(emptyText)}</span>
                </div>
            `;
        }

        return items.map((notif) => {
            const link = `${notif.link || ''}`.trim();
            const wrapperTag = link ? 'a' : 'div';
            const attrs = link
                ? `href="${escapeHtml(link)}" class="notification-item notification-link"`
                : 'class="notification-item"';

            return `
                <${wrapperTag} ${attrs}>
                    <div class="notification-icon-wrap">
                        <i class="bi ${escapeHtml(notif.icon || 'bi-bell')} ${escapeHtml(notif.color || 'text-primary')}"></i>
                    </div>
                    <div class="notification-copy">
                        <h4>${escapeHtml(notif.title || 'Notification')}</h4>
                        <p>${escapeHtml(notif.message || '')}</p>
                        <span>${escapeHtml(notif.time_ago || notif.time || '')}</span>
                    </div>
                </${wrapperTag}>
            `;
        }).join('');
    }

    function renderNotifications(data) {
        if (!notifList) {
            return;
        }

        const allItems = Array.isArray(data.notifications) ? data.notifications : [];
        const unreadItems = Array.isArray(data.unread) ? data.unread : [];
        const previousItems = Array.isArray(data.previous) ? data.previous : [];

        if (!['all', 'unread', 'previous'].includes(currentNotifView)) {
            currentNotifView = 'all';
        }

        if (currentNotifView === 'unread' && unreadItems.length === 0) {
            currentNotifView = 'all';
        }

        let html = `
            <li class="notif-shell">
                <div class="notif-topbar">
                    <div>
                        <h6>Notifications</h6>
                        <small>
                            ${allItems.length} total
                            ${data.count > 0 ? `• ${data.count} unread` : ''}
                        </small>
                    </div>
                    <div class="notif-topbar-actions">
                        ${data.count > 0 ? '<button type="button" class="btn btn-link btn-sm notif-mark-read" id="notifMarkReadBtn">Mark all as read</button>' : ''}
                        <a href="/inventory_system/admin/activity_log.php" class="btn btn-link btn-sm">View all</a>
                    </div>
                </div>

                <div class="notif-tabs" id="notifTabs">
                    <button type="button" class="notif-tab ${currentNotifView === 'all' ? 'active' : ''}" data-view="all">
                        All
                    </button>
                    <button type="button" class="notif-tab ${currentNotifView === 'unread' ? 'active' : ''}" data-view="unread">
                        Unread${unreadItems.length ? ` (${unreadItems.length})` : ''}
                    </button>
                    <button type="button" class="notif-tab ${currentNotifView === 'previous' ? 'active' : ''}" data-view="previous">
                        Previous${previousItems.length ? ` (${previousItems.length})` : ''}
                    </button>
                </div>

                <div class="notif-panels" id="notifPanels">
                    <div class="notif-panel ${currentNotifView === 'all' ? 'active' : ''}" data-panel="all">
                        ${renderNotificationItems(
                            allItems,
                            'No notifications yet',
                            'New activity will appear here as your team works.'
                        )}
                    </div>
                    <div class="notif-panel ${currentNotifView === 'unread' ? 'active' : ''}" data-panel="unread">
                        ${renderNotificationItems(
                            unreadItems,
                            'No unread notifications',
                            'You are all caught up right now.'
                        )}
                    </div>
                    <div class="notif-panel ${currentNotifView === 'previous' ? 'active' : ''}" data-panel="previous">
                        ${renderNotificationItems(
                            previousItems,
                            'No previous notifications',
                            'Older activity will appear here after you read it.'
                        )}
                    </div>
                </div>

                <div class="dropdown-footer">
                    <a href="/inventory_system/admin/activity_log.php">Show all activity</a>
                </div>
            </li>
        `;

        notifList.innerHTML = html;
    }

    function updateNotifications() {
        fetch('/inventory_system/http/ajax/fetch_notifications.php', {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(async (res) => {
            const text = await res.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Invalid JSON from fetch_notifications.php:', text);
                throw e;
            }
        })
        .then(data => {
            if (!data.success) {
                console.error('Fetch notifications failed:', data.error || data);
                return;
            }

            if (notifBadge) {
                if (data.count > 0) {
                    notifBadge.textContent = data.count > 99 ? '99+' : data.count;
                    notifBadge.classList.remove('d-none');
                } else {
                    notifBadge.textContent = '';
                    notifBadge.classList.add('d-none');
                }
            }

            renderNotifications(data);
        })
        .catch(err => {
            console.error('Notification refresh failed:', err);
        });
    }

    function markNotificationsSeen() {
        const csrfToken =
            document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const formData = new FormData();
        formData.append('csrf_token', csrfToken);

        fetch('/inventory_system/http/ajax/mark_notifications_seen.php', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
        })
        .then(async (res) => {
            const text = await res.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Invalid JSON from mark_notifications_seen.php:', text);
                throw e;
            }
        })
        .then(data => {
            if (!data.success) {
                console.error('Mark seen failed:', data.error || data);
                return;
            }

            if (notifBadge) {
                notifBadge.textContent = '';
                notifBadge.classList.add('d-none');
            }

            updateNotifications();
        })
        .catch(err => {
            console.error('Mark notifications seen failed:', err);
        });
    }

    function connectWebSocket() {
        try {
            window.socket = new WebSocket('ws://127.0.0.1:8080');

            window.socket.onopen = () => {
                console.log('WebSocket connected');
            };

            window.socket.onmessage = (event) => {
                try {
                    const data = JSON.parse(event.data);

                    if (data.event === 'notification_update') {
                        updateNotifications();
                    }
                } catch (e) {
                    console.error('Invalid WS message:', e);
                }
            };

            window.socket.onclose = () => {
                console.warn('WebSocket disconnected. Reconnecting in 3 seconds...');
                setTimeout(connectWebSocket, 3000);
            };

            window.socket.onerror = (err) => {
                console.error('WebSocket error:', err);
            };
        } catch (e) {
            console.error('WebSocket init failed:', e);
            setTimeout(connectWebSocket, 3000);
        }
    }

    if (notifList) {
        notifList.addEventListener('click', (event) => {
            if (
                event.target.closest('.notif-tab') ||
                event.target.closest('.notif-mark-read')
            ) {
                event.stopPropagation();
            }
        });

        notifList.addEventListener('click', (event) => {
            const tab = event.target.closest('.notif-tab');
            if (tab) {
                event.preventDefault();
                currentNotifView = tab.dataset.view || 'all';
                document.querySelectorAll('#notifList .notif-tab').forEach((button) => {
                    button.classList.toggle('active', button.dataset.view === currentNotifView);
                });
                document.querySelectorAll('#notifList .notif-panel').forEach((panel) => {
                    panel.classList.toggle('active', panel.dataset.panel === currentNotifView);
                });
                return;
            }

            const markReadButton = event.target.closest('.notif-mark-read');
            if (markReadButton) {
                event.preventDefault();
                markNotificationsSeen();
            }
        });
    }

    updateNotifications();
    connectWebSocket();
});
</script>
