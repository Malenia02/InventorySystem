document.addEventListener("DOMContentLoaded", function () {

    const BASE_URL = `${window.location.origin}/inventory_system/http/ajax/`;

    // ===========================
    // SIMPLE-DATATABLE
    // ===========================
    const tableElement = document.querySelector("#productsTable");
    const dataTable = tableElement
        ? new simpleDatatables.DataTable(tableElement, {
            searchable: true,
            fixedHeight: true,
            perPage: 10
        })
        : null;

    // ===========================
    // SWEETALERT TOAST
    // ===========================
    const Toast = Swal.mixin({
        toast: true,
        position: "top-end",
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
    });

    const showToast = (msg, icon = "success") =>
        Toast.fire({ icon, title: msg });

    // ===========================
    // MODAL BACKDROP FIX
    // ===========================
    document.querySelectorAll(".modal").forEach(modal => {
        modal.addEventListener("hidden.bs.modal", () => {
            document.querySelectorAll(".modal-backdrop").forEach(b => b.remove());
            document.body.classList.remove("modal-open");
            document.body.style.overflow = "";
        });
    });

    // ===========================
    // ONE GLOBAL EVENT HANDLER
    // ===========================
    document.addEventListener("click", function (e) {

        /* =====================
           EDIT PRODUCT
        ===================== */
        const editBtn = e.target.closest(".editProductBtn");
        if (editBtn) {
            const d = editBtn.dataset;

            document.getElementById("editProductId").value = d.id;
            document.getElementById("editProductName").value = d.name || "";
            document.getElementById("editProductSku").value = d.sku || "";
            document.getElementById("editProductCategory").value = d.category || "";
            document.getElementById("editProductSupplierSelect").value = d.supplier || "";
            document.getElementById("editProductPrice").value =
                d.price !== undefined ? d.price : 0;
            document.getElementById("editProductSalePrice").value =
                d.sale_price ?? "";
            document.getElementById("editProductVatable").value =
                d.vatable !== undefined ? d.vatable : 0;
            document.getElementById("editProductReorderLevel").value =
                d.reorder !== undefined ? d.reorder : 5;
            document.getElementById("editProductPhotoPreview").src =
                d.photo || "/inventory_system/assets/uploads/products/images.jpeg";

            new bootstrap.Modal(
                document.getElementById("editProductModal")
            ).show();
            return;
        }

        /* =====================
   TOGGLE STATUS
===================== */
const toggleBtn = e.target.closest(".toggleProductStatusBtn");
if (toggleBtn) {
    const id = toggleBtn.dataset.id;

    Swal.fire({
        title: "Change product status?",
        icon: "warning",
        showCancelButton: true,
        confirmButtonText: "Yes"
    }).then(result => {
        if (!result.isConfirmed) return;

        const fd = new FormData();
        fd.append("toggle_id", id);
        fd.append("csrf_token", CSRF_TOKEN);

        fetch(BASE_URL + "product_actions.php", {
            method: "POST",
            body: fd
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success)
                return showToast(data.error || "Server error", "error");

            showToast(data.message);

            const row = document.getElementById("productRow" + id);
            if (!row) return;

            const badge = row.querySelector(".badge");
            const btn = row.querySelector(".toggleProductStatusBtn");

            // Capitalize for display only
            const statusDisplay =
                data.new_status.charAt(0).toUpperCase() + data.new_status.slice(1);
            badge.textContent = statusDisplay;

            // Use lowercase for logic
            if (data.new_status === "active") {
                badge.classList.replace("bg-secondary", "bg-success");
                btn.classList.replace("btn-success", "btn-danger");
                btn.innerHTML = '<i class="bi bi-slash-circle"></i>';
            } else {
                badge.classList.replace("bg-success", "bg-secondary");
                btn.classList.replace("btn-danger", "btn-success");
                btn.innerHTML = '<i class="bi bi-check-circle"></i>';
            }
        })
        .catch(err => {
            console.error(err);
            showToast("Server error!", "error");
        });
    });

    return;
}

        /* =====================
           RESTOCK BUTTON
        ===================== */
        const restockBtn = e.target.closest(".restock-btn");
        if (restockBtn) {
            document.getElementById("restockProductId").value =
                restockBtn.dataset.id;
            document.getElementById("restockProductName").value =
                restockBtn.dataset.name;

            new bootstrap.Modal(
                document.getElementById("restockModal")
            ).show();
        }
    });

    // ===========================
    // ADD PRODUCT
    // ===========================
    const addForm = document.getElementById("addProductForm");
    if (addForm) {
        addForm.addEventListener("submit", function (e) {
            e.preventDefault();

            const fd = new FormData(this);
            fd.append("add_product", true);

            fetch(BASE_URL + "product_actions.php", {
                method: "POST",
                body: fd
            })
                .then(r => r.json())
                .then(data => {
                    if (!data.success)
                        return showToast(data.error || "Add failed", "error");

                    showToast(data.message);

                    if (data.newRowHtml && dataTable) {
                        const temp = document.createElement("div");
                        temp.innerHTML = data.newRowHtml.trim();
                        const row = temp.querySelector("tr");

                        if (row) {
                            tableElement.querySelector("tbody").appendChild(row);
                            dataTable.refresh();
                        }
                    }

                    this.reset();
                    bootstrap.Modal
                        .getInstance(document.getElementById("addProductModal"))
                        ?.hide();
                })
                .catch(err => {
                    console.error(err);
                    showToast("Server error!", "error");
                });
        });
    }
// ===========================
// EDIT PRODUCT FORM SUBMIT
// ===========================
const editForm = document.getElementById("editProductForm");

if (editForm) {
    editForm.addEventListener("submit", function (e) {
        e.preventDefault();

        const fd = new FormData(this);
        fd.append("edit_product", true);

        fetch(BASE_URL + "product_actions.php", {
            method: "POST",
            body: fd
        })
        .then(res => res.json())
        .then(data => {

            if (!data.success) {
                showToast(data.error || "Update failed", "error");
                return;
            }

            // Toast
            Swal.fire({
                toast: true,
                position: "top-end",
                html: data.message,
                icon: "success",
                showConfirmButton: false,
                timer: 4000,
                timerProgressBar: true
            });

            const id = document.getElementById("editProductId").value;
            const oldRow = document.getElementById("productRow" + id);

            if (oldRow && data.newRowHtml) {

                const temp = document.createElement("tbody");
                temp.innerHTML = data.newRowHtml.trim();
                const newRow = temp.querySelector("tr");

                if (newRow) {
                    oldRow.replaceWith(newRow);

                    // 🔥 IMPORTANT FOR simple-datatables
                    if (dataTable) {
                        dataTable.destroy();
                    }
                }
            }

            bootstrap.Modal
                .getInstance(document.getElementById("editProductModal"))
                ?.hide();

            editForm.reset();
        })
        .catch(err => {
            console.error(err);
            showToast("Server error!", "error");
        });
    });
}

    // ===========================
    // RESTOCK FORM
    // ===========================
    const restockForm = document.getElementById("restockForm");
    if (restockForm) {
        restockForm.addEventListener("submit", function (e) {
            e.preventDefault();

            const fd = new FormData(this);
            fd.append("restock_product", true);

            fetch(BASE_URL + "product_actions.php", {
                method: "POST",
                body: fd
            })
                .then(r => r.json())
                .then(data => {
                    if (!data.success)
                        return showToast(data.error || "Restock failed", "error");

                    showToast(data.message);

                    const row = document.getElementById(
                        "productRow" + data.product_id
                    );
                    if (row) {
                        const qty = row.querySelector(".product-quantity");
                        if (qty) {
                            qty.textContent = data.new_quantity;
                            qty.classList.add("fw-bold");
                            setTimeout(() => qty.classList.remove("fw-bold"), 700);
                        }
                    }

                    bootstrap.Modal
                        .getInstance(document.getElementById("restockModal"))
                        ?.hide();

                    this.reset();
                })
                .catch(err => {
                    console.error(err);
                    showToast("Server error!", "error");
                });
        });
    }

});