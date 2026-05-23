<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/staffController.php';

Middleware::auth()->role(['admin']);

$csrf_token = Middleware::generateCsrfToken();

try {
    $staffFilters = [
        'search'   => trim((string) ($_GET['search'] ?? '')),
        'status'   => strtolower(trim((string) ($_GET['status'] ?? 'all'))),
        'role'     => strtolower(trim((string) ($_GET['role'] ?? 'all'))),
        'page'     => max(1, (int) ($_GET['page'] ?? 1)),
        'per_page' => (int) ($_GET['per_page'] ?? 25),
    ];

    $staffPage = StaffController::paginate($conn, $staffFilters);
    $staffs    = $staffPage['items'];
    $staffFilters = [
        'search'   => (string) $staffPage['search'],
        'status'   => (string) $staffPage['status'],
        'role'     => (string) $staffPage['role'],
        'page'     => (int)    $staffPage['page'],
        'per_page' => (int)    $staffPage['per_page'],
    ];
    $staffRowStart = $staffPage['total'] > 0
        ? (($staffPage['page'] - 1) * $staffPage['per_page']) + 1
        : 0;

    // Summary counts for stat cards
    $staffSummary = StaffController::getSummary($conn);

} catch (Throwable $e) {
    error_log('[manage_staff.php] ' . $e->getMessage());
    $staffs       = [];
    $pageError    = 'Failed to load staff records.';
    $staffPage    = ['total' => 0, 'page' => 1, 'per_page' => 25, 'total_pages' => 1];
    $staffFilters = ['search' => '', 'status' => 'all', 'role' => 'all', 'page' => 1, 'per_page' => 25];
    $staffRowStart = 0;
    $staffSummary  = ['total' => 0, 'active' => 0, 'inactive' => 0, 'admin' => 0];
}

function staffListUrl(array $filters, array $overrides = []): string
{
    $params = array_merge($filters, $overrides);
    if (($params['page']     ?? 1)     <= 1)     unset($params['page']);
    if (($params['search']   ?? '')    === '')    unset($params['search']);
    if (($params['status']   ?? 'all') === 'all') unset($params['status']);
    if (($params['role']     ?? 'all') === 'all') unset($params['role']);
    if (($params['per_page'] ?? 25)    === 25)    unset($params['per_page']);
    $query = http_build_query($params);
    return '/inventory_system/admin/manage_staff.php' . ($query !== '' ? '?' . $query : '');
}

function staffInitials(string $first, string $last): string
{
    return strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/../components/head.php'; ?>
    <title>Manage Staff</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/inventory_system/assets/css/manage_staff.css">
</head>
<body>

<?php
require __DIR__ . '/../components/header.php';
require __DIR__ . '/../components/sidebar.php';
?>

<main id="main" class="main">
    <div class="pagetitle">
        <h1>Staff Management</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="/inventory_system/index.php">Home</a>
                </li>
                <li class="breadcrumb-item active">Staff Management</li>
            </ol>
        </nav>
    </div>

    <?php if (!empty($pageError)): ?>
        <div class="alert alert-danger mb-3" style="border-radius:var(--radius-md);font-size:13px;">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?= htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div id="staffMessages"></div>

    <!-- Stat cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--c-accent-bg);color:var(--c-accent);">
                <i class="bi bi-people-fill"></i>
            </div>
            <div class="stat-body">
                <div class="stat-label">Total staff</div>
                <div class="stat-val" style="color:var(--c-accent);"><?= number_format((int)($staffSummary['total'] ?? 0)) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--c-green-bg);color:var(--c-green);">
                <i class="bi bi-person-check-fill"></i>
            </div>
            <div class="stat-body">
                <div class="stat-label">Active</div>
                <div class="stat-val" style="color:var(--c-green);"><?= number_format((int)($staffSummary['active'] ?? 0)) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--c-amber-bg);color:var(--c-amber);">
                <i class="bi bi-person-dash-fill"></i>
            </div>
            <div class="stat-body">
                <div class="stat-label">Inactive</div>
                <div class="stat-val" style="color:var(--c-amber);"><?= number_format((int)($staffSummary['inactive'] ?? 0)) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:var(--c-purple-bg);color:var(--c-purple);">
                <i class="bi bi-shield-fill-check"></i>
            </div>
            <div class="stat-body">
                <div class="stat-label">Admins</div>
                <div class="stat-val" style="color:var(--c-purple);"><?= number_format((int)($staffSummary['admin'] ?? 0)) ?></div>
            </div>
        </div>
    </div>

    <!-- Main staff card -->
    <div class="staff-card">
        <div class="staff-card-head">
            <span class="staff-card-title">Staff list</span>
            <button
                type="button"
                class="btn btn-primary"
                data-bs-toggle="modal"
                data-bs-target="#addStaffModal"
            >
                <i class="bi bi-plus-lg"></i> Add staff
            </button>
        </div>

        <!-- Filter bar -->
        <form method="get" class="filter-bar">
            <div class="filter-item grow">
                <span class="filter-label">Search</span>
                <div class="search-wrap">
                    <i class="bi bi-search"></i>
                    <input
                        type="text"
                        name="search"
                        value="<?= htmlspecialchars($staffFilters['search'], ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="Name, username, email…"
                        autocomplete="off"
                    >
                </div>
            </div>
            <div class="filter-item">
                <span class="filter-label">Status</span>
                <select name="status" class="filter-select">
                    <option value="all"      <?= $staffFilters['status'] === 'all'      ? 'selected' : '' ?>>All status</option>
                    <option value="active"   <?= $staffFilters['status'] === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $staffFilters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="filter-item">
                <span class="filter-label">Role</span>
                <select name="role" class="filter-select">
                    <option value="all"     <?= $staffFilters['role'] === 'all'     ? 'selected' : '' ?>>All roles</option>
                    <option value="admin"   <?= $staffFilters['role'] === 'admin'   ? 'selected' : '' ?>>Admin</option>
                    <option value="staff"   <?= $staffFilters['role'] === 'staff'   ? 'selected' : '' ?>>Staff</option>
                    <option value="cashier" <?= $staffFilters['role'] === 'cashier' ? 'selected' : '' ?>>Cashier</option>
                </select>
            </div>
            <div class="filter-item">
                <span class="filter-label">Per page</span>
                <select name="per_page" class="filter-select">
                    <?php foreach ([10, 25, 50, 100] as $size): ?>
                        <option value="<?= $size ?>" <?= $staffFilters['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-item">
                <span class="filter-label">&nbsp;</span>
                <button type="submit" class="btn btn-primary">Apply</button>
            </div>
        </form>

        <!-- Table -->
        <div class="table-wrap">
            <table class="staff-table" id="staffTable">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th>Staff member</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th style="width:90px; text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($staffs)): ?>
                        <?php foreach ($staffs as $index => $staff):
                            $userId  = (int)($staff['user_id'] ?? 0);
                            $status  = $staff['status'] ?? 'inactive';
                            $photo   = !empty($staff['photo']) ? $staff['photo'] : null;
                            $first   = $staff['first_name'] ?? '';
                            $last    = $staff['last_name']  ?? '';
                            $initials = staffInitials($first, $last);
                            $avClass  = 'av-' . ($index % 8);
                        ?>
                            <tr id="staffRow<?= $userId ?>">
                                <td class="num"><?= $staffRowStart + $index ?></td>
                                <td>
                                    <div class="staff-cell">
                                        <?php if ($photo): ?>
                                            <img
                                                src="<?= htmlspecialchars($photo, ENT_QUOTES, 'UTF-8') ?>"
                                                alt="<?= htmlspecialchars($first . ' ' . $last, ENT_QUOTES, 'UTF-8') ?>"
                                                class="avatar"
                                                onerror="this.outerHTML='<div class=\'avatar-initials <?= $avClass ?>\'><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>';this.onerror=null;"
                                            >
                                        <?php else: ?>
                                            <div class="avatar-initials <?= $avClass ?>"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="staff-name"><?= htmlspecialchars(trim($first . ' ' . $last), ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="staff-email"><?= htmlspecialchars($staff['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td style="color:var(--c-text-2);font-family:var(--ff-mono);font-size:12px;">
                                    <?= htmlspecialchars($staff['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <?php
                                    $roleMap = ['admin' => 'badge-admin', 'staff' => 'badge-staff', 'cashier' => 'badge-cashier'];
                                    $roleClass = $roleMap[$staff['role'] ?? ''] ?? 'badge-staff';
                                    $roleIcons = ['admin' => 'bi-shield-check', 'staff' => 'bi-person', 'cashier' => 'bi-cash-stack'];
                                    $roleIcon  = $roleIcons[$staff['role'] ?? ''] ?? 'bi-person';
                                    ?>
                                    <span class="badge <?= $roleClass ?>">
                                        <i class="bi <?= $roleIcon ?>"></i>
                                        <?= htmlspecialchars(ucfirst($staff['role'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= $status === 'active' ? 'badge-active' : 'badge-inactive' ?>" id="staffStatus<?= $userId ?>">
                                        <i class="bi <?= $status === 'active' ? 'bi-circle-fill' : 'bi-circle' ?>" style="font-size:7px;"></i>
                                        <?= ucfirst($status) ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="display:flex;gap:6px;justify-content:center;">
                                        <button
                                            type="button"
                                            class="btn-icon-sm edit editStaffBtn"
                                            data-id="<?= $userId ?>"
                                            data-username="<?= htmlspecialchars($staff['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-firstname="<?= htmlspecialchars($first, ENT_QUOTES, 'UTF-8') ?>"
                                            data-lastname="<?= htmlspecialchars($last, ENT_QUOTES, 'UTF-8') ?>"
                                            data-email="<?= htmlspecialchars($staff['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-photo="<?= htmlspecialchars($photo ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                            data-role="<?= htmlspecialchars($staff['role'] ?? 'staff', ENT_QUOTES, 'UTF-8') ?>"
                                            title="Edit staff"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editStaffModal"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </button>

                                        <button
                                            type="button"
                                            class="btn-icon-sm <?= $status === 'active' ? 'deactivate' : 'activate' ?> toggleStatusBtn"
                                            data-id="<?= $userId ?>"
                                            data-name="<?= htmlspecialchars(trim($first . ' ' . $last), ENT_QUOTES, 'UTF-8') ?>"
                                            data-status="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"
                                            title="<?= $status === 'active' ? 'Deactivate' : 'Reactivate' ?>"
                                        >
                                            <i class="bi <?= $status === 'active' ? 'bi-slash-circle' : 'bi-check-circle' ?>"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <i class="bi bi-people"></i>
                                    <p>No staff found matching your filters.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="pagination-bar">
            <span class="pag-info">
                <?php if ($staffPage['total'] > 0): ?>
                    Showing <?= number_format($staffRowStart) ?>–<?= number_format(min($staffRowStart + count($staffs) - 1, $staffPage['total'])) ?> of <?= number_format($staffPage['total']) ?> staff
                <?php else: ?>
                    No results
                <?php endif; ?>
            </span>
            <nav aria-label="Staff pagination">
                <ul class="pagination">
                    <li class="page-item <?= $staffPage['page'] <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(staffListUrl($staffFilters, ['page' => $staffPage['page'] - 1]), ENT_QUOTES, 'UTF-8') ?>" aria-label="Previous">
                            <i class="bi bi-chevron-left" style="font-size:11px;"></i>
                        </a>
                    </li>
                    <?php
                    $staffStartPage = max(1, $staffPage['page'] - 2);
                    $staffEndPage   = min($staffPage['total_pages'], $staffPage['page'] + 2);
                    for ($p = $staffStartPage; $p <= $staffEndPage; $p++):
                    ?>
                        <li class="page-item <?= $p === $staffPage['page'] ? 'active' : '' ?>">
                            <a class="page-link" href="<?= htmlspecialchars(staffListUrl($staffFilters, ['page' => $p]), ENT_QUOTES, 'UTF-8') ?>"><?= $p ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $staffPage['page'] >= $staffPage['total_pages'] ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(staffListUrl($staffFilters, ['page' => $staffPage['page'] + 1]), ENT_QUOTES, 'UTF-8') ?>" aria-label="Next">
                            <i class="bi bi-chevron-right" style="font-size:11px;"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
    </div><!-- /.staff-card -->

    <!-- ══════════════════════════════════════════════════════════
         ADD STAFF MODAL
    ═══════════════════════════════════════════════════════════ -->
    <div class="modal fade modal-modern" id="addStaffModal" tabindex="-1" aria-labelledby="addStaffModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <form id="addStaffForm" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="modal-header">
                        <div>
                            <div class="modal-title" id="addStaffModalLabel">
                                <i class="bi bi-person-plus me-2" style="color:var(--c-accent);"></i>Add new staff
                            </div>
                            <div class="modal-subtitle">Create a new team member account and assign their role.</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-4">
                            <!-- Photo -->
                            <div class="col-lg-3">
                                <div class="photo-panel">
                                    <img id="addStaffPhotoPreview" src="/inventory_system/assets/img/default-user.png" alt="Preview" class="preview-image">
                                    <div style="width:100%">
                                        <label class="form-label">Photo (optional)</label>
                                        <input type="file" class="form-control" name="photo" accept="image/*" id="addStaffPhotoInput">
                                    </div>
                                </div>
                            </div>

                            <!-- Fields -->
                            <div class="col-lg-9">
                                <div class="modal-section-title">Basic information</div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">First name <span style="color:var(--c-red)">*</span></label>
                                        <input type="text" class="form-control" name="firstname" autocomplete="given-name" required placeholder="Juan">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Last name <span style="color:var(--c-red)">*</span></label>
                                        <input type="text" class="form-control" name="lastname" autocomplete="family-name" required placeholder="dela Cruz">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Email <span style="color:var(--c-red)">*</span></label>
                                        <input type="email" class="form-control" name="email" autocomplete="email" required placeholder="juan@store.com">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Username <span style="color:var(--c-red)">*</span></label>
                                        <input type="text" class="form-control" name="username" autocomplete="username" required placeholder="jdelacruz">
                                    </div>

                                    <div class="col-12 mt-1">
                                        <div class="modal-section-title">Access settings</div>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Password <span style="color:var(--c-red)">*</span></label>
                                        <input type="password" class="form-control" name="password" autocomplete="new-password" required placeholder="Min. 8 characters">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Role <span style="color:var(--c-red)">*</span></label>
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

                    <div class="modal-footer justify-content-end gap-2">
                        <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-person-plus"></i> Add staff
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         EDIT STAFF MODAL
    ═══════════════════════════════════════════════════════════ -->
    <div class="modal fade modal-modern" id="editStaffModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <form id="editStaffForm" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="staff_id" id="editStaffId">

                    <div class="modal-header">
                        <div>
                            <div class="modal-title">
                                <i class="bi bi-pencil-square me-2" style="color:var(--c-amber);"></i>Edit staff
                            </div>
                            <div class="modal-subtitle">Update account details, role, or photo.</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-4">
                            <div class="col-lg-3">
                                <div class="photo-panel">
                                    <img id="editStaffPhotoPreview" src="/inventory_system/assets/img/default-user.png" alt="Preview" class="preview-image">
                                    <div style="width:100%">
                                        <label class="form-label">New photo</label>
                                        <input type="file" class="form-control" name="photo" accept="image/*" id="editStaffPhotoInput">
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-9">
                                <div class="modal-section-title">Basic information</div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">First name <span style="color:var(--c-red)">*</span></label>
                                        <input type="text" class="form-control" name="firstname" id="editFirstname" autocomplete="given-name" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Last name <span style="color:var(--c-red)">*</span></label>
                                        <input type="text" class="form-control" name="lastname" id="editLastname" autocomplete="family-name" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Email <span style="color:var(--c-red)">*</span></label>
                                        <input type="email" class="form-control" name="email" id="editEmail" autocomplete="email" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Username <span style="color:var(--c-red)">*</span></label>
                                        <input type="text" class="form-control" name="username" id="editUsername" autocomplete="username" required>
                                    </div>

                                    <div class="col-12 mt-1">
                                        <div class="modal-section-title">Access settings</div>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">New password <span style="color:var(--c-text-3);font-weight:400;text-transform:none;letter-spacing:0;">(leave blank to keep)</span></label>
                                        <input type="password" class="form-control" name="password" id="editPassword" autocomplete="new-password" placeholder="Leave blank to keep current">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Role <span style="color:var(--c-red)">*</span></label>
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

                    <div class="modal-footer justify-content-end gap-2">
                        <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-floppy"></i> Save changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</main>

<?php require __DIR__ . '/../components/footer.php'; ?>
<?php require __DIR__ . '/../components/js_script.php'; ?>
<script src="/inventory_system/assets/js/manage_staff.js"></script>

</body>
</html>
