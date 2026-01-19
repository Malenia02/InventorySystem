<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ==========================
// ALLOWED STAFF ROLES
// ==========================
$allowedRoles = ['admin', 'staff', 'cashier'];

// ==========================
// GET ALL STAFF
// ==========================
function getAllStaff($conn, $table_users) {
    $stmt = $conn->prepare("SELECT * FROM {$table_users} ORDER BY role ASC, status ASC, user_id DESC");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ==========================
// GET STAFF BY ID
// ==========================
function getStaffById($conn, $table_users, $id) {
    $stmt = $conn->prepare("SELECT * FROM {$table_users} WHERE user_id = :id");
    $stmt->execute(['id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// ==========================
// HANDLE PHOTO UPLOAD
// ==========================
function handlePhotoUpload($fileInputName, $staffName = 'unknown') {
    if (empty($_FILES[$fileInputName]['name'])) return null;

    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    $maxFileSize = 2 * 1024 * 1024; // 2MB

    $fileType = mime_content_type($_FILES[$fileInputName]['tmp_name']);
    $fileSize = $_FILES[$fileInputName]['size'];

    if (!in_array($fileType, $allowedTypes)) throw new Exception("Invalid file type. Only JPG, PNG, WEBP allowed.");
    if ($fileSize > $maxFileSize) throw new Exception("File too large. Max 2MB.");

    // Extra security: make sure it's actually an image
    $imageInfo = getimagesize($_FILES[$fileInputName]['tmp_name']);
    if ($imageInfo === false) throw new Exception("Uploaded file is not a valid image.");

    $safeName = preg_replace("/[^a-zA-Z0-9_-]/", "_", strtolower($staffName));
    $uploadDir = $_SERVER['DOCUMENT_ROOT'] . "/inventory_system/uploads/staff/{$safeName}/";
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $ext = pathinfo($_FILES[$fileInputName]['name'], PATHINFO_EXTENSION);
    $photoName = uniqid('photo_', true) . '.' . $ext;

    if (!move_uploaded_file($_FILES[$fileInputName]['tmp_name'], $uploadDir . $photoName)) {
        throw new Exception("Failed to move uploaded file.");
    }

    return "/inventory_system/uploads/staff/{$safeName}/" . $photoName;
}

// ==========================
// ADD STAFF
// ==========================
function addStaff($conn, $table_users, $username, $password, $firstname, $lastname, $email, $photoPath = null, $role='staff') {
    global $allowedRoles;
    if (!in_array($role, $allowedRoles)) $role = 'staff';

    // Optional: check duplicate username/email
    $stmtCheck = $conn->prepare("SELECT COUNT(*) FROM {$table_users} WHERE username = :username OR email = :email");
    $stmtCheck->execute(['username' => $username, 'email' => $email]);
    if ($stmtCheck->fetchColumn() > 0) throw new Exception("Username or email already exists.");

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("
        INSERT INTO {$table_users}
        (first_name, last_name, email, username, password, role, status, photo)
        VALUES
        (:firstname, :lastname, :email, :username, :password, :role, 'active', :photo)
    ");

    return $stmt->execute([
        'firstname' => $firstname,
        'lastname'  => $lastname,
        'email'     => $email,
        'username'  => $username,
        'password'  => $hashedPassword,
        'photo'     => $photoPath,
        'role'      => $role
    ]);
}

// ==========================
// UPDATE STAFF
// ==========================
function updateStaff($conn, $table_users, $id, $username, $firstname, $lastname, $email, $password = null, $photoPath = null, $role = null) {
    global $allowedRoles;

    $fields = [
        "first_name = :firstname",
        "last_name = :lastname",
        "email = :email",
        "username = :username"
    ];
    $params = [
        'firstname' => $firstname,
        'lastname'  => $lastname,
        'email'     => $email,
        'username'  => $username,
        'id'        => $id
    ];

    if (!empty($password)) {
        $fields[] = "password = :password";
        $params['password'] = password_hash($password, PASSWORD_DEFAULT);
    }

    if (!empty($photoPath)) {
        $fields[] = "photo = :photo";
        $params['photo'] = $photoPath;
    }

    if (!empty($role) && in_array($role, $allowedRoles)) {
        $fields[] = "role = :role";
        $params['role'] = $role;
    }

    $sql = "UPDATE {$table_users} SET " . implode(", ", $fields) . " WHERE user_id = :id";
    $stmt = $conn->prepare($sql);

    return $stmt->execute($params);
}

// ==========================
// DEACTIVATE / REACTIVATE
// ==========================
function deactivateStaff($conn, $table_users, $id) {
    // Only admin can deactivate
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') return false;

    $stmt = $conn->prepare("UPDATE {$table_users} SET status = 'inactive', deactivated_at = NOW() WHERE user_id = :id");
    return $stmt->execute(['id' => $id]);
}

function reactivateStaff($conn, $table_users, $id) {
    // Only admin can reactivate
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') return false;

    $stmt = $conn->prepare("UPDATE {$table_users} SET status = 'active', deactivated_at = NULL WHERE user_id = :id");
    return $stmt->execute(['id' => $id]);
}

// ==========================
// GENERATE STAFF ROW HTML
// ==========================
function generateStaffRowHtml($staff) {
    $photo = !empty($staff['photo']) ? $staff['photo'] : '/inventory_system/assets/img/default-user.png';
    $statusClass = $staff['status'] === 'active' ? 'bg-success' : 'bg-secondary';
    $btnClass = $staff['status'] === 'active' ? 'btn-secondary' : 'btn-success';
    $btnIcon  = $staff['status'] === 'active' ? 'bi-person-x' : 'bi-person-check';

    $role = htmlspecialchars($staff['role']);

    return "
    <tr id='staffRow{$staff['user_id']}'>
        <td>0</td>
        <td class='text-center'>
            <img src='{$photo}' style='width:50px;height:50px;object-fit:cover;'>
        </td>
        <td>".htmlspecialchars($staff['username'])."</td>
        <td>".htmlspecialchars($staff['email'])."</td>
        <td>{$role}</td>
        <td>
            <span class='badge {$statusClass}' id='staffStatus{$staff['user_id']}'>".ucfirst($staff['status'])."</span>
        </td>
        <td>
            <button class='btn btn-sm btn-warning editStaffBtn' 
                data-id='{$staff['user_id']}' 
                data-username='".htmlspecialchars($staff['username'])."' 
                data-firstname='".htmlspecialchars($staff['first_name'])."' 
                data-lastname='".htmlspecialchars($staff['last_name'])."' 
                data-email='".htmlspecialchars($staff['email'])."' 
                data-photo='{$photo}' 
                data-role='{$role}' 
                data-bs-toggle='modal' data-bs-target='#editStaffModal' title='Edit'>
                <i class='bi bi-pencil-square'></i>
            </button>

            <!-- Toggle Status Button only for admin -->
            ".(isset($_SESSION['role']) && $_SESSION['role'] === 'admin' ? "
            <button class='btn btn-sm {$btnClass} toggleStatusBtn' data-id='{$staff['user_id']}'>
                <i class='bi {$btnIcon}'></i>
            </button>
            " : "")."
        </td>
    </tr>
    ";
}

// ==========================
// HANDLE STAFF REQUESTS (AJAX)
// ==========================
function handleStaffRequest($conn, $table_users) {
    $errorMsg = null;
    $successMsg = null;

    try {
        // ==========================
        // ADD STAFF
        // ==========================
        if (isset($_POST['add_staff'])) {
            $staffName = $_POST['firstname'] . '_' . $_POST['lastname'];
            $photoPath = handlePhotoUpload('photo', $staffName);
            $role = $_POST['role'] ?? 'staff';

            addStaff($conn, $table_users, $_POST['username'], $_POST['password'], $_POST['firstname'], $_POST['lastname'], $_POST['email'], $photoPath, $role);

            $staff = getStaffById($conn, $table_users, $conn->lastInsertId());
            $newStaffRow = generateStaffRowHtml($staff);

            $successMsg = "Staff '{$role}: {$_POST['firstname']} {$_POST['lastname']}' added successfully.";
            echo json_encode(['success' => $successMsg, 'newStaffRow' => $newStaffRow]);
            exit;
        }

        // ==========================
        // EDIT STAFF
        // ==========================
        if (isset($_POST['edit_staff'])) {
            $staffName = $_POST['firstname'] . '_' . $_POST['lastname'];
            $photoPath = handlePhotoUpload('photo', $staffName);
            $role = $_POST['role'] ?? null;

            updateStaff(
                $conn, 
                $table_users, 
                $_POST['staff_id'], 
                $_POST['username'], 
                $_POST['firstname'], 
                $_POST['lastname'], 
                $_POST['email'], 
                $_POST['password'] ?: null, 
                $photoPath, 
                $role
            );

            $staff = getStaffById($conn, $table_users, $_POST['staff_id']);
            $updatedRowHtml = generateStaffRowHtml($staff);

            $successMsg = "Staff '{$_POST['firstname']} {$_POST['lastname']}' updated successfully.";
            echo json_encode(['success' => $successMsg, 'updatedRowHtml' => $updatedRowHtml, 'staff_id' => $staff['user_id']]);
            exit;
        }

        // ==========================
        // TOGGLE STATUS
        // ==========================
        if (isset($_POST['toggle_id'])) {
            // Only admin can toggle
            if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
                echo json_encode(['error' => 'Unauthorized']);
                exit;
            }

            $id = intval($_POST['toggle_id']);
            $staff = getStaffById($conn, $table_users, $id);

            if ($staff) {
                if ($staff['status'] === 'active') deactivateStaff($conn, $table_users, $id);
                else reactivateStaff($conn, $table_users, $id);

                $staff = getStaffById($conn, $table_users, $id);
                $updatedRowHtml = generateStaffRowHtml($staff);
                $successMsg = "Staff '{$staff['first_name']} {$staff['last_name']}' status updated.";

                echo json_encode([
                    'success' => $successMsg,
                    'updatedRowHtml' => $updatedRowHtml,
                    'staff_id' => $id
                ]);
                exit;
            } else {
                $errorMsg = "Staff not found.";
            }
        }

    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
    }

    echo json_encode(['error' => $errorMsg, 'success' => $successMsg]);
    exit;
}
