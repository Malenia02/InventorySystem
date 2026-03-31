<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/staffController.php';

Middleware::auth()->role(['admin']);

$csrf_token = Middleware::generateCsrfToken();

try {
    $staffs = StaffController::getAllStaff($conn);
} catch (Throwable $e) {
    error_log('[manage_staff.php] ' . $e->getMessage());
    $staffs = [];
    $pageError = 'Failed to load staff records.';
}

require __DIR__ . '/../components/head.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/../components/head.php'; ?>
    <title>Manage Staff</title>
    <style>
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
        .modal-modern .form-select {
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

        .modal-modern .preview-image {
            width: 100%;
            max-width: 260px;
            height: 260px;
            object-fit: cover;
            border-radius: 1rem;
            border: 1px solid #dee2e6;
            box-shadow: 0 .25rem .75rem rgba(0,0,0,.08);
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
        <h1>Staff Management</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="/inventory_system/index.php">Home</a>
                </li>
                <li class="breadcrumb-item active">Staff Management</li>
            </ol>
        </nav>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-12">

                <div class="card shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title">Staff List</h5>

                        <button
                            type="button"
                            class="btn btn-primary mb-3"
                            data-bs-toggle="modal"
                            data-bs-target="#addStaffModal"
                        >
                            <i class="bi bi-plus-circle me-1"></i>Add New Staff
                        </button>

                        
                        <div class="table-responsive">
                            <table id="staffTable" class="table table-striped table-bordered align-middle">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Photo</th>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th style="min-width: 130px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($staffs)): ?>
                                        <?php foreach ($staffs as $index => $staff): ?>
                                            <?php
                                            $userId = (int)($staff['user_id'] ?? 0);
                                            $status = $staff['status'] ?? 'inactive';
                                            $photo = !empty($staff['photo'])
                                                ? $staff['photo']
                                                : '/inventory_system/assets/img/default-user.png';
                                            ?>
                                            <tr id="staffRow<?= $userId ?>">
                                                <td><?= $index + 1 ?></td>
                                                <td class="text-center">
                                                    <img
                                                        src="<?= htmlspecialchars($photo) ?>"
                                                        alt="Photo"
                                                        style="width:50px;height:50px;object-fit:cover;"
                                                        onerror="this.src='/inventory_system/assets/img/default-user.png';this.onerror=null;"
                                                    >
                                                </td>
                                                <td><?= htmlspecialchars($staff['username'] ?? '') ?></td>
                                                <td><?= htmlspecialchars($staff['email'] ?? '') ?></td>
                                                <td><?= htmlspecialchars($staff['role'] ?? '') ?></td>
                                                <td>
                                                    <span
                                                        class="badge <?= $status === 'active' ? 'bg-success' : 'bg-secondary' ?>"
                                                        id="staffStatus<?= $userId ?>"
                                                    >
                                                        <?= ucfirst($status) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="d-flex gap-2 justify-content-center">
                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-warning editStaffBtn"
                                                            data-id="<?= $userId ?>"
                                                            data-username="<?= htmlspecialchars($staff['username'] ?? '') ?>"
                                                            data-firstname="<?= htmlspecialchars($staff['first_name'] ?? '') ?>"
                                                            data-lastname="<?= htmlspecialchars($staff['last_name'] ?? '') ?>"
                                                            data-email="<?= htmlspecialchars($staff['email'] ?? '') ?>"
                                                            data-photo="<?= htmlspecialchars($photo) ?>"
                                                            data-role="<?= htmlspecialchars($staff['role'] ?? 'staff') ?>"
                                                            title="Edit Staff"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#editStaffModal"
                                                        >
                                                            <i class="bi bi-pencil-square"></i>
                                                        </button>

                                                        <button
                                                            type="button"
                                                            class="btn btn-sm <?= $status === 'active' ? 'btn-danger' : 'btn-success' ?> toggleStatusBtn"
                                                            data-id="<?= $userId ?>"
                                                            data-name="<?= htmlspecialchars(trim(($staff['first_name'] ?? '') . ' ' . ($staff['last_name'] ?? ''))) ?>"
                                                            data-status="<?= htmlspecialchars($status) ?>"
                                                            title="<?= $status === 'active' ? 'Deactivate Staff' : 'Reactivate Staff' ?>"
                                                        >
                                                            <i class="bi <?= $status === 'active' ? 'bi-slash-circle' : 'bi-check-circle' ?>"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-4">No staff found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- ADD STAFF MODAL -->
                        <div class="modal fade modal-modern" id="addStaffModal" tabindex="-1" aria-labelledby="addStaffModalLabel" aria-hidden="true">
                            <div class="modal-dialog modal-xl modal-dialog-centered">
                                <div class="modal-content">
                                    <form id="addStaffForm" enctype="multipart/form-data">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                                        <div class="modal-header bg-primary-subtle">
                                            <div>
                                                <h5 class="modal-title fw-bold mb-1" id="addStaffModalLabel">
                                                    <i class="bi bi-plus-circle me-2"></i>Add New Staff
                                                </h5>
                                                <small class="text-muted">Create a new team member account and assign their role.</small>
                                            </div>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>

                                        <div class="modal-body">
                                            <div class="row g-4">
                                                <div class="col-lg-4">
                                                    <div class="modal-side-card text-center">
                                                        <h6 class="modal-section-title text-start">Staff Photo</h6>
                                                        <img
                                                            id="addStaffPhotoPreview"
                                                            src="/inventory_system/assets/img/default-user.png"
                                                            alt="Preview"
                                                            class="preview-image"
                                                        >
                                                        <div class="mt-3">
                                                            <label class="form-label">Upload Photo</label>
                                                            <input
                                                                type="file"
                                                                class="form-control"
                                                                name="photo"
                                                                accept="image/*"
                                                                id="addStaffPhotoInput"
                                                            >
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="col-lg-8">
                                                    <div class="row g-3">
                                                        <div class="col-12">
                                                            <div class="modal-section-title">Basic Information</div>
                                                        </div>

                                                        <div class="col-md-6">
                                                            <label class="form-label">First Name</label>
                                                            <input type="text" class="form-control" name="firstname" autocomplete="given-name" required>
                                                        </div>

                                                        <div class="col-md-6">
                                                            <label class="form-label">Last Name</label>
                                                            <input type="text" class="form-control" name="lastname" autocomplete="family-name" required>
                                                        </div>

                                                        <div class="col-md-6">
                                                            <label class="form-label">Email</label>
                                                            <input type="email" class="form-control" name="email" autocomplete="email" required>
                                                        </div>

                                                        <div class="col-md-6">
                                                            <label class="form-label">Username</label>
                                                            <input type="text" class="form-control" name="username" autocomplete="username" required>
                                                        </div>

                                                        <div class="col-12 mt-2">
                                                            <div class="modal-section-title">Access Settings</div>
                                                        </div>

                                                        <div class="col-md-6">
                                                            <label class="form-label">Password</label>
                                                            <input type="password" class="form-control" name="password" autocomplete="new-password" required>
                                                        </div>

                                                        <div class="col-md-6">
                                                            <label class="form-label">Role</label>
                                                            <select class="form-select" name="role" required>
                                                                <option value="staff" selected>Staff</option>
                                                                <option value="admin">Admin</option>
                                                                <option value="cashier">Cashier</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-light border px-4" data-bs-dismiss="modal">Close</button>
                                            <button type="submit" class="btn btn-primary px-4">
                                                <i class="bi bi-save me-1"></i>Add Staff
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- EDIT STAFF MODAL -->
                        <div class="modal fade modal-modern" id="editStaffModal" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-xl modal-dialog-centered">
                                <div class="modal-content">
                                    <form id="editStaffForm" enctype="multipart/form-data">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="staff_id" id="editStaffId">

                                        <div class="modal-header bg-warning-subtle">
                                            <div>
                                                <h5 class="modal-title fw-bold mb-1">
                                                    <i class="bi bi-pencil-square me-2"></i>Edit Staff
                                                </h5>
                                                <small class="text-muted">Update account details, role, or photo for an existing team member.</small>
                                            </div>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>

                                        <div class="modal-body">
                                            <div class="row g-4">
                                                <div class="col-lg-4">
                                                    <div class="modal-side-card text-center">
                                                        <h6 class="modal-section-title text-start">Staff Photo</h6>
                                                        <img
                                                            id="editStaffPhotoPreview"
                                                            src="/inventory_system/assets/img/default-user.png"
                                                            alt="Preview"
                                                            class="preview-image"
                                                        >
                                                        <div class="mt-3">
                                                            <label class="form-label">Upload New Photo</label>
                                                            <input
                                                                type="file"
                                                                class="form-control"
                                                                name="photo"
                                                                accept="image/*"
                                                            >
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="col-lg-8">
                                                    <div class="row g-3">
                                                        <div class="col-12">
                                                            <div class="modal-section-title">Basic Information</div>
                                                        </div>

                                                        <div class="col-md-6">
                                                            <label class="form-label">First Name</label>
                                                            <input type="text" class="form-control" name="firstname" id="editFirstname" autocomplete="given-name" required>
                                                        </div>

                                                        <div class="col-md-6">
                                                            <label class="form-label">Last Name</label>
                                                            <input type="text" class="form-control" name="lastname" id="editLastname" autocomplete="family-name" required>
                                                        </div>

                                                        <div class="col-md-6">
                                                            <label class="form-label">Email</label>
                                                            <input type="email" class="form-control" name="email" id="editEmail" autocomplete="email" required>
                                                        </div>

                                                        <div class="col-md-6">
                                                            <label class="form-label">Username</label>
                                                            <input type="text" class="form-control" name="username" id="editUsername" autocomplete="username" required>
                                                        </div>

                                                        <div class="col-12 mt-2">
                                                            <div class="modal-section-title">Access Settings</div>
                                                        </div>

                                                        <div class="col-md-6">
                                                            <label class="form-label">Password (leave blank to keep)</label>
                                                            <input type="password" class="form-control" name="password" id="editPassword" autocomplete="new-password">
                                                        </div>

                                                        <div class="col-md-6">
                                                            <label class="form-label">Role</label>
                                                            <select class="form-select" name="role" id="editRole" required>
                                                                <option value="staff">Staff</option>
                                                                <option value="admin">Admin</option>
                                                                <option value="cashier">Cashier</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-light border px-4" data-bs-dismiss="modal">Close</button>
                                            <button type="submit" class="btn btn-success px-4">
                                                <i class="bi bi-save me-1"></i>Update Staff
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

            </div>
        </div>
    </section>
</main>

<?php require __DIR__ . '/../components/footer.php'; ?>
<?php require __DIR__ . '/../components/js_script.php'; ?>

<script src="<?= HOSTURL ?>/assets/js/manage_staff.js"></script>

</body>
</html>
