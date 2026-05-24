"use strict";

/**
 * purchase_orders.js
 *
 * Responsibilities
 * ─────────────────
 *  1. Supplier filter — hides/shows candidate rows instantly, updates hint.
 *  2. Checkbox ↔ qty-input coupling — enabling qty only when row is selected.
 *  3. PO view/receive modal — reads item data from data-* attributes on the
 *     button (populated server-side via batch fetch — no AJAX round-trip).
 *  4. Create-PO section accordion — collapsible to keep page compact.
 *  5. setLoading() on submit buttons to prevent double-submit.
 */

document.addEventListener("DOMContentLoaded", () => {

  // ── Toast ──────────────────────────────────────────────────────────────────
  const Toast = Swal.mixin({
    toast: true,
    position: "top-end",
    showConfirmButton: false,
    timer: 3200,
    timerProgressBar: true,
  });
  const showToast = (msg, icon = "success") => Toast.fire({ icon, title: msg });

  // ── Element refs ───────────────────────────────────────────────────────────
  const supplierSelect   = document.getElementById("poSupplierSelect");
  const candidateBody    = document.getElementById("poCandidateBody");
  const visibleHint      = document.getElementById("poVisibleHint");
  const createPoBtn      = document.getElementById("createPoBtn");
  const createToggle     = document.getElementById("createPoToggle");
  const createBody       = document.getElementById("createPoBody");

  // Modal
  const poModalEl        = document.getElementById("poModal");
  const poModal          = poModalEl ? new bootstrap.Modal(poModalEl) : null;
  const poModalTitle     = document.getElementById("poModalTitle");
  const poModalSubtitle  = document.getElementById("poModalSubtitle");
  const poModalId        = document.getElementById("poModalId");
  const poModalSummary   = document.getElementById("poModalSummary");
  const poModalItems     = document.getElementById("poModalItems");
  const poModalNotesWrap = document.getElementById("poModalNotesWrap");
  const poReceiveSubmit  = document.getElementById("poReceiveSubmitBtn");
  const poReceiveForm    = document.getElementById("poReceiveForm");

  // ── Utilities ──────────────────────────────────────────────────────────────
  function esc(v) {
    const d = document.createElement("div");
    d.textContent = v ?? "";
    return d.innerHTML;
  }

  function fmtInt(n) {
    return Number(n || 0).toLocaleString();
  }

  // ── Accordion — create section ─────────────────────────────────────────────
  if (createToggle && createBody) {
    createToggle.addEventListener("click", () => {
      const isOpen = createBody.style.display !== "none";
      createBody.style.display = isOpen ? "none" : "";
      createToggle.classList.toggle("collapsed", isOpen);
      createToggle.setAttribute("aria-expanded", String(!isOpen));
    });
  }

  // ── Supplier filter ────────────────────────────────────────────────────────
  function applySupplierFilter() {
    if (!candidateBody) return;
    const supplierId = supplierSelect?.value || "";
    const rows = Array.from(candidateBody.querySelectorAll("tr[data-supplier-id]"));
    let visible = 0;

    rows.forEach(row => {
      const match = supplierId === "" || row.dataset.supplierId === supplierId;
      row.style.display = match ? "" : "none";
      if (match) {
        visible++;
      } else {
        // Uncheck and disable qty when row is hidden
        const cb = row.querySelector(".po-item-checkbox");
        const qi = row.querySelector(".qty-input");
        if (cb) cb.checked = false;
        if (qi) qi.disabled = true;
      }
    });

    if (visibleHint) {
      visibleHint.textContent = supplierId
        ? `Showing ${fmtInt(visible)} candidate(s) for the selected supplier.`
        : `${fmtInt(visible)} orderable candidate(s) shown.`;
    }
  }

  supplierSelect?.addEventListener("change", applySupplierFilter);
  applySupplierFilter();

  // ── Checkbox ↔ qty coupling ────────────────────────────────────────────────
  candidateBody?.addEventListener("change", e => {
    const cb = e.target.closest(".po-item-checkbox");
    if (!cb) return;
    const qi = cb.closest("tr")?.querySelector(".qty-input");
    if (qi) {
      qi.disabled = !cb.checked;
      if (cb.checked) qi.focus();
    }
  });

  // ── Create PO form validation + loading state ──────────────────────────────
  const createForm = document.querySelector('input[name="create_purchase_order"]')?.closest("form");
  createForm?.addEventListener("submit", e => {
    const checked = createForm.querySelectorAll(".po-item-checkbox:checked");
    const sup     = createForm.querySelector('[name="supplier_id"]');

    if (!sup?.value) {
      e.preventDefault();
      showToast("Please select a supplier.", "warning");
      return;
    }
    if (checked.length === 0) {
      e.preventDefault();
      showToast("Select at least one product to include in the order.", "warning");
      return;
    }

    if (createPoBtn) {
      createPoBtn.disabled  = true;
      createPoBtn._orig     = createPoBtn.innerHTML;
      createPoBtn.innerHTML = `<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Creating…`;
    }
  });

  // ── PO Modal — build and open ──────────────────────────────────────────────
  function openPoModal(btn, mode) {
    if (!poModal) return;

    const poId        = btn.dataset.poId      || "";
    const poNumber    = btn.dataset.poNumber  || "Purchase Order";
    const supplier    = btn.dataset.supplier  || "—";
    const status      = (btn.dataset.status   || "ordered").toLowerCase();
    const orderedTot  = parseInt(btn.dataset.ordered   || "0", 10);
    const receivedTot = parseInt(btn.dataset.received  || "0", 10);
    const isReceive   = mode === "receive";
    const isClosed    = ["received", "cancelled"].includes(status);

    let items = [];
    try {
      items = JSON.parse(btn.dataset.items || "[]");
    } catch {
      items = [];
    }

    // ── Header ─────────────────────────────────────────────────────────────
    if (poModalId)       poModalId.value             = poId;
    if (poModalTitle)    poModalTitle.textContent     = poNumber;
    if (poModalSubtitle) poModalSubtitle.textContent  = isReceive
      ? "Enter quantities received for each remaining line."
      : "Review current order details.";

    // ── Summary tiles ───────────────────────────────────────────────────────
    if (poModalSummary) {
      const statusLabel = status.charAt(0).toUpperCase() + status.slice(1);
      poModalSummary.innerHTML = `
        <div class="po-detail-tile">
          <div class="po-detail-tile-label">Supplier</div>
          <div class="po-detail-tile-val" style="font-size:13px;">${esc(supplier)}</div>
        </div>
        <div class="po-detail-tile">
          <div class="po-detail-tile-label">Status</div>
          <div class="po-detail-tile-val">${esc(statusLabel)}</div>
        </div>
        <div class="po-detail-tile">
          <div class="po-detail-tile-label">Progress</div>
          <div class="po-detail-tile-val" style="font-size:13px;">
            ${fmtInt(receivedTot)} / ${fmtInt(orderedTot)} pcs
          </div>
        </div>`;
    }

    // ── Items ───────────────────────────────────────────────────────────────
    if (poModalItems) {
      poModalItems.innerHTML = "";

      if (items.length === 0) {
        poModalItems.innerHTML = `
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
          const disabled  = !isReceive || remaining <= 0 || isClosed;

          const tr = document.createElement("tr");
          tr.innerHTML = `
            <td>
              <div style="font-weight:500;font-size:13px;">${esc(item.product_name || "")}</div>
              <div style="font-size:11px;color:var(--c-text-3);">
                ${esc(item.category_name || "Uncategorized")}
                ${item.sku ? " · SKU " + esc(item.sku) : ""}
              </div>
            </td>
            <td style="text-align:right;font-family:var(--ff-mono);font-weight:600;">
              ${fmtInt(ordered)}
            </td>
            <td style="text-align:right;font-family:var(--ff-mono);color:var(--c-green);">
              ${fmtInt(received)}
            </td>
            <td style="text-align:right;font-family:var(--ff-mono);
                       color:${remaining > 0 ? "var(--c-amber)" : "var(--c-text-3)"};">
              ${fmtInt(remaining)}
            </td>
            <td>
              <input
                type="number"
                min="0"
                max="${remaining}"
                value="${disabled ? 0 : remaining}"
                class="qty-input"
                name="received[${esc(String(item.po_item_id || ""))}]"
                ${disabled ? "disabled" : ""}
                style="width:90px;"
                aria-label="Receive qty for ${esc(item.product_name || "")}"
              >
            </td>`;
          poModalItems.appendChild(tr);
        });
      }
    }

    // ── Notes + submit visibility ───────────────────────────────────────────
    if (poModalNotesWrap) {
      poModalNotesWrap.style.display = isReceive ? "" : "none";
    }
    if (poReceiveSubmit) {
      poReceiveSubmit.style.display = isReceive ? "" : "none";
      poReceiveSubmit.disabled      = false;
      poReceiveSubmit.innerHTML     =
        '<i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Save receipt';
    }

    // Reset notes
    const notesTA = poReceiveForm?.querySelector('textarea[name="receive_notes"]');
    if (notesTA) notesTA.value = "";

    poModal.show();
  }

  // ── Table click delegation — view / receive / cancel ─────────────────────────
  document.getElementById("poTable")?.addEventListener("click", e => {
    const viewBtn    = e.target.closest(".po-view-btn");
    const receiveBtn = e.target.closest(".po-receive-btn");
    const cancelBtn  = e.target.closest(".po-cancel-btn");
    if (viewBtn)    { openPoModal(viewBtn,    "view");    return; }
    if (receiveBtn) { openPoModal(receiveBtn, "receive"); return; }
    if (cancelBtn)  { handleCancel(cancelBtn); }
  });

  // ── Cancel PO ─────────────────────────────────────────────────────────────
  async function handleCancel(btn) {
    const poId     = btn.dataset.poId     || "";
    const poNumber = btn.dataset.poNumber || "this purchase order";

    const result = await Swal.fire({
      title:   `Cancel ${poNumber}?`,
      html:    `
        <p style="font-size:13px;margin-bottom:12px;">
          You're about to cancel this purchase order.
          Any stock already received will remain in inventory.
        </p>
        <label style="display:block;text-align:left;font-size:11px;font-weight:600;
                       text-transform:uppercase;letter-spacing:.04em;color:#9a9691;margin-bottom:5px;">
          Reason <span style="font-weight:400;color:#b0a9a3;">(optional)</span>
        </label>
        <input id="swalCancelReason" class="swal2-input"
               placeholder="e.g. Supplier no longer available…"
               style="margin:0;width:100%;font-size:13px;">`,
      icon:               "warning",
      showCancelButton:   true,
      confirmButtonText:  "Yes, cancel order",
      cancelButtonText:   "Go back",
      confirmButtonColor: "#dc2626",
      cancelButtonColor:  "#6b7280",
      focusConfirm:       false,
      preConfirm: () => document.getElementById("swalCancelReason")?.value.trim() || "",
    });

    if (!result.isConfirmed) return;

    const form     = document.getElementById("poCancelForm");
    const idInput  = document.getElementById("poCancelPoId");
    const resInput = document.getElementById("poCancelReason");

    if (!form || !idInput || !resInput) {
      showToast("Cancel form not found. Please refresh the page.", "error");
      return;
    }

    idInput.value  = poId;
    resInput.value = result.value || "";
    form.submit();
  }

  // ── Receive form — validate + loading state ────────────────────────────────
  poReceiveForm?.addEventListener("submit", e => {
    const inputs = poReceiveForm.querySelectorAll('input[name^="received["]');
    const hasAny = Array.from(inputs).some(i => parseInt(i.value || "0", 10) > 0);
    if (!hasAny) {
      e.preventDefault();
      showToast("Enter at least one quantity greater than zero.", "warning");
      return;
    }
    if (poReceiveSubmit) {
      poReceiveSubmit.disabled  = true;
      poReceiveSubmit.innerHTML =
        `<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Saving…`;
    }
  });

  // ── Modal cleanup ──────────────────────────────────────────────────────────
  poModalEl?.addEventListener("hidden.bs.modal", () => {
    if (poModalItems)   poModalItems.innerHTML   = "";
    if (poModalSummary) poModalSummary.innerHTML  = "";
    if (poModalId)      poModalId.value           = "";
  });

});