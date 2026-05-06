<?php

// Make sure $product variable is defined before including this file
// $product = [
//   'product_id' => ...,
//   'photo' => ...,
//   'product_name' => ...,
//   'category_name' => ...,
//   'supplier_name' => ...,
//   'category_id' => ...,
//   'supplier_id' => ...,
//   'sku' => ...,
//   'quantity' => ...,
//   'price' => ...,
//   'sale_price' => ...,
//   'vatable' => ...,
//   'status' => ...,
// ];
?>
<tr id="productRow<?= $product['product_id'] ?>">
    <td>#</td>
    <td class="text-center">
        <img src="<?= !empty($product['photo']) ? $product['photo'] : '/inventory_system/assets/img/card.jpg' ?>" 
             style="width:50px;height:50px;object-fit:cover;">
    </td>
    <td><?= htmlspecialchars($product['product_name']) ?></td>
    <td><?= htmlspecialchars($product['category_name'] ?? '-') ?></td>
    <td><?= htmlspecialchars($product['supplier_name'] ?? '-') ?></td>
    <td><?= $product['sku'] ?: '-' ?></td>
    <td><?= $product['quantity'] ?></td>
    <td>₱<?= number_format($product['price'],2) ?></td>
    <td><?= $product['sale_price'] ? '₱'.number_format($product['sale_price'],2) : '-' ?></td>
    <td><?= $product['vatable'] ? 'Yes' : 'No' ?></td>
        <td><?= $product['reorder_level'] ?></td>

    <td>
        <span class="badge <?= ($product['status'] === 'active') ? 'bg-success' : 'bg-secondary' ?>">
            <?= ucfirst($product['status'] ?? 'inactive') ?>
        </span>
    </td>
    <td>
 <button class="btn btn-sm btn-warning editProductBtn"
    data-id="<?= $product['product_id'] ?>"
    data-name="<?= htmlspecialchars($product['product_name']) ?>"
    data-category="<?= $product['category_id'] ?>"
    data-supplier="<?= $product['supplier_id'] ?>"
    data-sku="<?= htmlspecialchars($product['sku'] ?? '') ?>"
    data-price="<?= $product['price'] ?>"
    data-sale_price="<?= $product['sale_price'] ?>"
    data-vatable="<?= $product['vatable'] ?>"
    data-reorder="<?= $product['reorder_level'] ?>"
    data-photo="<?= !empty($product['photo']) ? $product['photo'] : '/inventory_system/assets/img/card.jpg' ?>"
    data-bs-toggle="modal"
    data-bs-target="#editProductModal">
    <i class="bi bi-pencil-square"></i>
</button>



        <button class="btn btn-sm <?= ($product['status'] === 'active') ? 'btn-danger' : 'btn-success' ?> toggleProductStatusBtn"
                data-id="<?= $product['product_id'] ?>" 
                data-status="<?= ($product['status'] === 'active') ? 'deactivate' : 'activate' ?>">
            <?= ($product['status'] === 'active') ? '<i class="bi bi-slash-circle"></i>' : '<i class="bi bi-check-circle"></i>' ?>
        </button>
    </td>
</tr>
