<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/SupplierController.php';

Middleware::auth()->role(['admin']);

$csrf_token = Middleware::generateCsrfToken();
$supplierFilters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'status' => strtolower(trim((string) ($_GET['status'] ?? 'all'))),
    'page' => max(1, (int) ($_GET['page'] ?? 1)),
    'per_page' => (int) ($_GET['per_page'] ?? 25),
];
$supplierPage = SupplierController::paginate($conn, $supplierFilters);
$suppliers  = $supplierPage['items'];
$supplierFilters = [
    'search' => (string) $supplierPage['search'],
    'status' => (string) $supplierPage['status'],
    'page' => (int) $supplierPage['page'],
    'per_page' => (int) $supplierPage['per_page'],
];
$supplierRowStart = $supplierPage['total'] > 0 ? (($supplierPage['page'] - 1) * $supplierPage['per_page']) + 1 : 0;
$pageTitle = 'Supplier Management';

function supplierListUrl(array $filters, array $overrides = []): string
{
    $params = array_merge($filters, $overrides);
    if (($params['page'] ?? 1) <= 1) unset($params['page']);
    if (($params['search'] ?? '') === '') unset($params['search']);
    if (($params['status'] ?? 'all') === 'all') unset($params['status']);
    if (($params['per_page'] ?? 25) === 25) unset($params['per_page']);
    $query = http_build_query($params);
    return '/inventory_system/supplier_management/manage_supplier.php' . ($query !== '' ? '?' . $query : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/../components/head.php'; ?>
    <style>
        .modal {
            z-index: 2000;
        }

        .modal-backdrop {
            z-index: 1990;
        }

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

                        <form method="get" class="row g-3 align-items-end mb-3">
                            <div class="col-lg-6">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($supplierFilters['search'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Supplier, contact, phone, email">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="all" <?= $supplierFilters['status'] === 'all' ? 'selected' : '' ?>>All</option>
                                    <option value="active" <?= $supplierFilters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= $supplierFilters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Per page</label>
                                <select name="per_page" class="form-select">
                                    <?php foreach ([10, 25, 50, 100] as $size): ?>
                                        <option value="<?= $size ?>" <?= $supplierFilters['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">Apply</button>
                            </div>
                        </form>

                        <div class="table-responsive mt-3" style="max-height:500px; overflow-y:auto;">
                            <div id="suppliersTableShell">
                                <table class="table table-striped table-bordered" id="suppliersTable">
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
                                                $rowNumber = $supplierRowStart + $index;
                                                require __DIR__ . '/../templates/supplier_row.php';
                                                ?>
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
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mt-3">
                            <div class="text-muted small">
                                <?php if ($supplierPage['total'] > 0): ?>
                                    Showing <?= $supplierRowStart ?> to <?= min($supplierRowStart + count($suppliers) - 1, $supplierPage['total']) ?> of <?= $supplierPage['total'] ?> suppliers
                                <?php else: ?>
                                    No suppliers found
                                <?php endif; ?>
                            </div>
                            <nav aria-label="Supplier pagination">
                                <ul class="pagination pagination-sm mb-0">
                                    <li class="page-item <?= $supplierPage['page'] <= 1 ? 'disabled' : '' ?>">
                                        <a class="page-link" href="<?= htmlspecialchars(supplierListUrl($supplierFilters, ['page' => $supplierPage['page'] - 1]), ENT_QUOTES, 'UTF-8') ?>">Previous</a>
                                    </li>
                                    <?php
                                    $supplierStartPage = max(1, $supplierPage['page'] - 2);
                                    $supplierEndPage = min($supplierPage['total_pages'], $supplierPage['page'] + 2);
                                    for ($pageNumber = $supplierStartPage; $pageNumber <= $supplierEndPage; $pageNumber++):
                                    ?>
                                        <li class="page-item <?= $pageNumber === $supplierPage['page'] ? 'active' : '' ?>">
                                            <a class="page-link" href="<?= htmlspecialchars(supplierListUrl($supplierFilters, ['page' => $pageNumber]), ENT_QUOTES, 'UTF-8') ?>"><?= $pageNumber ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?= $supplierPage['page'] >= $supplierPage['total_pages'] ? 'disabled' : '' ?>">
                                        <a class="page-link" href="<?= htmlspecialchars(supplierListUrl($supplierFilters, ['page' => $supplierPage['page'] + 1]), ENT_QUOTES, 'UTF-8') ?>">Next</a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    </div>
                </div>

                <!-- Add Supplier Modal -->
                <div class="modal fade modal-modern" id="supplierModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <form id="supplierForm" method="post" action="">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <input type="hidden" name="action" value="add_supplier">

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
                            <form id="editSupplierForm" method="post" action="">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <input type="hidden" name="action" value="edit_supplier">
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

    function escapeHtml(value) {
        const div = document.createElement("div");
        div.textContent = value ?? "";
        return div.innerHTML;
    }

    function queueReloadToast(message, icon = "success") {
        try {
            sessionStorage.setItem("manageSupplierFlash", JSON.stringify({ message, icon }));
        } catch (error) {
            console.warn("Unable to persist supplier flash message.", error);
        }
    }

    function flushQueuedToast() {
        try {
            const raw = sessionStorage.getItem("manageSupplierFlash");
            if (!raw) return;

            sessionStorage.removeItem("manageSupplierFlash");
            const payload = JSON.parse(raw);
            if (payload?.message) {
                showToast(payload.message, payload.icon || "success");
            }
        } catch (error) {
            console.warn("Unable to restore supplier flash message.", error);
        }
    }

    function reloadCurrentPage(message, icon = "success") {
        queueReloadToast(message, icon);
        window.location.assign(window.location.pathname + window.location.search);
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

    flushQueuedToast();

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
                    supplierForm.reset();
                    const modal = bootstrap.Modal.getInstance(document.getElementById("supplierModal"));
                    if (modal) modal.hide();
                    reloadCurrentPage(res.message || "Supplier added successfully.", "success");
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

    document.addEventListener("click", (e) => {
            if (!e.target.closest("#suppliersTable")) {
                return;
            }

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
                            reloadCurrentPage(res.message || "Supplier status updated.", "success");
                        })
                        .catch((error) => {
                            showToast(error?.message || "Server error.", "error");
                        });
                });

                return;
            }
        });

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

                    const modal = bootstrap.Modal.getInstance(document.getElementById("editSupplierModal"));
                    if (modal) modal.hide();
                    reloadCurrentPage(res.message || "Supplier updated successfully.", "success");
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
