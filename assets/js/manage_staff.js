/**
 * manage_staff.js
 *
 * Scalability / UX improvements over the original
 * ─────────────────────────────────────────────────
 * 1.  DOM patching  – add / edit / toggle all patch the existing table row in
 *     place.  No full page reload is fired; the browser never re-fetches or
 *     re-renders the entire staff list.
 *
 * 2.  Summary cards  – stat counters in the header cards are incremented /
 *     decremented locally so they stay accurate without a server round-trip.
 *
 * 3.  Search debounce – the filter form auto-submits 420 ms after the user
 *     stops typing so every keystroke does not fire a new HTTP request.
 *
 * 4.  Optimistic UI for toggle – the status badge flips immediately and
 *     reverts if the server returns an error.
 *
 * 5.  buildStaffRow() is the single source of truth for row HTML; it is used
 *     both when patching existing rows and when prepending brand-new ones.
 *
 * 6.  Flash toast is persisted to sessionStorage only for cases that truly
 *     need a reload (e.g. navigating away); normal operations never reload.
 */

"use strict";

document.addEventListener("DOMContentLoaded", () => {

  // ── Toast mixin ─────────────────────────────────────────────────────────
  const Toast = Swal.mixin({
    toast: true,
    position: "top-end",
    showConfirmButton: false,
    timer: 3400,
    timerProgressBar: true,
  });

  function showToast(message, icon = "success") {
    Toast.fire({ icon, title: message });
  }

  // ── Element cache ────────────────────────────────────────────────────────
  const el = {
    table:           document.getElementById("staffTable"),
    tbody:           document.querySelector("#staffTable tbody"),
    messages:        document.getElementById("staffMessages"),
    addForm:         document.getElementById("addStaffForm"),
    editForm:        document.getElementById("editStaffForm"),
    addModalEl:      document.getElementById("addStaffModal"),
    editModalEl:     document.getElementById("editStaffModal"),
    addPhotoInput:   document.getElementById("addStaffPhotoInput"),
    addPhotoPreview: document.getElementById("addStaffPhotoPreview"),
    editPhotoInput:  document.getElementById("editStaffPhotoInput"),
    editPhotoPreview:document.getElementById("editStaffPhotoPreview"),
    searchInput:     document.querySelector('input[name="search"]'),
    filterForm:      document.querySelector("form[method='get']"),

    // Stat card value elements – update in-place after writes
    statTotal:    document.querySelector(".stat-card:nth-child(1) .stat-val"),
    statActive:   document.querySelector(".stat-card:nth-child(2) .stat-val"),
    statInactive: document.querySelector(".stat-card:nth-child(3) .stat-val"),
  };

  const addModal  = el.addModalEl  ? bootstrap.Modal.getOrCreateInstance(el.addModalEl)  : null;
  const editModal = el.editModalEl ? bootstrap.Modal.getOrCreateInstance(el.editModalEl) : null;

  // CSRF token read once at boot
  const csrfToken =
    document.querySelector('meta[name="csrf-token"]')?.content ||
    document.querySelector('#addStaffForm  input[name="csrf_token"]')?.value ||
    document.querySelector('#editStaffForm input[name="csrf_token"]')?.value ||
    "";

  const ENDPOINT      = "/inventory_system/http/ajax/staff_actions.php";
  const FALLBACK_PHOTO = "/inventory_system/assets/img/default-user.png";

  // Avatar colour palette – mirrors the PHP av-N classes
  const AV_COLORS = [
    { bg: "#dbeafe", fg: "#1d4ed8" },
    { bg: "#d1fae5", fg: "#065f46" },
    { bg: "#ede9fe", fg: "#5b21b6" },
    { bg: "#fef3c7", fg: "#92400e" },
    { bg: "#fce7f3", fg: "#9d174d" },
    { bg: "#ccfbf1", fg: "#134e4a" },
    { bg: "#fee2e2", fg: "#991b1b" },
    { bg: "#e0e7ff", fg: "#3730a3" },
  ];

  // ── Utilities ────────────────────────────────────────────────────────────

  function esc(value) {
    const d = document.createElement("div");
    d.textContent = value ?? "";
    return d.innerHTML;
  }

  function initials(first, last) {
    return ((first || "")[0] || "").toUpperCase() +
           ((last  || "")[0] || "").toUpperCase();
  }

  function avatarColor(userId) {
    return AV_COLORS[Math.abs(userId) % AV_COLORS.length];
  }

  function setLoading(btn, loading, defaultHtml) {
    if (!btn) return;
    if (loading) {
      btn.disabled = true;
      btn._orig    = defaultHtml || btn.innerHTML;
      btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Saving…`;
    } else {
      btn.disabled  = false;
      btn.innerHTML = defaultHtml || btn._orig || btn.innerHTML;
    }
  }

  // ── Inline message banner (fades after 4 s) ──────────────────────────────

  let _msgTimer;
  function showMessage(type, message) {
    if (!el.messages) return;
    el.messages.innerHTML = `
      <div class="alert alert-${type} alert-dismissible fade show" role="alert">
        ${esc(message)}
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

  // ── Photo preview ─────────────────────────────────────────────────────────

  function bindPhotoPreview(input, preview) {
    if (!input || !preview) return;
    input.addEventListener("change", (e) => {
      const file = e.target.files?.[0];
      preview.src = file ? URL.createObjectURL(file) : FALLBACK_PHOTO;
    });
  }

  // ── Build a table row element from a staff view object ────────────────────

  function buildStaffRow(staff, rowNumber) {
    const userId      = Number(staff.user_id || 0);
    const firstName   = staff.first_name || "";
    const lastName    = staff.last_name  || "";
    const fullName    = staff.full_name  || `${firstName} ${lastName}`.trim();
    const username    = staff.username   || "";
    const email       = staff.email      || "";
    const role        = staff.role       || "staff";
    const status      = staff.status     || "inactive";
    const photo       = staff.photo      || "";
    const statusLabel = staff.status_label || (status.charAt(0).toUpperCase() + status.slice(1));

    const roleMap = {
      admin:   { cls: "badge-admin",   icon: "bi-shield-check" },
      staff:   { cls: "badge-staff",   icon: "bi-person"       },
      cashier: { cls: "badge-cashier", icon: "bi-cash-stack"   },
    };
    const roleInfo = roleMap[role] || roleMap.staff;

    const isActive       = status === "active";
    const statusBadgeCls = isActive ? "badge-active" : "badge-inactive";
    const statusDot      = isActive ? "bi-circle-fill" : "bi-circle";
    const toggleBtnCls   = isActive ? "deactivate" : "activate";
    const toggleIcon     = isActive ? "bi-slash-circle" : "bi-check-circle";
    const toggleTitle    = isActive ? "Deactivate" : "Reactivate";

    const col        = avatarColor(userId);
    const avatarHtml = photo && photo !== FALLBACK_PHOTO
      ? `<img src="${esc(photo)}" alt="${esc(fullName)}" class="avatar"
             onerror="this.outerHTML='<div class=\\'avatar-initials\\' style=\\'background:${col.bg};color:${col.fg}\\'>${esc(initials(firstName, lastName))}</div>';this.onerror=null;">`
      : `<div class="avatar-initials" style="background:${col.bg};color:${col.fg}">${esc(initials(firstName, lastName))}</div>`;

    const tr = document.createElement("tr");
    tr.id    = `staffRow${userId}`;
    tr.innerHTML = `
      <td class="num">${rowNumber !== undefined ? rowNumber : "–"}</td>
      <td>
        <div class="staff-cell">
          ${avatarHtml}
          <div>
            <div class="staff-name">${esc(fullName)}</div>
            <div class="staff-email">${esc(email)}</div>
          </div>
        </div>
      </td>
      <td style="color:var(--c-text-2);font-family:var(--ff-mono);font-size:12px;">${esc(username)}</td>
      <td>
        <span class="badge ${roleInfo.cls}">
          <i class="bi ${roleInfo.icon}" aria-hidden="true"></i>${esc(role.charAt(0).toUpperCase() + role.slice(1))}
        </span>
      </td>
      <td>
        <span class="badge ${statusBadgeCls}" id="staffStatus${userId}">
          <i class="bi ${statusDot}" style="font-size:7px;" aria-hidden="true"></i>${esc(statusLabel)}
        </span>
      </td>
      <td>
        <div style="display:flex;gap:6px;justify-content:center;">
          <button
            type="button"
            class="btn-icon-sm edit editStaffBtn"
            data-id="${userId}"
            data-username="${esc(username)}"
            data-firstname="${esc(firstName)}"
            data-lastname="${esc(lastName)}"
            data-email="${esc(email)}"
            data-photo="${esc(photo)}"
            data-role="${esc(role)}"
            title="Edit"
            data-bs-toggle="modal"
            data-bs-target="#editStaffModal"
            aria-label="Edit ${esc(fullName)}"
          ><i class="bi bi-pencil" aria-hidden="true"></i></button>

          <button
            type="button"
            class="btn-icon-sm ${toggleBtnCls} toggleStatusBtn"
            data-id="${userId}"
            data-name="${esc(fullName)}"
            data-status="${esc(status)}"
            title="${toggleTitle}"
            aria-label="${toggleTitle} ${esc(fullName)}"
          ><i class="bi ${toggleIcon}" aria-hidden="true"></i></button>
        </div>
      </td>`;
    return tr;
  }

  // ── Stat card helpers ─────────────────────────────────────────────────────

  function readStatInt(el) {
    return parseInt((el?.textContent || "0").replace(/,/g, ""), 10) || 0;
  }

  function adjustStats(delta) {
    // delta: { total, active, inactive }
    const update = (node, diff) => {
      if (!node || diff === 0) return;
      node.textContent = Math.max(0, readStatInt(node) + diff).toLocaleString();
    };
    update(el.statTotal,    delta.total    ?? 0);
    update(el.statActive,   delta.active   ?? 0);
    update(el.statInactive, delta.inactive ?? 0);
  }

  // ── Row number recalculator (after prepend) ───────────────────────────────

  function recalcRowNumbers() {
    if (!el.tbody) return;
    // Only recalc if first row starts at 1 (page 1 with no offset known)
    const paginfoEl = document.querySelector(".pag-info");
    const text      = paginfoEl?.textContent || "";
    const match     = text.match(/Showing\s+([\d,]+)/);
    let   start     = match ? parseInt(match[1].replace(/,/g, ""), 10) : 1;

    el.tbody.querySelectorAll("tr").forEach((row) => {
      const cell = row.querySelector("td.num");
      if (cell) { cell.textContent = String(start++); }
    });
  }

  // ── DOM patching helpers ──────────────────────────────────────────────────

  /**
   * Replace an existing row in-place.
   * Preserves the row's current row-number cell value.
   */
  function patchRow(staff) {
    const existing = document.getElementById(`staffRow${staff.user_id}`);
    if (!existing) return false;

    const rowNumCell = existing.querySelector("td.num");
    const rowNum     = rowNumCell ? parseInt(rowNumCell.textContent, 10) : undefined;

    const newRow = buildStaffRow(staff, rowNum);
    existing.replaceWith(newRow);

    // Brief flash highlight
    requestAnimationFrame(() => {
      const el = document.getElementById(`staffRow${staff.user_id}`);
      if (!el) return;
      el.style.transition = "background-color 0.4s";
      el.style.backgroundColor = "var(--c-accent-bg)";
      setTimeout(() => { el.style.backgroundColor = ""; }, 1200);
    });
    return true;
  }

  /**
   * Prepend a brand-new row at the top of tbody (mirrors server ORDER BY user_id DESC).
   */
  function prependRow(staff) {
    if (!el.tbody) return;

    // Remove empty-state row if present
    const emptyRow = el.tbody.querySelector("td[colspan]");
    if (emptyRow) emptyRow.closest("tr").remove();

    const newRow = buildStaffRow(staff, "–");
    el.tbody.insertBefore(newRow, el.tbody.firstChild);
    recalcRowNumbers();

    requestAnimationFrame(() => {
      const inserted = document.getElementById(`staffRow${staff.user_id}`);
      if (!inserted) return;
      inserted.style.transition = "background-color 0.4s";
      inserted.style.backgroundColor = "var(--c-green-bg)";
      setTimeout(() => { inserted.style.backgroundColor = ""; }, 1400);
    });
  }

  // ── Form helpers ──────────────────────────────────────────────────────────

  function resetAddForm() {
    el.addForm?.reset();
    if (el.addPhotoPreview) el.addPhotoPreview.src = FALLBACK_PHOTO;
  }

  function resetEditForm() {
    el.editForm?.reset();
    if (el.editPhotoPreview) el.editPhotoPreview.src = FALLBACK_PHOTO;
  }

  function fillEditModal(btn) {
    if (!btn || !el.editForm) return;
    const set = (id, val) => { const f = document.getElementById(id); if (f) f.value = val ?? ""; };

    set("editStaffId",  btn.dataset.id);
    set("editFirstname",btn.dataset.firstname);
    set("editLastname", btn.dataset.lastname);
    set("editEmail",    btn.dataset.email);
    set("editUsername", btn.dataset.username);
    set("editPassword", "");

    if (el.editPhotoPreview) {
      el.editPhotoPreview.src = btn.dataset.photo || FALLBACK_PHOTO;
    }

    const roleSelect = document.getElementById("editRole");
    if (roleSelect && btn.dataset.role) roleSelect.value = btn.dataset.role;
  }

  // ── Add staff ─────────────────────────────────────────────────────────────

  async function handleAdd(e) {
    e.preventDefault();
    if (!el.addForm) return;

    const submitBtn = el.addForm.querySelector("button[type='submit']");
    const defaultHtml = submitBtn?.innerHTML;
    const fd = new FormData(el.addForm);
    fd.append("add_staff", "1");

    setLoading(submitBtn, true, defaultHtml);

    try {
      const data = await post(fd);

      if (!data.success) {
        showMessage("danger", data.error || "Failed to add staff.");
        showToast(data.error || "Failed to add staff.", "error");
        return;
      }

      addModal?.hide();
      resetAddForm();
      prependRow(data.staff);
      adjustStats({ total: +1, active: +1 });
      showToast(data.message || `${data.staff.full_name} added.`, "success");
      showMessage("success", data.message || `${data.staff.full_name} is now part of the team.`);

    } catch (err) {
      console.error(err);
      showMessage("danger", "Something went wrong. Please try again.");
      showToast("Something went wrong.", "error");
    } finally {
      setLoading(submitBtn, false, defaultHtml);
    }
  }

  // ── Edit staff ────────────────────────────────────────────────────────────

  async function handleEdit(e) {
    e.preventDefault();
    if (!el.editForm) return;

    const submitBtn  = el.editForm.querySelector("button[type='submit']");
    const defaultHtml = submitBtn?.innerHTML;
    const fd = new FormData(el.editForm);
    fd.append("edit_staff", "1");

    setLoading(submitBtn, true, defaultHtml);

    try {
      const data = await post(fd);

      if (!data.success) {
        showMessage("danger", data.error || "Failed to update staff.");
        showToast(data.error || "Failed to update staff.", "error");
        return;
      }

      editModal?.hide();
      resetEditForm();
      patchRow(data.staff);
      showToast(data.message || `${data.staff.full_name} updated.`, "success");
      showMessage("success", data.message || `${data.staff.full_name}'s details have been saved.`);

    } catch (err) {
      console.error(err);
      showMessage("danger", "Something went wrong. Please try again.");
      showToast("Something went wrong.", "error");
    } finally {
      setLoading(submitBtn, false, defaultHtml);
    }
  }

  // ── Toggle status ─────────────────────────────────────────────────────────

  async function handleToggle(btn) {
    if (!btn) return;

    const staffId      = btn.dataset.id;
    const staffName    = btn.dataset.name    || "this staff member";
    const currentStatus = btn.dataset.status || "";
    const nextAction   = currentStatus === "active" ? "Deactivate" : "Reactivate";

    const confirmed = await Swal.fire({
      title: `${nextAction} ${staffName}?`,
      html:  `<p class="mb-1">You're about to <strong>${nextAction.toLowerCase()}</strong> this account.</p>
              <small class="text-muted">You can change it back any time.</small>`,
      icon: "warning",
      showCancelButton: true,
      confirmButtonText:  `Yes, ${nextAction.toLowerCase()}`,
      cancelButtonText:   "Cancel",
      cancelButtonColor:  "#d33",
      confirmButtonColor: currentStatus === "active" ? "#3085d6" : "#198754",
    });

    if (!confirmed.isConfirmed) return;

    // Optimistic UI – flip the badge immediately
    const row        = document.getElementById(`staffRow${staffId}`);
    const statusBadge = document.getElementById(`staffStatus${staffId}`);
    const newStatusPreview = currentStatus === "active" ? "inactive" : "active";
    if (statusBadge) {
      statusBadge.className = `badge ${newStatusPreview === "active" ? "badge-active" : "badge-inactive"}`;
      statusBadge.innerHTML = `<i class="bi ${newStatusPreview === "active" ? "bi-circle-fill" : "bi-circle"}" style="font-size:7px;" aria-hidden="true"></i>${newStatusPreview.charAt(0).toUpperCase() + newStatusPreview.slice(1)}`;
    }
    btn.disabled = true;

    try {
      const fd = new FormData();
      fd.append("toggle_id", staffId);
      const data = await post(fd);

      if (!data.success) {
        // Revert optimistic change
        patchRow({ ...data.staff, status: currentStatus });
        showMessage("danger", data.error || "Failed to update status.");
        showToast(data.error || "Failed to update status.", "error");
        return;
      }

      // Confirm with server-returned data
      patchRow(data.staff);

      const deltaActive   = data.new_status === "active"   ? +1 : -1;
      const deltaInactive = data.new_status === "inactive" ? +1 : -1;
      adjustStats({ active: deltaActive, inactive: deltaInactive });

      showToast(data.message || `${staffName} status updated.`, "success");
      showMessage("success", data.message);

    } catch (err) {
      console.error(err);
      // Revert the badge on network failure
      if (statusBadge) {
        statusBadge.className = `badge ${currentStatus === "active" ? "badge-active" : "badge-inactive"}`;
        statusBadge.innerHTML = `<i class="bi ${currentStatus === "active" ? "bi-circle-fill" : "bi-circle"}" style="font-size:7px;" aria-hidden="true"></i>${currentStatus.charAt(0).toUpperCase() + currentStatus.slice(1)}`;
      }
      showMessage("danger", "Something went wrong. Please try again.");
      showToast("Something went wrong.", "error");
    } finally {
      btn.disabled = false;
    }
  }

  // ── Search debounce ───────────────────────────────────────────────────────

  let _searchTimer;
  if (el.searchInput && el.filterForm) {
    el.searchInput.addEventListener("input", () => {
      clearTimeout(_searchTimer);
      _searchTimer = setTimeout(() => el.filterForm.submit(), 420);
    });
  }

  // ── Event delegation ──────────────────────────────────────────────────────

  if (el.tbody) {
    el.tbody.addEventListener("click", (e) => {
      const editBtn   = e.target.closest(".editStaffBtn");
      const toggleBtn = e.target.closest(".toggleStatusBtn");

      if (editBtn)   { fillEditModal(editBtn); return; }
      if (toggleBtn) { handleToggle(toggleBtn); }
    });
  }

  // ── Modal cleanup ─────────────────────────────────────────────────────────

  el.addModalEl?.addEventListener("hidden.bs.modal",  resetAddForm);
  el.editModalEl?.addEventListener("hidden.bs.modal", resetEditForm);

  // ── Form submit binding ───────────────────────────────────────────────────

  el.addForm?.addEventListener("submit",  handleAdd);
  el.editForm?.addEventListener("submit", handleEdit);

  // ── Photo preview binding ─────────────────────────────────────────────────

  bindPhotoPreview(el.addPhotoInput,  el.addPhotoPreview);
  bindPhotoPreview(el.editPhotoInput, el.editPhotoPreview);

  // ── Flush queued toast (from a genuine page navigation) ───────────────────

  try {
    const raw = sessionStorage.getItem("manageStaffFlash");
    if (raw) {
      sessionStorage.removeItem("manageStaffFlash");
      const p = JSON.parse(raw);
      if (p?.message) showToast(p.message, p.icon || "success");
    }
  } catch { /* non-critical */ }

});