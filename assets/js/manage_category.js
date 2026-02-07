// ==============================
// manage_category.js
// ==============================

// Get CSRF token from meta or hidden input
const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';

// Helper function for POST with CSRF
function postData(url, formData) {
    if (!(formData instanceof FormData)) formData = new FormData();
    if (!formData.has("csrf_token")) formData.append("csrf_token", csrfToken);

    return fetch(url, { method: "POST", body: formData })
        .then(res => res.json());
}

// Helper to show alerts
function showMessage(msg, type = "success") {
    const container = document.getElementById("categoryMessageContainer");
    if (!container) return alert(msg); // fallback

    container.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show" role="alert">
        ${msg}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>`;
}

document.addEventListener("DOMContentLoaded", () => {
    const table = document.getElementById("categoryTable");
    const addForm = document.getElementById("addCategoryForm");
    const editForm = document.getElementById("editCategoryForm");

    // Initialize DataTable
    const dataTable = new simpleDatatables.DataTable(table);

    // --------------------------
    // ADD CATEGORY
    // --------------------------
    addForm.addEventListener("submit", e => {
        e.preventDefault();
        const formData = new FormData(addForm);
        formData.append("add_category", true);

        postData("/inventory_system/http/ajax/category_ajax.php", formData)
            .then(res => {
                if (res.success && res.updatedRowHtml) {
                    // Add new row to DataTable
                    const temp = document.createElement("tbody");
                    temp.innerHTML = res.updatedRowHtml.trim();
                    dataTable.rows().add([temp.firstChild]);

                    bootstrap.Modal.getInstance(document.getElementById("addCategoryModal")).hide();
                    addForm.reset();
                    showMessage(res.success, "success");
                } else if (res.error) {
                    showMessage(res.error, "danger");
                }
            })
            .catch(err => console.error(err));
    });

    // --------------------------
    // OPEN EDIT MODAL
    // --------------------------
    table.addEventListener("click", e => {
        const btn = e.target.closest(".editCategoryBtn");
        if (!btn) return;

        document.getElementById("editCategoryId").value = btn.dataset.id;
        document.getElementById("editCategoryName").value = btn.dataset.name;
    });

    // --------------------------
    // EDIT CATEGORY
    // --------------------------
    editForm.addEventListener("submit", e => {
        e.preventDefault();
        const formData = new FormData(editForm);
        formData.append("edit_category", true);

        postData("/inventory_system/http/ajax/category_ajax.php", formData)
            .then(res => {
                if (res.success && res.updatedRowHtml) {
                    const id = document.getElementById("editCategoryId").value;
                    const oldRow = document.getElementById(`categoryRow${id}`);
                    if (oldRow) {
                        const temp = document.createElement("tbody");
                        temp.innerHTML = res.updatedRowHtml.trim();
                        dataTable.rows().update(oldRow, temp.firstChild);
                    }

                    bootstrap.Modal.getInstance(document.getElementById("editCategoryModal")).hide();
                    editForm.reset();
                    showMessage(res.success, "success");
                } else if (res.error) {
                    showMessage(res.error, "danger");
                }
            })
            .catch(err => console.error(err));
    });

    // --------------------------
    // TOGGLE STATUS
    // --------------------------
    table.addEventListener("click", e => {
        const btn = e.target.closest(".toggleCategoryStatusBtn");
        if (!btn) return;

        const row = btn.closest("tr");
        const categoryName = row.querySelector("td:nth-child(2)").innerText;
        const currentStatus = btn.dataset.status;
        const newStatus = currentStatus === "active" ? "inactive" : "active";

        if (!confirm(`Are you sure you want to ${newStatus} "${categoryName}"?`)) return;

        const formData = new FormData();
        formData.append("toggle_id", btn.dataset.id);

        postData("/inventory_system/http/ajax/category_ajax.php", formData)
            .then(res => {
                if (res.success && res.updatedRowHtml) {
                    const oldRow = document.getElementById(`categoryRow${btn.dataset.id}`);
                    if (oldRow) {
                        const temp = document.createElement("tbody");
                        temp.innerHTML = res.updatedRowHtml.trim();
                        dataTable.rows().update(oldRow, temp.firstChild);
                    }
                    showMessage(res.success, "success");
                } else if (res.error) {
                    showMessage(res.error, "danger");
                }
            })
            .catch(err => console.error(err));
    });
});
