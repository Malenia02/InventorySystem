document.addEventListener('DOMContentLoaded', () => {
    const page = document.querySelector('.notification-center-page');
    if (!page) return;

    const form = document.getElementById('notificationFilterForm');
    const categoryInput = document.getElementById('notificationCategoryInput');
    const categoryList = document.getElementById('notificationCategoryList');
    const feedContent = document.getElementById('notificationFeedContent');
    const feedCount = document.getElementById('notificationFeedCount');
    const feedPill = document.getElementById('notificationFeedPill');
    const resetFilters = document.getElementById('notificationResetFilters');
    const statsGrid = document.getElementById('notificationStatsGrid');
    const markReadForm = document.querySelector('.notification-mark-read-form');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const numberFormat = new Intl.NumberFormat('en-US');

    if (!form || !categoryInput || !feedContent) return;

    const categoryMeta = {
        sales: { label: 'Sales', icon: 'bi-receipt-cutoff', className: 'is-sales' },
        shift: { label: 'Shift', icon: 'bi-journal-check', className: 'is-shift' },
        inventory: { label: 'Inventory', icon: 'bi-box-seam', className: 'is-inventory' },
        security: { label: 'Security', icon: 'bi-shield-lock', className: 'is-security' },
        system: { label: 'System', icon: 'bi-bell', className: 'is-system' },
    };

    let debounceTimer = null;
    let activeRequest = null;

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function safeIconClass(value) {
        const icon = String(value || '').replace(/[^a-zA-Z0-9_\-\s]/g, '').trim();
        return icon || 'bi-bell';
    }

    function formatDate(value) {
        const date = new Date(value || '');
        if (Number.isNaN(date.getTime())) {
            return 'Unknown time';
        }

        return date.toLocaleString('en-PH', {
            month: 'short',
            day: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    function buildParams() {
        const formData = new FormData(form);
        const params = new URLSearchParams();

        for (const [key, rawValue] of formData.entries()) {
            const value = String(rawValue || '').trim();
            if (!value || value === 'all' || value === '0') continue;
            params.set(key, value);
        }

        return params;
    }

    function updateUrl(params) {
        const query = params.toString();
        const nextUrl = `/inventory_system/notifications.php${query ? `?${query}` : ''}`;
        window.history.pushState({}, '', nextUrl);
    }

    function setFormFromParams(params) {
        const query = params instanceof URLSearchParams ? params : new URLSearchParams(params || '');

        form.querySelector('[name="q"]').value = query.get('q') || '';
        form.querySelector('[name="status"]').value = query.get('status') || 'all';
        form.querySelector('[name="period"]').value = query.get('period') || 'all';
        categoryInput.value = query.get('category') || 'all';

        const actorSelect = form.querySelector('[name="actor_id"]');
        if (actorSelect) {
            actorSelect.value = query.get('actor_id') || '0';
        }

        updateActiveCategory(categoryInput.value || 'all');
    }

    function updateActiveCategory(category) {
        categoryList?.querySelectorAll('[data-category]').forEach((link) => {
            link.classList.toggle('active', link.dataset.category === category);
        });
    }

    function updateStats(stats = {}) {
        document.querySelectorAll('[data-stat-value]').forEach((node) => {
            const key = node.dataset.statValue;
            node.textContent = numberFormat.format(Number(stats[key] || 0));
        });
    }

    function feedPillLabel(status) {
        if (status === 'unread') return 'Unread only';
        if (status === 'previous') return 'Previous';
        return 'Live feed';
    }

    function renderEmptyState() {
        return `
            <div class="notification-empty-state">
                <i class="bi bi-inboxes"></i>
                <strong>No notifications found</strong>
                <span>Try changing the search, status, or category filter.</span>
            </div>
        `;
    }

    function renderErrorState(message) {
        return `
            <div class="notification-empty-state notification-feed-error">
                <i class="bi bi-exclamation-triangle"></i>
                <strong>Unable to load notifications</strong>
                <span>${escapeHtml(message || 'Please try again in a moment.')}</span>
            </div>
        `;
    }

    function renderItem(item) {
        const category = item.category || 'system';
        const meta = categoryMeta[category] || categoryMeta.system;
        const isUnread = Boolean(item.is_unread);
        const link = String(item.link || '').trim();
        const tag = link ? 'a' : 'article';
        const href = link ? ` href="${escapeHtml(link)}"` : '';
        const icon = safeIconClass(item.icon || meta.icon);

        return `
            <${tag} class="notification-feed-item ${escapeHtml(meta.className)} ${isUnread ? 'is-unread' : ''}"${href}>
                <div class="notification-feed-icon">
                    <i class="bi ${escapeHtml(icon)}"></i>
                </div>
                <div class="notification-feed-copy">
                    <div class="notification-feed-top">
                        <span class="notification-type-chip">${escapeHtml(meta.label)}</span>
                        ${isUnread ? '<span class="notification-unread-dot">Unread</span>' : ''}
                        <time>${escapeHtml(item.time_ago || '')}</time>
                    </div>
                    <h3>${escapeHtml(item.title || 'Notification')}</h3>
                    <p>${escapeHtml(item.message || '')}</p>
                    <div class="notification-feed-meta">
                        <span><i class="bi bi-person"></i> ${escapeHtml(item.actor_name || 'System')}</span>
                        <span><i class="bi bi-clock"></i> ${escapeHtml(formatDate(item.time))}</span>
                    </div>
                </div>
            </${tag}>
        `;
    }

    function renderData(data) {
        const items = Array.isArray(data.items) ? data.items : [];
        const filters = data.filters || {};

        updateStats(data.stats || {});
        updateActiveCategory(filters.category || categoryInput.value || 'all');

        if (feedCount) {
            feedCount.textContent = `${numberFormat.format(items.length)} item${items.length === 1 ? '' : 's'} shown`;
        }

        if (feedPill) {
            feedPill.textContent = feedPillLabel(filters.status || form.querySelector('[name="status"]').value || 'all');
        }

        feedContent.innerHTML = items.length
            ? `<div class="notification-feed-list">${items.map(renderItem).join('')}</div>`
            : renderEmptyState();
    }

    async function refreshNotifications({ push = true } = {}) {
        const params = buildParams();

        if (activeRequest) {
            activeRequest.abort();
        }

        activeRequest = new AbortController();
        page.classList.add('is-loading');

        try {
            const response = await fetch(`/inventory_system/http/ajax/notification_center.php?${params.toString()}`, {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: activeRequest.signal,
            });

            const text = await response.text();
            let data = null;

            try {
                data = JSON.parse(text);
            } catch (error) {
                console.error('Invalid JSON from notification_center.php:', text);
                throw new Error('The server returned an invalid notification response.');
            }

            if (!data.success) {
                throw new Error(data.error || 'Failed to load notifications.');
            }

            renderData(data);
            if (push) {
                updateUrl(params);
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error('Notification Center refresh failed:', error);
                feedContent.innerHTML = renderErrorState(error.message);
            }
        } finally {
            page.classList.remove('is-loading');
            activeRequest = null;
        }
    }

    function debounceRefresh() {
        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(() => refreshNotifications(), 300);
    }

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        refreshNotifications();
    });

    form.querySelector('[name="q"]')?.addEventListener('input', debounceRefresh);
    form.querySelectorAll('select').forEach((select) => {
        select.addEventListener('change', () => refreshNotifications());
    });

    categoryList?.addEventListener('click', (event) => {
        const link = event.target.closest('[data-category]');
        if (!link) return;

        event.preventDefault();
        categoryInput.value = link.dataset.category || 'all';
        updateActiveCategory(categoryInput.value);
        refreshNotifications();
    });

    statsGrid?.addEventListener('click', (event) => {
        const link = event.target.closest('[data-stat-filter]');
        if (!link) return;

        event.preventDefault();
        const filter = link.dataset.statFilter;

        if (filter === 'total') {
            setFormFromParams(new URLSearchParams());
        } else if (filter === 'unread') {
            setFormFromParams(new URLSearchParams('status=unread'));
        } else {
            setFormFromParams(new URLSearchParams(`category=${encodeURIComponent(filter || 'all')}`));
        }

        refreshNotifications();
    });

    resetFilters?.addEventListener('click', (event) => {
        event.preventDefault();
        setFormFromParams(new URLSearchParams());
        refreshNotifications();
    });

    markReadForm?.addEventListener('submit', async (event) => {
        event.preventDefault();

        try {
            const body = new URLSearchParams();
            body.set('csrf_token', markReadForm.querySelector('[name="csrf_token"]')?.value || csrfToken);

            const response = await fetch('/inventory_system/http/ajax/mark_notifications_seen.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body,
            });

            const data = await response.json();
            if (!data.success) {
                throw new Error(data.error || 'Failed to mark notifications as read.');
            }

            await refreshNotifications({ push: false });

            if (typeof window.refreshHeaderNotifications === 'function') {
                window.refreshHeaderNotifications();
            }
        } catch (error) {
            console.error('Mark notifications read failed:', error);
        }
    });

    window.addEventListener('popstate', () => {
        setFormFromParams(new URLSearchParams(window.location.search));
        refreshNotifications({ push: false });
    });

    window.refreshNotificationCenter = () => refreshNotifications({ push: false });
});
