<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/SupplierController.php';

Middleware::auth()->role(['admin']);

$csrf_token = Middleware::generateCsrfToken();
$suppliers  = SupplierController::all($conn);

require __DIR__ . '/../components/head.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <style>
        .modal-message-center {
            text-align: center;
            font-weight: 500;
            margin-bottom: 12px;
        }

        .modal-modern .modal-content {
            border: 0;
            border-radius: 1rem;
            overflow: hidden;
            box-shadow: 0 1rem 3rem rgba(0,0,0,.18);
        }

        .modal-modern .modal-header {
            border-bottom: 0;
            padding: 1rem 1.5rem;
        }

        .modal-modern .modal-body {
            padding: 1.5rem;
        }

        .modal-modern .modal-footer {
            border-top: 0;
            padding: 1rem 1.5rem 1.5rem;
        }

        .modal-modern .form-label {
            font-weight: 600;
            margin-bottom: .45rem;
            color: #495057;
        }

        .modal-modern .form-control,
        .modal-modern .form-select,
        .modal-modern .input-group-text {
            border-radius: .75rem;
        }

        .modal-modern .modal-section-title {
            font-size: .95rem;
            font-weight: 700;
            color: #6c757d;
            border-bottom: 1px solid #e9ecef;
            padding-bottom: .5rem;
            margin-bottom: .75rem;
        }

        .modal-modern .modal-side-card {
            border: 1px solid #e9ecef;
            background: #f8f9fa;
            border-radius: 1rem;
            padding: 1rem;
            height: 100%;
        }

        .modal-modern .btn {
            border-radius: .75rem;
        }
    </style>
</head>
<body>

<?php
require __DIR__ . '/../components/header.php';
require __DIR__ . '/../components/sidebar.php';
?>

<main id="main" class="main">
    <div class="pagetitle">
        <h1>Supplier Management</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="/inventory_system/index.php">Home</a>
                </li>
                <li class="breadcrumb-item active">Supplier Management</li>
            </ol>
        </nav>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">

                <div class="card shadow-sm">
                    <div class="card-body">
                       <h5 class="card-title">Manage Suppliers</h5>

<div class="mb-3">
    <button
        type="button"
        class="btn btn-primary"
        data-bs-toggle="modal"
        data-bs-target="#supplierModal"
    >
        <i class="bi bi-plus-circle me-1"></i>Add Supplier
    </button>
</div>

                        <div class="table-responsive mt-3">
                            <table class="table table-striped table-hover align-middle" id="suppliersTable">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Supplier Name</th>
                                        <th>Contact Person</th>
                                        <th>Phone</th>
                                        <th>Email</th>
                                        <th>Address</th>
                                        <th>Status</th>
                                        <th style="min-width: 140px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($suppliers)): ?>
                                        <?php foreach ($suppliers as $index => $supplier): ?>
                                            <?php
                                            $supplierId = (int)($supplier['supplier_id'] ?? 0);
                                            $status = $supplier['status'] ?? 'active';
                                            ?>
                                            <tr id="supplierRow<?= $supplierId ?>">
                                                <td><?= $index + 1 ?></td>
                                                <td class="supplier-name"><?= htmlspecialchars($supplier['supplier_name'] ?? '') ?></td>
                                                <td><?= htmlspecialchars($supplier['contact_person'] ?? '—') ?></td>
                                                <td><?= htmlspecialchars($supplier['phone'] ?? '—') ?></td>
                                                <td><?= htmlspecialchars($supplier['email'] ?? '—') ?></td>
                                                <td><?= htmlspecialchars($supplier['address'] ?? '—') ?></td>
                                                <td>
                                                    <span class="badge supplier-status-badge <?= $status === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                                                        <?= ucfirst($status) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                     <div class="d-flex gap-2 justify-content-center">
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-warning editSupplierBtn"
                                                        data-id="<?= $supplierId ?>"
                                                        data-name="<?= htmlspecialchars($supplier['supplier_name'] ?? '') ?>"
                                                        data-contact="<?= htmlspecialchars($supplier['contact_person'] ?? '') ?>"
                                                        data-phone="<?= htmlspecialchars($supplier['phone'] ?? '') ?>"
                                                        data-email="<?= htmlspecialchars($supplier['email'] ?? '') ?>"
                                                        data-address="<?= htmlspecialchars($supplier['address'] ?? '') ?>"
                                                        data-status="<?= htmlspecialchars($status) ?>"
                                                        title="Edit Supplier"
                                                    >
                                                        <i class="bi bi-pencil-square"></i>
                                                    </button>

                                                    <button
                                                        type="button"
                                                        class="btn btn-sm <?= $status === 'active' ? 'btn-danger' : 'btn-success' ?> toggleSupplierStatusBtn"
                                                        data-id="<?= $supplierId ?>"
                                                        data-name="<?= htmlspecialchars($supplier['supplier_name'] ?? '') ?>"
                                                        data-status="<?= htmlspecialchars($status) ?>"
                                                        title="<?= $status === 'active' ? 'Deactivate Supplier' : 'Activate Supplier' ?>"
                                                    >
                                                        <i class="bi <?= $status === 'active' ? 'bi-slash-circle' : 'bi-check-circle' ?>"></i>
                                                    </button>
                                                </td>
                                            </div>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted py-4">No suppliers found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Add Supplier Modal -->
                <div class="modal fade modal-modern" id="supplierModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <form id="supplierForm">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                                <div class="modal-header bg-info-subtle">
                                    <div>
                                        <h5 class="modal-title fw-bold mb-1">
                                            <i class="bi bi-truck me-2"></i>Add New Supplier
                                        </h5>
                                        <small class="text-muted">Create a supplier record.</small>
                                    </div>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>

                                <div class="modal-body">
                                    <div id="supplierMessage" class="modal-message-center"></div>

                                    <div class="modal-side-card">
                                        <div class="modal-section-title">Supplier Details</div>

                                        <div class="row g-3">
                                            <div class="col-12">
                                                <label class="form-label">Supplier Name</label>
                                                <input type="text" class="form-control" name="supplier_name" id="supplier_name" required>
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Contact Person</label>
                                                <input type="text" class="form-control" name="contact_person">
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Phone</label>
                                                <input type="text" class="form-control" name="phone">
                                            </div>

                                            <div class="col-12">
                                                <label class="form-label">Email</label>
                                                <input type="email" class="form-control" name="email">
                                            </div>

                                            <div class="col-12">
                                                <label class="form-label">Address</label>
                                                <textarea class="form-control" name="address" rows="3"></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="modal-footer">
                                    <button type="button" class="btn btn-light border px-4" data-bs-dismiss="modal">Close</button>
                                    <button type="submit" class="btn btn-info px-4 text-white" id="saveSupplierBtn">
                                        <i class="bi bi-save me-1"></i>Add Supplier
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Edit Supplier Modal -->
                <div class="modal fade modal-modern" id="editSupplierModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <form id="editSupplierForm">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <input type="hidden" name="supplier_id" id="editSupplierId">

                                <div class="modal-header bg-warning-subtle">
                                    <div>
                                        <h5 class="modal-title fw-bold mb-1">
                                            <i class="bi bi-pencil-square me-2"></i>Edit Supplier
                                        </h5>
                                        <small class="text-muted">Update supplier details.</small>
                                    </div>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>

                                <div class="modal-body">
                                    <div id="editSupplierMessage" class="modal-message-center"></div>

                                    <div class="modal-side-card">
                                        <div class="modal-section-title">Supplier Details</div>

                                        <div class="row g-3">
                                            <div class="col-12">
                                                <label class="form-label">Supplier Name</label>
                                                <input type="text" class="form-control" name="supplier_name" id="editSupplierName" required>
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Contact Person</label>
                                                <input type="text" class="form-control" name="contact_person" id="editSupplierContact">
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Phone</label>
                                                <input type="text" class="form-control" name="phone" id="editSupplierPhone">
                                            </div>

                                            <div class="col-12">
                                                <label class="form-label">Email</label>
                                                <input type="email" class="form-control" name="email" id="editSupplierEmail">
                                            </div>

                                            <div class="col-12">
                                                <label class="form-label">Address</label>
                                                <textarea class="form-control" name="address" id="editSupplierAddress" rows="3"></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="modal-footer">
                                    <button type="button" class="btn btn-light border px-4" data-bs-dismiss="modal">Close</button>
                                    <button type="submit" class="btn btn-warning px-4 text-dark" id="updateSupplierBtn">
                                        <i class="bi bi-save me-1"></i>Update Supplier
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>
</main>

<?php require __DIR__ . '/../components/footer.php'; ?>
<?php require __DIR__ . '/../components/js_script.php'; ?>

<script>
document.addEventListener("DOMContentLoaded", () => {
    const table = document.getElementById("suppliersTable");
    const supplierForm = document.getElementById("supplierForm");
    const editSupplierForm = document.getElementById("editSupplierForm");

    const supplierMessage = document.getElementById("supplierMessage");
    const editSupplierMessage = document.getElementById("editSupplierMessage");

    const saveSupplierBtn = document.getElementById("saveSupplierBtn");
    const updateSupplierBtn = document.getElementById("updateSupplierBtn");

    if (window.simpleDatatables && simpleDatatables.DataTable && table) {
        new simpleDatatables.DataTable(table, {
            searchable: true,
            fixedHeight: true,
            perPage: 10
        });
    }

    const Toast = Swal.mixin({
        toast: true,
        position: "top-end",
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true
    });

    function showToast(message, icon = "success") {
        Toast.fire({ icon, title: message });
    }

    function showDetailedToast(title, html, icon = "success") {
        Toast.fire({
            icon,
            title,
            html,
            timer: 4500
        });
    }

    function escapeHtml(value) {
        const div = document.createElement("div");
        div.textContent = value ?? "";
        return div.innerHTML;
    }

    function showSweetMessage(title, html, icon = "success") {
        return Swal.fire({
            title,
            html,
            icon,
            confirmButtonColor: "#0d6efd"
        });
    }

    function clearMessage(el) {
        if (el) el.innerHTML = "";
    }

    function showMessage(el, message, type = "success") {
        if (!el) return;
        el.innerHTML = `
            <div class="alert alert-${type} alert-dismissible fade show mb-0" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;
    }

    function postData(url, formData) {
        return fetch(url, {
            method: "POST",
            headers: {
                "X-Requested-With": "XMLHttpRequest",
                "Accept": "application/json"
            },
            body: formData
        }).then(async (res) => {
            const text = await res.text();
            let payload;

            try {
                payload = JSON.parse(text);
            } catch (e) {
                console.error("Invalid JSON response:", text);
                throw e;
            }

            if (!res.ok && !payload?.success) {
                throw new Error(payload?.error || `Request failed with status ${res.status}`);
            }

            return payload;
        });
    }

    function setCellText(cell, value, fallback = "-") {
        if (!cell) return;
        cell.textContent = value && String(value).trim() !== "" ? value : fallback;
    }

    function createIcon(iconClass) {
        const icon = document.createElement("i");
        icon.className = iconClass;
        return icon;
    }

    function addSupplierRow(supplier) {
        const tbody = table?.querySelector("tbody");
        if (!tbody) return;

        const emptyRow = Array.from(tbody.querySelectorAll("tr")).find((row) => {
            const onlyCell = row.children.length === 1 ? row.children[0] : null;
            return onlyCell && /No suppliers found/i.test(onlyCell.textContent || "");
        });

        if (emptyRow) {
            emptyRow.remove();
        }

        const rowCount = tbody.querySelectorAll("tr").length;
        const status = supplier.status || "active";

        const tr = document.createElement("tr");
        tr.id = `supplierRow${supplier.supplier_id}`;

        const indexCell = document.createElement("td");
        indexCell.textContent = String(rowCount + 1);

        const nameCell = document.createElement("td");
        nameCell.className = "supplier-name";
        nameCell.textContent = supplier.supplier_name || "-";

        const contactCell = document.createElement("td");
        setCellText(contactCell, supplier.contact_person);

        const phoneCell = document.createElement("td");
        setCellText(phoneCell, supplier.phone);

        const emailCell = document.createElement("td");
        setCellText(emailCell, supplier.email);

        const addressCell = document.createElement("td");
        setCellText(addressCell, supplier.address);

        const statusCell = document.createElement("td");
        const badge = document.createElement("span");
        badge.className = `badge supplier-status-badge ${status === "active" ? "bg-success" : "bg-secondary"}`;
        badge.textContent = status.charAt(0).toUpperCase() + status.slice(1);
        statusCell.appendChild(badge);

        const actionsCell = document.createElement("td");

        const editBtn = document.createElement("button");
        editBtn.type = "button";
        editBtn.className = "btn btn-sm btn-warning editSupplierBtn";
        editBtn.dataset.id = String(supplier.supplier_id);
        editBtn.dataset.name = supplier.supplier_name || "";
        editBtn.dataset.contact = supplier.contact_person || "";
        editBtn.dataset.phone = supplier.phone || "";
        editBtn.dataset.email = supplier.email || "";
        editBtn.dataset.address = supplier.address || "";
        editBtn.dataset.status = status;
        editBtn.title = "Edit Supplier";
        editBtn.appendChild(createIcon("bi bi-pencil-square"));

        const toggleBtn = document.createElement("button");
        toggleBtn.type = "button";
        toggleBtn.className = `btn btn-sm ${status === "active" ? "btn-danger" : "btn-success"} toggleSupplierStatusBtn`;
        toggleBtn.dataset.id = String(supplier.supplier_id);
        toggleBtn.dataset.name = supplier.supplier_name || "";
        toggleBtn.dataset.status = status;
        toggleBtn.title = status === "active" ? "Deactivate Supplier" : "Activate Supplier";
        toggleBtn.appendChild(createIcon(`bi ${status === "active" ? "bi-slash-circle" : "bi-check-circle"}`));

        actionsCell.append(editBtn, document.createTextNode(" "), toggleBtn);
        tr.append(indexCell, nameCell, contactCell, phoneCell, emailCell, addressCell, statusCell, actionsCell);

        tbody.prepend(tr);
    }

    if (supplierForm) {
        supplierForm.addEventListener("submit", (e) => {
            e.preventDefault();
            clearMessage(supplierMessage);

            const formData = new FormData(supplierForm);
            formData.append("action", "add_supplier");

            saveSupplierBtn.disabled = true;
            saveSupplierBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';

            postData("/inventory_system/http/ajax/supplier_actions.php", formData)
                .then((res) => {
                    if (!res.success) {
                        showMessage(supplierMessage, res.error || "Failed to add supplier.", "danger");
                        showToast(res.error || "Failed to add supplier.", "error");
                        return;
                    }

                    const contactPerson = formData.get("contact_person") || "Not provided";
                    const phone = formData.get("phone") || "Not provided";
                    const email = formData.get("email") || "Not provided";
                    const address = formData.get("address") || "Not provided";

                    addSupplierRow({
                        supplier_id: res.supplier_id,
                        supplier_name: res.supplier_name,
                        contact_person: formData.get("contact_person"),
                        phone: formData.get("phone"),
                        email: formData.get("email"),
                        address: formData.get("address"),
                        status: "active"
                    });

                    showToast(res.message || "Supplier added successfully.", "success");
                    supplierForm.reset();

                    const modal = bootstrap.Modal.getInstance(document.getElementById("supplierModal"));
                    if (modal) modal.hide();

                    showSweetMessage(
                        "Supplier Added",
                        `
                            <p class="mb-2"><strong>${escapeHtml(res.supplier_name || "New supplier")}</strong> has been added successfully.</p>
                            <div class="text-start small">
                                <div><strong>Contact Person:</strong> ${escapeHtml(contactPerson)}</div>
                                <div><strong>Phone:</strong> ${escapeHtml(phone)}</div>
                                <div><strong>Email:</strong> ${escapeHtml(email)}</div>
                                <div><strong>Address:</strong> ${escapeHtml(address)}</div>
                                <div><strong>Status:</strong> Active</div>
                            </div>
                        `,
                        "success"
                    );
                })
                .catch((error) => {
                    const message = error?.message || "Server error.";
                    showMessage(supplierMessage, message, "danger");
                    showToast(message, "error");
                })
                .finally(() => {
                    saveSupplierBtn.disabled = false;
                    saveSupplierBtn.innerHTML = '<i class="bi bi-save me-1"></i>Add Supplier';
                });
        });
    }

    if (table) {
        table.addEventListener("click", (e) => {
            const editBtn = e.target.closest(".editSupplierBtn");
            const toggleBtn = e.target.closest(".toggleSupplierStatusBtn");

            if (editBtn) {
                const d = editBtn.dataset;

                document.getElementById("editSupplierId").value = d.id || "";
                document.getElementById("editSupplierName").value = d.name || "";
                document.getElementById("editSupplierContact").value = d.contact || "";
                document.getElementById("editSupplierPhone").value = d.phone || "";
                document.getElementById("editSupplierEmail").value = d.email || "";
                document.getElementById("editSupplierAddress").value = d.address || "";

                clearMessage(editSupplierMessage);
                new bootstrap.Modal(document.getElementById("editSupplierModal")).show();
                return;
            }

            if (toggleBtn) {
                const supplierId = parseInt(toggleBtn.dataset.id, 10);
                const supplierName = toggleBtn.dataset.name || "this supplier";
                const currentStatus = toggleBtn.dataset.status || "active";
                const actionText = currentStatus === "active" ? "Deactivate" : "Activate";

                Swal.fire({
                    title: `${actionText} ${supplierName}?`,
                    html: `
                        <p class="mb-1">You're about to <strong>${actionText.toLowerCase()}</strong> this supplier.</p>
                        <small class="text-muted">You can change it back later anytime.</small>
                    `,
                    icon: "warning",
                    showCancelButton: true,
                    confirmButtonText: `Yes, ${actionText}`,
                    confirmButtonColor: "#3085d6",
                    cancelButtonColor: "#d33"
                }).then((result) => {
                    if (!result.isConfirmed) return;

                    const formData = new FormData();
                    formData.append("action", "toggle_status");
                    formData.append("supplier_id", String(supplierId));
                    formData.append("csrf_token", "<?= htmlspecialchars($csrf_token) ?>");

                    postData("/inventory_system/http/ajax/supplier_actions.php", formData)
                        .then((res) => {
                            if (!res.success) {
                                showToast(res.error || "Failed to update status.", "error");
                                return;
                            }

                            const row = document.getElementById(`supplierRow${supplierId}`);
                            if (!row) return;

                            const badge = row.querySelector(".supplier-status-badge");
                            const button = row.querySelector(".toggleSupplierStatusBtn");

                            if (badge) {
                                badge.textContent = res.new_status.charAt(0).toUpperCase() + res.new_status.slice(1);
                                badge.classList.remove("bg-success", "bg-secondary");
                                badge.classList.add(res.new_status === "active" ? "bg-success" : "bg-secondary");
                            }

                            if (button) {
                                button.dataset.status = res.new_status;
                                button.dataset.name = res.supplier_name || supplierName;
                                button.classList.remove("btn-danger", "btn-success");
                                button.classList.add(res.new_status === "active" ? "btn-danger" : "btn-success");
                                button.title = res.new_status === "active" ? "Deactivate Supplier" : "Activate Supplier";
                                button.innerHTML = `<i class="bi ${res.new_status === "active" ? "bi-slash-circle" : "bi-check-circle"}"></i>`;
                            }

                            showDetailedToast(
                                "Status Updated",
                                `
                                    <div class="text-start">
                                        <div><strong>Supplier:</strong> ${escapeHtml(res.supplier_name || supplierName)}</div>
                                        <div><strong>New Status:</strong> ${escapeHtml((res.new_status || "updated").charAt(0).toUpperCase() + (res.new_status || "updated").slice(1))}</div>
                                        
                                    </div>
                                `,
                                "success"
                            );
                        })
                        .catch((error) => {
                            showToast(error?.message || "Server error.", "error");
                        });
                });

                return;
            }
        });
    }

    if (editSupplierForm) {
        editSupplierForm.addEventListener("submit", (e) => {
            e.preventDefault();
            clearMessage(editSupplierMessage);

            const formData = new FormData(editSupplierForm);
            formData.append("action", "edit_supplier");

            updateSupplierBtn.disabled = true;
            updateSupplierBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Updating...';

            postData("/inventory_system/http/ajax/supplier_actions.php", formData)
                .then((res) => {
                    if (!res.success) {
                        showMessage(editSupplierMessage, res.error || "Failed to update supplier.", "danger");
                        showToast(res.error || "Failed to update supplier.", "error");
                        return;
                    }

                    const supplierId = formData.get("supplier_id");
                    const row = document.getElementById(`supplierRow${supplierId}`);
                    const supplierName = formData.get("supplier_name") || "";
                    const contactPerson = formData.get("contact_person") || "Not provided";
                    const phone = formData.get("phone") || "Not provided";
                    const email = formData.get("email") || "Not provided";
                    const address = formData.get("address") || "Not provided";

                    if (row) {
                        row.children[1].textContent = supplierName;
                        row.children[2].textContent = formData.get("contact_person") || "-";
                        row.children[3].textContent = formData.get("phone") || "-";
                        row.children[4].textContent = formData.get("email") || "-";
                        row.children[5].textContent = formData.get("address") || "-";

                        const editBtn = row.querySelector(".editSupplierBtn");
                        if (editBtn) {
                            editBtn.dataset.name = formData.get("supplier_name") || "";
                            editBtn.dataset.contact = formData.get("contact_person") || "";
                            editBtn.dataset.phone = formData.get("phone") || "";
                            editBtn.dataset.email = formData.get("email") || "";
                            editBtn.dataset.address = formData.get("address") || "";
                        }

                        const toggleBtn = row.querySelector(".toggleSupplierStatusBtn");
                        if (toggleBtn) {
                            toggleBtn.dataset.name = supplierName;
                        }
                    }

                    showToast(res.message || "Supplier updated successfully.", "success");

                    const modal = bootstrap.Modal.getInstance(document.getElementById("editSupplierModal"));
                    if (modal) modal.hide();

                    showSweetMessage(
                        "Supplier Updated",
                        `
                            <p class="mb-2"><strong>${escapeHtml(supplierName)}</strong> has been updated successfully.</p>
                            <div class="text-start small">
                                <div><strong>Contact Person:</strong> ${escapeHtml(contactPerson)}</div>
                                <div><strong>Phone:</strong> ${escapeHtml(phone)}</div>
                                <div><strong>Email:</strong> ${escapeHtml(email)}</div>
                                <div><strong>Address:</strong> ${escapeHtml(address)}</div>
                            </div>
                        `,
                        "success"
                    );
                })
                .catch((error) => {
                    const message = error?.message || "Server error.";
                    showMessage(editSupplierMessage, message, "danger");
                    showToast(message, "error");
                })
                .finally(() => {
                    updateSupplierBtn.disabled = false;
                    updateSupplierBtn.innerHTML = '<i class="bi bi-save me-1"></i>Update Supplier';
                });
        });
    }
});
</script>

</body>
</html>
