<tr id="productRow<?= $product['product_id'] ?>">
    <td>#</td>
    <td class="text-center">
        <img src="<?= htmlspecialchars($product['photo'] ?: '/inventory_system/assets/uploads/products/images.jpeg') ?>" 
             style="width:50px;height:50px;object-fit:cover;">
    </td>
    <td><?= htmlspecialchars($product['product_name']) ?></td>
    <td><?= htmlspecialchars($product['category_id']) ?></td>
    <td>₱<?= number_format($product['price'], 2) ?></td>
    <td><?= $product['sale_price'] ? '₱'.number_format($product['sale_price'], 2) : '-' ?></td>
    <td><?= $product['vatable'] ? 'Yes' : 'No' ?></td>
    <td>
        <span class="badge <?= $product['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>">
            <?= ucfirst($product['status']) ?>
        </span>
    </td>
    <td>
        <button class="btn btn-sm btn-warning editProductBtn" 
                data-id="<?= $product['product_id'] ?>"
                data-name="<?= htmlspecialchars($product['product_name']) ?>"
                data-category="<?= $product['category_id'] ?>"
                data-price="<?= $product['price'] ?>"
                data-sale_price="<?= $product['sale_price'] ?>"
                data-vatable="<?= $product['vatable'] ?>"
                data-photo="<?= htmlspecialchars($product['photo']) ?>"
                data-bs-toggle="modal" data-bs-target="#editProductModal">
            <i class="bi bi-pencil-square"></i>
        </button>
        <button class="btn btn-sm <?= $product['status'] === 'active' ? 'btn-danger' : 'btn-success' ?> toggleProductStatusBtn"
                data-id="<?= $product['product_id'] ?>"
                data-status="<?= $product['status'] === 'active' ? 'deactivate' : 'activate' ?>">
            <?= $product['status'] === 'active' ? '<i class="bi bi-slash-circle"></i>' : '<i class="bi bi-check-circle"></i>' ?>
        </button>
    </td>
</tr>
