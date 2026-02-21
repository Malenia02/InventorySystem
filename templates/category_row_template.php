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
                data-status="<?= $category['status'] ?>">
            <?= $category['status'] === 'active' 
                ? '<i class="bi bi-slash-circle"></i>' 
                : '<i class="bi bi-check-circle"></i>' ?>
        </button>
    </td>
</tr>
