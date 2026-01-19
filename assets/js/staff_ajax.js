document.addEventListener("DOMContentLoaded", function() {

    const staffMessages = document.getElementById('staffMessages');

    const showMessage = (type, msg) => {
        staffMessages.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show" role="alert">
            ${msg}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>`;
        setTimeout(() => {
            staffMessages.innerHTML = '';
        }, 4000);
    };

    const addStaffModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('addStaffModal'));
    const editStaffModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('editStaffModal'));
    const staffTableBody = document.querySelector('#staffTable tbody');

    // ==========================
    // Add Staff AJAX
    // ==========================
    const addForm = document.getElementById('addStaffForm');
    if(addForm) {
        addForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const submitBtn = this.querySelector("button[type='submit']");
            submitBtn.disabled = true;

            const formData = new FormData(this);
            formData.append('add_staff', true);

            fetch('/inventory_system/admin/manage_staff.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if(data.error) showMessage('danger', data.error);
                if(data.success) {
                    showMessage('success', data.success);

                    // Dynamically insert new row at top
                    const temp = document.createElement('tbody');
                    temp.innerHTML = data.newStaffRow;
                    const newRow = temp.firstElementChild;
                    staffTableBody.prepend(newRow);

                    attachRowEvents(newRow); // Attach edit/status events to new row

                    addStaffModal.hide();
                    this.reset();
                    document.getElementById('addStaffPhotoPreview').src = '/inventory_system/assets/img/default-user.png';
                }
            })
            .catch(err => showMessage('danger', 'Something went wrong!'))
            .finally(() => submitBtn.disabled = false);
        });
    }

    // ==========================
    // Edit Staff AJAX
    // ==========================
    const editForm = document.getElementById('editStaffForm');
    if(editForm) {
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const submitBtn = this.querySelector("button[type='submit']");
            submitBtn.disabled = true;

            const formData = new FormData(this);
            formData.append('edit_staff', true);

            fetch('/inventory_system/admin/manage_staff.php', {
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
                    const row = document.getElementById('staffRow' + data.staff_id);
                    const temp = document.createElement('tbody');
                    temp.innerHTML = data.updatedRowHtml;
                    const newRow = temp.firstElementChild;
                    row.replaceWith(newRow);

                    attachRowEvents(newRow); // re-attach events
                    editStaffModal.hide();
                    this.reset();
                }
            })
            .catch(err => showMessage('danger', 'Something went wrong!'))
            .finally(() => submitBtn.disabled = false);
        });
    }

    // ==========================
    // Toggle Status AJAX
    // ==========================
    function attachToggleEvent(btn) {
        btn.addEventListener('click', function() {
            if(!confirm('Are you sure?')) return;
            const staffId = this.dataset.id;

            const formData = new FormData();
            formData.append('toggle_id', staffId);

            fetch('/inventory_system/admin/manage_staff.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if(data.error) showMessage('danger', data.error);
                if(data.success) {
                    showMessage('success', data.success);

                    // Update the status badge and toggle button dynamically
                    const row = document.getElementById('staffRow' + staffId);
                    const statusBadge = row.querySelector(`#staffStatus${staffId}`);
                    const toggleBtn = row.querySelector('.toggleStatusBtn');

                    if (statusBadge.textContent.toLowerCase() === 'active') {
                        statusBadge.textContent = 'Inactive';
                        statusBadge.classList.remove('bg-success');
                        statusBadge.classList.add('bg-secondary');
                        toggleBtn.classList.remove('btn-secondary');
                        toggleBtn.classList.add('btn-success');
                        toggleBtn.querySelector('i').className = 'bi bi-person-check';
                    } else {
                        statusBadge.textContent = 'Active';
                        statusBadge.classList.remove('bg-secondary');
                        statusBadge.classList.add('bg-success');
                        toggleBtn.classList.remove('btn-success');
                        toggleBtn.classList.add('btn-secondary');
                        toggleBtn.querySelector('i').className = 'bi bi-person-x';
                    }
                }
            })
            .catch(err => showMessage('danger', 'Something went wrong!'));
        });
    }

    // Attach events to initial rows
    function attachRowEvents(row) {
        const editBtn = row.querySelector('.editStaffBtn');
        editBtn.addEventListener('click', function() {
            document.getElementById('editStaffId').value = this.dataset.id;
            document.getElementById('editFirstname').value = this.dataset.firstname;
            document.getElementById('editLastname').value = this.dataset.lastname;
            document.getElementById('editEmail').value = this.dataset.email;
            document.getElementById('editUsername').value = this.dataset.username;
            document.getElementById('editStaffPhotoPreview').src = this.dataset.photo;
            document.querySelector("#editStaffForm select[name='role']").value = this.dataset.role;
        });

        const toggleBtn = row.querySelector('.toggleStatusBtn');
        attachToggleEvent(toggleBtn);
    }

    // Attach events to all existing rows
    document.querySelectorAll('#staffTable tbody tr').forEach(row => attachRowEvents(row));

});
