<?php
header('Content-Type: application/json');
require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/controllers/CategoryController.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/middleware/csrf.php'; // CSRF check

$response = ['success' => false, 'error' => 'Invalid action'];

try {

    // ==========================
    // ADD CATEGORY
    // ==========================
    if (isset($_POST['add_category'])) {
        $name = trim($_POST['category_name']);
        if ($name === '')
            throw new Exception("Category name is required.");

        // Prevent duplicates
        $existing = $conn->prepare("SELECT * FROM categories WHERE category_name = ?");
        $existing->execute([$name]);
        if ($existing->rowCount() > 0)
            throw new Exception("Category '$name' already exists.");

        $id = CategoryController::addCategory($conn, 'categories', $name);
        $category = CategoryController::getCategoryById($conn, 'categories', $id);

        ob_start();
        include $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/templates/category_row_template.php';
        $rowHtml = ob_get_clean();

        $response = [
            'success' => true,
            'message' => "Category '$name' added successfully!",
            'updatedRowHtml' => $rowHtml
        ];
    }

    // ==========================
    // EDIT CATEGORY
    // ==========================
    if (isset($_POST['edit_category'])) {
        $id = $_POST['category_id'];
        $name = trim($_POST['category_name']);
        if ($name === '')
            throw new Exception("Category name is required.");

        // Prevent duplicates
        $existing = $conn->prepare("SELECT * FROM categories WHERE category_name = ? AND category_id != ?");
        $existing->execute([$name, $id]);
        if ($existing->rowCount() > 0)
            throw new Exception("Category '$name' already exists.");

        CategoryController::updateCategory($conn, 'categories', $id, $name);
        $category = CategoryController::getCategoryById($conn, 'categories', $id);

        ob_start();
        include $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/templates/category_row_template.php';
        $rowHtml = ob_get_clean();

        $response = [
            'success' => true,
            'message' => "Category '$name' updated successfully",
            'updatedRowHtml' => $rowHtml
        ];
    }

    // ==========================
    // TOGGLE STATUS
    // ==========================
    if (isset($_POST['toggle_id'])) {
        $id = $_POST['toggle_id'];
        $newStatus = CategoryController::toggleStatus($conn, 'categories', $id);
        if (!$newStatus)
            throw new Exception("Category not found.");

        $category = CategoryController::getCategoryById($conn, 'categories', $id);

        ob_start();
        include $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/templates/category_row_template.php';
        $rowHtml = ob_get_clean();

        $response = [
            'success' => true,
            'message' => "Category '{$category['category_name']}' has been " . ($newStatus === 'active' ? "activated" : "deactivated") . " successfully!",
            'updatedRowHtml' => $rowHtml
        ];
    }

} catch (Exception $e) {
    $response = ['error' => $e->getMessage()];
}

echo json_encode($response);
