<tr id="productRow<?= $product['product_id'] ?>">
    <td>#</td>
    <td class="text-center">
        <img src="<?= $product['photo'] ?: '/inventory_system/assets/uploads/products/images.jpeg' ?>" style="width:50px;height:50px;object-fit:cover;">
    </td>
    <td><?= htmlspecialchars($product['product_name'] ?? '') ?></td>
    <td><?= htmlspecialchars($product['category_name'] ?? '') ?></td>
    <td><?= htmlspecialchars($product['supplier_name'] ?? '') ?></td>
    <td><?= htmlspecialchars($product['sku'] ?? '') ?></td>
    <td><?= $product['quantity'] ?? 0 ?></td>
    <td>₱<?= number_format($product['price'] ?? 0, 2) ?></td>
    <td><?= isset($product['sale_price']) ? '₱'.number_format($product['sale_price'], 2) : '-' ?></td>
    <td><?= !empty($product['vatable']) ? 'Yes' : 'No' ?></td>
    <td><?= $product['reorder_level'] ?? 5 ?></td>
    <td>
        <span class="badge <?= ($product['status'] ?? 'inactive') === 'active' ? 'bg-success' : 'bg-secondary' ?>">
            <?= ucfirst($product['status'] ?? 'inactive') ?>
        </span>
    </td>
    <td>
        <div class="d-flex gap-2 justify-content-center">

            <button class="btn btn-sm btn-warning editProductBtn"
                    data-id="<?= $product['product_id'] ?>"
                    data-name="<?= htmlspecialchars($product['product_name'] ?? '') ?>"
                    data-category="<?= $product['category_id'] ?? 0 ?>"
                    data-supplier="<?= $product['supplier_id'] ?? 0 ?>"
                    data-sku="<?= htmlspecialchars($product['sku'] ?? '') ?>"
                    data-quantity="<?= $product['quantity'] ?? 0 ?>"
                    data-price="<?= $product['price'] ?? 0 ?>"
                    data-sale_price="<?= $product['sale_price'] ?? '' ?>"
                    data-vatable="<?= $product['vatable'] ?? 0 ?>"
                    data-reorder="<?= $product['reorder_level'] ?? 5 ?>"
                    data-photo="<?= $product['photo'] ?? '' ?>"
                    data-bs-toggle="modal" data-bs-target="#editProductModal">
                <i class="bi bi-pencil-square"></i>
            </button>

            <button class="btn btn-sm <?= ($product['status'] ?? 'inactive') === 'active' ? 'btn-danger' : 'btn-success' ?> toggleProductStatusBtn"
                    data-id="<?= $product['product_id'] ?>"
                    data-status="<?= ($product['status'] ?? 'inactive') === 'active' ? 'deactivate' : 'activate' ?>">
                <?= ($product['status'] ?? 'inactive') === 'active' ? '<i class="bi bi-slash-circle"></i>' : '<i class="bi bi-check-circle"></i>' ?>
            </button>

            <button class="btn btn-success btn-sm restock-btn"
                    data-id="<?= $product['product_id']; ?>"
                    data-name="<?= htmlspecialchars($product['product_name']); ?>">
                <i class="bi bi-box-arrow-in-down"></i>
            </button>

        </div>
    </td>
</tr>