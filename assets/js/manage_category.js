// assets/js/manage_category.js
document.addEventListener("DOMContentLoaded", () => {
    const tableBody = document.querySelector("table tbody");
    const addForm = document.getElementById("addCategoryForm");
    const editForm = document.getElementById("editCategoryForm");

    // ==========================
    // Initialize DataTable
    // ==========================
    const dataTable = new simpleDatatables.DataTable("table");

    // ==========================
    // ADD CATEGORY
    // ==========================
    addForm.addEventListener("submit", function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append("add_category", true);

        fetch("/inventory_system/http/ajax/category_ajax.php", {
            method: "POST",
            body: formData
        })
        .then(res => res.json())
        .then(response => {
            if (response.success && response.updatedRowHtml) {
                // Append new row
                const tempDiv = document.createElement("tbody");
                tempDiv.innerHTML = response.updatedRowHtml.trim();
                const newRow = tempDiv.firstChild;

                // Add row to DataTable
                dataTable.rows().add([newRow]);

                // Close modal
                bootstrap.Modal.getInstance(document.getElementById("addCategoryModal")).hide();
                addForm.reset();
            } else if (response.error) {
                alert(response.error);
            }
        })
        .catch(err => console.error(err));
    });

    // ==========================
    // OPEN EDIT MODAL
    // ==========================
    tableBody.addEventListener("click", (e) => {
        const btn = e.target.closest(".editCategoryBtn");
        if (!btn) return;

        document.getElementById("editCategoryId").value = btn.dataset.id;
        document.getElementById("editCategoryName").value = btn.dataset.name;
    });

    // ==========================
    // EDIT CATEGORY
    // ==========================
    editForm.addEventListener("submit", (e) => {
        e.preventDefault();
        const formData = new FormData(editForm);
        formData.append("edit_category", true);

        fetch("/inventory_system/http/ajax/category_ajax.php", {
            method: "POST",
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.updatedRowHtml) {
                const id = document.getElementById("editCategoryId").value;
                const oldRow = document.getElementById(`categoryRow${id}`);

                if (oldRow) {
                    // Replace row in DataTable
                    const tempDiv = document.createElement("tbody");
                    tempDiv.innerHTML = data.updatedRowHtml.trim();
                    const newRow = tempDiv.firstChild;

                    dataTable.rows().update(oldRow, newRow);
                }

                editForm.reset();
                bootstrap.Modal.getInstance(document.getElementById("editCategoryModal")).hide();
            } else if (data.error) {
                alert(data.error);
            }
        })
        .catch(err => console.error(err));
    });

    // ==========================
    // TOGGLE STATUS WITH CONFIRMATION
    // ==========================
    tableBody.addEventListener("click", (e) => {
        const btn = e.target.closest(".toggleCategoryStatusBtn");
        if (!btn) return;

        const row = btn.closest("tr");
        const categoryName = row.querySelector("td:nth-child(2)").innerText;
        const currentStatus = btn.dataset.status;
        const newStatus = currentStatus === "active" ? "inactive" : "active";

        if (!confirm(`Are you sure you want to ${newStatus} "${categoryName}"?`)) return;

        const formData = new FormData();
        formData.append("toggle_id", btn.dataset.id);

        fetch("/inventory_system/http/ajax/category_ajax.php", {
            method: "POST",
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.updatedRowHtml) {
                const oldRow = document.getElementById(`categoryRow${btn.dataset.id}`);
                if (oldRow) {
                    const tempDiv = document.createElement("tbody");
                    tempDiv.innerHTML = data.updatedRowHtml.trim();
                    const newRow = tempDiv.firstChild;

                    dataTable.rows().update(oldRow, newRow);
                }
            } else if (data.error) {
                alert(data.error);
            }
        })
        .catch(err => console.error(err));
    });
});
