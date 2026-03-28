<?php
/**
 * components/header.php
 * Role-based notifications + seen tracking + real session profile.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/controllers/NotificationController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/middleware/Middleware.php';

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
$notifCount    = 0;

if ($sessionUserId && isset($conn)) {
    $notifications = NotificationController::getNotifications($conn, $sessionUserId, $sessionRole, 8);
    $notifCount    = NotificationController::getUnreadCount($conn, $sessionUserId, $sessionRole);
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

            <li class="nav-item dropdown" id="notifDropdown">
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
                    style="min-width:340px; max-height:400px; overflow-y:auto;"
                >
                    <li class="dropdown-header">
                        <?php if (empty($notifications)): ?>
                            No new notifications
                        <?php else: ?>
                            <?= count($notifications) ?> notification<?= count($notifications) !== 1 ? 's' : '' ?>
                            <a href="/inventory_system/admin/activity_log.php">
                                <span class="badge rounded-pill bg-primary p-2 ms-2">View all</span>
                            </a>
                        <?php endif; ?>
                    </li>
                    <li><hr class="dropdown-divider"></li>

                    <?php if (empty($notifications)): ?>
                        <li class="text-center text-muted py-3 px-3">
                            <i class="bi bi-bell-slash fs-5 d-block mb-1"></i>
                            No recent activity
                        </li>
                    <?php else: ?>
                        <?php foreach ($notifications as $notif): ?>
                            <li class="notification-item">
                                <i class="bi <?= htmlspecialchars($notif['icon']) ?> <?= htmlspecialchars($notif['color']) ?>"></i>
                                <div>
                                    <h4><?= htmlspecialchars($notif['title']) ?></h4>
                                    <p><?= htmlspecialchars($notif['message']) ?></p>
                                    <p><?= NotificationController::timeAgo($notif['time']) ?></p>
                                </div>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <li class="dropdown-footer">
                        <a href="/inventory_system/admin/activity_log.php">Show all activity</a>
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
                        <a class="dropdown-item d-flex align-items-center" href="/inventory_system/logout.php">
                            <i class="bi bi-box-arrow-right"></i><span>Sign Out</span>
                        </a>
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

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str ?? '';
        return div.innerHTML;
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

            if (notifList) {
                let html = '';

                html += `
                    <li class="dropdown-header">
                        ${
                            data.notifications.length === 0
                                ? 'No new notifications'
                                : `${data.notifications.length} notification${data.notifications.length !== 1 ? 's' : ''} <a href="/inventory_system/admin/activity_log.php"><span class="badge rounded-pill bg-primary p-2 ms-2">View all</span></a>`
                        }
                    </li>
                    <li><hr class="dropdown-divider"></li>
                `;

                if (data.notifications.length === 0) {
                    html += `
                        <li class="text-center text-muted py-3 px-3">
                            <i class="bi bi-bell-slash fs-5 d-block mb-1"></i>
                            No recent activity
                        </li>
                    `;
                } else {
                    data.notifications.forEach(notif => {
                        html += `
                            <li class="notification-item">
                                <i class="bi ${escapeHtml(notif.icon)} ${escapeHtml(notif.color)}"></i>
                                <div>
                                    <h4>${escapeHtml(notif.title)}</h4>
                                    <p>${escapeHtml(notif.message)}</p>
                                    <p>${escapeHtml(notif.time_ago ?? notif.time)}</p>
                                </div>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                        `;
                    });
                }

                html += `
                    <li class="dropdown-footer">
                        <a href="/inventory_system/admin/activity_log.php">Show all activity</a>
                    </li>
                `;

                notifList.innerHTML = html;
            }
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

    if (notifDropdown) {
        notifDropdown.addEventListener('shown.bs.dropdown', () => {
            markNotificationsSeen();
        });
    }

    updateNotifications();
    connectWebSocket();
});
</script>