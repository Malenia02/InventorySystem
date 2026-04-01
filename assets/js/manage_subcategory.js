document.addEventListener("DOMContentLoaded", () => {
  const ACTION_URL = "/inventory_system/http/ajax/subcategory_actions.php";
  const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || "";
  const table = document.getElementById("subcategoryTable");
  const addForm = document.getElementById("addSubcategoryForm");
  const editForm = document.getElementById("editSubcategoryForm");
  let dataTable = null;

  if (!table || !addForm || !editForm) {
    return;
  }

  const Toast = Swal.mixin({
    toast: true,
    position: "top-end",
    showConfirmButton: false,
    timer: 3000,
    timerProgressBar: true
  });

  function showToast(message, icon = "success") {
    Toast.fire({ icon, title: message });
  }

  function postData(formData) {
    if (!formData.has("csrf_token")) {
      formData.append("csrf_token", csrfToken);
    }

    return fetch(ACTION_URL, {
      method: "POST",
      headers: { "X-Requested-With": "XMLHttpRequest" },
      body: formData
    }).then(async (response) => {
      const result = await response.json();
      if (!response.ok) {
        throw new Error(result.error || "Request failed.");
      }
      return result;
    });
  }

  function updateRowNumbers() {
    table.querySelectorAll("tbody tr").forEach((row, index) => {
      const firstCell = row.querySelector("td:first-child");
      if (firstCell) {
        firstCell.textContent = String(index + 1);
      }
    });
  }

  function initializeDataTable() {
    if (typeof simpleDatatables === "undefined" || !simpleDatatables.DataTable) {
      return;
    }

    if (dataTable) {
      try {
        dataTable.destroy();
      } catch (error) {
        console.warn("Failed to destroy existing subcategory DataTable instance.", error);
      }
    }

    dataTable = new simpleDatatables.DataTable(table, {
      searchable: true,
      fixedHeight: false,
      perPage: 10
    });
  }

  function createRowFromHtml(html) {
    const temp = document.createElement("tbody");
    temp.innerHTML = html.trim();
    return temp.firstElementChild;
  }

  function prependRow(html) {
    const row = createRowFromHtml(html);
    if (!row) return;
    table.querySelector("tbody")?.prepend(row);
    updateRowNumbers();
  }

  function replaceRow(id, html) {
    const oldRow = document.getElementById(`subcategoryRow${id}`);
    const newRow = createRowFromHtml(html);
    if (!oldRow || !newRow) return;
    oldRow.replaceWith(newRow);
    updateRowNumbers();
  }

  updateRowNumbers();
  initializeDataTable();

  addForm.addEventListener("submit", (event) => {
    event.preventDefault();
    const formData = new FormData(addForm);
    formData.append("add_subcategory", "1");

    postData(formData)
      .then((result) => {
        prependRow(result.newRowHtml || "");
        addForm.reset();
        bootstrap.Modal.getInstance(document.getElementById("addSubcategoryModal"))?.hide();
        showToast(result.message || "Subcategory added successfully.");
      })
      .catch((error) => showToast(error.message || "Failed to add subcategory.", "error"));
  });

  editForm.addEventListener("submit", (event) => {
    event.preventDefault();
    const formData = new FormData(editForm);
    formData.append("edit_subcategory", "1");

    postData(formData)
      .then((result) => {
        const id = document.getElementById("editSubcategoryId")?.value || "";
        replaceRow(id, result.newRowHtml || "");
        bootstrap.Modal.getInstance(document.getElementById("editSubcategoryModal"))?.hide();
        showToast(result.message || "Subcategory updated successfully.");
      })
      .catch((error) => showToast(error.message || "Failed to update subcategory.", "error"));
  });

  table.addEventListener("click", (event) => {
    const editBtn = event.target.closest(".editSubcategoryBtn");
    const toggleBtn = event.target.closest(".toggleSubcategoryStatusBtn");

    if (editBtn) {
      document.getElementById("editSubcategoryId").value = editBtn.dataset.id || "";
      document.getElementById("editSubcategoryCategory").value = editBtn.dataset.category || "";
      document.getElementById("editSubcategoryName").value = editBtn.dataset.name || "";
      document.getElementById("editSubcategoryDescription").value = editBtn.dataset.description || "";
      return;
    }

    if (toggleBtn) {
      const subcategoryId = toggleBtn.dataset.id || "";
      const subcategoryName = toggleBtn.dataset.name || "this subcategory";
      const currentStatus = (toggleBtn.dataset.status || "").toLowerCase();
      const actionText = currentStatus === "active" ? "Deactivate" : "Activate";

      Swal.fire({
        title: `${actionText} ${subcategoryName}?`,
        html: `
          <p class="mb-1">You are about to <strong>${actionText.toLowerCase()}</strong> this subcategory.</p>
          <small class="text-muted">You can change this again anytime.</small>
        `,
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        confirmButtonText: `Yes, ${actionText}`
      }).then((confirmResult) => {
        if (!confirmResult.isConfirmed) {
          return;
        }

        const formData = new FormData();
        formData.append("toggle_subcategory_id", subcategoryId);

        postData(formData)
          .then((result) => {
            replaceRow(subcategoryId, result.newRowHtml || "");
            showToast(result.message || "Subcategory status updated.");
          })
          .catch((error) => showToast(error.message || "Failed to update subcategory status.", "error"));
      });

      return;
    }
  });
});
