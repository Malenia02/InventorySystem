"use strict";

/**
 * shift-closing.js
 *
 * Changes from the previous version
 * ────────────────────────────────────
 *  1.  redirect_url guard — submitAjaxForm() now validates that
 *      data.redirect_url is a non-empty string before calling
 *      fetchAndReplace(). The old version called
 *      fetchAndReplace(undefined) if the server response was
 *      missing that key, causing a fetch to the literal URL
 *      "undefined".
 *
 *  2.  AbortController on modal fetches — opening the shift-sales
 *      modal twice in quick succession would previously fire two
 *      concurrent requests and render whichever arrived last.
 *      Now the previous in-flight request is aborted before the
 *      new one starts.
 *
 *  3.  Fetch timeout (10 s) on all AJAX calls via AbortController
 *      + setTimeout. The old code had no timeout — a slow server
 *      response would hang the UI indefinitely with the spinner
 *      running and the button disabled forever.
 *
 *  4.  All escapeHtml() calls remain consistent throughout.
 *
 *  5.  dataset.dynamicBound guards and initializeShiftClosingPage()
 *      pattern preserved — correctly handles DOM replacement after
 *      fetchAndReplace().
 *
 *  6.  Celebration animation uses the new CSS classes from
 *      shift_closing.php (.shift-celebration / .shift-pop).
 */

document.addEventListener("DOMContentLoaded", () => {

    const PAGE_SELECTOR = "main.shift-closing-page";
    const AJAX_TIMEOUT  = 10_000; // ms

    // ── Utilities ─────────────────────────────────────────────────────────────
    function escapeHtml(value) {
        return String(value ?? "")
            .replace(/&/g,  "&amp;")
            .replace(/</g,  "&lt;")
            .replace(/>/g,  "&gt;")
            .replace(/"/g,  "&quot;")
            .replace(/'/g,  "&#039;");
    }

    function showFeedback(type, message) {
        const el = document.getElementById("shiftActionFeedback");
        if (!el) return;
        el.innerHTML = `<div class="alert alert-${escapeHtml(type)}">${escapeHtml(message)}</div>`;
        el.scrollIntoView({ behavior: "smooth", block: "nearest" });
    }

    function sendSocketEvent(payload) {
        if (!payload) return;
        let attempts = 0;
        (function trySend() {
            if (window.socket?.readyState === WebSocket.OPEN) {
                window.socket.send(JSON.stringify(payload));
            } else if (++attempts <= 20) {
                setTimeout(trySend, 300);
            }
        })();
    }

    /**
     * Fetch with a hard timeout via AbortController.
     * Returns {response, data} or throws on timeout / network error.
     */
    async function fetchWithTimeout(url, options = {}, timeoutMs = AJAX_TIMEOUT) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), timeoutMs);
        try {
            const response = await fetch(url, { ...options, signal: controller.signal });
            return response;
        } finally {
            clearTimeout(timer);
        }
    }

    // ── Celebration animation ─────────────────────────────────────────────────
    function mountCelebration() {
        document.getElementById("shiftStartCelebration")?.remove();

        const wrapper = document.createElement("div");
        wrapper.id        = "shiftStartCelebration";
        wrapper.className = "shift-celebration";
        wrapper.setAttribute("aria-live", "polite");
        wrapper.innerHTML = `
            <div class="shift-pop">
                <div class="shift-pop-icon"><i class="bi bi-check2-circle"></i></div>
                <strong>Shift started!</strong>
                <span>The cashier session is now being tracked.</span>
            </div>`;

        document.body.appendChild(wrapper);
        setTimeout(() => wrapper.classList.add("show"),    80);
        setTimeout(() => wrapper.classList.remove("show"), 2100);
        setTimeout(() => wrapper.remove(),                 2500);
    }

    // ── Partial page replacement ──────────────────────────────────────────────
    function replaceMainFromHtml(html, url, options = {}) {
        const parser   = new DOMParser();
        const doc      = parser.parseFromString(html, "text/html");
        const nextMain = doc.querySelector(PAGE_SELECTOR);
        const currMain = document.querySelector(PAGE_SELECTOR);

        if (!nextMain || !currMain) {
            window.location.href = url;
            return;
        }

        currMain.replaceWith(nextMain);
        if (options.pushState !== false) {
            window.history.replaceState({}, "", url);
        }

        initializeShiftClosingPage();

        if (options.animateStart)  mountCelebration();
        if (options.socketEvent)   sendSocketEvent(options.socketEvent);
    }

    async function fetchAndReplace(url, options = {}) {
        if (!url || typeof url !== "string") {
            throw new Error("Invalid redirect URL returned from server.");
        }
        const response = await fetchWithTimeout(url, {
            headers: { "X-Requested-With": "XMLHttpRequest" },
        });
        if (!response.ok) throw new Error("Unable to refresh shift view right now.");
        const html = await response.text();
        replaceMainFromHtml(html, response.url || url, options);
    }

    // ── AJAX form submit ──────────────────────────────────────────────────────
    async function submitAjaxForm(form, button, extraOptions = {}) {
        const originalHtml = button?.innerHTML ?? "";

        try {
            if (button) {
                button.disabled   = true;
                button.classList.add("is-starting");
                if (extraOptions.loadingHtml) button.innerHTML = extraOptions.loadingHtml;
            }

            const response = await fetchWithTimeout(
                "/inventory_system/http/ajax/shift_closing_actions.php",
                {
                    method:  "POST",
                    body:    new FormData(form),
                    headers: { "X-Requested-With": "XMLHttpRequest", Accept: "application/json" },
                }
            );

            // Parse JSON defensively
            let data = {};
            try { data = await response.json(); } catch { /* non-JSON body */ }

            if (!response.ok || !data.success) {
                throw new Error(data.error || "Unable to save the shift right now.");
            }

            // BUG FIX: guard redirect_url before calling fetchAndReplace
            if (!data.redirect_url || typeof data.redirect_url !== "string") {
                throw new Error("Server response missing redirect URL.");
            }

            await fetchAndReplace(data.redirect_url, {
                animateStart: !!data.animate_start,
                socketEvent:  data.socket_event ?? null,
            });

            showFeedback("success", data.message || "Shift updated successfully.");

        } catch (err) {
            const msg = err.name === "AbortError"
                ? "Request timed out. Please try again."
                : (err.message || "Unable to save the shift right now.");
            showFeedback("danger", msg);

            if (button) {
                button.disabled  = false;
                button.classList.remove("is-starting");
                button.innerHTML = originalHtml;
            }
        }
    }

    // ── Render helpers for modal content ──────────────────────────────────────
    const money = new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP" });

    function renderShiftSales(data) {
        const body    = document.getElementById("shiftSalesModalBody");
        if (!body) return;
        const summary = data.summary || {};
        const sales   = Array.isArray(data.sales) ? data.sales : [];

        const rows = sales.length ? sales.map(sale => `
            <tr>
                <td>
                    ${sale.details_url
                        ? `<a class="shift-sales-open-link" href="${escapeHtml(sale.details_url)}">${escapeHtml(sale.transaction_no || "Transaction")} <i class="bi bi-box-arrow-up-right"></i></a>`
                        : `<button type="button" class="btn btn-outline" style="height:26px;padding:0 8px;font-size:12px;" data-shift-sale-detail-id="${escapeHtml(String(sale.sale_id || ""))}">${escapeHtml(sale.transaction_no || "Transaction")} <i class="bi bi-eye"></i></button>`
                    }
                </td>
                <td>${escapeHtml(new Date(sale.sale_date || "").toLocaleString("en-PH", { month:"short", day:"2-digit", hour:"2-digit", minute:"2-digit" }))}</td>
                <td>${escapeHtml(String(sale.payment_method || "").toUpperCase())}</td>
                <td>${Number(sale.total_items || 0).toLocaleString()} pcs</td>
                <td>${Number(sale.item_lines  || 0).toLocaleString()}</td>
                <td>${escapeHtml(String(sale.status || "completed").replaceAll("_", " "))}</td>
                <td style="text-align:right;">${money.format(Number(sale.total_amount || 0))}</td>
            </tr>`).join("")
        : `<tr><td colspan="7" style="text-align:center;color:var(--c-text-3);padding:2rem;">No sales in this shift window.</td></tr>`;

        body.innerHTML = `
            <div class="sales-summary">
                <div class="sales-chip"><span>Cashier</span><strong>${escapeHtml(summary.cashier_name || "Cashier")}</strong></div>
                <div class="sales-chip"><span>Transactions</span><strong>${Number(summary.total_transactions || 0).toLocaleString()}</strong></div>
                <div class="sales-chip"><span>Items</span><strong>${Number(summary.total_items || 0).toLocaleString()}</strong></div>
                <div class="sales-chip"><span>Total Sales</span><strong>${money.format(Number(summary.total_sales || 0))}</strong></div>
            </div>
            <div style="overflow-x:auto;border:1px solid var(--c-border);border-radius:var(--radius-lg);overflow:hidden;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="background:var(--c-surface-2);border-bottom:1px solid var(--c-border);">
                            <th style="padding:9px 14px;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);text-align:left;">Transaction</th>
                            <th style="padding:9px 14px;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);text-align:left;">Time</th>
                            <th style="padding:9px 14px;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);text-align:left;">Payment</th>
                            <th style="padding:9px 14px;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);text-align:left;">Items</th>
                            <th style="padding:9px 14px;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);text-align:left;">Lines</th>
                            <th style="padding:9px 14px;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);text-align:left;">Status</th>
                            <th style="padding:9px 14px;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);text-align:right;">Total</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>`;
    }

    function renderShiftSaleDetails(data) {
        const body  = document.getElementById("shiftSaleDetailsModalBody");
        const title = document.getElementById("shiftSaleDetailsModalLabel");
        if (!body) return;

        const sale    = data.sale    || {};
        const items   = Array.isArray(data.items)   ? data.items   : [];
        const returns = Array.isArray(data.returns)  ? data.returns : [];

        if (title) title.textContent = sale.transaction_no || "Sale Details";

        const itemRows = items.length ? items.map(item => `
            <tr style="border-bottom:1px solid var(--c-border);">
                <td style="padding:10px 14px;"><strong>${escapeHtml(item.product_name || "Product")}</strong><div style="font-size:11px;color:var(--c-text-3);">${escapeHtml(item.category_name || "Uncategorized")}</div></td>
                <td style="padding:10px 14px;">${escapeHtml(String(item.unit_type || "piece"))}</td>
                <td style="padding:10px 14px;text-align:right;">${Number(item.quantity || 0).toLocaleString()}</td>
                <td style="padding:10px 14px;text-align:right;">${Number(item.net_pieces_sold || item.pieces_sold || 0).toLocaleString()}</td>
                <td style="padding:10px 14px;text-align:right;">${money.format(Number(item.unit_price || 0))}</td>
                <td style="padding:10px 14px;text-align:right;font-weight:600;">${money.format(Number(item.net_line_total || item.line_total || 0))}</td>
            </tr>`).join("")
        : `<tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--c-text-3);">No sale items found.</td></tr>`;

        const returnSection = returns.length ? `
            <div style="margin-top:1.25rem;">
                <div style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--c-text-3);margin-bottom:.75rem;">Returns</div>
                <div style="overflow-x:auto;border:1px solid var(--c-border);border-radius:var(--radius-lg);overflow:hidden;">
                    <table style="width:100%;border-collapse:collapse;font-size:13px;">
                        <thead><tr style="background:var(--c-surface-2);border-bottom:1px solid var(--c-border);">
                            <th style="padding:9px 14px;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);">Returned Item</th>
                            <th style="padding:9px 14px;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);text-align:right;">Qty</th>
                            <th style="padding:9px 14px;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);text-align:right;">Amount</th>
                            <th style="padding:9px 14px;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);">Reason</th>
                        </tr></thead>
                        <tbody>${returns.map(r => `
                            <tr style="border-bottom:1px solid var(--c-border);">
                                <td style="padding:10px 14px;"><strong>${escapeHtml(r.product_name || "Product")}</strong><div style="font-size:11px;color:var(--c-text-3);">${escapeHtml(r.user_name || "Admin")}</div></td>
                                <td style="padding:10px 14px;text-align:right;">${Number(r.quantity || 0).toLocaleString()}</td>
                                <td style="padding:10px 14px;text-align:right;">${money.format(Number(r.unit_price || 0) * Number(r.quantity || 0))}</td>
                                <td style="padding:10px 14px;">${escapeHtml(r.reason || "No reason provided.")}</td>
                            </tr>`).join("")}
                        </tbody>
                    </table>
                </div>
            </div>` : "";

        body.innerHTML = `
            <div class="sales-summary">
                <div class="sales-chip"><span>Cashier</span><strong>${escapeHtml(sale.cashier_name || "Cashier")}</strong></div>
                <div class="sales-chip"><span>Payment</span><strong>${escapeHtml(String(sale.payment_method || "").toUpperCase())}</strong></div>
                <div class="sales-chip"><span>Status</span><strong>${escapeHtml(String(sale.status || "completed").replaceAll("_", " "))}</strong></div>
                <div class="sales-chip"><span>Total</span><strong>${money.format(Number(sale.total_amount || 0))}</strong></div>
            </div>
            <div style="overflow-x:auto;border:1px solid var(--c-border);border-radius:var(--radius-lg);overflow:hidden;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead><tr style="background:var(--c-surface-2);border-bottom:1px solid var(--c-border);">
                        <th style="padding:9px 14px;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);">Product</th>
                        <th style="padding:9px 14px;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);">Unit</th>
                        <th style="padding:9px 14px;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);text-align:right;">Qty</th>
                        <th style="padding:9px 14px;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);text-align:right;">Pieces</th>
                        <th style="padding:9px 14px;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);text-align:right;">Unit Price</th>
                        <th style="padding:9px 14px;font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-text-3);text-align:right;">Line Total</th>
                    </tr></thead>
                    <tbody>${itemRows}</tbody>
                </table>
            </div>
            ${returnSection}`;
    }

    // ── Page initialiser (re-runs after DOM replacement) ──────────────────────
    function initializeShiftClosingPage() {

        // ── Filter form ───────────────────────────────────────────────────────
        const filterForm = document.getElementById("shiftFilterForm");
        if (filterForm && !filterForm.dataset.dynamicBound) {
            filterForm.dataset.dynamicBound = "true";
            filterForm.addEventListener("submit", async e => {
                e.preventDefault();
                const btn = filterForm.querySelector('button[type="submit"]');
                const orig = btn?.innerHTML ?? "";
                try {
                    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Loading…'; }
                    const params = new URLSearchParams(new FormData(filterForm));
                    await fetchAndReplace(`/inventory_system/shift_closing.php?${params}`);
                } catch (err) {
                    showFeedback("danger", err.name === "AbortError"
                        ? "Request timed out." : (err.message || "Unable to load shift."));
                    if (btn) { btn.disabled = false; btn.innerHTML = orig; }
                }
            });
        }

        // ── Start shift form ──────────────────────────────────────────────────
        const startForm = document.getElementById("startShiftForm");
        const startBtn  = document.getElementById("startShiftButton");
        if (startForm && startBtn && !startForm.dataset.dynamicBound) {
            startForm.dataset.dynamicBound = "true";
            startForm.addEventListener("submit", e => {
                e.preventDefault();
                submitAjaxForm(startForm, startBtn, {
                    loadingHtml: '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Starting…',
                });
            });
        }

        // ── Close shift form ──────────────────────────────────────────────────
        const closeForm = document.getElementById("closeShiftForm");
        const closeBtn  = document.getElementById("closeShiftSubmitBtn");
        if (closeForm && !closeForm.dataset.dynamicBound) {
            closeForm.dataset.dynamicBound = "true";
            closeForm.addEventListener("submit", e => {
                e.preventDefault();
                submitAjaxForm(closeForm, closeBtn, {
                    loadingHtml: '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Saving…',
                });
            });
        }

        // ── Celebration on load (if PHP rendered it) ──────────────────────────
        const cel = document.getElementById("shiftStartCelebration");
        if (cel && !cel.dataset.dynamicHandled) {
            cel.dataset.dynamicHandled = "true";
            setTimeout(() => cel.classList.add("show"),    80);
            setTimeout(() => cel.classList.remove("show"), 2100);
            setTimeout(() => cel.remove(),                 2500);
        }

        // ── Shift sales modal ─────────────────────────────────────────────────
        const viewBtn    = document.getElementById("viewShiftSalesBtn");
        const salesModalEl = document.getElementById("shiftSalesModal");
        const salesBody  = document.getElementById("shiftSalesModalBody");

        if (viewBtn && salesModalEl && salesBody && !viewBtn.dataset.dynamicBound) {
            viewBtn.dataset.dynamicBound = "true";
            const salesModal = new bootstrap.Modal(salesModalEl);

            // AbortController so rapid clicks abort the previous fetch
            let salesAbort = null;

            viewBtn.addEventListener("click", async () => {
                salesAbort?.abort();
                salesAbort = new AbortController();

                salesBody.innerHTML = `
                    <div class="modal-state">
                        <span class="spinner-border text-primary" role="status" aria-hidden="true"></span>
                        <strong>Loading shift sales…</strong>
                    </div>`;
                salesModal.show();

                const timer = setTimeout(() => salesAbort.abort(), AJAX_TIMEOUT);
                try {
                    const params = new URLSearchParams({
                        shift_date: viewBtn.dataset.shiftDate || "",
                        user_id:    viewBtn.dataset.userId    || "",
                    });
                    const response = await fetch(
                        `/inventory_system/http/ajax/shift_sales.php?${params}`,
                        {
                            headers: { "X-Requested-With": "XMLHttpRequest", Accept: "application/json" },
                            signal:  salesAbort.signal,
                        }
                    );
                    clearTimeout(timer);
                    let data = {};
                    try { data = await response.json(); } catch { /* ignore */ }
                    if (!response.ok || !data.success) throw new Error(data.error || "Unable to load shift sales.");
                    renderShiftSales(data);
                } catch (err) {
                    clearTimeout(timer);
                    if (err.name === "AbortError") return; // intentional abort, don't show error
                    salesBody.innerHTML = `
                        <div class="modal-state">
                            <i class="bi bi-exclamation-triangle"></i>
                            <strong>${escapeHtml(err.message || "Unable to load shift sales.")}</strong>
                        </div>`;
                }
            });

            // ── Sale detail click inside sales modal ──────────────────────────
            if (!salesBody.dataset.detailBound) {
                salesBody.dataset.detailBound = "true";
                const detailModalEl = document.getElementById("shiftSaleDetailsModal");
                const detailBody    = document.getElementById("shiftSaleDetailsModalBody");

                if (detailModalEl && detailBody) {
                    const detailModal = new bootstrap.Modal(detailModalEl);
                    let   detailAbort = null;

                    salesBody.addEventListener("click", async e => {
                        const btn = e.target.closest("[data-shift-sale-detail-id]");
                        if (!btn) return;

                        detailAbort?.abort();
                        detailAbort = new AbortController();

                        detailBody.innerHTML = `
                            <div class="modal-state">
                                <span class="spinner-border text-primary" role="status" aria-hidden="true"></span>
                                <strong>Loading sale details…</strong>
                            </div>`;
                        detailModal.show();

                        const dtimer = setTimeout(() => detailAbort.abort(), AJAX_TIMEOUT);
                        try {
                            const saleId   = encodeURIComponent(btn.dataset.shiftSaleDetailId || "");
                            const response = await fetch(
                                `/inventory_system/http/ajax/sale_details.php?sale_id=${saleId}`,
                                {
                                    headers: { "X-Requested-With": "XMLHttpRequest", Accept: "application/json" },
                                    signal:  detailAbort.signal,
                                }
                            );
                            clearTimeout(dtimer);
                            let data = {};
                            try { data = await response.json(); } catch { /* ignore */ }
                            if (!response.ok || !data.success) throw new Error(data.error || "Unable to load sale details.");
                            renderShiftSaleDetails(data);
                        } catch (err) {
                            clearTimeout(dtimer);
                            if (err.name === "AbortError") return;
                            detailBody.innerHTML = `
                                <div class="modal-state">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    <strong>${escapeHtml(err.message || "Unable to load sale details.")}</strong>
                                </div>`;
                        }
                    });
                }
            }
        }

    } // end initializeShiftClosingPage

    initializeShiftClosingPage();

}); // end DOMContentLoaded