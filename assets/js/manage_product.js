document.addEventListener("DOMContentLoaded", function () {

    const BASE_URL = `${window.location.origin}/inventory_system/http/ajax/`;

    // ✅ Initialize Simple-DataTables
    const tableElement = document.querySelector("#productsTable");
    const dataTable = tableElement
        ? new simpleDatatables.DataTable(tableElement, {
            searchable: true,
            fixedHeight: true,
            perPage: 10
        })
        : null;

    // ✅ SweetAlert Toast
    const Toast = Swal.mixin({
        toast: true,
        position: "top-end",
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
    });

    function showToast(msg, icon = "success") {
        Toast.fire({ icon: icon, title: msg });
    }

    // ===============================
    // REBIND EVENTS (IMPORTANT)
    // ===============================
    function bindEvents() {

        document.querySelectorAll(".editProductBtn").forEach(btn => {
            btn.onclick = function () {
                const d = this.dataset;

                document.getElementById("editProductId").value = d.id;
                document.getElementById("editProductName").value = d.name || "";
                document.getElementById("editProductSku").value = d.sku || "";
                document.getElementById("editProductCategory").value = d.category || "";
                document.getElementById("editProductSupplierSelect").value = d.supplier || "";
                document.getElementById("editProductPrice").value = d.price || 0;
                document.getElementById("editProductSalePrice").value = d.sale_price || "";
                document.getElementById("editProductVatable").value = d.vatable || 0;
                document.getElementById("editProductReorderLevel").value = d.reorder || 5;
                document.getElementById("editProductPhotoPreview").src = d.photo;

                new bootstrap.Modal(document.getElementById("editProductModal")).show();
            };
        });

        document.querySelectorAll(".toggleProductStatusBtn").forEach(btn => {
            btn.onclick = function () {

                const id = this.dataset.id;

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

                            if (!data.success) {
                                showToast(data.error || "Server error", "error");
                                return;
                            }

                            showToast(data.message);

                            // ✅ EASIEST SAFE WAY:
                            // Reload page so Simple-DataTables refreshes cleanly
                            setTimeout(() => location.reload(), 800);
                        })
                        .catch(() => showToast("Server error!", "error"));
                });
            };
        });
    }

    bindEvents();

    // ===============================
    // ADD PRODUCT
    // ===============================
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

                    if (!data.success) {
                        showToast(data.error || "Add failed", "error");
                        return;
                    }

                    showToast(data.message);

                    setTimeout(() => location.reload(), 800);
                })
                .catch(() => showToast("Server error!", "error"));
        });
    }

    // ===============================
    // EDIT PRODUCT
    // ===============================
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
                .then(r => r.json())
                .then(data => {

                    if (!data.success) {
                        showToast(data.error || "Update failed", "error");
                        return;
                    }

                    showToast(data.message);

                    setTimeout(() => location.reload(), 800);
                })
                .catch(() => showToast("Server error!", "error"));
        });
    }

});