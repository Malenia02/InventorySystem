document.addEventListener("DOMContentLoaded", function() {

    const productMessages = document.getElementById('productMessages');

    const showMessage = (type, msg) => {
        if (!productMessages) return;
        productMessages.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show" role="alert">
            ${msg}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>`;
        setTimeout(() => { productMessages.innerHTML = ''; }, 4000);
    };

    const addProductModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('addProductModal'));
    const editProductModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('editProductModal'));
    const productsTableBody = document.querySelector('#productsTable tbody');

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
                if (data.error) showMessage('danger', data.error);
                if (data.success) {
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
    if (editForm) {
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
                if (data.error) showMessage('danger', data.error);
                if (data.success) {
                    showMessage('success', data.success);
                    const row = document.getElementById('productRow' + editForm.product_id.value);
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
    // TOGGLE STATUS
    // ==========================
    function attachToggleEvent(btn) {
        btn.addEventListener('click', function() {
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
                        badge.classList.remove('bg-secondary');
                        badge.classList.add('bg-success');
                        toggleBtn.classList.remove('btn-success');
                        toggleBtn.classList.add('btn-danger');
                        toggleBtn.dataset.status = 'deactivate';
                        toggleBtn.innerHTML = '<i class="bi bi-slash-circle"></i>';
                        // Ensure row is visible if previously removed
                        if(!row.parentNode) productsTableBody.prepend(row);
                    } else {
                        badge.textContent = 'Inactive';
                        badge.classList.remove('bg-success');
                        badge.classList.add('bg-secondary');
                        toggleBtn.classList.remove('btn-danger');
                        toggleBtn.classList.add('btn-success');
                        toggleBtn.dataset.status = 'activate';
                        toggleBtn.innerHTML = '<i class="bi bi-check-circle"></i>';
                        // Optionally remove from POS table if you have a separate POS table
                    }
                }
            })
            .catch(() => showMessage('danger', 'Something went wrong!'));
        });
    }

    // ==========================
    // ATTACH EVENTS TO ROWS
    // ==========================
    function attachRowEvents(row) {
        const editBtn = row.querySelector('.editProductBtn');
        const toggleBtn = row.querySelector('.toggleProductStatusBtn');

        // Edit modal
        editBtn.addEventListener('click', function() {
            document.getElementById('editProductId').value = this.dataset.id;
            document.getElementById('editProductName').value = this.dataset.name;
            document.getElementById('editProductCategory').value = this.dataset.category;
            document.getElementById('editProductPrice').value = this.dataset.price;
            document.getElementById('editProductSalePrice').value = this.dataset.sale_price;
            document.getElementById('editProductVatable').value = this.dataset.vatable;
            document.getElementById('editProductPhotoPreview').src = this.dataset.photo;
        });

        attachToggleEvent(toggleBtn);
    }

    // Attach to existing rows
    document.querySelectorAll('#productsTable tbody tr').forEach(row => attachRowEvents(row));

});
