<tr id="productRow<?= $product['product_id'] ?>">
    <td></td> <!-- JS will fill this dynamically -->
    <td>
        <img src="<?= $product['photo'] ?: '/inventory_system/assets/uploads/products/images.jpeg' ?>" 
             style="width:50px;height:50px;object-fit:cover;" 
             alt="<?= htmlspecialchars($product['product_name']) ?>">
    </td>
    <td><?= htmlspecialchars($product['product_name']) ?></td>
    <td><?= htmlspecialchars($product['category_name']) ?></td>
    <td><?= htmlspecialchars($product['supplier_name']) ?></td>
    <td><?= htmlspecialchars($product['sku']) ?></td>
    <td class="product-quantity"><?= (int)$product['quantity'] ?></td>
    <td>₱<?= number_format($product['price'], 2) ?></td>
    <td><?= $product['sale_price'] ? '₱' . number_format($product['sale_price'], 2) : '-' ?></td>
    <td><?= $product['vatable'] ? 'Yes' : 'No' ?></td>
    <td><?= (int)$product['reorder_level'] ?></td>
    <td>
        <span class="badge <?= $product['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>">
            <?= ucfirst($product['status']) ?>
        </span>
    </td>
    <td>
        <div class="d-flex gap-2 justify-content-center">
            <button class="btn btn-sm btn-warning editProductBtn"
                    data-id="<?= $product['product_id'] ?>"
                    data-name="<?= htmlspecialchars($product['product_name']) ?>"
                    data-category="<?= $product['category_id'] ?>"
                    data-supplier="<?= $product['supplier_id'] ?>"
                    data-sku="<?= htmlspecialchars($product['sku']) ?>"
                    data-quantity="<?= $product['quantity'] ?>"
                    data-price="<?= $product['price'] ?>"
                    data-sale_price="<?= $product['sale_price'] ?? '' ?>"
                    data-vatable="<?= $product['vatable'] ?>"
                    data-reorder="<?= $product['reorder_level'] ?>"
                    data-photo="<?= $product['photo'] ?>"
                    data-bs-toggle="modal" data-bs-target="#editProductModal">
                <i class="bi bi-pencil-square"></i>
            </button>

            <button class="btn btn-sm <?= $product['status'] === 'active' ? 'btn-danger' : 'btn-success' ?> toggleProductStatusBtn"
                    data-id="<?= $product['product_id'] ?>"
                    data-status="<?= $product['status'] ?>">
                <?= $product['status'] === 'active' 
                    ? '<i class="bi bi-slash-circle"></i>' 
                    : '<i class="bi bi-check-circle"></i>' ?>
            </button>

            <button class="btn btn-success btn-sm restock-btn"
                    data-id="<?= $product['product_id'] ?>"
                    data-name="<?= htmlspecialchars($product['product_name']) ?>">
                <i class="bi bi-box-arrow-in-down"></i>
            </button>
        </div>
    </td>
</tr>