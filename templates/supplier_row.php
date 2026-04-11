<?php
declare(strict_types=1);

$supplierId = (int) ($supplier['supplier_id'] ?? 0);
$status = (string) ($supplier['status'] ?? 'active');
$rowNumber = isset($rowNumber) ? (int) $rowNumber : 1;
?>
<tr id="supplierRow<?= $supplierId ?>">
    <td><?= $rowNumber ?></td>
    <td class="supplier-name"><?= htmlspecialchars((string) ($supplier['supplier_name'] ?? '')) ?></td>
    <td><?= htmlspecialchars((string) (($supplier['contact_person'] ?? '') !== '' ? $supplier['contact_person'] : '-')) ?></td>
    <td><?= htmlspecialchars((string) (($supplier['phone'] ?? '') !== '' ? $supplier['phone'] : '-')) ?></td>
    <td><?= htmlspecialchars((string) (($supplier['email'] ?? '') !== '' ? $supplier['email'] : '-')) ?></td>
    <td><?= htmlspecialchars((string) (($supplier['address'] ?? '') !== '' ? $supplier['address'] : '-')) ?></td>
    <td>
        <span class="badge supplier-status-badge <?= $status === 'active' ? 'bg-success' : 'bg-secondary' ?>">
            <?= ucfirst($status) ?>
        </span>
    </td>
    <td>
        <div class="d-flex gap-2 justify-content-center">
            <button
                type="button"
                class="btn btn-sm btn-warning editSupplierBtn"
                data-id="<?= $supplierId ?>"
                data-name="<?= htmlspecialchars((string) ($supplier['supplier_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                data-contact="<?= htmlspecialchars((string) ($supplier['contact_person'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                data-phone="<?= htmlspecialchars((string) ($supplier['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                data-email="<?= htmlspecialchars((string) ($supplier['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                data-address="<?= htmlspecialchars((string) ($supplier['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                data-status="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"
                title="Edit Supplier"
            >
                <i class="bi bi-pencil-square"></i>
            </button>

            <button
                type="button"
                class="btn btn-sm <?= $status === 'active' ? 'btn-danger' : 'btn-success' ?> toggleSupplierStatusBtn"
                data-id="<?= $supplierId ?>"
                data-name="<?= htmlspecialchars((string) ($supplier['supplier_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                data-status="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"
                title="<?= $status === 'active' ? 'Deactivate Supplier' : 'Activate Supplier' ?>"
            >
                <i class="bi <?= $status === 'active' ? 'bi-slash-circle' : 'bi-check-circle' ?>"></i>
            </button>
        </div>
    </td>
</tr>
