document.addEventListener('DOMContentLoaded', () => {
    const modalElement = document.getElementById('saleDetailsModal');
    const modalBody = document.getElementById('saleDetailsBody');
    const modalTitle = document.getElementById('saleDetailsModalLabel');

    if (!modalElement || !modalBody || !modalTitle || typeof bootstrap === 'undefined') {
        return;
    }

    const modal = new bootstrap.Modal(modalElement);
    const money = new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    });
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatDate(value) {
        const date = new Date(value || '');
        if (Number.isNaN(date.getTime())) {
            return 'Unknown date';
        }

        return date.toLocaleString('en-PH', {
            month: 'short',
            day: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    function unitLabel(item) {
        const unit = String(item.unit_type || 'piece');
        const multiplier = Number(item.unit_multiplier || 1);

        if (unit === 'piece' || multiplier <= 1) {
            return unit;
        }

        return `${unit} (${multiplier} pcs)`;
    }

    function loadingMarkup() {
        return `
            <div class="sale-details-state">
                <span class="spinner-border text-primary" role="status" aria-hidden="true"></span>
                <strong>Loading sale breakdown...</strong>
            </div>
        `;
    }

    function errorMarkup(message) {
        return `
            <div class="sale-details-state is-error">
                <i class="bi bi-exclamation-triangle"></i>
                <strong>${escapeHtml(message || 'Unable to load sale breakdown.')}</strong>
            </div>
        `;
    }

    function renderDetails(data) {
        const sale = data.sale || {};
        const items = Array.isArray(data.items) ? data.items : [];
        const isVoided = String(sale.status || '').toLowerCase() === 'voided';
        const itemRows = items.map((item) => `
            <tr>
                <td>
                    <div class="sale-detail-product">
                        <strong>${escapeHtml(item.product_name)}</strong>
                        <span>${escapeHtml(item.category_name || 'Uncategorized')}${item.sku ? ` | SKU ${escapeHtml(item.sku)}` : ''}</span>
                    </div>
                </td>
                <td>${escapeHtml(unitLabel(item))}</td>
                <td class="text-end">${Number(item.quantity || 0).toLocaleString()}</td>
                <td class="text-end">${Number(item.pieces_sold || 0).toLocaleString()}</td>
                <td class="text-end">${money.format(Number(item.unit_price || 0))}</td>
                <td class="text-end fw-bold">${money.format(Number(item.line_total || 0))}</td>
            </tr>
        `).join('');

        return `
            <div class="sale-detail-summary">
                <div>
                    <span>Cashier</span>
                    <strong>${escapeHtml(sale.cashier_name || 'Unknown')}</strong>
                </div>
                <div>
                    <span>Date</span>
                    <strong>${escapeHtml(formatDate(sale.sale_date))}</strong>
                </div>
                <div>
                    <span>Payment</span>
                    <strong>${escapeHtml(String(sale.payment_method || 'N/A').toUpperCase())}</strong>
                </div>
                <div>
                    <span>Total</span>
                    <strong>${money.format(Number(sale.total_amount || 0))}</strong>
                </div>
            </div>

            ${isVoided ? `
                <div class="alert alert-warning d-flex gap-2 align-items-start mt-3 mb-0">
                    <i class="bi bi-arrow-counterclockwise"></i>
                    <div>
                        <strong>This sale was voided.</strong>
                        <div class="small">By ${escapeHtml(sale.voided_by || 'admin')} ${sale.voided_at ? `on ${escapeHtml(formatDate(sale.voided_at))}` : ''}</div>
                        <div class="small">${escapeHtml(sale.void_reason || 'No reason provided.')}</div>
                    </div>
                </div>
            ` : ''}

            <div class="table-responsive sale-detail-table-wrap">
                <table class="table align-middle sale-detail-table mb-0">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Unit</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Pieces</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Line Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${itemRows || '<tr><td colspan="6" class="text-center text-muted py-4">No sale items found.</td></tr>'}
                    </tbody>
                </table>
            </div>

            <div class="sale-detail-totals">
                <div><span>Discount</span><strong>${money.format(Number(sale.discount || 0))}</strong></div>
                <div><span>VAT</span><strong>${money.format(Number(sale.tax || 0))}</strong></div>
                <div class="is-grand"><span>Grand Total</span><strong>${money.format(Number(sale.total_amount || 0))}</strong></div>
            </div>
        `;
    }

    async function openSaleDetails(saleId) {
        modalTitle.textContent = 'Sale Breakdown';
        modalBody.innerHTML = loadingMarkup();
        modal.show();

        try {
            const response = await fetch(`/inventory_system/http/ajax/sale_details.php?sale_id=${encodeURIComponent(saleId)}`, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Unable to load sale breakdown.');
            }

            modalTitle.textContent = data.sale?.transaction_no || 'Sale Breakdown';
            modalBody.innerHTML = renderDetails(data);
        } catch (error) {
            modalBody.innerHTML = errorMarkup(error.message);
        }
    }

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-sale-detail-id]');
        if (!trigger) return;

        event.preventDefault();
        openSaleDetails(trigger.dataset.saleDetailId);
    });

    async function voidSale(button) {
        const saleId = button.dataset.voidSaleId || '';
        const saleNo = button.dataset.voidSaleNo || 'this sale';
        if (!saleId) return;

        const reason = window.prompt(`Void ${saleNo}?\n\nEnter the reason for voiding this sale:`);
        if (reason === null) return;

        const cleanReason = reason.trim();
        if (cleanReason.length < 5) {
            window.alert('Please enter a clearer reason with at least 5 characters.');
            return;
        }

        if (!window.confirm(`This will restore the sold stock and remove ${saleNo} from revenue reports. Continue?`)) {
            return;
        }

        button.disabled = true;
        const originalText = button.textContent;
        button.textContent = 'Voiding...';

        try {
            const response = await fetch('/inventory_system/http/ajax/void_sale.php', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({
                    sale_id: saleId,
                    reason: cleanReason,
                    csrf_token: csrfToken,
                }),
            });
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Unable to void sale.');
            }

            button.closest('tr')?.remove();
            window.refreshHeaderNotifications?.();
            window.alert(data.message || `${saleNo} was voided.`);
        } catch (error) {
            button.disabled = false;
            button.textContent = originalText;
            window.alert(error.message || 'Unable to void sale right now.');
        }
    }

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-void-sale-id]');
        if (!button) return;

        event.preventDefault();
        voidSale(button);
    });
});
