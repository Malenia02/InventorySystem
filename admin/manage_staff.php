<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: /inventory_system/index.php");
    exit;
}
require $_SERVER['DOCUMENT_ROOT'].'/inventory_system/config/config.php';
require $_SERVER['DOCUMENT_ROOT'].'/inventory_system/controllers/staffController.php';




// We won’t redirect, just return JSON if AJAX
if($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '' === 'XMLHttpRequest') {
    handleStaffRequest($conn, $table_users);
    exit;
}

$staffs = getAllStaff($conn, $table_users);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php require $_SERVER['DOCUMENT_ROOT'].'/inventory_system/components/head.php'; ?>
</head>

<body>
    <?php
    require $_SERVER['DOCUMENT_ROOT'].'/inventory_system/components/header.php';
    require $_SERVER['DOCUMENT_ROOT'].'/inventory_system/components/sidebar.php';
    ?>

    <?php
    require $_SERVER['DOCUMENT_ROOT'].'/inventory_system/components/breadcrumb.php'; ?>

        <section class="section">
            <div class="row">
                <div class="col-lg-12">

                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">Staff List</h5>

                            <!-- AJAX Messages -->
                            <div id="staffMessages"></div>

                            <!-- Add Staff Button -->
                            <button type="button" class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#addStaffModal">
                                <i class="bi bi-person-plus"></i> Add New Staff
                            </button>

                            <!-- Staff Table -->
                            <div class="table-responsive">
                                <table id="staffTable" class="table table-striped table-bordered" style="width:100%">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Photo</th>
                                            <th>Username</th>
                                            <th>Email</th>
                                            <th>Role</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($staffs as $index => $staff): ?>
                                            <tr id="staffRow<?= $staff['user_id'] ?>">
                                                <td><?= $index + 1 ?></td>
                                                <td class="text-center">
                                                    <img src="<?= !empty($staff['photo']) ? $staff['photo'] : '/inventory_system/assets/img/default-user.png' ?>" 
                                                        alt="Photo" style="width:50px;height:50px;object-fit:cover;">
                                                </td>
                                                <td><?= htmlspecialchars($staff['username']) ?></td>
                                                <td><?= htmlspecialchars($staff['email']) ?></td>
                                                <td><?= htmlspecialchars($staff['role']) ?></td>
                                                <td>
                                                    <span class="badge <?= $staff['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>" id="staffStatus<?= $staff['user_id'] ?>">
                                                        <?= ucfirst($staff['status']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <!-- Edit -->
                                                    <button class="btn btn-sm btn-warning editStaffBtn"
                                                        data-id="<?= $staff['user_id'] ?>"
                                                        data-username="<?= htmlspecialchars($staff['username']) ?>"
                                                        data-firstname="<?= htmlspecialchars($staff['first_name']) ?>"
                                                        data-lastname="<?= htmlspecialchars($staff['last_name']) ?>"
                                                        data-email="<?= htmlspecialchars($staff['email']) ?>"
                                                        data-photo="<?= !empty($staff['photo']) ? $staff['photo'] : '/inventory_system/assets/img/default-user.png' ?>"
                                                        data-bs-toggle="modal" data-bs-target="#editStaffModal" title="Edit">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </button>

                                                    <!-- Toggle Status AJAX -->
                                                    <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                                                        <button class="btn btn-sm <?= $staff['status']==='active' ? 'btn-secondary' : 'btn-success' ?> toggleStatusBtn"
                                                            data-id="<?= $staff['user_id'] ?>">
                                                            <i class="bi <?= $staff['status']==='active' ? 'bi-person-x' : 'bi-person-check' ?>"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- =========================
                                 ADD STAFF MODAL
                                 ========================= -->
                            <div class="modal fade" id="addStaffModal" tabindex="-1" aria-labelledby="addStaffModalLabel" aria-hidden="true">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <form id="addStaffForm" enctype="multipart/form-data">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Add New Staff</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="row">
                                                    <div class="col-md-4 text-center">
                                                        <label class="form-label">Photo Preview</label>
                                                        <div>
                                                            <img id="addStaffPhotoPreview" src="/inventory_system/assets/img/default-user.png" style="width:150px;height:150px;object-fit:cover;">
                                                        </div>
                                                        <input type="file" class="form-control mt-2" name="photo" accept="image/*" id="addStaffPhotoInput">
                                                    </div>
                                                    <div class="col-md-8">
                                                        <div class="mb-3">
                                                            <label class="form-label">First Name</label>
                                                            <input type="text" class="form-control" name="firstname" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Last Name</label>
                                                            <input type="text" class="form-control" name="lastname" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Email</label>
                                                            <input type="email" class="form-control" name="email" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Username</label>
                                                            <input type="text" class="form-control" name="username" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Password</label>
                                                            <input type="password" class="form-control" name="password" required>
                                                        </div>
                                                        <div class="mb-3">
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
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                <button type="submit" class="btn btn-primary">Add Staff</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- =========================
                                 EDIT STAFF MODAL
                                 ========================= -->
                            <div class="modal fade" id="editStaffModal" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <form id="editStaffForm" enctype="multipart/form-data">
                                            <input type="hidden" name="staff_id" id="editStaffId">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Edit Staff</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="row">
                                                    <div class="col-md-4 text-center">
                                                        <label class="form-label">Current Photo</label>
                                                        <div>
                                                            <img id="editStaffPhotoPreview" src="/inventory_system/assets/img/default-user.png" style="width:150px;height:150px;object-fit:cover;">
                                                        </div>
                                                        <input type="file" class="form-control mt-2" name="photo" accept="image/*">
                                                    </div>
                                                    <div class="col-md-8">
                                                        <div class="mb-3">
                                                            <label class="form-label">First Name</label>
                                                            <input type="text" class="form-control" name="firstname" id="editFirstname" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Last Name</label>
                                                            <input type="text" class="form-control" name="lastname" id="editLastname" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Email</label>
                                                            <input type="email" class="form-control" name="email" id="editEmail" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Username</label>
                                                            <input type="text" class="form-control" name="username" id="editUsername" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Password (leave blank to keep)</label>
                                                            <input type="password" class="form-control" name="password" id="editPassword">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                <button type="submit" class="btn btn-success">Update Staff</button>
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

    <?php require $_SERVER['DOCUMENT_ROOT'].'/inventory_system/components/js_script.php'; ?>

    <!-- Simple-DataTables Init -->
    <script src="<?= HOSTURL ?>/assets/js/manage_staff.js"></script>
    <script src="<?= HOSTURL ?>/assets/js/staff_ajax.js"></script>

</body>
</html>
