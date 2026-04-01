<?php
/**
 * templates/product_row.php
 * Expects: $product
 */

$photo    = !empty($product['photo']) ? htmlspecialchars($product['photo']) : '/inventory_system/assets/img/card.jpg';
$status   = $product['status'] ?? 'inactive';
$isActive = $status === 'active';
?>
<tr id="productRow<?= (int)$product['product_id'] ?>">
    <td></td>

    <td class="text-center">
        <img src="<?= $photo ?>"
             alt="<?= htmlspecialchars($product['product_name'] ?? 'Product') ?>"
             style="width:50px;height:50px;object-fit:cover;">
    </td>

    <td><?= htmlspecialchars($product['product_name'] ?? '-') ?></td>
    <td><?= htmlspecialchars($product['category_name'] ?? '-') ?></td>
    <td><?= htmlspecialchars($product['subcategory_name'] ?? '-') ?></td>
    <td><?= htmlspecialchars($product['supplier_name'] ?? '-') ?></td>
    <td><?= htmlspecialchars($product['sku'] ?? '-') ?></td>

    <td class="product-quantity">
        <?= (int)($product['quantity'] ?? 0) ?>
    </td>

    <td>₱<?= number_format((float)($product['price'] ?? 0), 2) ?></td>

    <td><?= !empty($product['sale_price']) ? rtrim(rtrim(number_format((float) $product['sale_price'], 2), '0'), '.') . '%' : '-' ?></td>

    <td><?= !empty($product['vatable']) ? 'Yes' : 'No' ?></td>
    <td><?= (int)($product['reorder_level'] ?? 5) ?></td>

    <td>
        <span class="badge <?= $isActive ? 'bg-success' : 'bg-secondary' ?>">
            <?= ucfirst($status) ?>
        </span>
    </td>

    <td>
        <div class="d-flex gap-2 justify-content-center">

            <button class="btn btn-sm btn-warning editProductBtn"
                data-id="<?= (int)$product['product_id'] ?>"
                data-name="<?= htmlspecialchars($product['product_name'] ?? '') ?>"
                data-category="<?= (int)($product['category_id'] ?? 0) ?>"
                data-subcategory="<?= (int)($product['subcategory_id'] ?? 0) ?>"
                data-supplier="<?= (int)($product['supplier_id'] ?? 0) ?>"
                data-sku="<?= htmlspecialchars($product['sku'] ?? '') ?>"
                data-price="<?= (float)($product['price'] ?? 0) ?>"
                data-sale_price="<?= htmlspecialchars((string)($product['sale_price'] ?? '')) ?>"
                data-vatable="<?= (int)($product['vatable'] ?? 0) ?>"
                data-reorder="<?= (int)($product['reorder_level'] ?? 5) ?>"
                data-photo="<?= $photo ?>"
                data-bs-toggle="modal"
                data-bs-target="#editProductModal">
                <i class="bi bi-pencil-square"></i>
            </button>

            <button class="btn btn-success btn-sm restock-btn"
                data-id="<?= (int)$product['product_id'] ?>"
                data-name="<?= htmlspecialchars($product['product_name'] ?? '') ?>">
                <i class="bi bi-box-arrow-in-down"></i>
            </button>

            <button class="btn btn-secondary btn-sm stockout-btn"
                data-id="<?= (int)$product['product_id'] ?>"
                data-name="<?= htmlspecialchars($product['product_name'] ?? '') ?>">
                <i class="bi bi-box-arrow-up"></i>
            </button>

            <button class="btn btn-sm <?= $isActive ? 'btn-danger' : 'btn-success' ?> toggleProductStatusBtn"
                data-id="<?= (int)$product['product_id'] ?>"
                data-name="<?= htmlspecialchars($product['product_name'] ?? '') ?>"
                data-status="<?= $status ?>">
                <i class="bi <?= $isActive ? 'bi-slash-circle' : 'bi-check-circle' ?>"></i>
            </button>

        </div>
    </td>
</tr>
