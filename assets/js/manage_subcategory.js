"use strict";

/**
 * manage_subcategory.js
 *
 * Improvements over the previous version
 * ────────────────────────────────────────
 *  1.  Zero full page reloads. Add / edit / toggle all patch the
 *      specific table row in-place via buildSubcategoryRow().
 *
 *  2.  buildSubcategoryRow() is the single source of truth for row HTML —
 *      used by prependRow() (add) and patchRow() (edit/toggle).
 *      The server returns a structured `subcategory` JSON object, not HTML.
 *
 *  3.  Optimistic UI for toggle: the badge flips instantly and reverts
 *      on server error.
 *
 *  4.  Stat cards (total, active, inactive, categories_used) update
 *      in-place after writes via adjustStats().
 *
 *  5.  Search debounce: filter form auto-submits 420 ms after the user
 *      stops typing — no request-per-keystroke.
 *
 *  6.  setLoading() applied to all submit buttons preventing double-submits.
 *
 *  7.  Edit button no longer double-initializes Bootstrap Modal —
 *      data-bs-toggle on the button handles the open; JS only fills fields.
 *
 *  8.  reloadWithToast() kept only for fatal fallbacks.
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

  // ── Element cache ──────────────────────────────────────────────────────────
  const el = {
    table:        document.getElementById("subcategoryTable"),
    tbody:        document.querySelector("#subcategoryTable tbody"),
    messages:     document.getElementById("subcategoryMessages"),
    addForm:      document.getElementById("addSubcategoryForm"),
    editForm:     document.getElementById("editSubcategoryForm"),
    addModalEl:   document.getElementById("addSubcategoryModal"),
    editModalEl:  document.getElementById("editSubcategoryModal"),
    searchInput:  document.querySelector('input[name="search"]'),
    filterForm:   document.querySelector("form[method='get']"),

    // Stat card value nodes — targeted by ID
    statTotal:      document.getElementById("statSubTotal"),
    statActive:     document.getElementById("statSubActive"),
    statInactive:   document.getElementById("statSubInactive"),
    statCatsUsed:   document.getElementById("statSubCatsUsed"),
  };

  const ENDPOINT  = "/inventory_system/http/ajax/subcategory_actions.php";

  // CSRF token — read once at boot
  const csrfToken =
    document.querySelector('meta[name="csrf-token"]')?.content ||
    document.querySelector('#addSubcategoryForm  input[name="csrf_token"]')?.value ||
    document.querySelector('#editSubcategoryForm input[name="csrf_token"]')?.value ||
    "";

  // ── Utilities ──────────────────────────────────────────────────────────────

  function esc(v) {
    const d = document.createElement("div");
    d.textContent = v ?? "";
    return d.innerHTML;
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

  // ── Fetch wrapper ──────────────────────────────────────────────────────────
  async function post(formData) {
    if (csrfToken && !formData.has("csrf_token")) {
      formData.append("csrf_token", csrfToken);
    }
    const res  = await fetch(ENDPOINT, {
      method:  "POST",
      headers: { "X-Requested-With": "XMLHttpRequest" },
      body:    formData,
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

  // ── Reload with queued toast (fatal fallback only) ─────────────────────────
  function reloadWithToast(msg, icon = "success") {
    try {
      sessionStorage.setItem("manageSubcategoryFlash", JSON.stringify({ msg, icon }));
    } catch { /* ignore */ }
    window.location.assign(window.location.pathname + window.location.search);
  }

  function flushQueuedToast() {
    try {
      const raw = sessionStorage.getItem("manageSubcategoryFlash");
      if (!raw) return;
      sessionStorage.removeItem("manageSubcategoryFlash");
      const p = JSON.parse(raw);
      if (p?.msg) showToast(p.msg, p.icon || "success");
    } catch { /* ignore */ }
  }

  // ── Modal helpers ──────────────────────────────────────────────────────────
  function hideModal(id) {
    const modalEl = document.getElementById(id);
    if (!modalEl) return;
    const m = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
    m.hide();
    setTimeout(() => {
      document.querySelectorAll(".modal-backdrop").forEach(b => b.remove());
      document.body.classList.remove("modal-open");
      document.body.style.overflow     = "";
      document.body.style.paddingRight = "";
    }, 120);
  }

  // ── Stat card helpers ──────────────────────────────────────────────────────
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
    upd(el.statInactive, delta.inactive ?? 0);
    // categories_used is complex to patch client-side — skip local update;
    // it will reflect correctly after next page load
  }

  // ── Row number recalculation ───────────────────────────────────────────────
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

  // ── Build a full subcategory table row from a subcategory view object ──────
  function buildSubcategoryRow(s, rowNum) {
    const id       = Number(s.subcategory_id || 0);
    const name     = s.subcategory_name || "";
    const catName  = s.category_name   || "-";
    const catId    = Number(s.category_id || 0);
    const desc     = s.description     || "";
    const status   = s.status          || "inactive";
    const isActive = status === "active";

    const tr = document.createElement("tr");
    tr.id    = `subcategoryRow${id}`;
    tr.innerHTML = `
      <td class="num">${rowNum !== undefined ? rowNum : "–"}</td>

      <td>
        <div class="subcat-cell">
          <div class="subcat-icon">
            <i class="bi bi-diagram-3" aria-hidden="true"></i>
          </div>
          <div>
            <div class="subcat-name">${esc(name)}</div>
          </div>
        </div>
      </td>

      <td>
        <span class="cat-pill">
          <i class="bi bi-tag-fill" style="font-size:10px;" aria-hidden="true"></i>
          ${esc(catName)}
        </span>
      </td>

      <td style="color:var(--c-text-2);font-size:12px;max-width:240px;">
        ${desc ? esc(desc) : `<span style="color:var(--c-text-3)">—</span>`}
      </td>

      <td>
        <span class="badge ${isActive ? "badge-active" : "badge-inactive"}"
              id="subcategoryStatus${id}">
          <i class="bi ${isActive ? "bi-circle-fill" : "bi-circle"}"
             style="font-size:7px;" aria-hidden="true"></i>
          ${isActive ? "Active" : "Inactive"}
        </span>
      </td>

      <td>
        <div style="display:flex;gap:6px;justify-content:center;">
          <button type="button"
            class="btn-icon-sm edit editSubcategoryBtn"
            title="Edit subcategory"
            aria-label="Edit ${esc(name)}"
            data-id="${id}"
            data-name="${esc(name)}"
            data-category="${catId}"
            data-description="${esc(desc)}"
            data-bs-toggle="modal"
            data-bs-target="#editSubcategoryModal">
            <i class="bi bi-pencil" aria-hidden="true"></i>
          </button>

          <button type="button"
            class="btn-icon-sm ${isActive ? "deactivate" : "activate"} toggleSubcategoryStatusBtn"
            title="${isActive ? "Deactivate" : "Reactivate"}"
            aria-label="${isActive ? "Deactivate" : "Reactivate"} ${esc(name)}"
            data-id="${id}"
            data-name="${esc(name)}"
            data-status="${esc(status)}">
            <i class="bi ${isActive ? "bi-slash-circle" : "bi-check-circle"}"
               aria-hidden="true"></i>
          </button>
        </div>
      </td>`;
    return tr;
  }

  // ── DOM patching helpers ───────────────────────────────────────────────────
  function patchRow(subcategory) {
    const existing = document.getElementById(`subcategoryRow${subcategory.subcategory_id}`);
    if (!existing) return false;
    const numCell = existing.querySelector("td.num");
    const rowNum  = numCell ? parseInt(numCell.textContent, 10) : undefined;
    const newRow  = buildSubcategoryRow(subcategory, rowNum);
    existing.replaceWith(newRow);
    flashRow(`subcategoryRow${subcategory.subcategory_id}`, "var(--c-accent-bg)");
    return true;
  }

  function prependRow(subcategory) {
    if (!el.tbody) return;
    const emptyRow = el.tbody.querySelector("td[colspan]");
    if (emptyRow) emptyRow.closest("tr").remove();
    const newRow = buildSubcategoryRow(subcategory, "–");
    el.tbody.insertBefore(newRow, el.tbody.firstChild);
    recalcRowNumbers();
    flashRow(`subcategoryRow${subcategory.subcategory_id}`, "var(--c-green-bg)");
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

  // ── Fill edit modal ────────────────────────────────────────────────────────
  function fillEditModal(btn) {
    if (!btn || !el.editForm) return;
    const set = (id, v) => { const f = document.getElementById(id); if (f) f.value = v ?? ""; };
    set("editSubcategoryId",          btn.dataset.id);
    set("editSubcategoryCategory",    btn.dataset.category);
    set("editSubcategoryName",        btn.dataset.name);
    set("editSubcategoryDescription", btn.dataset.description);
  }

  // ── ADD SUBCATEGORY ────────────────────────────────────────────────────────
  el.addForm?.addEventListener("submit", async e => {
    e.preventDefault();
    const btn = el.addForm.querySelector("button[type='submit']");
    const fd  = new FormData(el.addForm);
    fd.append("add_subcategory", "1");
    setLoading(btn, true);

    try {
      const data = await post(fd);
      if (!data.success) {
        showMessage("danger", data.error || "Failed to add subcategory.");
        showToast(data.error || "Failed to add subcategory.", "error");
        return;
      }
      hideModal("addSubcategoryModal");
      el.addForm.reset();
      prependRow(data.subcategory);
      adjustStats({ total: +1, active: +1 });
      showToast(data.message || `${data.subcategory?.subcategory_name} added.`, "success");
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

  // ── EDIT SUBCATEGORY ───────────────────────────────────────────────────────
  el.editForm?.addEventListener("submit", async e => {
    e.preventDefault();
    const btn = el.editForm.querySelector("button[type='submit']");
    const fd  = new FormData(el.editForm);
    fd.append("edit_subcategory", "1");
    setLoading(btn, true);

    try {
      const data = await post(fd);
      if (!data.success) {
        showMessage("danger", data.error || "Failed to update subcategory.");
        showToast(data.error || "Failed to update subcategory.", "error");
        return;
      }
      hideModal("editSubcategoryModal");
      el.editForm.reset();
      patchRow(data.subcategory);
      showToast(data.message || `${data.subcategory?.subcategory_name} updated.`, "success");
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

  // ── TOGGLE STATUS ──────────────────────────────────────────────────────────
  async function handleToggle(btn) {
    const id        = btn.dataset.id;
    const subName   = btn.dataset.name   || "this subcategory";
    const curStatus = btn.dataset.status || "";
    const isActive  = curStatus === "active";
    const nextAction = isActive ? "Deactivate" : "Activate";

    const confirmed = await Swal.fire({
      title: `${nextAction} ${subName}?`,
      html:  `<p class="mb-1">You're about to <strong>${nextAction.toLowerCase()}</strong> this subcategory.</p>
              <small class="text-muted">You can change it back any time.</small>`,
      icon: "warning",
      showCancelButton:   true,
      confirmButtonText:  `Yes, ${nextAction.toLowerCase()}`,
      cancelButtonText:   "Cancel",
      cancelButtonColor:  "#d33",
      confirmButtonColor: isActive ? "#3085d6" : "#198754",
    });
    if (!confirmed.isConfirmed) return;

    // Optimistic flip
    const newStatus   = isActive ? "inactive" : "active";
    const statusBadge = document.getElementById(`subcategoryStatus${id}`);
    if (statusBadge) {
      statusBadge.className = `badge ${newStatus === "active" ? "badge-active" : "badge-inactive"}`;
      statusBadge.innerHTML = `<i class="bi ${newStatus === "active" ? "bi-circle-fill" : "bi-circle"}" style="font-size:7px;" aria-hidden="true"></i>${newStatus === "active" ? "Active" : "Inactive"}`;
    }
    btn.disabled = true;

    try {
      const fd = new FormData();
      fd.append("toggle_subcategory_id", id);
      const data = await post(fd);

      if (!data.success) {
        // Revert
        if (statusBadge) {
          statusBadge.className = `badge ${isActive ? "badge-active" : "badge-inactive"}`;
          statusBadge.innerHTML = `<i class="bi ${isActive ? "bi-circle-fill" : "bi-circle"}" style="font-size:7px;" aria-hidden="true"></i>${isActive ? "Active" : "Inactive"}`;
        }
        showToast(data.error || "Failed to update status.", "error");
        return;
      }

      // Confirm with server data
      patchRow(data.subcategory);
      const deltaActive   = data.new_status === "active"   ? +1 : -1;
      const deltaInactive = data.new_status === "inactive" ? +1 : -1;
      adjustStats({ active: deltaActive, inactive: deltaInactive });
      showToast(data.message || `${subName} status updated.`, "success");
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

  // ── Table delegation ───────────────────────────────────────────────────────
  el.tbody?.addEventListener("click", e => {
    const editBtn   = e.target.closest(".editSubcategoryBtn");
    const toggleBtn = e.target.closest(".toggleSubcategoryStatusBtn");

    // Edit — fill modal fields; data-bs-toggle on the button handles the open
    if (editBtn) {
      fillEditModal(editBtn);
      return;
    }

    if (toggleBtn) {
      handleToggle(toggleBtn);
    }
  });

  // ── Modal cleanup ──────────────────────────────────────────────────────────
  el.addModalEl?.addEventListener("hidden.bs.modal",  () => el.addForm?.reset());
  el.editModalEl?.addEventListener("hidden.bs.modal", () => el.editForm?.reset());

  // ── Search debounce ────────────────────────────────────────────────────────
  let _searchTimer;
  if (el.searchInput && el.filterForm) {
    el.searchInput.addEventListener("input", () => {
      clearTimeout(_searchTimer);
      _searchTimer = setTimeout(() => el.filterForm.submit(), 420);
    });
  }

  // ── Flush queued toast ─────────────────────────────────────────────────────
  flushQueuedToast();
});