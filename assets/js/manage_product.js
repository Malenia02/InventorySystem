

const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || "";

// SweetAlert Toast
const Toast = Swal.mixin({
  toast: true,
  position: "top-end",
  showConfirmButton: false,
  timer: 3000,
  timerProgressBar: true
});

function showToast(message, icon = "success") {
  Toast.fire({ icon, title: message });
}

// POST helper
function postData(url, formData) {
  if (!(formData instanceof FormData)) formData = new FormData();
  if (!formData.has("csrf_token")) formData.append("csrf_token", csrfToken);
  return fetch(url, { method: "POST", body: formData }).then(res => res.json());
}

// Helper function to properly hide modal and remove backdrop
function hideModal(modalId) {
  const modalEl = document.getElementById(modalId);
  if (!modalEl) return;

  // Try to get existing instance
  let modal = bootstrap.Modal.getInstance(modalEl);
  
  // If no instance exists, create one and hide it
  if (!modal) {
    modal = new bootstrap.Modal(modalEl);
  }
  
  modal.hide();
  
  // Clean up any orphan backdrops and body styles
  setTimeout(cleanupModals, 100);
}

// Clean up all modal artifacts
function cleanupModals() {
  // Remove all modal backdrops
  document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
    backdrop.remove();
  });
  
  // Clean up body styles
  document.body.classList.remove('modal-open');
  document.body.style.overflow = '';
  document.body.style.paddingRight = '';
}

// ==========================
// IMAGE PREVIEW FUNCTIONS
// ==========================

// Add Product - Photo Preview
const addProductPhotoInput = document.querySelector('#addProductForm input[name="photo"]');
const addProductPhotoPreview = document.getElementById('addProductPhotoPreview');

if (addProductPhotoInput && addProductPhotoPreview) {
  addProductPhotoInput.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
      const reader = new FileReader();
      reader.onload = function(e) {
        addProductPhotoPreview.src = e.target.result;
      };
      reader.readAsDataURL(file);
    } else {
      addProductPhotoPreview.src = '/inventory_system/assets/img/card.jpg';
    }
  });
}

// Edit Product - Photo Preview
const editProductPhotoInput = document.querySelector('#editProductForm input[name="photo"]');
const editProductPhotoPreview = document.getElementById('editProductPhotoPreview');

if (editProductPhotoInput && editProductPhotoPreview) {
  editProductPhotoInput.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
      const reader = new FileReader();
      reader.onload = function(e) {
        editProductPhotoPreview.src = e.target.result;
      };
      reader.readAsDataURL(file);
    }
  });
}

// Reset Add Product form when modal is closed
document.getElementById('addProductModal')?.addEventListener('hidden.bs.modal', function() {
  const form = document.getElementById('addProductForm');
  if (form) {
    form.reset();
    const preview = document.getElementById('addProductPhotoPreview');
    if (preview) preview.src = '/inventory_system/assets/img/card.jpg';
  }
});

// Reset Edit Product form when modal is closed
document.getElementById('editProductModal')?.addEventListener('hidden.bs.modal', function() {
  const form = document.getElementById('editProductForm');
  if (form) {
    form.reset();
    const preview = document.getElementById('editProductPhotoPreview');
    if (preview) preview.src = '/inventory_system/assets/img/card.jpg';
  }
});

document.addEventListener("DOMContentLoaded", () => {
  // Listen for modal hidden events to clean up backdrops when user clicks X or Close
  document.addEventListener('hidden.bs.modal', function() {
    cleanupModals();
  });

  const table = document.getElementById("productsTable");
  const addForm = document.getElementById("addProductForm");
  const editForm = document.getElementById("editProductForm");
  const restockForm = document.getElementById("restockForm");

  let dataTable = table ? new simpleDatatables.DataTable(table, { searchable: true, fixedHeight: true, perPage: 10 }) : null;

  // Update row numbers dynamically
  function updateRowNumbers() {
    if (!table) return;
    table.querySelectorAll("tbody tr").forEach((tr, idx) => {
      tr.querySelector("td:first-child").innerText = idx + 1;
    });
  }

  // Replace row content safely, keeping event listeners intact
  function replaceRow(oldRow, newRowHtml) {
    const temp = document.createElement("tbody");
    temp.innerHTML = newRowHtml.trim();
    oldRow.innerHTML = temp.firstChild.innerHTML; // Replace only innerHTML
    updateRowNumbers();
  }

  // ==========================
  // ADD PRODUCT
  // ==========================
  if (addForm) {
    addForm.addEventListener("submit", e => {
      e.preventDefault();
      const formData = new FormData(addForm);
      formData.append("add_product", true);

      postData("/inventory_system/http/ajax/product_actions.php", formData)
        .then(res => {
          if (res.success && res.newRowHtml) {
            const temp = document.createElement("tbody");
            temp.innerHTML = res.newRowHtml.trim();
            const newRow = temp.firstChild;
            table.querySelector("tbody").prepend(newRow);

            // Highlight the newly added row
            newRow.classList.add('table-success');
            setTimeout(() => {
              newRow.classList.remove('table-success');
            }, 3000);

            updateRowNumbers();
            hideModal("addProductModal");
            addForm.reset();
            showToast(res.message, "success");
          } else {
            showToast(res.error || "Add failed", "error");
          }
        })
        .catch(() => showToast("Server error!", "error"));
    });
  }

  // ==========================
  // TABLE-LEVEL DELEGATION
  // Handles Edit, Toggle, Restock buttons
  // ==========================
  if (table) {
    table.addEventListener("click", e => {
      const editBtn = e.target.closest(".editProductBtn");
      const toggleBtn = e.target.closest(".toggleProductStatusBtn");
      const restockBtn = e.target.closest(".restock-btn");

      // --- EDIT PRODUCT ---
      if (editBtn) {
        const d = editBtn.dataset;
        document.getElementById("editProductId").value = d.id;
        document.getElementById("editProductName").value = d.name || "";
        document.getElementById("editProductSku").value = d.sku || "";
        document.getElementById("editProductCategory").value = d.category || "";
        document.getElementById("editProductSupplierSelect").value = d.supplier || "";
        document.getElementById("editProductPrice").value = d.price || 0;
        document.getElementById("editProductSalePrice").value = d.sale_price || "";
        document.getElementById("editProductVatable").value = d.vatable || 0;
        document.getElementById("editProductReorderLevel").value = d.reorder || 5;
        document.getElementById("editProductPhotoPreview").src = d.photo || "/inventory_system/assets/uploads/products/images.jpeg";

        new bootstrap.Modal(document.getElementById("editProductModal")).show();
        return;
      }

      // --- TOGGLE STATUS ---
      if (toggleBtn) {
        const id = toggleBtn.dataset.id;
        const currentStatus = toggleBtn.dataset.status;
        const newAction = currentStatus === "active" ? "Deactivate" : "Activate";

        Swal.fire({
          title: `${newAction} Product?`,
          text: "You can change it back later.",
          icon: "warning",
          showCancelButton: true,
          confirmButtonColor: "#3085d6",
          cancelButtonColor: "#d33",
          confirmButtonText: `Yes, ${newAction}`
        }).then(result => {
          if (!result.isConfirmed) return;

          const formData = new FormData();
          formData.append("toggle_id", id);

          postData("/inventory_system/http/ajax/product_actions.php", formData)
            .then(res => {
              if (res.success && res.new_status) {
                const oldRow = document.getElementById(`productRow${id}`);
                if (oldRow) {
                  const badge = oldRow.querySelector(".badge");
                  const toggleBtn = oldRow.querySelector(".toggleProductStatusBtn");

                  badge.textContent = res.new_status.charAt(0).toUpperCase() + res.new_status.slice(1);

                  if (res.new_status === "active") {
                    badge.classList.replace("bg-secondary", "bg-success");
                    toggleBtn.classList.replace("btn-success", "btn-danger");
                    toggleBtn.innerHTML = '<i class="bi bi-slash-circle"></i>';
                    toggleBtn.dataset.status = "active";
                  } else {
                    badge.classList.replace("bg-success", "bg-secondary");
                    toggleBtn.classList.replace("btn-danger", "btn-success");
                    toggleBtn.innerHTML = '<i class="bi bi-check-circle"></i>';
                    toggleBtn.dataset.status = "inactive";
                  }
                }

                showToast(res.message, "success");
              } else {
                showToast(res.error || "Action failed", "error");
              }
            })
            .catch(() => showToast("Server error!", "error"));
        });
        return;
      }

      // --- RESTOCK PRODUCT ---
      if (restockBtn) {
        document.getElementById("restockProductId").value = restockBtn.dataset.id;
        document.getElementById("restockProductName").value = restockBtn.dataset.name;
        new bootstrap.Modal(document.getElementById("restockModal")).show();
        return;
      }
    });
  }

 // ==========================
  // EDIT PRODUCT
  // ==========================
  if (editForm) {
    editForm.addEventListener("submit", e => {
      e.preventDefault();
      const formData = new FormData(editForm);
      formData.append("edit_product", true);

      postData("/inventory_system/http/ajax/product_actions.php", formData)
        .then(res => {
          if (res.success && res.newRowHtml) {
            const id = document.getElementById("editProductId").value;
            const oldRow = document.getElementById(`productRow${id}`);
            if (oldRow) {
              replaceRow(oldRow, res.newRowHtml);

              // Highlight edited row
              oldRow.classList.add('table-warning');
              setTimeout(() => oldRow.classList.remove('table-warning'), 3000);
            }

            hideModal("editProductModal");
            editForm.reset();
            showToast(res.message, "success");
          } else {
            showToast(res.error || "Update failed", "error");
          }
        })
        .catch(() => showToast("Server error!", "error"));
    });
  }

  // ==========================
  // RESTOCK PRODUCT
  // ==========================
  if (restockForm) {
    restockForm.addEventListener("submit", e => {
      e.preventDefault();
      const formData = new FormData(restockForm);
      formData.append("restock_product", true);

      postData("/inventory_system/http/ajax/product_actions.php", formData)
        .then(res => {
          if (!res.success) return showToast(res.error || "Restock failed", "error");

          const row = document.getElementById(`productRow${res.product_id}`);
          if (row) {
            const qtyCell = row.querySelector(".product-quantity");
            if (qtyCell) {
              qtyCell.textContent = res.new_quantity;
              qtyCell.classList.add("fw-bold");
              setTimeout(() => qtyCell.classList.remove("fw-bold"), 700);
            }
          }

          // Use the helper function to properly hide modal and remove backdrop
          hideModal("restockModal");
          restockForm.reset();
          showToast(res.message, "success");
        })
        .catch(() => showToast("Server error!", "error"));
    });
  }

});
