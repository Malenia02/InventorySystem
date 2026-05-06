document.addEventListener("DOMContentLoaded", () => {
  const table = document.getElementById("receivingHistoryTable");
  if (!table) return;

  const rows = Array.from(table.querySelectorAll("tbody tr"));
  const searchInput = document.getElementById("receivingSearch");
  const statusInput = document.getElementById("receivingStatus");
  const dateFromInput = document.getElementById("receivingDateFrom");
  const dateToInput = document.getElementById("receivingDateTo");
  const countNode = document.getElementById("receivingHistoryCount");
  const receivedCountNode = document.getElementById("receivingReceivedCount");
  const partialCountNode = document.getElementById("receivingPartialCount");
  const piecesCountNode = document.getElementById("receivingPiecesCount");
  const metaNode = document.getElementById("receivingHistoryMeta");
  const emptyNode = document.getElementById("receivingHistoryEmpty");
  const modalElement = document.getElementById("receivingHistoryModal");
  const modalBody = document.getElementById("receivingHistoryModalBody");
  const modalLabel = document.getElementById("receivingHistoryModalLabel");
  const modal = modalElement ? new bootstrap.Modal(modalElement) : null;
  const payload = window.PURCHASE_RECEIVING_HISTORY || {};
  const detailMap = payload.details || {};

  const escapeHtml = (value) =>
    String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");

  const formatDate = (value) => {
    const date = new Date(value || "");
    return Number.isNaN(date.getTime()) ? "-" : date.toLocaleString("en-PH", {
      month: "short",
      day: "2-digit",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    });
  };

  const applyFilters = () => {
    const search = (searchInput?.value || "").trim().toLowerCase();
    const status = statusInput?.value || "all";
    const dateFrom = dateFromInput?.value || "";
    const dateTo = dateToInput?.value || "";
    let visible = 0;
    let receivedCount = 0;
    let partialCount = 0;
    let receivedPieces = 0;

    rows.forEach((row) => {
      const matches =
        (!search || (row.dataset.search || "").includes(search)) &&
        (status === "all" || (row.dataset.status || "") === status) &&
        (!dateFrom || (row.dataset.date || "") >= dateFrom) &&
        (!dateTo || (row.dataset.date || "") <= dateTo);

      row.classList.toggle("d-none", !matches);
      if (matches) {
        visible += 1;
        receivedPieces += Number(row.dataset.receivedTotal || 0);
        if ((row.dataset.status || "") === "received") receivedCount += 1;
        if ((row.dataset.status || "") === "partial") partialCount += 1;
      }
    });

    if (countNode) countNode.textContent = visible.toLocaleString();
    if (receivedCountNode) receivedCountNode.textContent = receivedCount.toLocaleString();
    if (partialCountNode) partialCountNode.textContent = partialCount.toLocaleString();
    if (piecesCountNode) piecesCountNode.textContent = receivedPieces.toLocaleString();
    if (metaNode) metaNode.textContent = `Showing ${visible.toLocaleString()} of ${rows.length.toLocaleString()} purchase orders.`;
    if (emptyNode) emptyNode.classList.toggle("d-none", visible !== 0);
  };

  const renderModal = (detail) => {
    const items = Array.isArray(detail.items) ? detail.items : [];
    const orderedTotal = items.reduce((sum, item) => sum + Number(item.ordered_quantity || 0), 0);
    const receivedTotal = items.reduce((sum, item) => sum + Number(item.received_quantity || 0), 0);
    const progress = orderedTotal > 0 ? Math.min(100, Math.round((receivedTotal / orderedTotal) * 100)) : 0;
    const statusValue = String(detail.status || "ordered").toLowerCase();
    const statusTone = statusValue === "received"
      ? "is-success"
      : statusValue === "partial"
        ? "is-warning"
        : statusValue === "cancelled"
          ? "is-danger"
          : "is-info";

    const itemRows = items.map((item) => {
      const ordered = Number(item.ordered_quantity || 0);
      const received = Number(item.received_quantity || 0);
      const remaining = Math.max(0, ordered - received);
      return `
        <tr>
          <td><strong>${escapeHtml(item.product_name || "Product")}</strong><div class="small text-muted">${escapeHtml(item.category_name || "Uncategorized")}${item.sku ? ` | SKU ${escapeHtml(item.sku)}` : ""}</div></td>
          <td class="text-end">${ordered.toLocaleString()}</td>
          <td class="text-end">${received.toLocaleString()}</td>
          <td class="text-end">${remaining.toLocaleString()}</td>
          <td class="text-end">${Number(item.current_stock || 0).toLocaleString()}</td>
          <td>${escapeHtml(item.notes || "-")}</td>
        </tr>
      `;
    }).join("");
    const timelineSteps = [
      {
        label: "Order created",
        value: formatDate(detail.ordered_at),
        active: true,
      },
      {
        label: statusValue === "cancelled" ? "Order cancelled" : "Receiving started",
        value: statusValue === "ordered" ? "Waiting for first receipt" : formatDate(detail.received_at || detail.ordered_at),
        active: statusValue !== "ordered",
      },
      {
        label: "Fully received",
        value: statusValue === "received" ? formatDate(detail.received_at) : `${Math.max(0, orderedTotal - receivedTotal).toLocaleString()} pcs remaining`,
        active: statusValue === "received",
      },
    ];
    const timelineHtml = timelineSteps.map((step) => `
      <div class="ops-timeline-step ${step.active ? "is-active" : ""}">
        <span class="ops-timeline-dot"></span>
        <div>
          <strong>${escapeHtml(step.label)}</strong>
          <small>${escapeHtml(step.value)}</small>
        </div>
      </div>
    `).join("");

    modalLabel.textContent = detail.po_number || "Receiving Detail";
    modalBody.innerHTML = `
      <div class="ops-preview-stack">
        <section class="ops-preview-hero">
          <div>
            <span class="ops-eyebrow">Receiving Preview</span>
            <h3 class="ops-preview-title mb-1">${escapeHtml(detail.po_number || "Receiving Detail")}</h3>
            <p class="ops-muted mb-0">Inspect supplier details, receipt progress, and ordered versus received quantities before closing the delivery loop.</p>
          </div>
          <span class="ops-status ${statusTone}">${escapeHtml(String(detail.status || "ordered").toUpperCase())}</span>
        </section>

        <div class="ops-modal-summary">
          <div class="ops-kpi"><span>Supplier</span><strong>${escapeHtml(detail.supplier_name || "Supplier")}</strong></div>
          <div class="ops-kpi"><span>Status</span><strong>${escapeHtml(String(detail.status || "ordered").toUpperCase())}</strong></div>
          <div class="ops-kpi"><span>Ordered At</span><strong>${escapeHtml(formatDate(detail.ordered_at))}</strong></div>
          <div class="ops-kpi"><span>Received At</span><strong>${escapeHtml(detail.received_at ? formatDate(detail.received_at) : "Pending")}</strong></div>
        </div>

        <div class="ops-detail-grid mb-3">
          <div><span class="ops-detail-label">Supplier Contact</span><p class="mb-0">${escapeHtml(detail.contact_person || "-")}<br>${escapeHtml(detail.phone || "-")}<br>${escapeHtml(detail.email || "-")}</p></div>
          <div><span class="ops-detail-label">Order Notes</span><p class="mb-0">${escapeHtml(detail.notes || "No notes added.")}</p></div>
        </div>

        <div class="ops-detail-grid mb-3">
          <div>
            <span class="ops-detail-label">Receipt Progress</span>
            <div class="ops-progress mt-3"><span style="width:${progress}%"></span></div>
            <p class="mb-0 mt-2">${progress}% received | ${receivedTotal.toLocaleString()} of ${orderedTotal.toLocaleString()} pcs</p>
          </div>
          <div>
            <span class="ops-detail-label">Receiving Snapshot</span>
            <p class="mb-0">Pending pieces: ${Math.max(0, orderedTotal - receivedTotal).toLocaleString()}<br>Item lines: ${items.length.toLocaleString()}<br>Current status: ${escapeHtml(String(detail.status || "ordered").toUpperCase())}</p>
          </div>
        </div>

        <section class="ops-preview-section">
          <div class="ops-preview-section__head">
            <div>
              <span class="ops-eyebrow">PO Timeline</span>
              <h4 class="ops-preview-section__title">Receiving lifecycle</h4>
            </div>
          </div>
          <div class="ops-timeline">${timelineHtml}</div>
        </section>

        <section class="ops-preview-section">
          <div class="ops-preview-section__head">
            <div>
              <span class="ops-eyebrow">Line Details</span>
              <h4 class="ops-preview-section__title">Ordered versus received items</h4>
            </div>
          </div>
          <div class="table-responsive">
            <table class="table align-middle">
              <thead><tr><th>Product</th><th class="text-end">Ordered</th><th class="text-end">Received</th><th class="text-end">Remaining</th><th class="text-end">Current Stock</th><th>Notes</th></tr></thead>
              <tbody>${itemRows || '<tr><td colspan="6" class="text-center text-muted py-4">No purchase order items found.</td></tr>'}</tbody>
            </table>
          </div>
        </section>
      </div>
    `;
  };

  document.addEventListener("click", (event) => {
    const button = event.target.closest(".receiving-detail-btn");
    if (!button || !modal) return;
    const poId = button.dataset.poId || "";
    const detail = detailMap[poId];
    if (!detail) return;
    renderModal(detail);
    modal.show();
  });

  const deepLinkPoId = new URLSearchParams(window.location.search).get("po_id");
  if (deepLinkPoId && detailMap[deepLinkPoId] && modal) {
    renderModal(detailMap[deepLinkPoId]);
    modal.show();
  }

  [searchInput, statusInput, dateFromInput, dateToInput].forEach((element) => {
    element?.addEventListener("input", applyFilters);
    element?.addEventListener("change", applyFilters);
  });

  applyFilters();
});
