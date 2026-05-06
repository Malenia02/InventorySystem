document.addEventListener('DOMContentLoaded', () => {
    const modalElement = document.getElementById('saleDetailsModal');
    const modalBody = document.getElementById('saleDetailsBody');
    const modalTitle = document.getElementById('saleDetailsModalLabel');
    const actionModalElement = document.getElementById('saleActionModal');
    const actionForm = document.getElementById('saleActionForm');
    const toastStack = document.getElementById('saleToastStack');

    if (
        !modalElement
        || !modalBody
        || !modalTitle
        || !actionModalElement
        || !actionForm
        || typeof bootstrap === 'undefined'
    ) {
        return;
    }

    const modal = new bootstrap.Modal(modalElement);
    const actionModal = new bootstrap.Modal(actionModalElement);
    const money = new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    });
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const actionEls = {
        kicker: document.getElementById('saleActionKicker'),
        title: document.getElementById('saleActionModalLabel'),
        subtitle: document.getElementById('saleActionSubtitle'),
        icon: document.getElementById('saleActionIcon'),
        subject: document.getElementById('saleActionSubject'),
        meta: document.getElementById('saleActionMeta'),
        saleNo: document.getElementById('saleActionSaleNo'),
        quantityCard: document.getElementById('saleActionQuantityCard'),
        availableQty: document.getElementById('saleActionAvailableQty'),
        quantityWrap: document.getElementById('saleActionQuantityWrap'),
        quantityInput: document.getElementById('saleActionQuantity'),
        reasonInput: document.getElementById('saleActionReason'),
        error: document.getElementById('saleActionError'),
        submit: document.getElementById('saleActionSubmitBtn'),
        submitLabel: actionModalElement.querySelector('.sale-action-submit-label'),
    };

    const actionState = {
        type: null,
        saleId: null,
        saleItemId: null,
        saleNo: '',
        productName: '',
        remainingQty: 0,
        reopenSaleId: null,
        isSubmitting: false,
        completed: false,
        rowElement: null,
    };

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

    function showToast(kind, title, message) {
        if (!toastStack) {
            return;
        }

        const toast = document.createElement('div');
        toast.className = `toast sale-toast is-${kind}`;
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'assertive');
        toast.setAttribute('aria-atomic', 'true');
        toast.innerHTML = `
            <div class="toast-header">
                <strong class="me-auto">${escapeHtml(title)}</strong>
                <small>Now</small>
                <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body">${escapeHtml(message)}</div>
        `;
        toastStack.appendChild(toast);

        const instance = new bootstrap.Toast(toast, { delay: 4200 });
        toast.addEventListener('hidden.bs.toast', () => toast.remove());
        instance.show();
    }

    async function fetchSaleDetailsData(saleId) {
        const response = await fetch(`/inventory_system/http/ajax/sale_details.php?sale_id=${encodeURIComponent(saleId)}`, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const data = await response.json();
        if (!data.success) {
            throw new Error(data.error || 'Unable to load sale details.');
        }
        return data;
    }

    function setActionError(message) {
        if (!actionEls.error) {
            return;
        }

        if (!message) {
            actionEls.error.classList.add('d-none');
            actionEls.error.textContent = '';
            return;
        }

        actionEls.error.textContent = message;
        actionEls.error.classList.remove('d-none');
    }

    function resetActionState() {
        actionState.type = null;
        actionState.saleId = null;
        actionState.saleItemId = null;
        actionState.saleNo = '';
        actionState.productName = '';
        actionState.remainingQty = 0;
        actionState.rowElement = null;
        actionState.isSubmitting = false;
        actionState.completed = false;
        actionForm.reset();
        setActionError('');
        actionEls.submit.disabled = false;
        actionEls.submitLabel.textContent = 'Confirm';
    }

    function setSubmitState(isBusy, label) {
        actionState.isSubmitting = isBusy;
        actionEls.submit.disabled = isBusy;
        actionEls.submitLabel.textContent = label;
    }

    function configureActionModal(config) {
        resetActionState();

        actionState.type = config.type;
        actionState.saleId = config.saleId;
        actionState.saleItemId = config.saleItemId || null;
        actionState.saleNo = config.saleNo || 'Transaction';
        actionState.productName = config.productName || '';
        actionState.remainingQty = Number(config.remainingQty || 0);
        actionState.reopenSaleId = config.reopenSaleId || null;
        actionState.rowElement = config.rowElement || null;

        const isReturn = config.type === 'return';
        actionEls.kicker.textContent = isReturn ? 'Item return' : 'Transaction void';
        actionEls.title.textContent = isReturn ? 'Return sold item' : 'Void transaction';
        actionEls.subtitle.textContent = isReturn
            ? 'Restore only the selected quantity back to inventory.'
            : 'This will cancel the entire transaction and restore all sold stock.';
        actionEls.subject.textContent = isReturn ? (config.productName || 'Sale item') : 'Transaction reversal';
        actionEls.meta.textContent = isReturn
            ? 'Review the quantity and reason before saving the return.'
            : 'Confirm the reversal reason before removing this sale from totals.';
        actionEls.saleNo.textContent = actionState.saleNo;
        actionEls.icon.className = `sale-action-icon ${isReturn ? 'is-warning' : 'is-danger'}`;
        actionEls.icon.innerHTML = isReturn
            ? '<i class="bi bi-arrow-return-left"></i>'
            : '<i class="bi bi-x-octagon"></i>';

        actionEls.quantityWrap.classList.toggle('d-none', !isReturn);
        actionEls.quantityCard.classList.toggle('d-none', !isReturn);
        actionEls.availableQty.textContent = isReturn ? String(actionState.remainingQty) : '-';

        if (isReturn) {
            actionEls.quantityInput.min = '1';
            actionEls.quantityInput.max = String(actionState.remainingQty);
            actionEls.quantityInput.value = '1';
        } else {
            actionEls.quantityInput.value = '';
        }

        actionEls.submit.className = `btn sale-action-submit ${isReturn ? 'btn-warning' : 'btn-danger'}`;
        actionEls.submitLabel.textContent = isReturn ? 'Save return' : 'Void sale';
        actionModal.show();
    }

    async function openSaleDetails(saleId) {
        modalTitle.textContent = 'Sale Breakdown';
        modalBody.innerHTML = loadingMarkup();
        modal.show();

        try {
            const data = await fetchSaleDetailsData(saleId);
            modalTitle.textContent = data.sale?.transaction_no || 'Sale Breakdown';
            modalBody.innerHTML = renderDetails(data);
        } catch (error) {
            modalBody.innerHTML = errorMarkup(error.message);
        }
    }

    function buildReceiptHtml(data) {
        const sale = data.sale || {};
        const items = Array.isArray(data.items) ? data.items : [];
        const itemRows = items
            .filter((item) => Number(item.remaining_quantity ?? item.quantity ?? 0) > 0)
            .map((item) => `
                <tr>
                    <td class="item-name">${escapeHtml(item.product_name || '')}</td>
                    <td class="item-qty">${Number(item.remaining_quantity ?? item.quantity ?? 0)}</td>
                    <td class="item-price">${money.format(Number(item.unit_price || 0))}</td>
                    <td class="item-total">${money.format(Number(item.net_line_total || item.line_total || 0))}</td>
                </tr>
            `)
            .join('');

        const title = escapeHtml(sale.transaction_no || 'Receipt');
        return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>${title}</title>
<style>
*{box-sizing:border-box}body{background:#eef2f7;display:flex;justify-content:center;padding:30px 0;font-family:'Courier New',Courier,monospace}.receipt{background:#fff;width:320px;padding:16px 14px 20px;color:#000;box-shadow:0 2px 12px rgba(0,0,0,.12)}.r-store{text-align:center;font-size:15px;font-weight:bold;letter-spacing:.04em;text-transform:uppercase}.r-sub{text-align:center;font-size:10.5px;color:#555;margin:3px 0 10px}.r-meta{font-size:10.5px;margin-bottom:2px}.r-sep{border:none;border-top:1px dashed #000;margin:7px 0}table{width:100%;border-collapse:collapse}th,td{font-size:10px;padding:3px 2px}th{text-transform:uppercase;border-bottom:1px solid #000}.item-name{text-align:left;width:46%}.item-qty{text-align:center;width:10%}.item-price,.item-total{text-align:right}.totals td{font-size:11px;padding:2px 2px}.t-label{text-align:left}.t-value{text-align:right}.grand td{font-size:14px;font-weight:bold;padding-top:5px}.status{margin-top:8px;text-align:center;font-size:10px;color:#666;text-transform:uppercase}.footer{text-align:center;font-size:10.5px;margin-top:12px;color:#444}.foot-note{margin-top:4px}.tag{display:inline-block;padding:2px 6px;border:1px solid #000;border-radius:12px}
@media print{@page{size:auto;margin:8mm}body{background:none;padding:0}.receipt{box-shadow:none}}
</style>
</head>
<body>
<div class="receipt">
    <div class="r-store">StockWise Store</div>
    <div class="r-sub">Sales Report Reprint</div>
    <div class="r-meta">Transaction: ${title}</div>
    <div class="r-meta">Date: ${escapeHtml(formatDate(sale.sale_date))}</div>
    <div class="r-meta">Cashier: ${escapeHtml(sale.cashier_name || 'Unknown')}</div>
    <div class="r-meta">Payment: ${escapeHtml(String(sale.payment_method || 'N/A').toUpperCase())}</div>
    <div class="status"><span class="tag">${escapeHtml(String(sale.status || 'completed').replaceAll('_', ' '))}</span></div>
    <hr class="r-sep">
    <table>
        <thead>
            <tr><th class="item-name">Item</th><th class="item-qty">Qty</th><th class="item-price">Price</th><th class="item-total">Total</th></tr>
        </thead>
        <tbody>${itemRows || '<tr><td colspan="4" style="text-align:center;padding:8px 0">No receiptable items</td></tr>'}</tbody>
    </table>
    <hr class="r-sep">
    <table class="totals">
        <tr><td class="t-label">Discount</td><td class="t-value">${money.format(Number(sale.discount || 0))}</td></tr>
        <tr><td class="t-label">VAT</td><td class="t-value">${money.format(Number(sale.tax || 0))}</td></tr>
        <tr class="grand"><td class="t-label">Grand Total</td><td class="t-value">${money.format(Number(sale.total_amount || 0))}</td></tr>
    </table>
    <div class="footer">
        <div>Generated from sales history</div>
        <div class="foot-note">Thank you.</div>
    </div>
</div>
</body>
</html>`;
    }

    function openReceiptWindow(receiptHtml) {
        const receiptWindow = window.open('', '_blank', 'width=480,height=760,scrollbars=yes');
        if (!receiptWindow) {
            throw new Error('Pop-up blocked. Allow pop-ups to print the receipt.');
        }
        receiptWindow.document.write(receiptHtml);
        receiptWindow.document.close();
    }

    function renderDetails(data) {
        const sale = data.sale || {};
        const items = Array.isArray(data.items) ? data.items : [];
        const returns = Array.isArray(data.returns) ? data.returns : [];
        const isVoided = String(sale.status || '').toLowerCase() === 'voided';
        const canReturnItems = !!sale.can_return_items;
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
                <td class="text-end">${Number(item.net_pieces_sold || item.pieces_sold || 0).toLocaleString()}</td>
                <td class="text-end">${money.format(Number(item.unit_price || 0))}</td>
                <td class="text-end fw-bold">${money.format(Number(item.net_line_total || item.line_total || 0))}</td>
                <td class="text-end">
                    ${Number(item.returned_quantity || 0) > 0 ? `<div class="small text-warning mb-1">Returned: ${Number(item.returned_quantity || 0).toLocaleString()}</div>` : ''}
                    ${Number(item.remaining_quantity || 0) > 0 ? `<div class="small text-muted mb-1">Available: ${Number(item.remaining_quantity || 0).toLocaleString()}</div>` : '<div class="small text-muted mb-1">Fully returned</div>'}
                    ${canReturnItems && Number(item.remaining_quantity || 0) > 0 ? `
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-warning sale-item-return-btn"
                            data-sale-id="${escapeHtml(sale.sale_id)}"
                            data-sale-item-id="${escapeHtml(item.sale_item_id)}"
                            data-product-name="${escapeHtml(item.product_name)}"
                            data-transaction-no="${escapeHtml(sale.transaction_no || '')}"
                            data-remaining-qty="${escapeHtml(item.remaining_quantity)}"
                        >
                            <i class="bi bi-arrow-return-left me-1"></i>Return
                        </button>
                    ` : ''}
                </td>
            </tr>
        `).join('');

        const returnRows = returns.map((entry) => `
            <tr>
                <td>
                    <strong>${escapeHtml(entry.product_name)}</strong>
                    <div class="small text-muted">${escapeHtml(formatDate(entry.created_at))}</div>
                </td>
                <td class="text-end">${Number(entry.quantity || 0).toLocaleString()}</td>
                <td class="text-end">${money.format(Number(entry.unit_price || 0))}</td>
                <td class="text-end">${money.format(Number((entry.quantity || 0) * (entry.unit_price || 0) + (entry.tax_amount || 0)))}</td>
                <td>
                    <div class="small">${escapeHtml(entry.reason || 'No reason provided.')}</div>
                    <div class="small text-muted">By ${escapeHtml(entry.user_name || 'Admin')}</div>
                </td>
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
                            <th class="text-end">Returns</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${itemRows || '<tr><td colspan="7" class="text-center text-muted py-4">No sale items found.</td></tr>'}
                    </tbody>
                </table>
            </div>

            ${returns.length ? `
                <div class="table-responsive sale-detail-table-wrap mt-3">
                    <table class="table align-middle sale-detail-table mb-0">
                        <thead>
                            <tr>
                                <th>Return History</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Unit Price</th>
                                <th class="text-end">Effect</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>${returnRows}</tbody>
                    </table>
                </div>
            ` : ''}

            <div class="sale-detail-totals">
                <div><span>Discount</span><strong>${money.format(Number(sale.discount || 0))}</strong></div>
                <div><span>VAT</span><strong>${money.format(Number(sale.tax || 0))}</strong></div>
                <div class="is-grand"><span>Grand Total</span><strong>${money.format(Number(sale.total_amount || 0))}</strong></div>
            </div>
        `;
    }

    async function submitVoidAction() {
        const response = await fetch('/inventory_system/http/ajax/void_sale.php', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken,
            },
            body: JSON.stringify({
                sale_id: actionState.saleId,
                reason: actionEls.reasonInput.value.trim(),
                csrf_token: csrfToken,
            }),
        });
        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error || 'Unable to void sale.');
        }

        actionState.rowElement?.remove();
        window.refreshHeaderNotifications?.();
        actionState.completed = true;
        actionModal.hide();
        showToast('success', 'Sale voided', data.message || `${actionState.saleNo} was voided.`);
    }

    async function submitReturnAction() {
        const response = await fetch('/inventory_system/http/ajax/return_sale_item.php', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken,
            },
            body: JSON.stringify({
                sale_id: actionState.saleId,
                sale_item_id: actionState.saleItemId,
                quantity: Number.parseInt(actionEls.quantityInput.value, 10),
                reason: actionEls.reasonInput.value.trim(),
                csrf_token: csrfToken,
            }),
        });
        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error || 'Unable to process item return.');
        }

        window.refreshHeaderNotifications?.();
        actionState.completed = true;
        actionModal.hide();
        showToast('success', 'Item returned', data.message || `${actionState.productName} was returned.`);
        await openSaleDetails(actionState.saleId);
    }

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-sale-detail-id]');
        if (!trigger) return;

        event.preventDefault();
        openSaleDetails(trigger.dataset.saleDetailId);
    });

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('.sale-print-btn');
        if (!button) return;

        event.preventDefault();
        button.disabled = true;
        try {
            const data = await fetchSaleDetailsData(button.dataset.saleDetailId || '');
            openReceiptWindow(buildReceiptHtml(data));
            showToast('success', 'Receipt opened', 'The receipt reprint is ready to print.');
        } catch (error) {
            showToast('error', 'Print failed', error.message || 'Unable to open the receipt reprint.');
        } finally {
            button.disabled = false;
        }
    });

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-void-sale-id]');
        if (!button) return;

        event.preventDefault();
        configureActionModal({
            type: 'void',
            saleId: button.dataset.voidSaleId || '',
            saleNo: button.dataset.voidSaleNo || 'Transaction',
            rowElement: button.closest('tr'),
        });
    });

    document.addEventListener('click', (event) => {
        const button = event.target.closest('.sale-item-return-btn');
        if (!button) return;

        event.preventDefault();
        const saleId = button.dataset.saleId || '';
        modal.hide();
        configureActionModal({
            type: 'return',
            saleId,
            saleItemId: button.dataset.saleItemId || '',
            saleNo: button.dataset.transactionNo || 'Transaction',
            productName: button.dataset.productName || 'Sale item',
            remainingQty: button.dataset.remainingQty || '0',
            reopenSaleId: saleId,
        });
    });

    actionForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (actionState.isSubmitting) {
            return;
        }

        const reason = actionEls.reasonInput.value.trim();
        if (reason.length < 5) {
            setActionError('Please enter a clear reason with at least 5 characters.');
            return;
        }

        if (actionState.type === 'return') {
            const quantity = Number.parseInt(actionEls.quantityInput.value, 10);
            if (!Number.isFinite(quantity) || quantity <= 0 || quantity > actionState.remainingQty) {
                setActionError(`Enter a valid quantity from 1 to ${actionState.remainingQty}.`);
                return;
            }
        }

        setActionError('');
        setSubmitState(true, actionState.type === 'return' ? 'Saving...' : 'Voiding...');

        try {
            if (actionState.type === 'return') {
                await submitReturnAction();
            } else {
                await submitVoidAction();
            }
        } catch (error) {
            setSubmitState(false, actionState.type === 'return' ? 'Save return' : 'Void sale');
            setActionError(error.message || 'Unable to process this action right now.');
            showToast('error', 'Action failed', error.message || 'Unable to process this action right now.');
        }
    });

    actionModalElement.addEventListener('hidden.bs.modal', () => {
        const shouldReopenDetails = !!actionState.reopenSaleId && !actionState.completed;
        const reopenSaleId = actionState.reopenSaleId;
        resetActionState();

        if (shouldReopenDetails && reopenSaleId) {
            openSaleDetails(reopenSaleId);
        }
    });

    const saleIdFromUrl = new URLSearchParams(window.location.search).get('sale_id');
    if (saleIdFromUrl && /^\d+$/.test(saleIdFromUrl)) {
        openSaleDetails(saleIdFromUrl);
    }
});
