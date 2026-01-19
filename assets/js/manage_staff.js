document.addEventListener("DOMContentLoaded", function() {

    const staffMessages = document.getElementById('staffMessages');

    const showMessage = (type, msg) => {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
        alertDiv.role = 'alert';
        alertDiv.innerHTML = `${msg}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
        staffMessages.appendChild(alertDiv);
        setTimeout(() => alertDiv.remove(), 4000);
    };

    const addStaffModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('addStaffModal'));
    const editStaffModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('editStaffModal'));

    const addPhotoInput = document.getElementById('addStaffPhotoInput');
    const addPhotoPreview = document.getElementById('addStaffPhotoPreview');
    if(addPhotoInput){
        addPhotoInput.addEventListener('change', e => {
            const [file] = e.target.files;
            if(file) addPhotoPreview.src = URL.createObjectURL(file);
        });
    }

    const editPhotoInput = document.querySelector('#editStaffForm input[name="photo"]');
    const editPhotoPreview = document.getElementById('editStaffPhotoPreview');
    if(editPhotoInput){
        editPhotoInput.addEventListener('change', e => {
            const [file] = e.target.files;
            if(file) editPhotoPreview.src = URL.createObjectURL(file);
        });
    }

    // Add Staff
    const addForm = document.getElementById('addStaffForm');
    if(addForm){
        addForm.addEventListener('submit', function(e){
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
                if(data.success){
                    showMessage('success', data.success);
                    addStaffModal.hide();
                    this.reset();
                    addPhotoPreview.src = '/inventory_system/assets/img/default-user.png';
                    if(data.newStaffRow){
                        document.querySelector('#staffTable tbody').insertAdjacentHTML('afterbegin', data.newStaffRow);
                        attachRowEvents();
                    }
                }
            })
            .catch(() => showMessage('danger', 'Something went wrong!'))
            .finally(() => submitBtn.disabled = false);
        });
    }

    // Edit Staff
    const editForm = document.getElementById('editStaffForm');
    if(editForm){
        editForm.addEventListener('submit', function(e){
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
                if(data.success){
                    showMessage('success', data.success);
                    editStaffModal.hide();
                    if(data.updatedRowHtml && data.staff_id){
                        const oldRow = document.getElementById(`staffRow${data.staff_id}`);
                        oldRow.outerHTML = data.updatedRowHtml;
                        attachRowEvents();
                    }
                }
            })
            .catch(() => showMessage('danger', 'Something went wrong!'))
            .finally(() => submitBtn.disabled = false);
        });
    }

    // Edit modal prefill
    const editButtons = document.querySelectorAll('.editStaffBtn');
    editButtons.forEach(btn => {
        btn.addEventListener('click', function(){
            const staffId = this.dataset.id;
            editForm.staff_id.value = staffId;
            editForm.firstname.value = this.dataset.firstname;
            editForm.lastname.value = this.dataset.lastname;
            editForm.username.value = this.dataset.username;
            editForm.email.value = this.dataset.email;
            editPhotoPreview.src = this.dataset.photo;
        });
    });

    // Toggle Status
    const attachRowEvents = () => {
        document.querySelectorAll('.toggleStatusBtn').forEach(btn => {
            btn.onclick = function(){
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
                    if(data.success){
                        showMessage('success', data.success);
                        const badge = document.getElementById(`staffStatus${staffId}`);
                        if(badge.textContent.toLowerCase() === 'active'){
                            badge.textContent = 'Inactive';
                            badge.classList.replace('bg-success','bg-secondary');
                            this.classList.replace('btn-secondary','btn-success');
                            this.querySelector('i').classList.replace('bi-person-x','bi-person-check');
                        } else {
                            badge.textContent = 'Active';
                            badge.classList.replace('bg-secondary','bg-success');
                            this.classList.replace('btn-success','btn-secondary');
                            this.querySelector('i').classList.replace('bi-person-check','bi-person-x');
                        }
                    }
                })
                .catch(()=>showMessage('danger','Something went wrong!'));
            };
        });
    };

    attachRowEvents();

});
