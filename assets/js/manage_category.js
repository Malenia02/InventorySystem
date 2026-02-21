// ==============================
// manage_category.js (PRO VERSION)
// ==============================

const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || "";

// ------------------------------
// SweetAlert Toast
// ------------------------------
const Toast = Swal.mixin({
  toast: true,
  position: "top-end",
  showConfirmButton: false,
  timer: 3000,
  timerProgressBar: true
});

function showToast(message, icon = "success") {
  Toast.fire({
    icon: icon,
    title: message
  });
}

// ------------------------------
// POST helper
// ------------------------------
function postData(url, formData) {
  if (!(formData instanceof FormData)) formData = new FormData();
  if (!formData.has("csrf_token")) formData.append("csrf_token", csrfToken);

  return fetch(url, {
    method: "POST",
    body: formData
  }).then(res => res.json());
}

document.addEventListener("DOMContentLoaded", () => {
  const table = document.getElementById("categoryTable");
  const addForm = document.getElementById("addCategoryForm");
  const editForm = document.getElementById("editCategoryForm");

  // Initialize DataTable
  let dataTable = new simpleDatatables.DataTable(table);

  // ------------------------------
  // Helper: Update row numbers dynamically
  // ------------------------------
  function updateRowNumbers() {
    table.querySelectorAll("tbody tr").forEach((tr, idx) => {
      tr.querySelector("td:first-child").innerText = idx + 1;
    });
  }

  // ------------------------------
  // Helper: Replace a single row safely
  // ------------------------------
  function replaceRow(oldRow, newRowHtml) {
    const temp = document.createElement("tbody");
    temp.innerHTML = newRowHtml.trim();
    oldRow.replaceWith(temp.firstChild);
    updateRowNumbers();
    dataTable.refresh(); // refresh sorting/pagination
  }

  // ==========================
  // ADD CATEGORY
  // ==========================
  addForm.addEventListener("submit", e => {
    e.preventDefault();

    const formData = new FormData(addForm);
    formData.append("add_category", true);

    postData("/inventory_system/http/ajax/category_ajax.php", formData)
      .then(res => {
        if (res.success && res.updatedRowHtml) {
          const temp = document.createElement("tbody");
          temp.innerHTML = res.updatedRowHtml.trim();
          table.querySelector("tbody").prepend(temp.firstChild);

          updateRowNumbers();
          dataTable.refresh();

          bootstrap.Modal.getInstance(document.getElementById("addCategoryModal")).hide();
          addForm.reset();

          showToast(res.message, "success");
        } else {
          showToast(res.error || "Something went wrong", "error");
        }
      })
      .catch(() => showToast("Server error occurred", "error"));
  });

  // ==========================
  // OPEN EDIT MODAL
  // ==========================
  table.addEventListener("click", e => {
    const btn = e.target.closest(".editCategoryBtn");
    if (!btn) return;

    document.getElementById("editCategoryId").value = btn.dataset.id;
    document.getElementById("editCategoryName").value = btn.dataset.name;
  });

  // ==========================
  // EDIT CATEGORY
  // ==========================
  editForm.addEventListener("submit", e => {
    e.preventDefault();

    const formData = new FormData(editForm);
    formData.append("edit_category", true);

    postData("/inventory_system/http/ajax/category_ajax.php", formData)
      .then(res => {
        if (res.success && res.updatedRowHtml) {
          const id = document.getElementById("editCategoryId").value;
          const oldRow = document.getElementById(`categoryRow${id}`);
          if (oldRow) replaceRow(oldRow, res.updatedRowHtml);

          bootstrap.Modal.getInstance(document.getElementById("editCategoryModal")).hide();
          editForm.reset();

          showToast(res.message, "success");
        } else {
          showToast(res.error || "Update failed", "error");
        }
      })
      .catch(() => showToast("Server error occurred", "error"));
  });

  // ==========================
  // TOGGLE STATUS
  // ==========================
  table.addEventListener("click", e => {
    const btn = e.target.closest(".toggleCategoryStatusBtn");
    if (!btn) return;

    const id = btn.dataset.id;
    const currentStatus = btn.getAttribute("data-status");
    const newAction = currentStatus === "active" ? "Deactivate" : "Activate";

    Swal.fire({
      title: `${newAction} Category?`,
      text: "You can change it back later.",
      icon: "warning",
      showCancelButton: true,
      confirmButtonColor: "#3085d6",
      cancelButtonColor: "#d33",
      confirmButtonText: `Yes, ${newAction}`
    }).then(result => {
      if (!result.isConfirmed) return;

      const formData = new FormData();
      formData.append("toggle_id", id);

      postData("/inventory_system/http/ajax/category_ajax.php", formData)
        .then(res => {
          if (res.success && res.updatedRowHtml) {
            const oldRow = document.getElementById(`categoryRow${id}`);
            if (oldRow) replaceRow(oldRow, res.updatedRowHtml);

            // Toast message
            showToast(res.message, "success");
          } else {
            showToast(res.error || "Action failed", "error");
          }
        })
        .catch(() => showToast("Server error occurred", "error"));
    });
  });

});