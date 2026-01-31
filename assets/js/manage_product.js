document.addEventListener("DOMContentLoaded", function() {

    const productMessages = document.getElementById('productMessages');
    const productsTableBody = document.querySelector('#productsTable tbody');

    const addProductModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('addProductModal'));
    const editProductModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('editProductModal'));

    // ==========================
    // HELPER: SHOW MESSAGES
    // ==========================
    const showMessage = (type, msg) => {
        if (!productMessages) return;
        productMessages.innerHTML = `
            <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                ${msg}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>`;
        setTimeout(() => { productMessages.innerHTML = ''; }, 4000);
    };

    // ==========================
    // ATTACH EVENTS TO ROW
    // ==========================
    function attachRowEvents(row) {
        const editBtn = row.querySelector('.editProductBtn');
        const toggleBtn = row.querySelector('.toggleProductStatusBtn');

        if(editBtn) {
            editBtn.addEventListener('click', function() {
                const productId = this.dataset.id;
             document.getElementById('editProductId').value = this.dataset.id;
    document.getElementById('editProductName').value = this.dataset.name;
    document.getElementById('editProductCategory').value = this.dataset.category;

    // ✅ correct ID
    document.getElementById('editProductSupplierSelect').value = this.dataset.supplier;

    // ✅ now SKU exists
    document.getElementById('editProductSku').value = this.dataset.sku || '';

    // ✅ PRICE (this was not running before)
    document.getElementById('editProductPrice').value = this.dataset.price;
    document.getElementById('editProductSalePrice').value = this.dataset.sale_price || '';

    document.getElementById('editProductVatable').value = this.dataset.vatable;
    document.getElementById('editProductReorderLevel').value = this.dataset.reorder;

    // ✅ PHOTO PREVIEW (file input stays empty – correct behavior)
    document.getElementById('editProductPhotoPreview').src =
        this.dataset.photo || '/inventory_system/assets/uploads/products/images.jpeg';
               
console.log(this.dataset);
                editProductModal.show();
            });
        }

        if(toggleBtn) {
            toggleBtn.addEventListener('click', function() {
                const productId = this.dataset.id;
                if(!confirm(`Are you sure you want to change this product's status?`)) return;

                const formData = new FormData();
                formData.append('toggle_id', productId);

                fetch('/inventory_system/http/ajax/product_actions.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.error) return showMessage('danger', data.error);
                    if (data.success) {
                        showMessage('success', data.success);
                        const row = document.getElementById('productRow' + productId);
                        const badge = row.querySelector('span.badge');
                        const toggleBtn = row.querySelector('.toggleProductStatusBtn');

                        if(data.new_status === 'active') {
                            badge.textContent = 'Active';
                            badge.classList.replace('bg-secondary', 'bg-success');
                            toggleBtn.classList.replace('btn-success', 'btn-danger');
                            toggleBtn.dataset.status = 'deactivate';
                            toggleBtn.innerHTML = '<i class="bi bi-slash-circle"></i>';
                        } else {
                            badge.textContent = 'Inactive';
                            badge.classList.replace('bg-success', 'bg-secondary');
                            toggleBtn.classList.replace('btn-danger', 'btn-success');
                            toggleBtn.dataset.status = 'activate';
                            toggleBtn.innerHTML = '<i class="bi bi-check-circle"></i>';
                        }
                    }
                })
                .catch(() => showMessage('danger', 'Something went wrong!'));
            });
        }
    }

    // Attach events to existing rows
    document.querySelectorAll('#productsTable tbody tr').forEach(row => attachRowEvents(row));

    // ==========================
    // ADD PRODUCT
    // ==========================
    const addForm = document.getElementById('addProductForm');
    if (addForm) {
        addForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const submitBtn = this.querySelector("button[type='submit']");
            submitBtn.disabled = true;

            const formData = new FormData(this);
            formData.append('add_product', true);

            fetch('/inventory_system/http/ajax/product_actions.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if(data.error) showMessage('danger', data.error);
                if(data.success) {
                    showMessage('success', data.success);

                    const temp = document.createElement('tbody');
                    temp.innerHTML = data.newProductRow;
                    const newRow = temp.firstElementChild;
                    productsTableBody.prepend(newRow);
                    attachRowEvents(newRow);

                    addProductModal.hide();
                    this.reset();
                    document.getElementById('addProductPhotoPreview').src = '/inventory_system/assets/uploads/products/images.jpeg';
                }
            })
            .catch(() => showMessage('danger', 'Something went wrong!'))
            .finally(() => submitBtn.disabled = false);
        });
    }

    // ==========================
    // EDIT PRODUCT
    // ==========================
    const editForm = document.getElementById('editProductForm');
    if(editForm) {
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const submitBtn = this.querySelector("button[type='submit']");
            submitBtn.disabled = true;

            const formData = new FormData(this);
            formData.append('edit_product', true);

            fetch('/inventory_system/http/ajax/product_actions.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if(data.error) showMessage('danger', data.error);
                if(data.success) {
                    showMessage('success', data.success);

                    const productId = document.getElementById('editProductId').value;
                    const row = document.getElementById('productRow' + productId);

                    const temp = document.createElement('tbody');
                    temp.innerHTML = data.updatedRowHtml;
                    const newRow = temp.firstElementChild;

                    row.replaceWith(newRow);
                    attachRowEvents(newRow);

                    editProductModal.hide();
                    this.reset();
                }
            })
            .catch(() => showMessage('danger', 'Something went wrong!'))
            .finally(() => submitBtn.disabled = false);
        });
    }

});


/* ===============================
   ADD SUPPLIER (FROM ADD PRODUCT)
   =============================== */
document.getElementById('addSupplierForm').addEventListener('submit', function(e) {
    e.preventDefault();

    let form = this;
    let messageBox = document.getElementById('supplierMessage');
    let formData = new FormData(form);

    fetch('/inventory_system/http/ajax/add_supplier.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {

            // Show success message
            messageBox.innerHTML = `<div class="alert alert-success">${data.message}</div>`;

            // Add supplier to ADD PRODUCT dropdown
            let select = document.getElementById('addProductSupplierSelect');
            let option = document.createElement('option');
            option.value = data.id;
            option.textContent = data.name;
            option.selected = true;
            select.appendChild(option);

            // Clear form
            form.reset();

            // Close supplier modal and go back to ADD PRODUCT modal
            setTimeout(() => {
                let supplierModalEl = document.getElementById('addSupplierModal');
                let supplierModal = bootstrap.Modal.getInstance(supplierModalEl);
                supplierModal.hide();

                messageBox.innerHTML = '';

                // Re-open Add Product modal
                let addProductModal = new bootstrap.Modal(document.getElementById('addProductModal'));
                addProductModal.show();

            }, 600);

        } else {
            messageBox.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
        }
    })
    .catch(err => {
        messageBox.innerHTML = `<div class="alert alert-danger">Server error. Check console.</div>`;
        console.error(err);
    });
});


/* ===============================
   ADD SUPPLIER (FROM EDIT PRODUCT)
   =============================== */
document.getElementById('editAddSupplierForm').addEventListener('submit', function(e) {
    e.preventDefault();

    let form = this;
    let messageBox = document.getElementById('editSupplierMessage');
    let formData = new FormData(form);

    fetch('/inventory_system/http/ajax/add_supplier.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {

            // Show success message
            messageBox.innerHTML = `<div class="alert alert-success">${data.message}</div>`;

            // Add supplier to EDIT PRODUCT dropdown
            let select = document.getElementById('editProductSupplierSelect');
            let option = document.createElement('option');
            option.value = data.id;
            option.textContent = data.name;
            option.selected = true;
            select.appendChild(option);

            // Clear form
            form.reset();

            // Close supplier modal and go back to EDIT PRODUCT modal
            setTimeout(() => {
                let supplierModalEl = document.getElementById('editAddSupplierModal');
                let supplierModal = bootstrap.Modal.getInstance(supplierModalEl);
                supplierModal.hide();

                messageBox.innerHTML = '';

                // Re-open Edit Product modal
                let editProductModal = new bootstrap.Modal(document.getElementById('editProductModal'));
                editProductModal.show();

            }, 600);

        } else {
            messageBox.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
        }
    })
    .catch(err => {
        messageBox.innerHTML = `<div class="alert alert-danger">Server error. Check console.</div>`;
        console.error(err);
    });
});
