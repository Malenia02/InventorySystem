"use strict";
document.addEventListener("DOMContentLoaded", () => {

  // ── Element refs ───────────────────────────────────────────────────────────
  const searchInput = document.getElementById("rhSearchInput");
  const filterForm  = document.querySelector("form[method='get']");
  const rhTable     = document.getElementById("rhTable");

  // Modal — IDs match the PHP exactly
  const modalEl       = document.getElementById("rhDetailModal");
  const modal         = modalEl ? new bootstrap.Modal(modalEl) : null;
  const modalTitle    = document.getElementById("rhModalTitle");
  const modalSubtitle = document.getElementById("rhModalSubtitle");
  const modalMeta     = document.getElementById("rhModalMeta");
  const modalItems    = document.getElementById("rhModalItems");

  // ── Utilities ──────────────────────────────────────────────────────────────
  function esc(v) {
    const d = document.createElement("div");
    d.textContent = v ?? "";
    return d.innerHTML;
  }

  function fmtInt(n) {
    return Number(n || 0).toLocaleString();
  }

  function fmtDate(v) {
    if (!v || v === "0000-00-00 00:00:00") return "—";
    const d = new Date(v.replace(" ", "T"));
    if (isNaN(d.getTime())) return String(v);
    return d.toLocaleString("en-PH", {
      month: "short", day: "2-digit", year: "numeric",
      hour: "2-digit", minute: "2-digit",
    });
  }

  // ── Search debounce — submits server-side filter form ─────────────────────
  let _searchTimer;
  if (searchInput && filterForm) {
    searchInput.addEventListener("input", () => {
      clearTimeout(_searchTimer);
      _searchTimer = setTimeout(() => filterForm.submit(), 420);
    });
  }

  // ── Build and open the detail modal ───────────────────────────────────────
  function openDetailModal(btn) {
    if (!modal) return;

    const poNumber    = btn.dataset.poNumber   || "Purchase Order";
    const supplier    = btn.dataset.supplier   || "—";
    const status      = (btn.dataset.status    || "ordered").toLowerCase();
    const orderedAt   = btn.dataset.orderedAt  || "";
    const receivedAt  = btn.dataset.receivedAt || "";
    const orderedTot  = parseInt(btn.dataset.ordered   || "0", 10);
    const receivedTot = parseInt(btn.dataset.received  || "0", 10);
    const progress    = parseInt(btn.dataset.progress  || "0", 10);
    const createdBy   = btn.dataset.createdBy  || "—";
    const receivedBy  = btn.dataset.receivedBy || "—";
    const statusLabel = status.charAt(0).toUpperCase() + status.slice(1);

    let items = [];
    try {
      items = JSON.parse(btn.dataset.items || "[]");
    } catch {
      items = [];
    }

    // ── Header ─────────────────────────────────────────────────────────────
    if (modalTitle)    modalTitle.textContent    = poNumber;
    if (modalSubtitle) modalSubtitle.textContent = `${esc(supplier)} · ${statusLabel}`;

    // ── Meta tiles (4 columns) ──────────────────────────────────────────────
    if (modalMeta) {
      const remaining = Math.max(0, orderedTot - receivedTot);
      modalMeta.innerHTML = `
        <div class="detail-tile">
          <div class="detail-tile-label">Supplier</div>
          <div class="detail-tile-val" style="font-size:13px;">${esc(supplier)}</div>
        </div>
        <div class="detail-tile">
          <div class="detail-tile-label">Status</div>
          <div class="detail-tile-val">${esc(statusLabel)}</div>
        </div>
        <div class="detail-tile">
          <div class="detail-tile-label">Progress</div>
          <div class="detail-tile-val" style="font-size:13px;">${fmtInt(receivedTot)} / ${fmtInt(orderedTot)} pcs</div>
          <div class="detail-tile-sub">${progress}% received${remaining > 0 ? ` · ${fmtInt(remaining)} remaining` : ""}</div>
        </div>
        <div class="detail-tile">
          <div class="detail-tile-label">Received at</div>
          <div class="detail-tile-val" style="font-size:12px;">${receivedAt ? esc(fmtDate(receivedAt)) : '<span style="color:var(--c-text-3);">Pending</span>'}</div>
          <div class="detail-tile-sub">Ordered ${esc(fmtDate(orderedAt))}</div>
        </div>`;
    }

    // ── Timeline strip ──────────────────────────────────────────────────────
    const timelineSteps = [
      {
        label: "Order created",
        value: fmtDate(orderedAt),
        by:    createdBy ? `By ${createdBy}` : "",
        done:  true,
      },
      {
        label: status === "cancelled" ? "Order cancelled" : "Receiving started",
        value: status === "ordered"
          ? "Waiting for first receipt"
          : fmtDate(receivedAt || orderedAt),
        by:    "",
        done:  !["ordered"].includes(status),
      },
      {
        label: "Fully received",
        value: status === "received"
          ? fmtDate(receivedAt)
          : `${fmtInt(Math.max(0, orderedTot - receivedTot))} pcs remaining`,
        by:    receivedBy && status === "received" ? `By ${receivedBy}` : "",
        done:  status === "received",
      },
    ];

    const timelineHtml = `
      <div style="display:flex;gap:0;margin-bottom:1.25rem;">
        ${timelineSteps.map((step, i) => `
          <div style="flex:1;position:relative;padding:0 0 0 ${i === 0 ? 0 : "1rem"};">
            ${i > 0 ? `<div style="position:absolute;left:0;top:8px;height:2px;right:50%;background:${step.done ? "var(--c-green)" : "var(--c-border)"};"></div>` : ""}
            <div style="width:16px;height:16px;border-radius:50%;background:${step.done ? "var(--c-green)" : "var(--c-border)"};border:2px solid ${step.done ? "var(--c-green)" : "var(--c-border-2)"};margin-bottom:6px;position:relative;z-index:1;"></div>
            <div style="font-size:11px;font-weight:600;color:${step.done ? "var(--c-text-1)" : "var(--c-text-3)"};">${esc(step.label)}</div>
            <div style="font-size:11px;color:var(--c-text-3);margin-top:1px;">${esc(step.value)}</div>
            ${step.by ? `<div style="font-size:11px;color:var(--c-text-3);">${esc(step.by)}</div>` : ""}
          </div>`).join("")}
      </div>`;

    // ── Overall progress bar ────────────────────────────────────────────────
    const progressHtml = `
      <div style="margin-bottom:1rem;">
        <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--c-text-3);margin-bottom:4px;">
          <span>Receipt progress</span><span>${progress}%</span>
        </div>
        <div style="background:var(--c-border);border-radius:99px;height:8px;overflow:hidden;">
          <div style="height:100%;border-radius:99px;width:${progress}%;
               background:${progress >= 100 ? "var(--c-green)" : progress > 0 ? "var(--c-amber)" : "var(--c-border)"};
               transition:width .3s;"></div>
        </div>
      </div>`;

    // ── Item rows ───────────────────────────────────────────────────────────
    if (modalItems) {
      modalItems.innerHTML = "";

      if (items.length === 0) {
        modalItems.innerHTML = `
          <tr>
            <td colspan="5" style="text-align:center;padding:2.5rem;color:var(--c-text-3);">
              No items on this order.
            </td>
          </tr>`;
      } else {
        items.forEach(item => {
          const ordered   = Number(item.ordered_quantity  || 0);
          const received  = Number(item.received_quantity || 0);
          const remaining = Math.max(0, ordered - received);
          const pct       = ordered > 0 ? Math.min(100, Math.round((received / ordered) * 100)) : 0;
          const isFull    = pct >= 100;

          const tr = document.createElement("tr");
          tr.innerHTML = `
            <td>
              <div style="font-weight:500;font-size:13px;">${esc(item.product_name || "")}</div>
              <div style="font-size:11px;color:var(--c-text-3);">
                ${esc(item.category_name || "Uncategorized")}
                ${item.sku ? " · SKU " + esc(item.sku) : ""}
              </div>
            </td>
            <td style="text-align:right;font-family:var(--ff-mono);font-weight:600;">${fmtInt(ordered)}</td>
            <td style="text-align:right;font-family:var(--ff-mono);color:var(--c-green);">${fmtInt(received)}</td>
            <td style="text-align:right;font-family:var(--ff-mono);color:${remaining > 0 ? "var(--c-amber)" : "var(--c-text-3)"};">${fmtInt(remaining)}</td>
            <td style="min-width:90px;">
              <div style="background:var(--c-border);border-radius:99px;height:6px;overflow:hidden;">
                <div style="height:100%;border-radius:99px;width:${pct}%;background:${isFull ? "var(--c-green)" : "var(--c-amber)"};"></div>
              </div>
              <div style="font-size:10px;color:var(--c-text-3);margin-top:3px;">${pct}%</div>
            </td>`;
          modalItems.appendChild(tr);
        });
      }
    }

    // Inject timeline + progress bar above the items table inside modal-body
    const modalBody = modalEl?.querySelector(".modal-body");
    if (modalBody) {
      // Remove any previously injected timeline/progress
      modalBody.querySelectorAll(".rh-timeline-inject").forEach(el => el.remove());

      const inject = document.createElement("div");
      inject.className = "rh-timeline-inject";
      inject.innerHTML = timelineHtml + progressHtml;

      // Insert before the table-wrap
      const tableWrap = modalBody.querySelector(".table-wrap");
      if (tableWrap) {
        modalBody.insertBefore(inject, tableWrap);
      } else {
        modalBody.prepend(inject);
      }
    }

    modal.show();
  }

  // ── Table click delegation ─────────────────────────────────────────────────
  rhTable?.addEventListener("click", e => {
    const btn = e.target.closest(".rh-detail-btn");
    if (btn) openDetailModal(btn);
  });

  // ── Modal cleanup ──────────────────────────────────────────────────────────
  modalEl?.addEventListener("hidden.bs.modal", () => {
    if (modalItems) modalItems.innerHTML = "";
    if (modalMeta)  modalMeta.innerHTML  = "";
    modalEl?.querySelectorAll(".rh-timeline-inject").forEach(el => el.remove());
  });

  // ── Deep-link: ?po_id=N opens the modal automatically ─────────────────────
  const deepLinkId = new URLSearchParams(window.location.search).get("po_id");
  if (deepLinkId) {
    const btn = rhTable?.querySelector(`.rh-detail-btn[data-po-id="${CSS.escape(deepLinkId)}"]`);
    if (btn) {
      // Small delay so Bootstrap is fully ready
      setTimeout(() => openDetailModal(btn), 180);
    }
  }

});