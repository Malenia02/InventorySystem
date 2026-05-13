// ==============================
// manage_category.js (PRODUCTION LEVEL)
// ==============================

document.addEventListener("DOMContentLoaded", () => {
  const CATEGORY_ACTION_URL = "/inventory_system/http/ajax/category_actions.php";
  const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || "";

  const table = document.getElementById("categoryTable");
  const addForm = document.getElementById("addCategoryForm");
  const editForm = document.getElementById("editCategoryForm");
  const addModalEl = document.getElementById("addCategoryModal");
  const editModalEl = document.getElementById("editCategoryModal");

  if (!table || !addForm || !editForm) {
    console.error("Category management elements are missing.");
    return;
  }

  function queueReloadToast(message, icon = "success") {
    try {
      sessionStorage.setItem("manageCategoryFlash", JSON.stringify({ message, icon }));
    } catch (error) {
      console.warn("Unable to persist category flash message.", error);
    }
  }

  function flushQueuedToast() {
    try {
      const raw = sessionStorage.getItem("manageCategoryFlash");
      if (!raw) return;

      sessionStorage.removeItem("manageCategoryFlash");
      const payload = JSON.parse(raw);
      if (payload?.message) {
        showToast(payload.message, payload.icon || "success");
      }
    } catch (error) {
      console.warn("Unable to restore category flash message.", error);
    }
  }

  function reloadCurrentPage(message, icon = "success") {
    queueReloadToast(message, icon);
    window.location.assign(window.location.pathname + window.location.search);
  }

  // ------------------------------
  // SweetAlert Toast
  // ------------------------------
  const Toast = Swal.mixin({
    toast: true,
    position: "top-end",
    showConfirmButton: false,
    timer: 3500,
    timerProgressBar: true
  });

  function showToast(message, icon = "success") {
    Toast.fire({
      icon,
      title: message
    });
  }

  function showDetailedToast(title, html, icon = "success") {
    Toast.fire({
      icon,
      title,
      html,
      timer: 4500
    });
  }

  function escapeHtml(value) {
    const div = document.createElement("div");
    div.textContent = value ?? "";
    return div.innerHTML;
  }

  // ------------------------------
  // UI Helpers
  // ------------------------------
  function updateRowNumbers() {
    const tableBody = table.querySelector("tbody");
    if (!tableBody) return;

    const rows = tableBody.querySelectorAll("tr");
    rows.forEach((tr, index) => {
      const firstCell = tr.querySelector("td:first-child");
      if (firstCell) {
        firstCell.textContent = String(index + 1);
      }
    });
  }

  function createRowFromHtml(rowHtml) {
    const temp = document.createElement("tbody");
    temp.innerHTML = rowHtml.trim();
    return temp.firstElementChild;
  }

  function replaceRow(rowId, newRowHtml) {
    const oldRow = document.getElementById(rowId);
    const newRow = createRowFromHtml(newRowHtml);

    if (!oldRow || !newRow) return false;

    oldRow.replaceWith(newRow);
    updateRowNumbers();
    return true;
  }

  function prependRow(newRowHtml) {
    const tableBody = table.querySelector("tbody");
    const newRow = createRowFromHtml(newRowHtml);
    if (!newRow || !tableBody) return false;

    tableBody.prepend(newRow);
    updateRowNumbers();
    return true;
  }

  function getBootstrapModalInstance(modalEl) {
    if (!modalEl) return null;
    return bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
  }

  function setButtonLoading(button, isLoading, loadingText = "Processing...") {
    if (!button) return;

    if (isLoading) {
      button.dataset.originalText = button.innerHTML;
      button.disabled = true;
      button.innerHTML = `
        <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
        ${loadingText}
      `;
    } else {
      button.disabled = false;
      button.innerHTML = button.dataset.originalText || button.innerHTML;
    }
  }

  // ------------------------------
  // Request Helper
  // ------------------------------
  async function postData(url, formData) {
    const payload = formData instanceof FormData ? formData : new FormData();

    if (!payload.has("csrf_token")) {
      payload.append("csrf_token", csrfToken);
    }

    const response = await fetch(url, {
      method: "POST",
      body: payload,
      headers: {
        "X-Requested-With": "XMLHttpRequest"
      }
    });

    let result;
    try {
      result = await response.json();
    } catch (error) {
      throw {
        status: response.status,
        message: "Invalid server response."
      };
    }

    if (!response.ok) {
      throw {
        status: response.status,
        message: result.error || "Request failed."
      };
    }

    return result;
  }

  function handleRequestError(error) {
    if (!error || typeof error !== "object") {
      showToast("An unexpected error occurred.", "error");
      return;
    }

    switch (error.status) {
      case 401:
        showToast(error.message || "Unauthorized access.", "error");
        break;
      case 404:
        showToast(error.message || "Record not found.", "error");
        break;
      case 409:
        showToast(error.message || "Duplicate record found.", "warning");
        break;
      case 422:
        showToast(error.message || "Validation failed.", "warning");
        break;
      case 500:
        showToast(error.message || "Internal server error.", "error");
        break;
      default:
        showToast(error.message || "Something went wrong.", "error");
        break;
    }
  }

  // ------------------------------
  // ADD CATEGORY
  // ------------------------------
  addForm.addEventListener("submit", async (e) => {
    e.preventDefault();

    const submitBtn = addForm.querySelector("button[type='submit']");
    setButtonLoading(submitBtn, true, "Saving...");

    try {
      const formData = new FormData(addForm);
      formData.append("add_category", "1");

      const res = await postData(CATEGORY_ACTION_URL, formData);

      if (res.success && res.newRowHtml) {
        const modal = getBootstrapModalInstance(addModalEl);
        modal?.hide();

        const categoryName = formData.get("category_name") || "New category";
        addForm.reset();
        reloadCurrentPage(`${categoryName} was created successfully.`, "success");
      } else {
        showToast(res.error || "Failed to add category.", "error");
      }
    } catch (error) {
      handleRequestError(error);
    } finally {
      setButtonLoading(submitBtn, false);
    }
  });

  // ------------------------------
  // OPEN EDIT MODAL
  // ------------------------------
  table.addEventListener("click", (e) => {
    const btn = e.target.closest(".editCategoryBtn");
    if (!btn) return;

    const editIdInput = document.getElementById("editCategoryId");
    const editNameInput = document.getElementById("editCategoryName");
    const editDescriptionInput = document.getElementById("editCategoryDescription");

    if (editIdInput) editIdInput.value = btn.dataset.id || "";
    if (editNameInput) editNameInput.value = btn.dataset.name || "";
    if (editDescriptionInput) editDescriptionInput.value = btn.dataset.description || "";
  });

  // ------------------------------
  // EDIT CATEGORY
  // ------------------------------
  editForm.addEventListener("submit", async (e) => {
    e.preventDefault();

    const submitBtn = editForm.querySelector("button[type='submit']");
    setButtonLoading(submitBtn, true, "Updating...");

    try {
      const formData = new FormData(editForm);
      formData.append("edit_category", "1");

      const res = await postData(CATEGORY_ACTION_URL, formData);

      if (res.success && res.newRowHtml) {
        const id = document.getElementById("editCategoryId")?.value || "";
        const modal = getBootstrapModalInstance(editModalEl);
        modal?.hide();

        const categoryName = formData.get("category_name") || "Category";
        editForm.reset();
        reloadCurrentPage(`${categoryName} was updated successfully.`, "success");
      } else {
        showToast(res.error || "Failed to update category.", "error");
      }
    } catch (error) {
      handleRequestError(error);
    } finally {
      setButtonLoading(submitBtn, false);
    }
  });

  // ------------------------------
  // TOGGLE CATEGORY STATUS
  // ------------------------------
  table.addEventListener("click", async (e) => {
    const btn = e.target.closest(".toggleCategoryStatusBtn");
    if (!btn) return;

    const id = btn.dataset.id || "";
    const categoryName = btn.dataset.name || "this category";
    const currentStatus = (btn.dataset.status || "").toLowerCase();
    const actionText = currentStatus === "active" ? "Deactivate" : "Activate";

    const result = await Swal.fire({
      title: `${actionText} ${escapeHtml(categoryName)}?`,
      html: `
        <p class="mb-1">You are about to <strong>${actionText.toLowerCase()}</strong> this category.</p>
        <small class="text-muted">You can change this again anytime.</small>
      `,
      icon: "warning",
      showCancelButton: true,
      confirmButtonColor: "#3085d6",
      cancelButtonColor: "#d33",
      confirmButtonText: `Yes, ${actionText}`
    });

    if (!result.isConfirmed) {
      return;
    }

    setButtonLoading(btn, true, `${actionText}...`);

    try {
      const formData = new FormData();
      formData.append("toggle_id", id);

      const res = await postData(CATEGORY_ACTION_URL, formData);

      if (res.success && res.newRowHtml) {
        reloadCurrentPage(
          `${categoryName} is now ${res.new_status ? res.new_status.toLowerCase() : "updated"}.`,
          "success"
        );
      } else {
        showToast(res.error || "Failed to update category status.", "error");
      }
    } catch (error) {
      handleRequestError(error);
    } finally {
      setButtonLoading(btn, false);
    }
  });

  // ------------------------------
  // Reset forms when modals close
  // ------------------------------
  if (addModalEl) {
    addModalEl.addEventListener("hidden.bs.modal", () => {
      addForm.reset();
    });
  }

  if (editModalEl) {
    editModalEl.addEventListener("hidden.bs.modal", () => {
      editForm.reset();
    });
  }

  flushQueuedToast();
});
