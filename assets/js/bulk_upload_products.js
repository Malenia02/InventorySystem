"use strict";

/**
 * bulk_upload_products.js
 *
 * Changes from the previous version
 * ────────────────────────────────────
 *  1.  Wrapped in DOMContentLoaded — no accidental global execution.
 *
 *  2.  Card count badge (#bulkCardCount) updates on every add/remove.
 *
 *  3.  Empty state (#bulkEmptyState) and submit row (#bulkSubmitRow)
 *      are shown/hidden based on whether any cards exist.
 *
 *  4.  Per-card error highlighting — after a failed bulk submit, each
 *      card that had an error gets class "has-error" and its
 *      .bulk-card-error-msg is filled with the specific error message.
 *      Cards that were saved get class "is-saved".
 *
 *  5.  New add button (#addBulkProductCardEmpty) inside the empty state.
 *
 *  6.  Results panel uses the new banner + table markup from the PHP.
 *
 *  7.  All other logic (photo preview, subcategory filter, beverage
 *      rules, form submission) is identical to the previous version.
 */

document.addEventListener("DOMContentLoaded", () => {

  // ── Element refs ───────────────────────────────────────────────────────────
  const bulkForm        = document.getElementById("bulkUploadProductsForm");
  const bulkSubmitBtn   = document.getElementById("bulkUploadSubmitBtn");
  const bulkResults     = document.getElementById("bulkUploadResults");
  const bulkCards       = document.getElementById("bulkProductCards");
  const bulkTemplate    = document.getElementById("bulkProductCardTemplate");
  const bulkEmptyState  = document.getElementById("bulkEmptyState");
  const bulkSubmitRow   = document.getElementById("bulkSubmitRow");
  const bulkCardCount   = document.getElementById("bulkCardCount");

  const addButtons = [
    document.getElementById("addBulkProductCardBtn"),
    document.getElementById("addBulkProductCardBtnBottom"),
    document.getElementById("addBulkProductCardEmpty"),
  ].filter(Boolean);

  const csrfToken = document.querySelector(
    '#bulkUploadProductsForm input[name="csrf_token"]'
  )?.value || "";

  let cardIndex = 0;

  // ── Toast ──────────────────────────────────────────────────────────────────
  const Toast = Swal.mixin({
    toast: true,
    position: "top-end",
    showConfirmButton: false,
    timer: 3500,
    timerProgressBar: true,
  });
  const showToast = (msg, icon = "success") => Toast.fire({ icon, title: msg });

  // ── Utilities ──────────────────────────────────────────────────────────────
  function esc(v) {
    const d = document.createElement("div");
    d.textContent = v ?? "";
    return d.innerHTML;
  }

  function sendWS(event = "notification_update", type = "product") {
    if (window.socket?.readyState === WebSocket.OPEN) {
      window.socket.send(JSON.stringify({ event, type }));
    }
  }

  async function postBulk(formData) {
    if (!formData.has("csrf_token")) formData.append("csrf_token", csrfToken);
    const res  = await fetch("/inventory_system/http/ajax/product_actions.php", {
      method:  "POST",
      headers: { "X-Requested-With": "XMLHttpRequest" },
      body:    formData,
    });
    const text = await res.text();
    try {
      return JSON.parse(text);
    } catch {
      console.error("Invalid JSON:", text);
      throw new Error("Server returned invalid JSON.");
    }
  }

  // ── UI state helpers ───────────────────────────────────────────────────────
  function getCardEls() {
    return Array.from(bulkCards?.querySelectorAll(".bulk-product-card") || []);
  }

  function updateUI() {
    const count = getCardEls().length;
    if (bulkCardCount)  bulkCardCount.textContent   = String(count);
    // Empty state and submit row are siblings of #bulkProductCards (not inside it)
    if (bulkEmptyState) bulkEmptyState.style.display = count === 0 ? "flex" : "none";
    if (bulkSubmitRow)  bulkSubmitRow.style.display  = count > 0   ? "flex" : "none";
  }

  function updateCardNumbers() {
    getCardEls().forEach((card, idx) => {
      const badge  = card.querySelector(".bulk-card-num-badge");
      const label  = card.querySelector(".bulk-card-num-label");
      const sub    = card.querySelector(".bulk-card-num-sub");
      const rmBtn  = card.querySelector(".remove-bulk-card-btn");
      const n = idx + 1;
      if (badge)  badge.textContent  = String(n);
      if (label)  label.textContent  = `Product ${n}`;
      if (sub)    sub.textContent    = "Complete this form, then save all at the end.";
      if (rmBtn)  rmBtn.setAttribute("aria-label", `Remove product ${n}`);
    });
    updateUI();
  }

  // ── Subcategory helpers ────────────────────────────────────────────────────
  function cacheSubcatOptions(select) {
    if (!select) return [];
    return Array.from(select.options).map(o => ({
      value:      o.value,
      label:      o.textContent,
      categoryId: o.dataset.categoryId || "",
    }));
  }

  function populateSubcatSelect(select, options, categoryId, selected = "") {
    if (!select) return;
    const catStr = String(categoryId || "");
    const selStr = String(selected || "");
    select.innerHTML = options
      .filter(o => o.value === "" || o.categoryId === catStr)
      .map(o => `<option value="${esc(o.value)}"${o.value === selStr ? " selected" : ""}>${esc(o.label)}</option>`)
      .join("");
    if (!Array.from(select.options).some(o => o.value === selStr)) {
      select.value = "";
    }
  }

  function isBeverage(select) {
    return (select?.options?.[select.selectedIndex]?.textContent || "").toLowerCase().includes("beverage");
  }

  function applyUnitRules(card, catSelect) {
    if (!card || !catSelect) return;
    const bev = isBeverage(catSelect);
    card.querySelectorAll(".bulk-box-field").forEach(wrap => {
      wrap.classList.toggle("d-none", bev);
      wrap.querySelectorAll("input, select").forEach(f => {
        f.disabled = bev;
        if (bev) f.value = "";
      });
    });
    // Show/hide the beverage note via CSS class on the .bulk-unit-note wrapper
    card.querySelectorAll('.bulk-unit-note[data-unit-note="beverage"]').forEach(note => {
      note.classList.toggle("show-note", bev);
    });
  }

  // ── Add a card ─────────────────────────────────────────────────────────────
  function addCard() {
    if (!bulkCards || !bulkTemplate) return;

    const currentCount = getCardEls().length;
    const displayNum   = currentCount + 1;

    const html = bulkTemplate.innerHTML
      .replaceAll("__INDEX__",  String(cardIndex))
      .replaceAll("__NUMBER__", String(displayNum));

    const wrapper = document.createElement("div");
    wrapper.innerHTML = html.trim();
    const card = wrapper.firstElementChild;
    if (!card) return;

    // ── Photo preview ─────────────────────────────────────────────────────
    const photoInput   = card.querySelector(".bulk-photo-input");
    const photoPreview = card.querySelector(".bulk-photo-preview");
    if (photoInput && photoPreview) {
      photoInput.addEventListener("change", e => {
        const file = e.target.files?.[0];
        if (!file) {
          photoPreview.src = "/inventory_system/assets/img/card.jpg";
          return;
        }
        const reader = new FileReader();
        reader.onload = ev => { photoPreview.src = ev.target.result || "/inventory_system/assets/img/card.jpg"; };
        reader.readAsDataURL(file);
      });
    }

    // ── Subcategory + unit rules ──────────────────────────────────────────
    const catSelect    = card.querySelector(".bulk-category-select");
    const subcatSelect = card.querySelector(".bulk-subcategory-select");
    const subcatOpts   = cacheSubcatOptions(subcatSelect);

    if (catSelect && subcatSelect) {
      populateSubcatSelect(subcatSelect, subcatOpts, catSelect.value, subcatSelect.value);
      applyUnitRules(card, catSelect);

      catSelect.addEventListener("change", () => {
        populateSubcatSelect(subcatSelect, subcatOpts, catSelect.value, "");
        applyUnitRules(card, catSelect);
      });
    }

    // ── Remove button ─────────────────────────────────────────────────────
    card.querySelector(".remove-bulk-card-btn")?.addEventListener("click", () => {
      card.remove();
      updateCardNumbers();
      if (bulkResults) bulkResults.innerHTML = "";
    });

    bulkCards.appendChild(card);
    cardIndex++;
    updateCardNumbers();

    // Smooth scroll to the new card
    requestAnimationFrame(() => {
      card.scrollIntoView({ behavior: "smooth", block: "nearest" });
    });
  }

  // ── Clear per-card state ───────────────────────────────────────────────────
  function clearCardStates() {
    getCardEls().forEach(card => {
      card.classList.remove("has-error", "is-saved");
      const errMsg = card.querySelector(".bulk-card-error-msg");
      if (errMsg) errMsg.textContent = "This product could not be saved. See details below.";
    });
  }

  // ── Mark cards with errors/success after submit ────────────────────────────
  function applyCardStates(result) {
    const errors     = Array.isArray(result.errors) ? result.errors : [];
    const errorRows  = new Set(errors.map(e => Number(e.row)));

    // "row" in the error object is the 1-based index of the products[] array
    // The card index (data-card-index) is the JS cardIndex, but the PHP
    // uses the array key from products[N] which is the __INDEX__ value.
    // We map by position: first card = position 1, second = 2, etc.
    const cards = getCardEls();

    cards.forEach((card, pos) => {
      const position = pos + 1; // 1-based to match server "row" numbering

      if (errorRows.has(position)) {
        card.classList.add("has-error");
        card.classList.remove("is-saved");
        const errForThis = errors.find(e => Number(e.row) === position);
        const errMsg = card.querySelector(".bulk-card-error-msg");
        if (errMsg && errForThis?.error) {
          errMsg.textContent = errForThis.error;
        }
        card.scrollIntoView({ behavior: "smooth", block: "nearest" });
      } else {
        card.classList.add("is-saved");
        card.classList.remove("has-error");
      }
    });
  }

  // ── Render results banner ──────────────────────────────────────────────────
  function renderResults(result) {
    if (!bulkResults) return;

    const created  = Number(result.created_count || 0);
    const errored  = Number(result.error_count   || 0);
    const errors   = Array.isArray(result.errors) ? result.errors : [];

    const bannerType = created > 0 && errored === 0 ? "success"
                     : created > 0                   ? "warning"
                     : "danger";

    const bannerIcon = bannerType === "success" ? "bi-check-circle-fill"
                     : bannerType === "warning"  ? "bi-exclamation-triangle-fill"
                     : "bi-x-circle-fill";

    let html = `
      <div class="result-banner ${bannerType}">
        <i class="bi ${bannerIcon}" aria-hidden="true"></i>
        <div class="result-banner-body">
          <strong>${esc(result.message || "Bulk create finished.")}</strong>
          Saved: <strong>${created}</strong>&ensp;·&ensp;Failed: <strong>${errored}</strong>
        </div>
      </div>`;

    if (errors.length > 0) {
      const rows = errors.map(item => `
        <tr>
          <td class="err-row-num">#${esc(String(item.row ?? ""))}</td>
          <td>${esc(item.product || "—")}</td>
          <td class="err-msg">${esc(item.error || "Unknown error")}</td>
        </tr>`).join("");

      html += `
        <div class="error-table-wrap">
          <div class="error-table-head">
            <i class="bi bi-exclamation-circle me-1" aria-hidden="true"></i>
            ${errors.length} product(s) need attention
          </div>
          <div style="overflow-x:auto;">
            <table class="error-table">
              <thead>
                <tr>
                  <th style="width:60px;">Card</th>
                  <th style="width:200px;">Product</th>
                  <th>Issue</th>
                </tr>
              </thead>
              <tbody>${rows}</tbody>
            </table>
          </div>
        </div>`;
    }

    bulkResults.innerHTML = html;
    bulkResults.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }

  // ── Reset after full success ───────────────────────────────────────────────
  function resetCards() {
    if (!bulkCards) return;
    bulkCards.innerHTML = "";
    cardIndex = 0;
    if (bulkResults) bulkResults.innerHTML = "";
    addCard();
  }

  // ── Wire add buttons ───────────────────────────────────────────────────────
  addButtons.forEach(btn => btn.addEventListener("click", addCard));

  // ── Form submit ────────────────────────────────────────────────────────────
  bulkForm?.addEventListener("submit", async e => {
    e.preventDefault();

    const totalCards = getCardEls().length;
    if (totalCards === 0) {
      showToast("Add at least one product card first.", "warning");
      return;
    }

    // Clear previous error states
    clearCardStates();
    if (bulkResults) bulkResults.innerHTML = "";

    const fd = new FormData(bulkForm);
    fd.append("bulk_create_products", "1");

    // Loading state
    if (bulkSubmitBtn) {
      bulkSubmitBtn.disabled  = true;
      bulkSubmitBtn._orig     = bulkSubmitBtn.innerHTML;
      bulkSubmitBtn.innerHTML =
        `<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Saving…`;
    }

    try {
      const result = await postBulk(fd);

      renderResults(result);
      applyCardStates(result);

      if (result.success && Number(result.error_count || 0) === 0) {
        // All products created — reset cards
        showToast(result.message || "All products created successfully.", "success");
        sendWS(result.event, result.type);
        setTimeout(resetCards, 800);
      } else if (result.success) {
        // Partial success
        showToast(result.message || "Some products were saved. Fix the highlighted cards.", "warning");
        sendWS(result.event, result.type);
        // Remove successfully saved cards (those without has-error)
        getCardEls().forEach(card => {
          if (card.classList.contains("is-saved")) {
            card.remove();
          }
        });
        updateCardNumbers();
      } else {
        showToast(result.message || result.error || "No products were saved.", "error");
      }

    } catch (err) {
      console.error(err);
      if (bulkResults) {
        bulkResults.innerHTML = `
          <div class="result-banner danger">
            <i class="bi bi-x-circle-fill" aria-hidden="true"></i>
            <div class="result-banner-body">
              <strong>Server error</strong>
              Unable to save products right now. Please try again.
            </div>
          </div>`;
      }
      showToast("Server error while saving products.", "error");
    } finally {
      if (bulkSubmitBtn) {
        bulkSubmitBtn.disabled  = false;
        bulkSubmitBtn.innerHTML = bulkSubmitBtn._orig ||
          '<i class="bi bi-check2-circle" aria-hidden="true"></i> Save all products';
      }
    }
  });

  // ── Boot: add first card ───────────────────────────────────────────────────
  addCard();

});