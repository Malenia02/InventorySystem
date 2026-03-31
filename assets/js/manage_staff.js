document.addEventListener("DOMContentLoaded", () => {
  const Toast = Swal.mixin({
    toast: true,
    position: "top-end",
    showConfirmButton: false,
    timer: 3200,
    timerProgressBar: true
  });

  const page = {
    table: document.getElementById("staffTable"),
    tableBody: document.querySelector("#staffTable tbody"),
    messages: document.getElementById("staffMessages"),

    addForm: document.getElementById("addStaffForm"),
    editForm: document.getElementById("editStaffForm"),

    addModalEl: document.getElementById("addStaffModal"),
    editModalEl: document.getElementById("editStaffModal"),

    addPhotoInput: document.getElementById("addStaffPhotoInput"),
    addPhotoPreview: document.getElementById("addStaffPhotoPreview"),
    editPhotoInput: document.querySelector('#editStaffForm input[name="photo"]'),
    editPhotoPreview: document.getElementById("editStaffPhotoPreview")
  };

  const addModal = page.addModalEl ? bootstrap.Modal.getOrCreateInstance(page.addModalEl) : null;
  const editModal = page.editModalEl ? bootstrap.Modal.getOrCreateInstance(page.editModalEl) : null;

  const csrfToken =
    document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") ||
    document.querySelector('#addStaffForm input[name="csrf_token"]')?.value ||
    document.querySelector('#editStaffForm input[name="csrf_token"]')?.value ||
    "";

  const endpoint = "/inventory_system/http/ajax/staff_actions.php";
  const fallbackPhoto = "/inventory_system/assets/img/default-user.png";

  function showToast(message, icon = "success") {
    Toast.fire({ icon, title: message });
  }

  function escapeHtml(value) {
    const div = document.createElement("div");
    div.textContent = value ?? "";
    return div.innerHTML;
  }

  function showMessage(type, message) {
    if (!page.messages) return;

    page.messages.innerHTML = `
      <div class="alert alert-${type} alert-dismissible fade show" role="alert">
        ${escapeHtml(message)}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    `;

    window.clearTimeout(showMessage._timer);
    showMessage._timer = window.setTimeout(() => {
      if (page.messages) page.messages.innerHTML = "";
    }, 4000);
  }

  function setButtonLoading(button, isLoading, loadingText, defaultHtml) {
    if (!button) return;

    if (isLoading) {
      button.disabled = true;
      button.dataset.originalHtml = defaultHtml || button.innerHTML;
      button.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>${loadingText}`;
    } else {
      button.disabled = false;
      button.innerHTML = defaultHtml || button.dataset.originalHtml || button.innerHTML;
    }
  }

  async function postData(formData) {
    if (!(formData instanceof FormData)) {
      formData = new FormData();
    }

    if (csrfToken && !formData.has("csrf_token")) {
      formData.append("csrf_token", csrfToken);
    }

    const response = await fetch(endpoint, {
      method: "POST",
      headers: {
        "X-Requested-With": "XMLHttpRequest"
      },
      body: formData
    });

    const text = await response.text();

    try {
      return JSON.parse(text);
    } catch (error) {
      console.error("Invalid JSON response:", text);
      throw new Error("Invalid server response.");
    }
  }

  function previewImage(input, previewEl, fallbackSrc = fallbackPhoto) {
    if (!input || !previewEl) return;

    input.addEventListener("change", (e) => {
      const file = e.target.files?.[0];
      if (file) {
        previewEl.src = URL.createObjectURL(file);
      } else {
        previewEl.src = fallbackSrc;
      }
    });
  }

  function resetAddForm() {
    if (page.addForm) page.addForm.reset();
    if (page.addPhotoPreview) page.addPhotoPreview.src = fallbackPhoto;
  }

  function resetEditForm() {
    if (page.editForm) page.editForm.reset();
    if (page.editPhotoPreview) page.editPhotoPreview.src = fallbackPhoto;
  }

  function updateRowNumbers() {
    if (!page.tableBody) return;

    page.tableBody.querySelectorAll("tr").forEach((row, index) => {
      const firstCell = row.querySelector("td:first-child");
      if (firstCell) firstCell.textContent = String(index + 1);
    });
  }

  function buildStaffRow(staff) {
    const userId = Number(staff.user_id || 0);
    const fullName = staff.full_name || "";
    const username = staff.username || "";
    const email = staff.email || "";
    const role = staff.role || "staff";
    const status = staff.status || "inactive";
    const statusLabel = staff.status_label || (status.charAt(0).toUpperCase() + status.slice(1));
    const photo = staff.photo || fallbackPhoto;

    const statusClass = status === "active" ? "bg-success" : "bg-secondary";
    const toggleButtonClass = status === "active" ? "btn-danger" : "btn-success";
    const toggleIcon = status === "active" ? "bi-slash-circle" : "bi-check-circle";
    const toggleTitle = status === "active" ? "Deactivate Staff" : "Reactivate Staff";

    const tr = document.createElement("tr");
    tr.id = `staffRow${userId}`;

    tr.innerHTML = `
      <td>0</td>
      <td class="text-center">
        <img
          src="${escapeHtml(photo)}"
          alt="Photo"
          style="width:50px;height:50px;object-fit:cover;"
          onerror="this.src='${fallbackPhoto}';this.onerror=null;"
        >
      </td>
      <td>${escapeHtml(username)}</td>
      <td>${escapeHtml(email)}</td>
      <td>${escapeHtml(role)}</td>
      <td>
        <span class="badge ${statusClass}" id="staffStatus${userId}">${escapeHtml(statusLabel)}</span>
      </td>
      <td>
        <div class="d-flex gap-2 justify-content-center">
          <button
            type="button"
            class="btn btn-sm btn-warning editStaffBtn"
            data-id="${userId}"
            data-username="${escapeHtml(username)}"
            data-firstname="${escapeHtml(staff.first_name || "")}"
            data-lastname="${escapeHtml(staff.last_name || "")}"
            data-email="${escapeHtml(email)}"
            data-photo="${escapeHtml(photo)}"
            data-role="${escapeHtml(role)}"
            title="Edit Staff"
            data-bs-toggle="modal"
            data-bs-target="#editStaffModal"
          >
            <i class="bi bi-pencil-square"></i>
          </button>

          <button
            type="button"
            class="btn btn-sm ${toggleButtonClass} toggleStatusBtn"
            data-id="${userId}"
            data-name="${escapeHtml(fullName)}"
            data-status="${escapeHtml(status)}"
            title="${toggleTitle}"
          >
            <i class="bi ${toggleIcon}"></i>
          </button>
        </div>
      </td>
    `;

    return tr;
  }

  function insertNewRowFromData(staff) {
    if (!page.tableBody || !staff) return;

    const newRow = buildStaffRow(staff);
    page.tableBody.prepend(newRow);
    updateRowNumbers();
  }

  function replaceExistingRowFromData(staffId, staff) {
    const oldRow = document.getElementById(`staffRow${staffId}`);
    if (!oldRow || !staff) return;

    const newRow = buildStaffRow(staff);
    oldRow.replaceWith(newRow);
    updateRowNumbers();
  }

  function fillEditModalFromButton(button) {
    if (!button || !page.editForm) return;

    const setValue = (id, value) => {
      const field = document.getElementById(id);
      if (field) field.value = value || "";
    };

    setValue("editStaffId", button.dataset.id);
    setValue("editFirstname", button.dataset.firstname);
    setValue("editLastname", button.dataset.lastname);
    setValue("editEmail", button.dataset.email);
    setValue("editUsername", button.dataset.username);

    const passwordField = document.getElementById("editPassword");
    if (passwordField) passwordField.value = "";

    if (page.editPhotoPreview) {
      page.editPhotoPreview.src = button.dataset.photo || fallbackPhoto;
    }

    const roleSelect = document.getElementById("editRole");
    if (roleSelect && button.dataset.role) {
      roleSelect.value = button.dataset.role;
    }
  }

  async function handleAddStaffSubmit(event) {
    event.preventDefault();
    if (!page.addForm) return;

    const submitBtn = page.addForm.querySelector("button[type='submit']");
    const formData = new FormData(page.addForm);
    formData.append("add_staff", "1");

    setButtonLoading(submitBtn, true, "Saving...");

    try {
      const data = await postData(formData);

      if (data.error) {
        showMessage("danger", data.error);
        showToast(data.error, "error");
        return;
      }

      if (data.success) {
        const successMessage = data.message || "Nice work! The staff record was saved.";
        showMessage("success", successMessage);
        showToast(successMessage, "success");

        if (data.staff) {
          insertNewRowFromData(data.staff);
        }

        resetAddForm();
        if (addModal) addModal.hide();
      }
    } catch (error) {
      console.error(error);
      showMessage("danger", "Something went wrong.");
      showToast("Something went wrong while saving the staff record.", "error");
    } finally {
      setButtonLoading(submitBtn, false, "", '<i class="bi bi-person-plus me-1"></i>Add New Staff');
    }
  }

  async function handleEditStaffSubmit(event) {
    event.preventDefault();
    if (!page.editForm) return;

    const submitBtn = page.editForm.querySelector("button[type='submit']");
    const formData = new FormData(page.editForm);
    formData.append("edit_staff", "1");

    setButtonLoading(submitBtn, true, "Updating...");

    try {
      const data = await postData(formData);

      if (data.error) {
        showMessage("danger", data.error);
        showToast(data.error, "error");
        return;
      }

      if (data.success) {
        const successMessage = data.message || "Sweet update. Staff details are saved.";
        showMessage("success", successMessage);
        showToast(successMessage, "success");

        if (data.staff && data.staff_id) {
          replaceExistingRowFromData(data.staff_id, data.staff);
        }

        if (editModal) editModal.hide();
        resetEditForm();
      }
    } catch (error) {
      console.error(error);
      showMessage("danger", "Something went wrong.");
      showToast("Something went wrong while updating the staff record.", "error");
    } finally {
      setButtonLoading(submitBtn, false, "", '<i class="bi bi-save me-1"></i>Update Staff');
    }
  }

  async function handleToggleStatus(button) {
    if (!button) return;

    const staffId = button.dataset.id;
    if (!staffId) return;

    const staffName = button.dataset.name || "this staff member";
    const currentStatus = button.dataset.status || "";
    const nextAction = currentStatus === "active" ? "Deactivate" : "Reactivate";

    const result = await Swal.fire({
      title: `${nextAction} ${staffName}?`,
      html: `
        <p class="mb-1">You're about to <strong>${nextAction.toLowerCase()}</strong> this staff account.</p>
        <small class="text-muted">You can change it back later anytime.</small>
      `,
      icon: "warning",
      showCancelButton: true,
      confirmButtonText: `Yes, ${nextAction.toLowerCase()}`,
      cancelButtonText: "Cancel",
      cancelButtonColor: "#d33",
      confirmButtonColor: currentStatus === "active" ? "#3085d6" : "#198754"
    });

    if (!result.isConfirmed) return;

    button.disabled = true;

    try {
      const formData = new FormData();
      formData.append("toggle_id", staffId);

      const data = await postData(formData);

      if (data.error) {
        showMessage("danger", data.error);
        showToast(data.error, "error");
        return;
      }

      if (data.success) {
        const successMessage = data.message || `${staffName} is all set.`;
        showMessage("success", successMessage);
        showToast(`All set. ${successMessage}`, "success");

        if (data.staff && data.staff_id) {
          replaceExistingRowFromData(data.staff_id, data.staff);
        }
      }
    } catch (error) {
      console.error(error);
      showMessage("danger", "Something went wrong.");
      showToast("Something went wrong while updating the staff status.", "error");
    } finally {
      button.disabled = false;
    }
  }

  function bindTableEvents() {
    if (!page.tableBody) return;

    page.tableBody.addEventListener("click", (event) => {
      const editBtn = event.target.closest(".editStaffBtn");
      if (editBtn) {
        fillEditModalFromButton(editBtn);
        return;
      }

      const toggleBtn = event.target.closest(".toggleStatusBtn");
      if (toggleBtn) {
        handleToggleStatus(toggleBtn);
      }
    });
  }

  function initDataTable() {
    if (page.table && window.simpleDatatables && simpleDatatables.DataTable) {
      new simpleDatatables.DataTable(page.table, {
        searchable: true,
        fixedHeight: true,
        perPage: 10
      });
    }
  }

  function initModalCleanup() {
    page.addModalEl?.addEventListener("hidden.bs.modal", () => {
      resetAddForm();
    });

    page.editModalEl?.addEventListener("hidden.bs.modal", () => {
      resetEditForm();
    });
  }

  previewImage(page.addPhotoInput, page.addPhotoPreview);
  previewImage(page.editPhotoInput, page.editPhotoPreview);
  initDataTable();
  initModalCleanup();
  bindTableEvents();

  if (page.addForm) {
    page.addForm.addEventListener("submit", handleAddStaffSubmit);
  }

  if (page.editForm) {
    page.editForm.addEventListener("submit", handleEditStaffSubmit);
  }
});