document.addEventListener("DOMContentLoaded", () => {
  const table = document.getElementById("cashierSalesHistoryTable");
  if (!table) return;

  const rows = Array.from(table.querySelectorAll("tbody tr"));
  const searchInput = document.getElementById("historySearch");
  const paymentInput = document.getElementById("historyPayment");
  const statusInput = document.getElementById("historyStatus");
  const dateFromInput = document.getElementById("historyDateFrom");
  const dateToInput = document.getElementById("historyDateTo");
  const cashierInput = document.getElementById("historyCashier");
  const countNode = document.getElementById("cashierHistoryCount");
  const revenueNode = document.getElementById("cashierHistoryRevenue");
  const metaNode = document.getElementById("cashierHistoryMeta");
  const emptyNode = document.getElementById("cashierSalesHistoryEmpty");
  const detailBody = document.getElementById("cashierSaleDetailsBody");
  const detailLabel = document.getElementById("cashierSaleDetailsLabel");
  const modalElement = document.getElementById("cashierSaleDetailsModal");
  const detailModal = modalElement ? new bootstrap.Modal(modalElement) : null;
  const historyData = window.CASHIER_HISTORY_DATA || {};
  const requestModalElement = document.getElementById("cashierSaleRequestModal");
  const requestModal = requestModalElement ? new bootstrap.Modal(requestModalElement) : null;
  const requestForm = document.getElementById("cashierSaleRequestForm");
  const requestTitle = document.getElementById("cashierSaleRequestTitle");
  const requestSaleId = document.getElementById("cashierRequestSaleId");
  const requestSaleItemId = document.getElementById("cashierRequestSaleItemId");
  const requestActionType = document.getElementById("cashierRequestActionType");
  const requestQuantityWrap = document.getElementById("cashierRequestQuantityWrap");
  const requestQuantity = document.getElementById("cashierRequestQuantity");
  const requestReason = document.getElementById("cashierRequestReason");
  const requestSubmit = document.getElementById("cashierSaleRequestSubmit");
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('input[name="csrf_token"]')?.value || "";
  const money = new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP" });

  const escapeHtml = (value) =>
    String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");

  const formatDate = (value) => {
    const date = new Date(value || "");
    return Number.isNaN(date.getTime()) ? "Unknown" : date.toLocaleString("en-PH", {
      month: "short",
      day: "2-digit",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    });
  };

  const unitLabel = (item) => {
    const unitType = String(item.unit_type || "piece").toLowerCase();
    if (unitType === "case") return "Case";
    if (unitType === "box") return "Box";
    return "Piece";
  };

  const matchesFilters = (row) => {
    const search = (searchInput?.value || "").trim().toLowerCase();
    const payment = paymentInput?.value || "all";
    const status = statusInput?.value || "all";
    const dateFrom = dateFromInput?.value || "";
    const dateTo = dateToInput?.value || "";
    const cashier = cashierInput?.value || "all";

    if (search && !(row.dataset.search || "").includes(search)) return false;
    if (payment !== "all" && (row.dataset.payment || "") !== payment) return false;
    if (status !== "all" && (row.dataset.status || "") !== status) return false;
    if (cashier !== "all" && (row.dataset.cashierId || "") !== cashier) return false;
    if (dateFrom && (row.dataset.date || "") < dateFrom) return false;
    if (dateTo && (row.dataset.date || "") > dateTo) return false;
    return true;
  };

  const applyFilters = () => {
    let visible = 0;
    let visibleRevenue = 0;
    rows.forEach((row) => {
      const show = matchesFilters(row);
      row.classList.toggle("d-none", !show);
      if (show) {
        visible += 1;
        visibleRevenue += Number(row.dataset.totalAmount || 0);
      }
    });

    if (countNode) countNode.textContent = visible.toLocaleString();
    if (revenueNode) revenueNode.textContent = `PHP ${visibleRevenue.toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    if (metaNode) metaNode.textContent = `Showing ${visible.toLocaleString()} of ${rows.length.toLocaleString()} loaded transactions.`;
    if (emptyNode) emptyNode.classList.toggle("d-none", visible !== 0);
  };

  const fetchSaleDetails = async (saleId) => {
    const response = await fetch(`/inventory_system/http/ajax/sale_details.php?sale_id=${encodeURIComponent(saleId)}`, {
      headers: {
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
      },
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.success) {
      throw new Error(data.error || "Unable to load sale details.");
    }
    return data;
  };

  const buildReceiptHtml = (data) => {
    const sale = data.sale || {};
    const items = Array.isArray(data.items) ? data.items : [];
    const itemRows = items
      .filter((item) => Number(item.remaining_quantity ?? item.quantity ?? 0) > 0)
      .map((item) => `
        <tr>
          <td>${escapeHtml(item.product_name || "")}</td>
          <td style="text-align:center">${Number(item.remaining_quantity ?? item.quantity ?? 0)}</td>
          <td style="text-align:right">${money.format(Number(item.unit_price || 0))}</td>
          <td style="text-align:right">${money.format(Number(item.net_line_total || item.line_total || 0))}</td>
        </tr>
      `).join("");

    return `<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>${escapeHtml(sale.transaction_no || "Receipt")}</title>
<style>*{box-sizing:border-box}body{font-family:'Courier New',monospace;padding:20px;background:#eef2f7}.receipt{width:320px;margin:0 auto;background:#fff;padding:16px;box-shadow:0 2px 12px rgba(0,0,0,.12)}table{width:100%;border-collapse:collapse}th,td{font-size:10px;padding:3px 2px}th{border-bottom:1px solid #000;text-transform:uppercase}.meta{font-size:10px;margin-bottom:3px}.grand{font-size:14px;font-weight:bold}.sep{border:none;border-top:1px dashed #000;margin:7px 0}@media print{@page{margin:8mm}body{background:none;padding:0}.receipt{box-shadow:none}}</style></head>
<body><div class="receipt">
<div style="text-align:center;font-weight:bold;font-size:15px;text-transform:uppercase">StockWise Store</div>
<div style="text-align:center;font-size:10px;color:#555;margin:3px 0 10px">Cashier History Reprint</div>
<div class="meta">Transaction: ${escapeHtml(sale.transaction_no || "Receipt")}</div>
<div class="meta">Date: ${escapeHtml(formatDate(sale.sale_date))}</div>
<div class="meta">Cashier: ${escapeHtml(sale.cashier_name || "Unknown")}</div>
<hr class="sep">
<table><thead><tr><th style="text-align:left">Item</th><th>Qty</th><th style="text-align:right">Price</th><th style="text-align:right">Total</th></tr></thead><tbody>${itemRows || '<tr><td colspan="4" style="text-align:center;padding:8px 0">No items</td></tr>'}</tbody></table>
<hr class="sep">
<table><tr><td>Discount</td><td style="text-align:right">${money.format(Number(sale.discount || 0))}</td></tr><tr><td>VAT</td><td style="text-align:right">${money.format(Number(sale.tax || 0))}</td></tr><tr><td class="grand">Grand Total</td><td class="grand" style="text-align:right">${money.format(Number(sale.total_amount || 0))}</td></tr></table>
</div></body></html>`;
  };

  const openReceiptWindow = (receiptHtml) => {
    const win = window.open("", "_blank", "width=480,height=760,scrollbars=yes");
    if (!win) throw new Error("Pop-up blocked. Allow pop-ups to print the receipt.");
    win.document.write(receiptHtml);
    win.document.close();
  };

  const renderDetails = (data) => {
    const sale = data.sale || {};
    const items = Array.isArray(data.items) ? data.items : [];
    const returns = Array.isArray(data.returns) ? data.returns : [];
    const statusLabel = String(sale.status || "completed").replace(/_/g, " ");

    const itemRows = items.map((item) => `
      <tr>
        <td><strong>${escapeHtml(item.product_name)}</strong><div class="small text-muted">${escapeHtml(item.category_name || "Uncategorized")}${item.sku ? ` | SKU ${escapeHtml(item.sku)}` : ""}</div></td>
        <td>${escapeHtml(unitLabel(item))}</td>
        <td class="text-end">${Number(item.quantity || 0).toLocaleString()}</td>
        <td class="text-end">${Number(item.net_pieces_sold || item.pieces_sold || 0).toLocaleString()}</td>
        <td class="text-end">${money.format(Number(item.unit_price || 0))}</td>
        <td class="text-end fw-bold">${money.format(Number(item.net_line_total || item.line_total || 0))}</td>
      </tr>
    `).join("");

    const returnRows = returns.map((entry) => `
      <tr>
        <td><strong>${escapeHtml(entry.product_name)}</strong><div class="small text-muted">${escapeHtml(formatDate(entry.created_at))}</div></td>
        <td class="text-end">${Number(entry.quantity || 0).toLocaleString()}</td>
        <td class="text-end">${money.format(Number(entry.unit_price || 0))}</td>
        <td>${escapeHtml(entry.reason || "No reason provided.")}</td>
      </tr>
    `).join("");
    const canRequest = !historyData.isAdmin && !["voided", "returned"].includes(String(sale.status || "").toLowerCase());
    const requestButtons = canRequest ? `
      <section class="ops-preview-section">
        <div class="ops-preview-section__head">
          <div>
            <span class="ops-eyebrow">Admin Approval</span>
            <h4 class="ops-preview-section__title">Request void or return</h4>
          </div>
          <button type="button" class="btn btn-sm btn-outline-danger cashier-request-action" data-action-type="void_sale" data-sale-id="${escapeHtml(sale.sale_id)}">Request void sale</button>
        </div>
        <div class="ops-review-note-grid">
          <div>
            <span class="ops-detail-label">Why request instead of direct void?</span>
            <p>Stock restoration is protected. The owner/admin reviews the reason before any inventory movement is applied.</p>
          </div>
          <div>
            <span class="ops-detail-label">For item returns</span>
            <p>Use the return buttons in the item table below. The admin approval will restore the returned quantity to stock.</p>
          </div>
        </div>
      </section>
    ` : "";

    return `
      <div class="ops-preview-stack">
        <section class="ops-preview-hero">
          <div>
            <span class="ops-eyebrow">Transaction Preview</span>
            <h3 class="ops-preview-title mb-1">${escapeHtml(sale.transaction_no || "Sale Breakdown")}</h3>
            <p class="ops-muted mb-0">Review item lines, payment details, and any return activity tied to this transaction.</p>
          </div>
          <span class="ops-status ${escapeHtml(String(sale.status || "completed").toLowerCase() === "voided" ? "is-danger" : String(sale.status || "").toLowerCase() === "partial_returned" ? "is-warning" : String(sale.status || "").toLowerCase() === "returned" ? "is-info" : "is-success")}">${escapeHtml(statusLabel.replace(/\b\w/g, (char) => char.toUpperCase()))}</span>
        </section>

        <div class="ops-modal-summary">
          <div class="ops-kpi"><span>Cashier</span><strong>${escapeHtml(sale.cashier_name || "Unknown")}</strong></div>
          <div class="ops-kpi"><span>Date</span><strong>${escapeHtml(formatDate(sale.sale_date))}</strong></div>
          <div class="ops-kpi"><span>Payment</span><strong>${escapeHtml(String(sale.payment_method || "N/A").toUpperCase())}</strong></div>
          <div class="ops-kpi"><span>Total</span><strong>${money.format(Number(sale.total_amount || 0))}</strong></div>
        </div>

        <div class="ops-detail-grid mb-3">
          <div><span class="ops-detail-label">Discount</span><p class="mb-0 fw-bold">${money.format(Number(sale.discount || 0))}</p></div>
          <div><span class="ops-detail-label">VAT</span><p class="mb-0 fw-bold">${money.format(Number(sale.tax || 0))}</p></div>
        </div>

        ${requestButtons}

        <section class="ops-preview-section">
          <div class="ops-preview-section__head">
            <div>
              <span class="ops-eyebrow">Sold Items</span>
              <h4 class="ops-preview-section__title">Item breakdown</h4>
            </div>
          </div>
          <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Product</th><th>Unit</th><th class="text-end">Qty</th><th class="text-end">Pieces</th><th class="text-end">Unit Price</th><th class="text-end">Line Total</th>${canRequest ? '<th class="text-end">Request</th>' : ''}</tr></thead><tbody>${items.map((item) => `
      <tr>
        <td><strong>${escapeHtml(item.product_name)}</strong><div class="small text-muted">${escapeHtml(item.category_name || "Uncategorized")}${item.sku ? ` | SKU ${escapeHtml(item.sku)}` : ""}</div></td>
        <td>${escapeHtml(unitLabel(item))}</td>
        <td class="text-end">${Number(item.quantity || 0).toLocaleString()}</td>
        <td class="text-end">${Number(item.net_pieces_sold || item.pieces_sold || 0).toLocaleString()}</td>
        <td class="text-end">${money.format(Number(item.unit_price || 0))}</td>
        <td class="text-end fw-bold">${money.format(Number(item.net_line_total || item.line_total || 0))}</td>
        ${canRequest ? `<td class="text-end"><button type="button" class="btn btn-sm btn-outline-primary cashier-request-action" data-action-type="return_item" data-sale-id="${escapeHtml(sale.sale_id)}" data-sale-item-id="${escapeHtml(item.sale_item_id)}" data-max-qty="${escapeHtml(item.remaining_quantity || 0)}">Return</button></td>` : ""}
      </tr>
    `).join("") || `<tr><td colspan="${canRequest ? 7 : 6}" class="text-center text-muted py-4">No sale items found.</td></tr>`}</tbody></table></div>
        </section>

        ${returns.length ? `<section class="ops-preview-section">
          <div class="ops-preview-section__head">
            <div>
              <span class="ops-eyebrow">Adjustments</span>
              <h4 class="ops-preview-section__title">Return history</h4>
            </div>
          </div>
          <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Return History</th><th class="text-end">Qty</th><th class="text-end">Unit Price</th><th>Reason</th></tr></thead><tbody>${returnRows}</tbody></table></div>
        </section>` : ""}
      </div>
    `;
  };

  const openRequestModal = (button) => {
    if (!requestModal) return;
    const actionType = button.dataset.actionType || "";
    const maxQty = Math.max(1, Number(button.dataset.maxQty || 1));
    if (requestTitle) requestTitle.textContent = actionType === "void_sale" ? "Request Sale Void" : "Request Item Return";
    if (requestSaleId) requestSaleId.value = button.dataset.saleId || "";
    if (requestSaleItemId) requestSaleItemId.value = button.dataset.saleItemId || "";
    if (requestActionType) requestActionType.value = actionType;
    if (requestQuantityWrap) requestQuantityWrap.classList.toggle("d-none", actionType !== "return_item");
    if (requestQuantity) {
      requestQuantity.max = String(maxQty);
      requestQuantity.value = "1";
    }
    if (requestReason) requestReason.value = "";
    requestModal.show();
  };

  requestForm?.addEventListener("submit", async (event) => {
    event.preventDefault();
    const originalText = requestSubmit?.textContent || "Send request";
    try {
      if (requestSubmit) {
        requestSubmit.disabled = true;
        requestSubmit.textContent = "Sending...";
      }
      const response = await fetch("/inventory_system/http/ajax/sale_action_requests.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-Requested-With": "XMLHttpRequest",
          Accept: "application/json",
        },
        body: JSON.stringify({
          csrf_token: csrfToken,
          action: "create",
          sale_id: requestSaleId?.value || "",
          sale_item_id: requestSaleItemId?.value || "",
          action_type: requestActionType?.value || "",
          quantity: requestQuantity?.value || 1,
          reason: requestReason?.value || "",
        }),
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.success) throw new Error(data.error || "Unable to send request.");
      requestModal?.hide();
      if (window.Swal) {
        window.Swal.fire({ icon: "success", title: "Request sent", text: data.message || "Request sent to admin." });
      }
    } catch (error) {
      if (window.Swal) {
        window.Swal.fire({ icon: "error", title: "Request failed", text: error.message || "Unable to send request." });
      }
    } finally {
      if (requestSubmit) {
        requestSubmit.disabled = false;
        requestSubmit.textContent = originalText;
      }
    }
  });

  const openSaleDetails = async (saleId) => {
    if (!detailBody || !detailModal) return;
    detailLabel.textContent = "Sale Breakdown";
    detailBody.innerHTML = `<div class="text-muted">Loading sale details...</div>`;
    detailModal.show();
    try {
      const data = await fetchSaleDetails(saleId);
      detailLabel.textContent = data.sale?.transaction_no || "Sale Breakdown";
      detailBody.innerHTML = renderDetails(data);
    } catch (error) {
      detailBody.innerHTML = `<div class="alert alert-danger mb-0">${escapeHtml(error.message || "Unable to load sale details.")}</div>`;
    }
  };

  document.addEventListener("click", async (event) => {
    const openBtn = event.target.closest(".cashier-sale-open");
    if (openBtn) {
      event.preventDefault();
      await openSaleDetails(openBtn.dataset.saleId || "");
    }

    const printBtn = event.target.closest(".cashier-sale-print");
    if (printBtn) {
      event.preventDefault();
      try {
        const data = await fetchSaleDetails(printBtn.dataset.saleId || "");
        openReceiptWindow(buildReceiptHtml(data));
      } catch (error) {
        if (window.Swal) {
          window.Swal.fire({
            icon: "error",
            title: "Print failed",
            text: error.message || "Unable to print this receipt.",
          });
        } else {
          window.alert(error.message || "Unable to print this receipt.");
        }
      }
    }

    const requestBtn = event.target.closest(".cashier-request-action");
    if (requestBtn) {
      event.preventDefault();
      openRequestModal(requestBtn);
    }
  });

  rows.forEach((row) => {
    row.addEventListener("click", async (event) => {
      if (event.target.closest("button, a")) {
        return;
      }
      await openSaleDetails(row.dataset.saleId || "");
    });
  });

  const deepLinkSaleId = new URLSearchParams(window.location.search).get("sale_id");
  if (deepLinkSaleId) {
    openSaleDetails(deepLinkSaleId);
  }

  [searchInput, paymentInput, statusInput, dateFromInput, dateToInput, cashierInput].forEach((element) => {
    element?.addEventListener("input", applyFilters);
    element?.addEventListener("change", applyFilters);
  });

  applyFilters();
});
