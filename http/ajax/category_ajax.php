<?php
header('Content-Type: application/json');
require_once $_SERVER['DOCUMENT_ROOT'].'/inventory_system/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/inventory_system/controllers/CategoryController.php';

$response = ['success' => false, 'error' => 'Invalid action'];

try {

    // ==========================
    // ADD CATEGORY
    // ==========================
    if (isset($_POST['add_category'])) {

        $name = trim($_POST['category_name']);
        if ($name === '') throw new Exception("Category name is required.");

        $id = CategoryController::addCategory($conn, 'categories', $name);
        $category = CategoryController::getCategoryById($conn, 'categories', $id);

        ob_start();
        ?>
        <tr id="categoryRow<?= $category['category_id'] ?>">
            <td>#</td>
            <td><?= htmlspecialchars($category['category_name']) ?></td>
            <td>
                <span class="badge <?= $category['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                    <?= ucfirst($category['status']) ?>
                </span>
            </td>
            <td>
                <button class="btn btn-sm btn-warning editCategoryBtn"
                        data-id="<?= $category['category_id'] ?>"
                        data-name="<?= htmlspecialchars($category['category_name']) ?>"
                        data-bs-toggle="modal" data-bs-target="#editCategoryModal">
                    <i class="bi bi-pencil-square"></i>
                </button>

                <button class="btn btn-sm <?= $category['status'] === 'active' ? 'btn-danger' : 'btn-success' ?> toggleCategoryStatusBtn"
                        data-id="<?= $category['category_id'] ?>"
                        data-status="<?= $category['status'] === 'active' ? 'deactivate' : 'activate' ?>">
                    <?= $category['status'] === 'active' 
                        ? '<i class="bi bi-slash-circle"></i>' 
                        : '<i class="bi bi-check-circle"></i>' ?>
                </button>
            </td>
        </tr>
        <?php

        $response = [
            'success' => 'Category added successfully!',
            'updatedRowHtml' => ob_get_clean()
        ];
    }

    // ==========================
    // EDIT CATEGORY
    // ==========================
    if (isset($_POST['edit_category'])) {

        $id   = $_POST['category_id'];
        $name = trim($_POST['category_name']);
        if ($name === '') throw new Exception("Category name is required.");

        CategoryController::updateCategory($conn, 'categories', $id, $name);
        $category = CategoryController::getCategoryById($conn, 'categories', $id);

        ob_start();
        ?>
        <tr id="categoryRow<?= $category['category_id'] ?>">
            <td>#</td>
            <td><?= htmlspecialchars($category['category_name']) ?></td>
            <td>
                <span class="badge <?= $category['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                    <?= ucfirst($category['status']) ?>
                </span>
            </td>
            <td>
                <button class="btn btn-sm btn-warning editCategoryBtn"
                        data-id="<?= $category['category_id'] ?>"
                        data-name="<?= htmlspecialchars($category['category_name']) ?>"
                        data-bs-toggle="modal" data-bs-target="#editCategoryModal">
                    <i class="bi bi-pencil-square"></i>
                </button>

                <button class="btn btn-sm <?= $category['status'] === 'active' ? 'btn-danger' : 'btn-success' ?> toggleCategoryStatusBtn"
                        data-id="<?= $category['category_id'] ?>"
                        data-status="<?= $category['status'] === 'active' ? 'deactivate' : 'activate' ?>">
                    <?= $category['status'] === 'active' 
                        ? '<i class="bi bi-slash-circle"></i>' 
                        : '<i class="bi bi-check-circle"></i>' ?>
                </button>
            </td>
        </tr>
        <?php

        $response = [
            'success' => 'Category updated successfully!',
            'updatedRowHtml' => ob_get_clean()
        ];
    }

    // ==========================
    // TOGGLE STATUS
    // ==========================
    if (isset($_POST['toggle_id'])) {
        $id = $_POST['toggle_id'];
        $newStatus = CategoryController::toggleStatus($conn, 'categories', $id);
        if (!$newStatus) throw new Exception("Category not found.");

        $category = CategoryController::getCategoryById($conn, 'categories', $id);

        ob_start();
        ?>
        <tr id="categoryRow<?= $category['category_id'] ?>">
            <td>#</td>
            <td><?= htmlspecialchars($category['category_name']) ?></td>
            <td>
                <span class="badge <?= $category['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                    <?= ucfirst($category['status']) ?>
                </span>
            </td>
            <td>
                <button class="btn btn-sm btn-warning editCategoryBtn"
                        data-id="<?= $category['category_id'] ?>"
                        data-name="<?= htmlspecialchars($category['category_name']) ?>"
                        data-bs-toggle="modal" data-bs-target="#editCategoryModal">
                    <i class="bi bi-pencil-square"></i>
                </button>

                <button class="btn btn-sm <?= $category['status'] === 'active' ? 'btn-danger' : 'btn-success' ?> toggleCategoryStatusBtn"
                        data-id="<?= $category['category_id'] ?>"
                        data-status="<?= $category['status'] === 'active' ? 'deactivate' : 'activate' ?>">
                    <?= $category['status'] === 'active' 
                        ? '<i class="bi bi-slash-circle"></i>' 
                        : '<i class="bi bi-check-circle"></i>' ?>
                </button>
            </td>
        </tr>
        <?php

        $response = [
            'success' => "Category status updated to $newStatus",
            'updatedRowHtml' => ob_get_clean()
        ];
    }

} catch (Exception $e) {
    $response = ['error' => $e->getMessage()];
}

echo json_encode($response);
