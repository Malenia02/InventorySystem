<?php
$status = (string) ($subcategory['status'] ?? 'inactive');
$isActive = $status === 'active';
?>
<tr id="subcategoryRow<?= (int) ($subcategory['subcategory_id'] ?? 0) ?>">
    <td><?= isset($rowNumber) ? (int) $rowNumber : '' ?></td>
    <td><?= htmlspecialchars((string) ($subcategory['category_name'] ?? '-')) ?></td>
    <td><?= htmlspecialchars((string) ($subcategory['subcategory_name'] ?? '-')) ?></td>
    <td><?= htmlspecialchars((string) ($subcategory['description'] ?? '-')) ?></td>
    <td>
        <span class="badge <?= $isActive ? 'bg-success' : 'bg-secondary' ?>">
            <?= ucfirst($status) ?>
        </span>
    </td>
    <td>
        <div class="d-flex gap-2 justify-content-center">
            <button
                class="btn btn-sm btn-warning editSubcategoryBtn"
                data-id="<?= (int) ($subcategory['subcategory_id'] ?? 0) ?>"
                data-category="<?= (int) ($subcategory['category_id'] ?? 0) ?>"
                data-name="<?= htmlspecialchars((string) ($subcategory['subcategory_name'] ?? '')) ?>"
                data-description="<?= htmlspecialchars((string) ($subcategory['description'] ?? '')) ?>"
                data-bs-toggle="modal"
                data-bs-target="#editSubcategoryModal"
            >
                <i class="bi bi-pencil-square"></i>
            </button>
            <button
                class="btn btn-sm <?= $isActive ? 'btn-danger' : 'btn-success' ?> toggleSubcategoryStatusBtn"
                data-id="<?= (int) ($subcategory['subcategory_id'] ?? 0) ?>"
                data-name="<?= htmlspecialchars((string) ($subcategory['subcategory_name'] ?? '')) ?>"
                data-status="<?= htmlspecialchars($status) ?>"
            >
                <i class="bi <?= $isActive ? 'bi-slash-circle' : 'bi-check-circle' ?>"></i>
            </button>
        </div>
    </td>
</tr>
