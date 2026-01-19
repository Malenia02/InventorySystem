document.addEventListener("DOMContentLoaded", function() {

    const productMessages = document.getElementById('productMessages');

    const showMessage = (type, msg) => {
        productMessages.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show" role="alert">
            ${msg}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>`;
        setTimeout(() => {
            productMessages.innerHTML = '';
        }, 4000);
    };

    const addProductModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('addProductModal'));
    const editProductModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('editProductModal'));
    const productsTableBody = document.querySelector('#productsTable tbody');

    // ==========================
    // Add Product AJAX
    // ==========================
    const addForm = document.getElementById('addProductForm');
    if(addForm) {
        addForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const submitBtn = this.querySelector("button[type='submit']");
            submitBtn.disabled = true;

            const formData = new FormData(this);
            formData.append('add_product', true);

            fetch('/inventory_system/admin/manage_product.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if(data.error) showMessage('danger', data.error);
                if(data.success) {
                    showMessage('success', data.success);

                    // Insert new row dynamically
                    const temp = document.createElement('tbody');
                    temp.innerHTML = data.newProductRow;
                    const newRow = temp.firstElementChild;
                    productsTableBody.prepend(newRow);

                    attachRowEvents(newRow); // attach edit/toggle events

                    addProductModal.hide();
                    this.reset();
                    document.getElementById('addProductPhotoPreview').src = '/inventory_system/assets/uploads/products/images.jpeg';
                }
            })
            .catch(err => showMessage('danger', 'Something went wrong!'))
            .finally(() => submitBtn.disabled = false);
        });
    }

    // ==========================
    // Edit Product AJAX
    // ==========================
    const editForm = document.getElementById('editProductForm');
    if(editForm) {
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const submitBtn = this.querySelector("button[type='submit']");
            submitBtn.disabled = true;

            const formData = new FormData(this);
            formData.append('edit_product', true);

            fetch('/inventory_system/admin/manage_product.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if(data.error) showMessage('danger', data.error);
                if(data.success) {
                    showMessage('success', data.success);

                    // Replace the existing row HTML
                    const row = document.getElementById('productRow' + data.product_id);
                    const temp = document.createElement('tbody');
                    temp.innerHTML = data.updatedRowHtml;
                    const newRow = temp.firstElementChild;
                    row.replaceWith(newRow);

                    attachRowEvents(newRow);
                    editProductModal.hide();
                    this.reset();
                }
            })
            .catch(err => showMessage('danger', 'Something went wrong!'))
            .finally(() => submitBtn.disabled = false);
        });
    }

    // ==========================
    // Toggle Product Status AJAX
    // ==========================
    function attachToggleEvent(btn) {
        btn.addEventListener('click', function() {
            const productId = this.dataset.id;
            const action = this.dataset.status;

            if(!confirm(`Are you sure you want to ${action} this product?`)) return;

            const formData = new FormData();
            formData.append('toggle_id', productId);

            fetch('/inventory_system/admin/manage_product.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if(data.error) showMessage('danger', data.error);
                if(data.success) {
                    showMessage('success', data.success);

                    // Update status badge and toggle button dynamically
                    const row = document.getElementById('productRow' + productId);
                    const badge = row.querySelector('span.badge');
                    const toggleBtn = row.querySelector('.toggleProductStatusBtn');

                    if(action === 'deactivate') {
                        badge.textContent = 'Inactive';
                        badge.classList.remove('bg-success');
                        badge.classList.add('bg-secondary');
                        toggleBtn.classList.remove('btn-danger');
                        toggleBtn.classList.add('btn-success');
                        toggleBtn.dataset.status = 'activate';
                        toggleBtn.innerHTML = '<i class="bi bi-check-circle"></i>';
                    } else {
                        badge.textContent = 'Active';
                        badge.classList.remove('bg-secondary');
                        badge.classList.add('bg-success');
                        toggleBtn.classList.remove('btn-success');
                        toggleBtn.classList.add('btn-danger');
                        toggleBtn.dataset.status = 'deactivate';
                        toggleBtn.innerHTML = '<i class="bi bi-slash-circle"></i>';
                    }
                }
            })
            .catch(err => showMessage('danger', 'Something went wrong!'));
        });
    }

    // ==========================
    // Attach events to each row
    // ==========================
    function attachRowEvents(row) {
        // Edit Button
        const editBtn = row.querySelector('.editProductBtn');
        editBtn.addEventListener('click', function() {
            document.getElementById('editProductId').value = this.dataset.id;
            document.getElementById('editProductName').value = this.dataset.name;
            document.getElementById('editProductCategory').value = this.dataset.category;
            document.getElementById('editProductPrice').value = this.dataset.price;
            document.getElementById('editProductSalePrice').value = this.dataset.sale_price;
            document.getElementById('editProductVatable').value = this.dataset.vatable;
            document.getElementById('editProductPhotoPreview').src = this.dataset.photo;
        });

        // Toggle Status
        const toggleBtn = row.querySelector('.toggleProductStatusBtn');
        attachToggleEvent(toggleBtn);
    }

    // Attach events to existing rows
    document.querySelectorAll('#productsTable tbody tr').forEach(row => attachRowEvents(row));

});
