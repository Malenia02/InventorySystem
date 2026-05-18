"use strict";

/**
 * manage_product.js
 *
 * Scalability / UX improvements over the previous version
 * ────────────────────────────────────────────────────────
 *  1.  Zero full page reloads.  Every action (add, edit, toggle,
 *      restock, stockout) patches the specific table row in-place.
 *      buildProductRow() is the single source of truth for row HTML,
 *      used by both prepend (add) and replace (edit/toggle).
 *
 *  2.  Quantity cells update immediately on restock/stockout via
 *      patchQuantityCell() — no reload, no full row rebuild needed.
 *
 *  3.  Optimistic UI for toggle: the badge and button flip immediately
 *      and revert on server error.
 *
 *  4.  Stat cards (total, active, low stock, inactive) update in-place
 *      after writes via adjustStats().
 *
 *  5.  Search debounce: filter form auto-submits 420 ms after the user
 *      stops typing — no request-per-keystroke.
 *
 *  6.  The edit button no longer manually calls new bootstrap.Modal().show()
 *      on top of data-bs-toggle="modal" — the HTML attribute handles it;
 *      the JS only fills the form fields.
 *
 *  7.  postData() and helpers are defined inside DOMContentLoaded so
 *      there are no accidental globals.
 *
 *  8.  reloadCurrentPage() / queueReloadToast() are kept only for
 *      the bulk-create path (which legitimately needs a reload to
 *      show all the new rows) and for fatal fallbacks.
 */

document.addEventListener("DOMContentLoaded", () => {

  // ── Toast ─────────────────────────────────────────────────────────────────
  const Toast = Swal.mixin({
    toast: true,
    position: "top-end",
    showConfirmButton: false,
    timer: 3200,
    timerProgressBar: true,
  });
  const showToast = (msg, icon = "success") => Toast.fire({ icon, title: msg });

  // ── Element cache ─────────────────────────────────────────────────────────
  const el = {
    table:              document.getElementById("productsTable"),
    tbody:              document.querySelector("#productsTable tbody"),
    messages:           document.getElementById("productMessages"),
    addForm:            document.getElementById("addProductForm"),
    editForm:           document.getElementById("editProductForm"),
    restockForm:        document.getElementById("restockForm"),
    stockOutForm:       document.getElementById("stockOutForm"),
    supplierForm:       document.getElementById("supplierForm"),
    supplierMessage:    document.getElementById("supplierMessage"),
    supplierModalEl:    document.getElementById("supplierModal"),
    saveSupplierBtn:    document.getElementById("saveSupplierBtn"),
    addCatSelect:       document.getElementById("addProductCategory"),
    addSubcatSelect:    document.getElementById("addProductSubcategory"),
    editCatSelect:      document.getElementById("editProductCategory"),
    editSubcatSelect:   document.getElementById("editProductSubcategory"),
    searchInput:        document.querySelector('input[name="search"]'),
    filterForm:         document.querySelector("form[method='get']"),

    // Stat card value nodes — targeted by ID, not fragile nth-child
    statTotal:    document.getElementById("statTotal"),
    statActive:   document.getElementById("statActive"),
    statLow:      document.getElementById("statLowStock"),
    statInactive: document.getElementById("statInactive"),
  };

  const ENDPOINT     = "/inventory_system/http/ajax/product_actions.php";
  const FALLBACK_IMG = "/inventory_system/assets/img/card.jpg";

  // CSRF — read once at boot
  const csrfToken =
    document.querySelector('meta[name="csrf-token"]')?.content ||
    document.querySelector('#addProductForm  input[name="csrf_token"]')?.value ||
    document.querySelector('#editProductForm input[name="csrf_token"]')?.value ||
    "";

  // ── Utilities ─────────────────────────────────────────────────────────────

  function esc(v) {
    const d = document.createElement("div");
    d.textContent = v ?? "";
    return d.innerHTML;
  }

  function fmt2(n) {
    return Number(n || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function fmtInt(n) {
    return Number(n || 0).toLocaleString();
  }

  function setLoading(btn, loading, defaultHtml) {
    if (!btn) return;
    if (loading) {
      btn.disabled  = true;
      btn._orig     = defaultHtml || btn.innerHTML;
      btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Saving…`;
    } else {
      btn.disabled  = false;
      btn.innerHTML = defaultHtml || btn._orig || btn.innerHTML;
    }
  }

  // ── Inline message banner (auto-clears after 4 s) ─────────────────────────
  let _msgTimer;
  function showMessage(type, msg) {
    if (!el.messages) return;
    el.messages.innerHTML = `
      <div class="alert alert-${type} alert-dismissible fade show" role="alert">
        ${esc(msg)}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>`;
    clearTimeout(_msgTimer);
    _msgTimer = setTimeout(() => { if (el.messages) el.messages.innerHTML = ""; }, 4000);
  }

  // ── Fetch wrapper ─────────────────────────────────────────────────────────
  async function post(formData) {
    if (csrfToken && !formData.has("csrf_token")) {
      formData.append("csrf_token", csrfToken);
    }
    const res  = await fetch(ENDPOINT, {
      method: "POST",
      headers: { "X-Requested-With": "XMLHttpRequest" },
      body: formData,
    });
    const text = await res.text();
    try {
      return JSON.parse(text);
    } catch {
      console.error("Non-JSON response:", text);
      throw new Error("Invalid server response.");
    }
  }

  // ── WebSocket notification trigger (best-effort) ──────────────────────────
  function sendWS(event = "notification_update", type = "general") {
    if (window.socket?.readyState === WebSocket.OPEN) {
      window.socket.send(JSON.stringify({ event, type }));
    }
  }

  // ── Reload with queued toast (bulk-create / fatal fallback only) ──────────
  function reloadWithToast(msg, icon = "success") {
    try {
      sessionStorage.setItem("manageProductsFlash", JSON.stringify({ msg, icon }));
    } catch { /* ignore */ }
    window.location.assign(window.location.pathname + window.location.search);
  }

  function flushQueuedToast() {
    try {
      const raw = sessionStorage.getItem("manageProductsFlash");
      if (!raw) return;
      sessionStorage.removeItem("manageProductsFlash");
      const p = JSON.parse(raw);
      if (p?.msg) showToast(p.msg, p.icon || "success");
    } catch { /* ignore */ }
  }

  // ── Modal helpers ─────────────────────────────────────────────────────────
  function hideModal(id) {
    const modalEl = document.getElementById(id);
    if (!modalEl) return;
    const m = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
    m.hide();
    setTimeout(() => {
      document.querySelectorAll(".modal-backdrop").forEach(b => b.remove());
      document.body.classList.remove("modal-open");
      document.body.style.overflow = "";
      document.body.style.paddingRight = "";
    }, 120);
  }

  // ── Stat card helpers ─────────────────────────────────────────────────────
  function readStat(node) {
    return parseInt((node?.textContent || "0").replace(/,/g, ""), 10) || 0;
  }
  function adjustStats(delta) {
    const upd = (node, d) => {
      if (!node || !d) return;
      node.textContent = Math.max(0, readStat(node) + d).toLocaleString();
    };
    upd(el.statTotal,    delta.total    ?? 0);
    upd(el.statActive,   delta.active   ?? 0);
    upd(el.statLow,      delta.low      ?? 0);
    upd(el.statInactive, delta.inactive ?? 0);
  }

  // ── Row number recalc ─────────────────────────────────────────────────────
  function recalcRowNumbers() {
    if (!el.tbody) return;
    const info  = document.querySelector(".pag-info")?.textContent || "";
    const match = info.match(/Showing\s+([\d,]+)/);
    let   n     = match ? parseInt(match[1].replace(/,/g, ""), 10) : 1;
    el.tbody.querySelectorAll("tr").forEach(row => {
      const cell = row.querySelector("td.num");
      if (cell) cell.textContent = String(n++);
    });
  }

  // ── Build a full product table row from a product view object ─────────────
  function buildProductRow(p, rowNum) {
    const id        = Number(p.product_id  || 0);
    const name      = p.product_name       || "";
    const cat       = p.category_name      || "-";
    const subcat    = p.subcategory_name   || "";
    const sup       = p.supplier_name      || "-";
    const sku       = p.sku                || "";
    const qty       = Number(p.quantity    || 0);
    const price     = Number(p.price       || 0);
    const boxPrice  = p.box_price   ? Number(p.box_price)  : null;
    const casePrice = p.case_price  ? Number(p.case_price) : null;
    const salePrice = p.sale_price      ? Number(p.sale_price)      : null;
    const boxSale   = p.box_sale_price  ? Number(p.box_sale_price)  : null;
    const caseSale  = p.case_sale_price ? Number(p.case_sale_price) : null;
    const vatable   = !!(p.vatable);
    const reorder   = Number(p.reorder_level || 0);
    const status    = p.status || "inactive";
    const photo     = p.photo || FALLBACK_IMG;
    const isActive  = status === "active";
    const isLow     = reorder > 0 && qty <= reorder;

    // Discounts
    const discountParts = [];
    if (salePrice !== null) discountParts.push(`Pc: ${fmt2(salePrice)}%`);
    if (boxSale   !== null) discountParts.push(`Box: ${fmt2(boxSale)}%`);
    if (caseSale  !== null) discountParts.push(`Case: ${fmt2(caseSale)}%`);
    const discountHtml = discountParts.length
      ? discountParts.map(d => `<span class="discount-chip">${esc(d)}</span>`).join("")
      : `<span style="color:var(--c-text-3)">—</span>`;

    // Box/case prices
    const boxCaseHtml =
      (boxPrice  !== null ? `<div class="price-meta">Box ₱${fmt2(boxPrice)}</div>`  : "") +
      (casePrice !== null ? `<div class="price-meta">Case ₱${fmt2(casePrice)}</div>` : "") ||
      `<span style="color:var(--c-text-3)">—</span>`;

    const tr = document.createElement("tr");
    tr.id    = `productRow${id}`;
    tr.innerHTML = `
      <td class="num">${rowNum !== undefined ? rowNum : "–"}</td>

      <td>
        <div class="product-cell">
          <img src="${esc(photo)}" alt="${esc(name)}" class="product-thumb"
               onerror="this.src='${FALLBACK_IMG}';this.onerror=null;">
          <div>
            <div class="product-name">${esc(name)}</div>
            ${sku ? `<div class="product-sku">${esc(sku)}</div>` : ""}
          </div>
        </div>
      </td>

      <td>
        <div style="font-size:13px;">${esc(cat)}</div>
        ${subcat ? `<div style="font-size:11px;color:var(--c-text-3);">${esc(subcat)}</div>` : ""}
      </td>

      <td style="color:var(--c-text-2);font-size:13px;">${esc(sup)}</td>

      <td class="qty-cell${isLow ? " qty-low" : ""}" id="productQty${id}">
        ${fmtInt(qty)}
        ${isLow ? `<span class="badge badge-low ms-1" style="font-size:10px;">Low</span>` : ""}
      </td>

      <td><span class="price-primary">₱${fmt2(price)}</span></td>

      <td>${boxCaseHtml}</td>

      <td><div style="display:flex;flex-direction:column;gap:2px;">${discountHtml}</div></td>

      <td>
        <span class="badge ${vatable ? "badge-vat" : "badge-novat"}">
          ${vatable ? "VAT" : "No VAT"}
        </span>
      </td>

      <td style="font-family:var(--ff-mono);font-size:12px;color:var(--c-text-3);">${reorder}</td>

      <td>
        <span class="badge ${isActive ? "badge-active" : "badge-inactive"}"
              id="productStatus${id}">
          <i class="bi ${isActive ? "bi-circle-fill" : "bi-circle"}"
             style="font-size:7px;" aria-hidden="true"></i>
          ${isActive ? "Active" : "Inactive"}
        </span>
      </td>

      <td>
        <div style="display:flex;gap:5px;justify-content:center;">
          <button type="button"
            class="btn-icon-sm edit editProductBtn"
            title="Edit product" aria-label="Edit ${esc(name)}"
            data-id="${id}"
            data-name="${esc(name)}"
            data-category="${esc(String(p.category_id || ""))}"
            data-subcategory="${esc(String(p.subcategory_id || ""))}"
            data-supplier="${esc(String(p.supplier_id || ""))}"
            data-sku="${esc(sku)}"
            data-price="${esc(String(price))}"
            data-box_price="${esc(String(p.box_price ?? ""))}"
            data-case_price="${esc(String(p.case_price ?? ""))}"
            data-sale_price="${esc(String(p.sale_price ?? ""))}"
            data-box_sale_price="${esc(String(p.box_sale_price ?? ""))}"
            data-case_sale_price="${esc(String(p.case_sale_price ?? ""))}"
            data-vatable="${esc(String(p.vatable ?? 0))}"
            data-pieces_per_box="${esc(String(p.pieces_per_box ?? 1))}"
            data-boxes_per_case="${esc(String(p.boxes_per_case ?? 1))}"
            data-reorder="${reorder}"
            data-photo="${esc(photo)}"
            data-bs-toggle="modal" data-bs-target="#editProductModal">
            <i class="bi bi-pencil" aria-hidden="true"></i>
          </button>

          <button type="button"
            class="btn-icon-sm restock restock-btn"
            title="Restock" aria-label="Restock ${esc(name)}"
            data-id="${id}" data-name="${esc(name)}">
            <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i>
          </button>

          <button type="button"
            class="btn-icon-sm stockout stockout-btn"
            title="Stock out" aria-label="Stock out ${esc(name)}"
            data-id="${id}" data-name="${esc(name)}">
            <i class="bi bi-box-arrow-up" aria-hidden="true"></i>
          </button>

          <button type="button"
            class="btn-icon-sm ${isActive ? "deactivate" : "activate"} toggleProductStatusBtn"
            title="${isActive ? "Deactivate" : "Reactivate"}"
            aria-label="${isActive ? "Deactivate" : "Reactivate"} ${esc(name)}"
            data-id="${id}" data-name="${esc(name)}" data-status="${esc(status)}">
            <i class="bi ${isActive ? "bi-slash-circle" : "bi-check-circle"}"
               aria-hidden="true"></i>
          </button>
        </div>
      </td>`;
    return tr;
  }

  // ── DOM patching helpers ──────────────────────────────────────────────────
  function patchRow(product) {
    const existing = document.getElementById(`productRow${product.product_id}`);
    if (!existing) return false;
    const numCell  = existing.querySelector("td.num");
    const rowNum   = numCell ? parseInt(numCell.textContent, 10) : undefined;
    const newRow   = buildProductRow(product, rowNum);
    existing.replaceWith(newRow);
    flashRow(`productRow${product.product_id}`, "var(--c-accent-bg)");
    return true;
  }

  function prependRow(product) {
    if (!el.tbody) return;
    const emptyRow = el.tbody.querySelector("td[colspan]");
    if (emptyRow) emptyRow.closest("tr").remove();
    const newRow = buildProductRow(product, "–");
    el.tbody.insertBefore(newRow, el.tbody.firstChild);
    recalcRowNumbers();
    flashRow(`productRow${product.product_id}`, "var(--c-green-bg)");
  }

  function patchQuantityCell(productId, newQty, reorderLevel) {
    const cell  = document.getElementById(`productQty${productId}`);
    const row   = document.getElementById(`productRow${productId}`);
    if (!cell) return;
    const isLow = newQty <= reorderLevel && newQty >= 0;
    cell.className = `qty-cell${isLow ? " qty-low" : ""}`;
    cell.innerHTML = `${fmtInt(newQty)}${isLow ? `<span class="badge badge-low ms-1" style="font-size:10px;">Low</span>` : ""}`;
    if (row) {
      flashRow(row.id, isLow ? "var(--c-amber-bg)" : "var(--c-teal-bg)");
    }
    // Update the restock/stockout btn data-* attrs so next open reflects new qty
    row?.querySelectorAll(".restock-btn, .stockout-btn").forEach(btn => {
      btn.dataset.qty = String(newQty);
    });
  }

  function flashRow(rowId, color) {
    requestAnimationFrame(() => {
      const row = document.getElementById(rowId);
      if (!row) return;
      row.style.transition = "background-color 0.35s";
      row.style.backgroundColor = color;
      setTimeout(() => { row.style.backgroundColor = ""; }, 1300);
    });
  }

  // ── Photo preview ─────────────────────────────────────────────────────────
  function bindPhotoPreview(inputSel, previewSel) {
    const input   = document.querySelector(inputSel);
    const preview = document.querySelector(previewSel);
    if (!input || !preview) return;
    input.addEventListener("change", e => {
      const file = e.target.files?.[0];
      if (file) {
        const reader = new FileReader();
        reader.onload = ev => { preview.src = ev.target.result; };
        reader.readAsDataURL(file);
      } else {
        preview.src = FALLBACK_IMG;
      }
    });
  }

  bindPhotoPreview('#addProductForm  input[name="photo"]', "#addProductPhotoPreview");
  bindPhotoPreview('#editProductForm input[name="photo"]', "#editProductPhotoPreview");

  // ── Subcategory filter helpers ────────────────────────────────────────────
  function cacheSubcatOptions(select) {
    if (!select) return [];
    return Array.from(select.options).map(o => ({
      value:      o.value,
      label:      o.textContent,
      categoryId: o.dataset.categoryId || "",
    }));
  }

  const addSubcatOptions  = cacheSubcatOptions(el.addSubcatSelect);
  const editSubcatOptions = cacheSubcatOptions(el.editSubcatSelect);

  function populateSubcatSelect(select, options, categoryId, selectedValue = "") {
    if (!select) return;
    const catStr = String(categoryId || "");
    const selStr = String(selectedValue || "");
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

  function applyUnitRules(form, catSelect) {
    if (!form || !catSelect) return;
    const bev = isBeverage(catSelect);
    form.querySelectorAll(".product-box-field").forEach(wrap => {
      wrap.classList.toggle("d-none", bev);
      wrap.querySelectorAll("input, select").forEach(f => {
        f.disabled = bev;
        if (bev) f.value = "";
      });
    });
    form.querySelectorAll('.product-unit-note[data-unit-note="beverage"]').forEach(n => {
      n.classList.toggle("d-none", !bev);
    });
  }

  // Initial population
  populateSubcatSelect(el.addSubcatSelect,  addSubcatOptions,  el.addCatSelect?.value,  el.addSubcatSelect?.value);
  populateSubcatSelect(el.editSubcatSelect, editSubcatOptions, el.editCatSelect?.value, el.editSubcatSelect?.value);
  applyUnitRules(el.addForm,  el.addCatSelect);
  applyUnitRules(el.editForm, el.editCatSelect);

  el.addCatSelect?.addEventListener("change", () => {
    populateSubcatSelect(el.addSubcatSelect,  addSubcatOptions,  el.addCatSelect.value,  "");
    applyUnitRules(el.addForm, el.addCatSelect);
  });
  el.editCatSelect?.addEventListener("change", () => {
    populateSubcatSelect(el.editSubcatSelect, editSubcatOptions, el.editCatSelect.value, el.editSubcatSelect?.value || "");
    applyUnitRules(el.editForm, el.editCatSelect);
  });

  // ── Fill edit modal from button data-* attrs ──────────────────────────────
  function fillEditModal(btn) {
    if (!btn || !el.editForm) return;
    const set = (id, v) => { const f = document.getElementById(id); if (f) f.value = v ?? ""; };
    const d   = btn.dataset;

    set("editProductId",         d.id);
    set("editProductName",       d.name);
    set("editProductSku",        d.sku);
    set("editProductCategory",   d.category);
    populateSubcatSelect(el.editSubcatSelect, editSubcatOptions, d.category, d.subcategory);
    set("editProductSubcategory",d.subcategory);
    set("editProductSupplierSelect", d.supplier);
    set("editProductPrice",      d.price);
    set("editProductPiecesPerBox",   d.pieces_per_box);
    set("editProductBoxPrice",       d.box_price);
    set("editProductBoxesPerCase",   d.boxes_per_case);
    set("editProductCasePrice",      d.case_price);
    set("editProductSalePrice",      d.sale_price);
    set("editProductBoxSalePrice",   d.box_sale_price);
    set("editProductCaseSalePrice",  d.case_sale_price);
    set("editProductVatable",        d.vatable);
    set("editProductReorderLevel",   d.reorder);

    const preview = document.getElementById("editProductPhotoPreview");
    if (preview) preview.src = d.photo || FALLBACK_IMG;
    applyUnitRules(el.editForm, el.editCatSelect);
  }

  // ── ADD PRODUCT ───────────────────────────────────────────────────────────
  el.addForm?.addEventListener("submit", async e => {
    e.preventDefault();
    const btn = el.addForm.querySelector("button[type='submit']");
    const fd  = new FormData(el.addForm);
    fd.append("add_product", "1");
    setLoading(btn, true);
    try {
      const data = await post(fd);
      if (!data.success) {
        showMessage("danger", data.error || "Failed to add product.");
        showToast(data.error || "Failed to add product.", "error");
        return;
      }
      hideModal("addProductModal");
      el.addForm.reset();
      document.getElementById("addProductPhotoPreview").src = FALLBACK_IMG;
      prependRow(data.product);
      adjustStats({ total: +1, active: data.product?.status === "active" ? +1 : 0 });
      showToast(data.message || `${data.product?.product_name} added.`, "success");
      showMessage("success", data.message);
      sendWS(data.event, data.type);
    } catch (err) {
      console.error(err);
      showMessage("danger", "Something went wrong. Please try again.");
      showToast("Something went wrong.", "error");
    } finally {
      setLoading(btn, false);
    }
  });

  // ── EDIT PRODUCT ──────────────────────────────────────────────────────────
  el.editForm?.addEventListener("submit", async e => {
    e.preventDefault();
    const btn = el.editForm.querySelector("button[type='submit']");
    const fd  = new FormData(el.editForm);
    fd.append("edit_product", "1");
    setLoading(btn, true);
    try {
      const data = await post(fd);
      if (!data.success) {
        showMessage("danger", data.error || "Failed to update product.");
        showToast(data.error || "Failed to update product.", "error");
        return;
      }
      hideModal("editProductModal");
      el.editForm.reset();
      patchRow(data.product);
      showToast(data.message || `${data.product?.product_name} updated.`, "success");
      showMessage("success", data.message);
      sendWS(data.event, data.type);
    } catch (err) {
      console.error(err);
      showMessage("danger", "Something went wrong. Please try again.");
      showToast("Something went wrong.", "error");
    } finally {
      setLoading(btn, false);
    }
  });

  // ── RESTOCK ───────────────────────────────────────────────────────────────
  el.restockForm?.addEventListener("submit", async e => {
    e.preventDefault();
    const btn   = el.restockForm.querySelector("button[type='submit']");
    const id    = document.getElementById("restockProductId")?.value || "";
    const qty   = el.restockForm.querySelector('input[name="quantity"]')?.value || "";
    const adjType = el.restockForm.querySelector('select[name="adjustment_type"]')?.value || "";

    if (!id || parseInt(id, 10) <= 0)  { showToast("Invalid product ID.", "error"); return; }
    if (!qty || parseInt(qty, 10) <= 0) { showToast("Quantity must be greater than zero.", "error"); return; }
    if (!adjType)                        { showToast("Select an adjustment type.", "error"); return; }

    const fd = new FormData(el.restockForm);
    fd.set("product_id", id);
    fd.append("restock_product", "1");
    setLoading(btn, true);

    try {
      const data = await post(fd);
      if (!data.success) {
        showToast(data.error || "Restock failed.", "error");
        return;
      }
      hideModal("restockModal");
      el.restockForm.reset();

      // Patch just the quantity cell — no full row rebuild needed
      const row     = document.getElementById(`productRow${data.product_id}`);
      const reorder = parseInt(row?.querySelector(".editProductBtn")?.dataset?.reorder || "5", 10);
      patchQuantityCell(data.product_id, data.new_quantity, reorder);

      showToast(data.message || "Restocked.", "success");
      showMessage("success", data.message);
      sendWS(data.event, data.type);
    } catch (err) {
      console.error(err);
      showToast("Something went wrong.", "error");
    } finally {
      setLoading(btn, false);
    }
  });

  // ── STOCK OUT ─────────────────────────────────────────────────────────────
  el.stockOutForm?.addEventListener("submit", async e => {
    e.preventDefault();
    const btn    = el.stockOutForm.querySelector("button[type='submit']");
    const id     = document.getElementById("stockOutProductId")?.value || "";
    const qty    = el.stockOutForm.querySelector('input[name="quantity"]')?.value || "";
    const adjSel = el.stockOutForm.querySelector('select[name="adjustment_type"]')?.value || "";
    const custom = document.getElementById("customStockOutReason")?.value.trim() || "";
    const notes  = el.stockOutForm.querySelector('textarea[name="notes"]')?.value.trim() || "";

    if (!id || parseInt(id, 10) <= 0)  { showToast("Invalid product ID.", "error"); return; }
    if (!qty || parseInt(qty, 10) <= 0) { showToast("Quantity must be greater than zero.", "error"); return; }
    const finalReason = adjSel === "Other" && custom !== "" ? custom : adjSel;
    if (!finalReason)                   { showToast("Please provide a reason.", "error"); return; }
    if (finalReason.length > 500)       { showToast("Reason must be 500 characters or fewer.", "error"); return; }
    if (notes.length > 500)             { showToast("Notes must be 500 characters or fewer.", "error"); return; }

    const fd = new FormData(el.stockOutForm);
    fd.set("product_id",      id);
    fd.set("reason",          finalReason);
    fd.set("adjustment_type", adjSel);
    fd.append("stockout_product", "1");
    setLoading(btn, true);

    try {
      const data = await post(fd);
      if (!data.success) {
        showToast(data.error || "Stock out failed.", "error");
        return;
      }
      hideModal("stockOutModal");
      el.stockOutForm.reset();
      if (document.getElementById("customStockOutReason")) {
        document.getElementById("customStockOutReason").value = "";
      }

      const row     = document.getElementById(`productRow${data.product_id}`);
      const reorder = parseInt(row?.querySelector(".editProductBtn")?.dataset?.reorder || "5", 10);
      patchQuantityCell(data.product_id, data.new_quantity, reorder);

      showToast(data.message || "Stock out recorded.", "success");
      showMessage("success", data.message);
      sendWS(data.event, data.type);
    } catch (err) {
      console.error(err);
      showToast("Something went wrong.", "error");
    } finally {
      setLoading(btn, false);
    }
  });

  // ── Table delegation (edit / toggle / restock / stockout buttons) ─────────
  el.tbody?.addEventListener("click", e => {
    const editBtn    = e.target.closest(".editProductBtn");
    const toggleBtn  = e.target.closest(".toggleProductStatusBtn");
    const restockBtn = e.target.closest(".restock-btn");
    const stockOutBtn = e.target.closest(".stockout-btn");

    // Edit — just fill the form; data-bs-toggle on the button opens the modal
    if (editBtn) {
      fillEditModal(editBtn);
      return;
    }

    // Toggle status
    if (toggleBtn) {
      handleToggle(toggleBtn);
      return;
    }

    // Restock — populate hidden fields and open modal
    if (restockBtn) {
      const pid  = restockBtn.dataset.id;
      const pname = restockBtn.dataset.name || "";
      if (!pid || parseInt(pid, 10) <= 0) { showToast("Invalid product ID.", "error"); return; }
      const hidId  = document.getElementById("restockProductId");
      const hidName = document.getElementById("restockProductName");
      if (hidId)   hidId.value   = pid;
      if (hidName) hidName.value = pname;
      bootstrap.Modal.getOrCreateInstance(document.getElementById("restockModal")).show();
      return;
    }

    // Stock out — populate hidden fields and open modal
    if (stockOutBtn) {
      const pid   = stockOutBtn.dataset.id;
      const pname = stockOutBtn.dataset.name || "";
      if (!pid || parseInt(pid, 10) <= 0) { showToast("Invalid product ID.", "error"); return; }
      const hidId   = document.getElementById("stockOutProductId");
      const hidName = document.getElementById("stockOutProductName");
      if (hidId)   hidId.value   = pid;
      if (hidName) hidName.value = pname;
      bootstrap.Modal.getOrCreateInstance(document.getElementById("stockOutModal")).show();
      return;
    }
  });

  // ── Toggle status ─────────────────────────────────────────────────────────
  async function handleToggle(btn) {
    const id          = btn.dataset.id;
    const productName = btn.dataset.name || "this product";
    const curStatus   = btn.dataset.status || "";
    const isActive    = curStatus === "active";
    const nextAction  = isActive ? "Deactivate" : "Activate";

    const confirmed = await Swal.fire({
      title: `${nextAction} ${productName}?`,
      html:  `<p class="mb-1">You're about to <strong>${nextAction.toLowerCase()}</strong> this product.</p>
              <small class="text-muted">You can change it back any time.</small>`,
      icon: "warning",
      showCancelButton: true,
      confirmButtonText:  `Yes, ${nextAction.toLowerCase()}`,
      cancelButtonText:   "Cancel",
      cancelButtonColor:  "#d33",
      confirmButtonColor: isActive ? "#3085d6" : "#198754",
    });
    if (!confirmed.isConfirmed) return;

    // Optimistic flip
    const statusBadge = document.getElementById(`productStatus${id}`);
    const newStatus   = isActive ? "inactive" : "active";
    if (statusBadge) {
      statusBadge.className = `badge ${newStatus === "active" ? "badge-active" : "badge-inactive"}`;
      statusBadge.innerHTML = `<i class="bi ${newStatus === "active" ? "bi-circle-fill" : "bi-circle"}" style="font-size:7px;" aria-hidden="true"></i>${newStatus === "active" ? "Active" : "Inactive"}`;
    }
    btn.disabled = true;

    try {
      const fd = new FormData();
      fd.append("toggle_id", id);
      const data = await post(fd);

      if (!data.success) {
        // Revert optimistic change
        if (statusBadge) {
          statusBadge.className = `badge ${isActive ? "badge-active" : "badge-inactive"}`;
          statusBadge.innerHTML = `<i class="bi ${isActive ? "bi-circle-fill" : "bi-circle"}" style="font-size:7px;" aria-hidden="true"></i>${isActive ? "Active" : "Inactive"}`;
        }
        showToast(data.error || "Action failed.", "error");
        return;
      }

      // Full row patch with server-confirmed data
      patchRow(data.product);
      const deltaActive   = data.new_status === "active"   ? +1 : -1;
      const deltaInactive = data.new_status === "inactive" ? +1 : -1;
      adjustStats({ active: deltaActive, inactive: deltaInactive });
      showToast(data.message || `${productName} status updated.`, "success");
      showMessage("success", data.message);
      sendWS(data.event, data.type);
    } catch (err) {
      console.error(err);
      // Revert on network failure
      if (statusBadge) {
        statusBadge.className = `badge ${isActive ? "badge-active" : "badge-inactive"}`;
        statusBadge.innerHTML = `<i class="bi ${isActive ? "bi-circle-fill" : "bi-circle"}" style="font-size:7px;" aria-hidden="true"></i>${isActive ? "Active" : "Inactive"}`;
      }
      showToast("Something went wrong.", "error");
    } finally {
      btn.disabled = false;
    }
  }

  // ── ADD SUPPLIER ──────────────────────────────────────────────────────────
  el.supplierModalEl?.addEventListener("shown.bs.modal", () => {
    if (el.supplierMessage) el.supplierMessage.innerHTML = "";
    document.getElementById("supplier_name")?.focus();
  });
  el.supplierModalEl?.addEventListener("hidden.bs.modal", () => {
    if (el.supplierMessage) el.supplierMessage.innerHTML = "";
    el.supplierForm?.reset();
    setLoading(el.saveSupplierBtn, false, '<i class="bi bi-floppy me-1" aria-hidden="true"></i>Add supplier');
  });

  el.supplierForm?.addEventListener("submit", async e => {
    e.preventDefault();
    setLoading(el.saveSupplierBtn, true);
    try {
      const data = await post(new FormData(el.supplierForm));
      if (!data.success) {
        if (el.supplierMessage) el.supplierMessage.innerHTML = `<div class="alert alert-danger mb-0">${esc(data.error || "Failed.")}</div>`;
        return;
      }
      const sid   = String(data.supplier_id || "");
      const sname = data.supplier_name || "";
      if (sid && sname) {
        document.querySelectorAll('select[name="supplier_id"]').forEach(sel => {
          if (!Array.from(sel.options).some(o => o.value === sid)) {
            sel.add(new Option(sname, sid, true, true));
          } else {
            sel.value = sid;
          }
        });
      }
      if (el.supplierMessage) el.supplierMessage.innerHTML = `<div class="alert alert-success mb-0">${esc(data.message || "Supplier added.")}</div>`;
      showToast(data.message || "Supplier added.", "success");
      setTimeout(() => hideModal("supplierModal"), 900);
    } catch {
      if (el.supplierMessage) el.supplierMessage.innerHTML = `<div class="alert alert-danger mb-0">Server error.</div>`;
    } finally {
      setLoading(el.saveSupplierBtn, false, '<i class="bi bi-floppy me-1" aria-hidden="true"></i>Add supplier');
    }
  });

  // ── Modal cleanup ─────────────────────────────────────────────────────────
  document.getElementById("addProductModal")?.addEventListener("hidden.bs.modal", () => {
    el.addForm?.reset();
    const prev = document.getElementById("addProductPhotoPreview");
    if (prev) prev.src = FALLBACK_IMG;
    el.addCatSelect?.dispatchEvent(new Event("change"));
  });
  document.getElementById("editProductModal")?.addEventListener("hidden.bs.modal", () => {
    el.editForm?.reset();
    const prev = document.getElementById("editProductPhotoPreview");
    if (prev) prev.src = FALLBACK_IMG;
    el.editCatSelect?.dispatchEvent(new Event("change"));
  });

  // ── Search debounce ───────────────────────────────────────────────────────
  let _searchTimer;
  if (el.searchInput && el.filterForm) {
    el.searchInput.addEventListener("input", () => {
      clearTimeout(_searchTimer);
      _searchTimer = setTimeout(() => el.filterForm.submit(), 420);
    });
  }

  // ── Flush queued toast ────────────────────────────────────────────────────
  flushQueuedToast();

});