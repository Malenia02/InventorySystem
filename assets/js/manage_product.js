document.addEventListener("DOMContentLoaded", function() {
const BASE_URL = `${window.location.origin}/inventory_system/http/ajax/`;
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
                const dataset = this.dataset;
                document.getElementById('editProductId').value = dataset.id;
                document.getElementById('editProductName').value = dataset.name || '';
                document.getElementById('editProductCategory').value = dataset.category || '';

                // SAFE supplier handling
                const supplierSelect = document.getElementById('editProductSupplierSelect');
                if (dataset.supplier && dataset.supplier !== '0') {
                    supplierSelect.value = dataset.supplier;
                } else {
                    supplierSelect.value = '';
                }

                document.getElementById('editProductSku').value = dataset.sku || '';
                document.getElementById('editProductPrice').value = dataset.price || '';
                document.getElementById('editProductSalePrice').value = dataset.sale_price || '';
                document.getElementById('editProductVatable').value = dataset.vatable || 0;
                document.getElementById('editProductReorderLevel').value = dataset.reorder || 5;
                document.getElementById('editProductPhotoPreview').src = dataset.photo || '/inventory_system/assets/uploads/products/images.jpeg';

                editProductModal.show();
            });
        }

        if(toggleBtn) {
            toggleBtn.addEventListener('click', function() {
                const productId = this.dataset.id;
                if(!confirm(`Are you sure you want to change this product's status?`)) return;

                const formData = new FormData();
                formData.append('toggle_id', productId);
                formData.append('csrf_token', CSRF_TOKEN); // ✅ keep CSRF

                fetch(BASE_URL +'product_actions.php', {
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

                        if(data.new_status === 'active') {
                            badge.textContent = 'Active';
                            badge.classList.replace('bg-secondary', 'bg-success');
                            toggleBtn.classList.replace('btn-success', 'btn-danger');
                            toggleBtn.innerHTML = '<i class="bi bi-slash-circle"></i>';
                        } else {
                            badge.textContent = 'Inactive';
                            badge.classList.replace('bg-success', 'bg-secondary');
                            toggleBtn.classList.replace('btn-danger', 'btn-success');
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

            // ✅ keep CSRF token from hidden input

            fetch(BASE_URL +'product_actions.php', {
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

            // FRONTEND VALIDATION: Supplier must be valid
            const supplierValue = document.getElementById('editProductSupplierSelect').value;
            if (supplierValue !== '' && isNaN(parseInt(supplierValue))) {
                showMessage('danger', 'Invalid supplier selected.');
                submitBtn.disabled = false;
                return;
            }

            const formData = new FormData(this);
            formData.append('edit_product', true);
            formData.append('csrf_token', CSRF_TOKEN); // ✅ keep CSRF

            fetch(BASE_URL +'product_actions.php', {
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

    // ==========================
    // ADD SUPPLIER (FROM ADD PRODUCT)
    // ==========================
    const addSupplierForm = document.getElementById('addSupplierForm');
    if(addSupplierForm) {
        addSupplierForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);

            fetch(BASE_URL +'product_actions.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                const messageBox = document.getElementById('supplierMessage');
                if(data.success) {
                    messageBox.innerHTML = `<div class="alert alert-success">${data.message}</div>`;

                    const select = document.getElementById('addProductSupplierSelect');
                    const option = document.createElement('option');
                    option.value = data.id;
                    option.textContent = data.name;
                    option.selected = true;
                    select.appendChild(option);

                    this.reset();

                    setTimeout(() => {
                        const supplierModal = bootstrap.Modal.getInstance(document.getElementById('addSupplierModal'));
                        supplierModal.hide();
                        messageBox.innerHTML = '';
                        addProductModal.show();
                    }, 600);

                } else {
                    messageBox.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
                }
            })
            .catch(err => {
                document.getElementById('supplierMessage').innerHTML = `<div class="alert alert-danger">Server error. Check console.</div>`;
                console.error(err);
            });
        });
    }

    // ==========================
    // ADD SUPPLIER (FROM EDIT PRODUCT)
    // ==========================
    const editAddSupplierForm = document.getElementById('editAddSupplierForm');
    if(editAddSupplierForm) {
        editAddSupplierForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);

            fetch(BASE_URL +'product_actions.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                const messageBox = document.getElementById('editSupplierMessage');
                if(data.success) {
                    messageBox.innerHTML = `<div class="alert alert-success">${data.message}</div>`;

                    const select = document.getElementById('editProductSupplierSelect');
                    const option = document.createElement('option');
                    option.value = data.id;
                    option.textContent = data.name;
                    option.selected = true;
                    select.appendChild(option);

                    this.reset();

                    setTimeout(() => {
                        const supplierModal = bootstrap.Modal.getInstance(document.getElementById('editAddSupplierModal'));
                        supplierModal.hide();
                        messageBox.innerHTML = '';
                        editProductModal.show();
                    }, 600);

                } else {
                    messageBox.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
                }
            })
            .catch(err => {
                document.getElementById('editSupplierMessage').innerHTML = `<div class="alert alert-danger">Server error. Check console.</div>`;
                console.error(err);
            });
        });
    }

});
