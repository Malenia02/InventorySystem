document.addEventListener("DOMContentLoaded", () => {
  const pageSelector = "main.shift-closing-page";

  const escapeHtml = (value) =>
    String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");

  const showFeedback = (type, message) => {
    const feedback = document.getElementById("shiftActionFeedback");
    if (!feedback) return;
    feedback.innerHTML = `<div class="alert alert-${type} mt-3">${escapeHtml(message)}</div>`;
  };

  const sendSocketEvent = (socketPayload) => {
    if (!socketPayload) return;

    let attempts = 0;
    const trySend = () => {
      if (window.socket && window.socket.readyState === WebSocket.OPEN) {
        window.socket.send(JSON.stringify(socketPayload));
        return;
      }

      attempts += 1;
      if (attempts <= 20) {
        window.setTimeout(trySend, 300);
      }
    };

    trySend();
  };

  const mountCelebration = () => {
    const existing = document.getElementById("shiftStartCelebration");
    if (existing) {
      existing.remove();
    }

    const wrapper = document.createElement("div");
    wrapper.className = "shift-start-celebration";
    wrapper.id = "shiftStartCelebration";
    wrapper.setAttribute("aria-live", "polite");
    wrapper.innerHTML = `
      <div class="shift-start-pop">
        <i class="bi bi-check2-circle"></i>
        <strong>Shift started</strong>
        <span>The cashier session is now being tracked.</span>
      </div>
    `;

    document.body.appendChild(wrapper);
    window.setTimeout(() => wrapper.classList.add("show"), 80);
    window.setTimeout(() => wrapper.classList.remove("show"), 2100);
    window.setTimeout(() => wrapper.remove(), 2500);
  };

  const replaceMainFromHtml = (html, url, options = {}) => {
    const parser = new DOMParser();
    const doc = parser.parseFromString(html, "text/html");
    const nextMain = doc.querySelector(pageSelector);
    const currentMain = document.querySelector(pageSelector);

    if (!nextMain || !currentMain) {
      window.location.href = url;
      return;
    }

    currentMain.replaceWith(nextMain);
    if (options.pushState !== false) {
      window.history.replaceState({}, "", url);
    }

    initializeShiftClosingPage();

    if (options.animateStart) {
      mountCelebration();
    }

    if (options.socketEvent) {
      sendSocketEvent(options.socketEvent);
    }
  };

  const fetchAndReplace = async (url, options = {}) => {
    const response = await fetch(url, {
      headers: {
        "X-Requested-With": "XMLHttpRequest",
      },
    });

    if (!response.ok) {
      throw new Error("Unable to refresh shift view right now.");
    }

    const html = await response.text();
    replaceMainFromHtml(html, response.url || url, options);
  };

  const renderShiftSales = (data) => {
    const summary = data.summary || {};
    const sales = Array.isArray(data.sales) ? data.sales : [];
    const money = new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP" });
    const body = document.getElementById("shiftSalesModalBody");
    if (!body) return;

    const rows = sales.length
      ? sales
          .map(
            (sale) => `
              <tr>
                <td>
                  ${
                    sale.details_url
                      ? `<a class="shift-sales-open-link" href="${escapeHtml(sale.details_url)}">
                          ${escapeHtml(sale.transaction_no || "Transaction")}
                          <i class="bi bi-box-arrow-up-right"></i>
                        </a>`
                      : `<button type="button" class="shift-sales-open-btn" data-shift-sale-detail-id="${escapeHtml(sale.sale_id)}">
                          ${escapeHtml(sale.transaction_no || "Transaction")}
                          <i class="bi bi-eye"></i>
                        </button>`
                  }
                </td>
                <td>${escapeHtml(new Date(sale.sale_date || "").toLocaleString("en-PH", { month: "short", day: "2-digit", hour: "2-digit", minute: "2-digit" }))}</td>
                <td>${escapeHtml(String(sale.payment_method || "").toUpperCase())}</td>
                <td>${Number(sale.total_items || 0).toLocaleString()} pcs</td>
                <td>${Number(sale.item_lines || 0).toLocaleString()}</td>
                <td>${escapeHtml(String(sale.status || "completed").replaceAll("_", " "))}</td>
                <td class="text-end">${money.format(Number(sale.total_amount || 0))}</td>
              </tr>
            `
          )
          .join("")
      : `<tr><td colspan="7" class="text-center text-muted py-4">No sales recorded for this shift window.</td></tr>`;

    body.innerHTML = `
      <div class="shift-sales-summary">
        <div class="shift-sales-summary-card">
          <span>Cashier</span>
          <strong>${escapeHtml(summary.cashier_name || "Cashier")}</strong>
        </div>
        <div class="shift-sales-summary-card">
          <span>Transactions</span>
          <strong>${Number(summary.total_transactions || 0).toLocaleString()}</strong>
        </div>
        <div class="shift-sales-summary-card">
          <span>Total Items</span>
          <strong>${Number(summary.total_items || 0).toLocaleString()}</strong>
        </div>
        <div class="shift-sales-summary-card">
          <span>Total Sales</span>
          <strong>${money.format(Number(summary.total_sales || 0))}</strong>
        </div>
      </div>
      <div class="shift-sales-table-wrap">
        <div class="table-responsive">
          <table class="table align-middle shift-sales-table">
            <thead>
              <tr>
                <th>Transaction</th>
                <th>Time</th>
                <th>Payment</th>
                <th>Items</th>
                <th>Lines</th>
                <th>Status</th>
                <th class="text-end">Total</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      </div>
    `;
  };

  const renderShiftSaleDetails = (data) => {
    const sale = data.sale || {};
    const items = Array.isArray(data.items) ? data.items : [];
    const returns = Array.isArray(data.returns) ? data.returns : [];
    const body = document.getElementById("shiftSaleDetailsModalBody");
    const title = document.getElementById("shiftSaleDetailsModalLabel");
    const money = new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP" });

    if (!body || !title) return;

    title.textContent = sale.transaction_no || "Sale Details";

    const itemRows = items.length
      ? items
          .map(
            (item) => `
              <tr>
                <td>
                  <strong>${escapeHtml(item.product_name || "Product")}</strong>
                  <div class="small text-muted">${escapeHtml(item.category_name || "Uncategorized")}</div>
                </td>
                <td>${escapeHtml(String(item.unit_type || "piece"))}</td>
                <td class="text-end">${Number(item.quantity || 0).toLocaleString()}</td>
                <td class="text-end">${Number(item.net_pieces_sold || item.pieces_sold || 0).toLocaleString()}</td>
                <td class="text-end">${money.format(Number(item.unit_price || 0))}</td>
                <td class="text-end">${money.format(Number(item.net_line_total || item.line_total || 0))}</td>
              </tr>
            `
          )
          .join("")
      : `<tr><td colspan="6" class="text-center text-muted py-4">No sale items found.</td></tr>`;

    const returnRows = returns.length
      ? `
        <div class="shift-sales-table-wrap mt-3">
          <div class="table-responsive">
            <table class="table align-middle shift-sales-table">
              <thead>
                <tr>
                  <th>Returned Item</th>
                  <th class="text-end">Qty</th>
                  <th class="text-end">Amount</th>
                  <th>Reason</th>
                </tr>
              </thead>
              <tbody>
                ${returns
                  .map(
                    (entry) => `
                      <tr>
                        <td>
                          <strong>${escapeHtml(entry.product_name || "Product")}</strong>
                          <div class="small text-muted">${escapeHtml(entry.user_name || "Admin")}</div>
                        </td>
                        <td class="text-end">${Number(entry.quantity || 0).toLocaleString()}</td>
                        <td class="text-end">${money.format(Number(entry.unit_price || 0) * Number(entry.quantity || 0))}</td>
                        <td>${escapeHtml(entry.reason || "No reason provided.")}</td>
                      </tr>
                    `
                  )
                  .join("")}
              </tbody>
            </table>
          </div>
        </div>
      `
      : "";

    body.innerHTML = `
      <div class="shift-sales-summary">
        <div class="shift-sales-summary-card">
          <span>Cashier</span>
          <strong>${escapeHtml(sale.cashier_name || "Cashier")}</strong>
        </div>
        <div class="shift-sales-summary-card">
          <span>Payment</span>
          <strong>${escapeHtml(String(sale.payment_method || "").toUpperCase())}</strong>
        </div>
        <div class="shift-sales-summary-card">
          <span>Status</span>
          <strong>${escapeHtml(String(sale.status || "completed").replaceAll("_", " "))}</strong>
        </div>
        <div class="shift-sales-summary-card">
          <span>Total</span>
          <strong>${money.format(Number(sale.total_amount || 0))}</strong>
        </div>
      </div>
      <div class="shift-sales-table-wrap">
        <div class="table-responsive">
          <table class="table align-middle shift-sales-table">
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
            <tbody>${itemRows}</tbody>
          </table>
        </div>
      </div>
      ${returnRows}
    `;
  };

  const submitAjaxForm = async (form, button, extraOptions = {}) => {
    const originalText = button ? button.innerHTML : "";

    try {
      if (button) {
        button.disabled = true;
        button.classList.add("is-starting");
        if (extraOptions.loadingHtml) {
          button.innerHTML = extraOptions.loadingHtml;
        }
      }

      const payload = new FormData(form);
      const response = await fetch("/inventory_system/http/ajax/shift_closing_actions.php", {
        method: "POST",
        body: payload,
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          Accept: "application/json",
        },
      });

      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.success) {
        throw new Error(data.error || "Unable to save the shift right now.");
      }

      await fetchAndReplace(data.redirect_url, {
        animateStart: !!data.animate_start,
        socketEvent: data.socket_event || null,
      });
      showFeedback("success", data.message || "Shift updated successfully.");
    } catch (error) {
      showFeedback("danger", error.message || "Unable to save the shift right now.");
      if (button) {
        button.disabled = false;
        button.classList.remove("is-starting");
        button.innerHTML = originalText;
      }
    }
  };

  function initializeShiftClosingPage() {
    const filterForm = document.getElementById("shiftFilterForm");
    const startForm = document.getElementById("startShiftForm");
    const closeForm = document.getElementById("closeShiftForm");
    const startButton = document.getElementById("startShiftButton");
    const celebration = document.getElementById("shiftStartCelebration");
    const viewShiftSalesBtn = document.getElementById("viewShiftSalesBtn");
    const shiftSalesModalElement = document.getElementById("shiftSalesModal");
    const shiftSalesModalBody = document.getElementById("shiftSalesModalBody");
    const shiftSaleDetailsModalElement = document.getElementById("shiftSaleDetailsModal");
    const shiftSaleDetailsModalBody = document.getElementById("shiftSaleDetailsModalBody");

    if (filterForm && !filterForm.dataset.dynamicBound) {
      filterForm.dataset.dynamicBound = "true";
      filterForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        const submitButton = filterForm.querySelector('button[type="submit"]');
        const originalText = submitButton ? submitButton.innerHTML : "";

        try {
          if (submitButton) {
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Loading shift...';
          }

          const params = new URLSearchParams(new FormData(filterForm));
          await fetchAndReplace(`/inventory_system/shift_closing.php?${params.toString()}`);
        } catch (error) {
          showFeedback("danger", error.message || "Unable to load shift details right now.");
          if (submitButton) {
            submitButton.disabled = false;
            submitButton.innerHTML = originalText;
          }
        }
      });
    }

    if (startForm && startButton && !startForm.dataset.dynamicBound) {
      startForm.dataset.dynamicBound = "true";
      startForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        await submitAjaxForm(startForm, startButton, {
          loadingHtml: '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Starting shift...',
        });
      });
    }

    if (closeForm && !closeForm.dataset.dynamicBound) {
      closeForm.dataset.dynamicBound = "true";
      closeForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        const submitButton = closeForm.querySelector('button[type="submit"]');
        await submitAjaxForm(closeForm, submitButton, {
          loadingHtml: '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Saving shift...',
        });
      });
    }

    if (celebration && !celebration.dataset.dynamicHandled) {
      celebration.dataset.dynamicHandled = "true";
      window.setTimeout(() => celebration.classList.add("show"), 80);
      window.setTimeout(() => celebration.classList.remove("show"), 2100);
      window.setTimeout(() => celebration.remove(), 2500);
    }

    if (viewShiftSalesBtn && shiftSalesModalElement && shiftSalesModalBody && !viewShiftSalesBtn.dataset.dynamicBound) {
      viewShiftSalesBtn.dataset.dynamicBound = "true";
      const shiftSalesModal = new bootstrap.Modal(shiftSalesModalElement);

      viewShiftSalesBtn.addEventListener("click", async () => {
        shiftSalesModalBody.innerHTML = `
          <div class="sale-details-state">
            <span class="spinner-border text-primary" role="status" aria-hidden="true"></span>
            <strong>Loading shift sales...</strong>
          </div>
        `;
        shiftSalesModal.show();

        try {
          const params = new URLSearchParams({
            shift_date: viewShiftSalesBtn.dataset.shiftDate || "",
            user_id: viewShiftSalesBtn.dataset.userId || "",
          });
          const response = await fetch(`/inventory_system/http/ajax/shift_sales.php?${params.toString()}`, {
            headers: {
              "X-Requested-With": "XMLHttpRequest",
              Accept: "application/json",
            },
          });
          const data = await response.json();
          if (!response.ok || !data.success) {
            throw new Error(data.error || "Unable to load shift sales.");
          }
          renderShiftSales(data);
        } catch (error) {
          shiftSalesModalBody.innerHTML = `
            <div class="sale-details-state is-error">
              <i class="bi bi-exclamation-triangle"></i>
              <strong>${escapeHtml(error.message || "Unable to load shift sales.")}</strong>
            </div>
          `;
        }
      });
    }

    if (shiftSalesModalBody && shiftSaleDetailsModalElement && !shiftSalesModalBody.dataset.detailBound) {
      shiftSalesModalBody.dataset.detailBound = "true";
      const shiftSaleDetailsModal = new bootstrap.Modal(shiftSaleDetailsModalElement);

      shiftSalesModalBody.addEventListener("click", async (event) => {
        const button = event.target.closest("[data-shift-sale-detail-id]");
        if (!button) return;

        const saleId = button.dataset.shiftSaleDetailId || "";
        if (!saleId) return;

        shiftSaleDetailsModalBody.innerHTML = `
          <div class="sale-details-state">
            <span class="spinner-border text-primary" role="status" aria-hidden="true"></span>
            <strong>Loading sale details...</strong>
          </div>
        `;
        shiftSaleDetailsModal.show();

        try {
          const response = await fetch(`/inventory_system/http/ajax/sale_details.php?sale_id=${encodeURIComponent(saleId)}`, {
            headers: {
              "X-Requested-With": "XMLHttpRequest",
              Accept: "application/json",
            },
          });
          const data = await response.json();
          if (!response.ok || !data.success) {
            throw new Error(data.error || "Unable to load sale details.");
          }
          renderShiftSaleDetails(data);
        } catch (error) {
          shiftSaleDetailsModalBody.innerHTML = `
            <div class="sale-details-state is-error">
              <i class="bi bi-exclamation-triangle"></i>
              <strong>${escapeHtml(error.message || "Unable to load sale details.")}</strong>
            </div>
          `;
        }
      });
    }
  }

  initializeShiftClosingPage();
});
