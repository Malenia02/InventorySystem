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

function escapeHtml(value) {
  const div = document.createElement("div");
  div.textContent = value ?? "";
  return div.innerHTML;
}

function sendWS(event = "notification_update", type = "general") {
  if (window.socket && window.socket.readyState === WebSocket.OPEN) {
    window.socket.send(JSON.stringify({ event, type }));
  } else {
    console.warn("WebSocket not connected");
  }
}

// POST helper
function postData(url, formData) {
  if (!(formData instanceof FormData)) formData = new FormData();
  if (!formData.has("csrf_token")) formData.append("csrf_token", csrfToken);

  return fetch(url, {
    method: "POST",
    headers: {
      "X-Requested-With": "XMLHttpRequest"
    },
    body: formData
  }).then(async (res) => {
    const text = await res.text();

    try {
      return JSON.parse(text);
    } catch (e) {
      console.error("Invalid JSON response:", text);
      throw new Error("Server returned invalid JSON.");
    }
  });
}

// Helper function to properly hide modal and remove backdrop
function hideModal(modalId) {
  const modalEl = document.getElementById(modalId);
  if (!modalEl) return;

  let modal = bootstrap.Modal.getInstance(modalEl);
  if (!modal) {
    modal = new bootstrap.Modal(modalEl);
  }

  modal.hide();
  setTimeout(cleanupModals, 120);
}

// Clean up all modal artifacts
function cleanupModals() {
  document.querySelectorAll(".modal-backdrop").forEach((backdrop) => {
    backdrop.remove();
  });

  document.body.classList.remove("modal-open");
  document.body.style.overflow = "";
  document.body.style.paddingRight = "";
}

// IMAGE PREVIEW FUNCTIONS
const addProductPhotoInput = document.querySelector('#addProductForm input[name="photo"]');
const addProductPhotoPreview = document.getElementById('addProductPhotoPreview');

if (addProductPhotoInput && addProductPhotoPreview) {
  addProductPhotoInput.addEventListener("change", function (e) {
    const file = e.target.files[0];
    if (file) {
      const reader = new FileReader();
      reader.onload = function (ev) {
        addProductPhotoPreview.src = ev.target.result;
      };
      reader.readAsDataURL(file);
    } else {
      addProductPhotoPreview.src = "/inventory_system/assets/img/card.jpg";
    }
  });
}

const editProductPhotoInput = document.querySelector('#editProductForm input[name="photo"]');
const editProductPhotoPreview = document.getElementById('editProductPhotoPreview');

if (editProductPhotoInput && editProductPhotoPreview) {
  editProductPhotoInput.addEventListener("change", function (e) {
    const file = e.target.files[0];
    if (file) {
      const reader = new FileReader();
      reader.onload = function (ev) {
        editProductPhotoPreview.src = ev.target.result;
      };
      reader.readAsDataURL(file);
    }
  });
}

document.getElementById("addProductModal")?.addEventListener("hidden.bs.modal", function () {
  const form = document.getElementById("addProductForm");
  if (form) {
    form.reset();
    const preview = document.getElementById("addProductPhotoPreview");
    if (preview) preview.src = "/inventory_system/assets/img/card.jpg";
    document.getElementById("addProductCategory")?.dispatchEvent(new Event("change"));
  }
});

document.getElementById("editProductModal")?.addEventListener("hidden.bs.modal", function () {
  const form = document.getElementById("editProductForm");
  if (form) {
    form.reset();
    const preview = document.getElementById("editProductPhotoPreview");
    if (preview) preview.src = "/inventory_system/assets/img/card.jpg";
    document.getElementById("editProductCategory")?.dispatchEvent(new Event("change"));
  }
});

document.addEventListener("DOMContentLoaded", () => {
  document.addEventListener("hidden.bs.modal", function () {
    cleanupModals();
  });

  const table = document.getElementById("productsTable");
  const addForm = document.getElementById("addProductForm");
  const editForm = document.getElementById("editProductForm");
  const restockForm = document.getElementById("restockForm");
  const stockOutForm = document.getElementById("stockOutForm");

  const supplierForm = document.getElementById("supplierForm");
  const supplierMessage = document.getElementById("supplierMessage");
  const supplierModalEl = document.getElementById("supplierModal");
  const saveSupplierBtn = document.getElementById("saveSupplierBtn");
  const addCategorySelect = document.getElementById("addProductCategory");
  const addSubcategorySelect = document.getElementById("addProductSubcategory");
  const editCategorySelect = document.getElementById("editProductCategory");
  const editSubcategorySelect = document.getElementById("editProductSubcategory");

  function cacheSubcategoryOptions(select) {
    if (!select) return [];

    return Array.from(select.options).map((option) => ({
      value: option.value,
      label: option.textContent,
      categoryId: option.dataset.categoryId || ""
    }));
  }

  const addSubcategoryOptions = cacheSubcategoryOptions(addSubcategorySelect);
  const editSubcategoryOptions = cacheSubcategoryOptions(editSubcategorySelect);

  function populateSubcategorySelect(select, options, categoryId, selectedValue = "") {
    if (!select) return;

    const normalizedCategory = String(categoryId || "");
    const normalizedSelected = String(selectedValue || "");
    const filteredOptions = options.filter((option) => (
      option.value === "" || option.categoryId === normalizedCategory
    ));

    select.innerHTML = filteredOptions.map((option) => {
      const selected = option.value === normalizedSelected ? " selected" : "";
      return `<option value="${escapeHtml(option.value)}"${selected}>${escapeHtml(option.label)}</option>`;
    }).join("");

    if (!Array.from(select.options).some((option) => option.value === normalizedSelected)) {
      select.value = "";
    }
  }

  if (table && window.simpleDatatables && simpleDatatables.DataTable) {
    new simpleDatatables.DataTable(table, {
      searchable: true,
      fixedHeight: true,
      perPage: 10
    });
  }

  function updateRowNumbers() {
    if (!table) return;

    table.querySelectorAll("tbody tr").forEach((tr, idx) => {
      const firstCell = tr.querySelector("td:first-child");
      if (firstCell) firstCell.innerText = idx + 1;
    });
  }

  function replaceRow(oldRow, newRowHtml) {
    const temp = document.createElement("tbody");
    temp.innerHTML = newRowHtml.trim();

    const newRow = temp.firstElementChild;
    if (!newRow || !oldRow) return;

    oldRow.innerHTML = newRow.innerHTML;
    updateRowNumbers();
  }

  function showSupplierMessage(message, type = "success") {
    if (!supplierMessage) return;

    supplierMessage.innerHTML = `
      <div class="alert alert-${type} alert-dismissible fade show mb-0" role="alert">
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    `;
  }

  function clearSupplierMessage() {
    if (supplierMessage) {
      supplierMessage.innerHTML = "";
    }
  }

  populateSubcategorySelect(
    addSubcategorySelect,
    addSubcategoryOptions,
    addCategorySelect?.value || "",
    addSubcategorySelect?.value || ""
  );
  populateSubcategorySelect(
    editSubcategorySelect,
    editSubcategoryOptions,
    editCategorySelect?.value || "",
    editSubcategorySelect?.value || ""
  );

  addCategorySelect?.addEventListener("change", () => {
    populateSubcategorySelect(
      addSubcategorySelect,
      addSubcategoryOptions,
      addCategorySelect.value,
      ""
    );
  });

  editCategorySelect?.addEventListener("change", () => {
    populateSubcategorySelect(
      editSubcategorySelect,
      editSubcategoryOptions,
      editCategorySelect.value,
      editSubcategorySelect?.value || ""
    );
  });

  if (supplierModalEl) {
    supplierModalEl.addEventListener("shown.bs.modal", () => {
      clearSupplierMessage();
      document.getElementById("supplier_name")?.focus();
    });

    supplierModalEl.addEventListener("hidden.bs.modal", () => {
      clearSupplierMessage();

      if (supplierForm) {
        supplierForm.reset();
      }

      if (saveSupplierBtn) {
        saveSupplierBtn.disabled = false;
        saveSupplierBtn.innerHTML = '<i class="bi bi-save me-1"></i>Add Supplier';
      }
    });
  }

  // ADD SUPPLIER
  if (supplierForm) {
    supplierForm.addEventListener("submit", (e) => {
      e.preventDefault();
      clearSupplierMessage();

      if (saveSupplierBtn) {
        saveSupplierBtn.disabled = true;
        saveSupplierBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';
      }

      const formData = new FormData(supplierForm);

      postData("/inventory_system/http/ajax/supplier_actions.php", formData)
        .then((res) => {
          if (!res.success) {
            showSupplierMessage(res.error || "Failed to add supplier", "danger");
            return;
          }

          const supplierId = String(res.supplier_id || "");
          const supplierName = res.supplier_name || "";

          if (supplierId && supplierName) {
            const selects = document.querySelectorAll('select[name="supplier_id"]');

            selects.forEach((select) => {
              let existingOption = Array.from(select.options).find(
                (option) => option.value === supplierId
              );

              if (!existingOption) {
                existingOption = new Option(supplierName, supplierId, true, true);
                select.add(existingOption);
              } else {
                select.value = supplierId;
              }

              select.dispatchEvent(new Event("change"));
            });
          }

          showSupplierMessage(res.message || "Supplier added successfully", "success");
          showToast(res.message || "Supplier added successfully", "success");

          setTimeout(() => {
            hideModal("supplierModal");
          }, 900);
        })
        .catch((err) => {
          console.error(err);
          showSupplierMessage("Server error!", "danger");
        })
        .finally(() => {
          if (saveSupplierBtn) {
            saveSupplierBtn.disabled = false;
            saveSupplierBtn.innerHTML = '<i class="bi bi-save me-1"></i>Add Supplier';
          }
        });
    });
  }

  // ADD PRODUCT
  if (addForm) {
    addForm.addEventListener("submit", (e) => {
      e.preventDefault();

      const formData = new FormData(addForm);
      formData.append("add_product", "1");

      postData("/inventory_system/http/ajax/product_actions.php", formData)
        .then((res) => {
          if (res.success && res.newRowHtml) {
            const temp = document.createElement("tbody");
            temp.innerHTML = res.newRowHtml.trim();
            const newRow = temp.firstElementChild;

            const tbody = table?.querySelector("tbody");
            if (tbody && newRow) {
              tbody.prepend(newRow);
              newRow.classList.add("table-success");
              setTimeout(() => newRow.classList.remove("table-success"), 3000);
            }

            updateRowNumbers();
            hideModal("addProductModal");
            addForm.reset();
            showToast(res.message || "Product added successfully", "success");
            sendWS(res.event, res.type);
          } else {
            showToast(res.error || "Add failed", "error");
          }
        })
        .catch(() => showToast("Server error!", "error"));
    });
  }

  // TABLE-LEVEL DELEGATION
  if (table) {
    table.addEventListener("click", (e) => {
      const editBtn = e.target.closest(".editProductBtn");
      const toggleBtn = e.target.closest(".toggleProductStatusBtn");
      const restockBtn = e.target.closest(".restock-btn");
      const stockOutBtn = e.target.closest(".stockout-btn");

      // EDIT PRODUCT
      if (editBtn) {
        const d = editBtn.dataset;

        document.getElementById("editProductId").value = d.id || "";
        document.getElementById("editProductName").value = d.name || "";
        document.getElementById("editProductSku").value = d.sku || "";
        document.getElementById("editProductCategory").value = d.category || "";
        populateSubcategorySelect(
          editSubcategorySelect,
          editSubcategoryOptions,
          d.category || "",
          d.subcategory || ""
        );
        document.getElementById("editProductSubcategory").value = d.subcategory || "";
        document.getElementById("editProductSupplierSelect").value = d.supplier || "";
        document.getElementById("editProductPrice").value = d.price || 0;
        document.getElementById("editProductSalePrice").value = d.sale_price || "";
        document.getElementById("editProductVatable").value = d.vatable || 0;
        document.getElementById("editProductReorderLevel").value = d.reorder || 5;
        document.getElementById("editProductPhotoPreview").src =
          d.photo || "/inventory_system/assets/img/card.jpg";

        new bootstrap.Modal(document.getElementById("editProductModal")).show();
        return;
      }

      // TOGGLE STATUS
      if (toggleBtn) {
        const id = parseInt(toggleBtn.dataset.id, 10);
        const productName = toggleBtn.dataset.name || "this product";
        const currentStatus = toggleBtn.dataset.status || "";
        const isCurrentlyActive = currentStatus === "active";
        const newAction = isCurrentlyActive ? "Deactivate" : "Activate";

        if (!id || id <= 0) {
          showToast("Invalid product ID", "error");
          return;
        }

        Swal.fire({
          title: `${newAction} ${productName}?`,
          html: `
            <p class="mb-1">You're about to <strong>${newAction.toLowerCase()}</strong> this product.</p>
            <small class="text-muted">You can change it back later anytime.</small>
          `,
          icon: "warning",
          showCancelButton: true,
          confirmButtonColor: "#3085d6",
          cancelButtonColor: "#d33",
          confirmButtonText: `Yes, ${newAction}`
        }).then((result) => {
          if (!result.isConfirmed) return;

          const formData = new FormData();
          formData.append("toggle_id", String(id));

          postData("/inventory_system/http/ajax/product_actions.php", formData)
            .then((res) => {
              if (res.success && res.new_status) {
                const row = document.getElementById(`productRow${id}`);
                if (row) {
                  const badge = row.querySelector(".badge");
                  const btn = row.querySelector(".toggleProductStatusBtn");

                  if (badge) {
                    badge.textContent =
                      res.new_status.charAt(0).toUpperCase() + res.new_status.slice(1);

                    badge.classList.remove("bg-success", "bg-secondary");
                    badge.classList.add(res.new_status === "active" ? "bg-success" : "bg-secondary");
                  }

                  if (btn) {
                    btn.classList.remove("btn-success", "btn-danger");
                    btn.classList.add(res.new_status === "active" ? "btn-danger" : "btn-success");
                    btn.innerHTML =
                      res.new_status === "active"
                        ? '<i class="bi bi-slash-circle"></i>'
                        : '<i class="bi bi-check-circle"></i>';

                    btn.dataset.status = res.new_status;
                    btn.dataset.name = productName;
                  }
                }

                const successMessage = res.message || `${productName} is now ${res.new_status}.`;
                showToast(`All set. ${successMessage}`, "success");
                sendWS(res.event, res.type);
              } else {
                showToast(res.error || "Action failed", "error");
              }
            })
            .catch(() => showToast("Server error!", "error"));
        });

        return;
      }

      // RESTOCK PRODUCT
      if (restockBtn) {
        const productId = parseInt(restockBtn.dataset.id, 10);
        const productName = restockBtn.dataset.name || "";

        if (!productId || productId <= 0) {
          console.error("Invalid product ID:", restockBtn.dataset.id);
          showToast("Invalid product ID", "error");
          return;
        }

        const hiddenId = document.getElementById("restockProductId");
        const nameInput = document.getElementById("restockProductName");

        if (!hiddenId || !nameInput) {
          showToast("Restock form elements not found", "error");
          return;
        }

        hiddenId.value = String(productId);
        nameInput.value = productName;

        new bootstrap.Modal(document.getElementById("restockModal")).show();
        return;
      }

      // STOCK OUT PRODUCT
      if (stockOutBtn) {
        const productId = parseInt(stockOutBtn.dataset.id, 10);
        const productName = stockOutBtn.dataset.name || "";

        if (!productId || productId <= 0) {
          showToast("Invalid product ID", "error");
          return;
        }

        const hiddenId = document.getElementById("stockOutProductId");
        const nameInput = document.getElementById("stockOutProductName");

        if (!hiddenId || !nameInput) {
          showToast("Stock-out form elements not found", "error");
          return;
        }

        hiddenId.value = String(productId);
        nameInput.value = productName;

        new bootstrap.Modal(document.getElementById("stockOutModal")).show();
        return;
      }
    });
  }

  // EDIT PRODUCT
  if (editForm) {
    editForm.addEventListener("submit", (e) => {
      e.preventDefault();

      const formData = new FormData(editForm);
      formData.append("edit_product", "1");

      postData("/inventory_system/http/ajax/product_actions.php", formData)
        .then((res) => {
          if (res.success && res.newRowHtml) {
            const id = document.getElementById("editProductId").value;
            const oldRow = document.getElementById(`productRow${id}`);

            if (oldRow) {
              replaceRow(oldRow, res.newRowHtml);
              oldRow.classList.add("table-warning");
              setTimeout(() => oldRow.classList.remove("table-warning"), 3000);
            }

            hideModal("editProductModal");
            editForm.reset();
            showToast(res.message || "Product updated", "success");
            sendWS(res.event, res.type);
          } else {
            showToast(res.error || "Update failed", "error");
          }
        })
        .catch(() => showToast("Server error!", "error"));
    });
  }

  // RESTOCK PRODUCT
  if (restockForm) {
    restockForm.addEventListener("submit", (e) => {
      e.preventDefault();

      const productId = document.getElementById("restockProductId")?.value || "";
      const quantity = restockForm.querySelector('input[name="quantity"]')?.value || "";

      if (!productId || parseInt(productId, 10) <= 0) {
        showToast("Invalid product ID", "error");
        return;
      }

      if (!quantity || parseInt(quantity, 10) <= 0) {
        showToast("Invalid quantity", "error");
        return;
      }

      const formData = new FormData(restockForm);
      formData.set("product_id", productId);
      formData.append("restock_product", "1");

      postData("/inventory_system/http/ajax/product_actions.php", formData)
        .then((res) => {
          if (!res.success) {
            return showToast(res.error || "Restock failed", "error");
          }

          const row = document.getElementById(`productRow${res.product_id}`);
          if (row) {
            const qtyCell = row.querySelector(".product-quantity");
            if (qtyCell) {
              qtyCell.textContent = res.new_quantity;
              qtyCell.classList.add("fw-bold");
              setTimeout(() => qtyCell.classList.remove("fw-bold"), 700);
            }
          }

          hideModal("restockModal");
          restockForm.reset();

          const restockName = document.getElementById("restockProductName");
          const restockId = document.getElementById("restockProductId");
          if (restockName) restockName.value = "";
          if (restockId) restockId.value = "";

          showToast(res.message || "Product restocked", "success");
          sendWS(res.event, res.type);
        })
        .catch(() => showToast("Server error!", "error"));
    });
  }

  // STOCK OUT PRODUCT
  if (stockOutForm) {
    stockOutForm.addEventListener("submit", (e) => {
      e.preventDefault();

      const productId = document.getElementById("stockOutProductId")?.value || "";
      const quantity = stockOutForm.querySelector('input[name="quantity"]')?.value || "";
      const reasonSelect = stockOutForm.querySelector('select[name="reason"]')?.value || "";
      const customReason = document.getElementById("customStockOutReason")?.value.trim() || "";

      if (!productId || parseInt(productId, 10) <= 0) {
        showToast("Invalid product ID", "error");
        return;
      }

      if (!quantity || parseInt(quantity, 10) <= 0) {
        showToast("Invalid quantity", "error");
        return;
      }

      let finalReason = reasonSelect;
      if (reasonSelect === "Other" && customReason !== "") {
        finalReason = customReason;
      }

      if (!finalReason) {
        showToast("Please provide a reason", "error");
        return;
      }

      const formData = new FormData(stockOutForm);
      formData.set("product_id", productId);
      formData.set("reason", finalReason);
      formData.append("stockout_product", "1");

      postData("/inventory_system/http/ajax/product_actions.php", formData)
        .then((res) => {
          if (!res.success) {
            return showToast(res.error || "Stock out failed", "error");
          }

          const row = document.getElementById(`productRow${res.product_id}`);
          if (row) {
            const qtyCell = row.querySelector(".product-quantity");
            if (qtyCell) {
              qtyCell.textContent = res.new_quantity;
              qtyCell.classList.add("fw-bold");
              setTimeout(() => qtyCell.classList.remove("fw-bold"), 700);
            }
          }

          hideModal("stockOutModal");
          stockOutForm.reset();

          const productNameInput = document.getElementById("stockOutProductName");
          const productIdInput = document.getElementById("stockOutProductId");
          const customReasonInput = document.getElementById("customStockOutReason");

          if (productNameInput) productNameInput.value = "";
          if (productIdInput) productIdInput.value = "";
          if (customReasonInput) customReasonInput.value = "";

          showToast(res.message || "Stock out recorded", "success");
          sendWS(res.event, res.type);
        })
        .catch(() => showToast("Server error!", "error"));
    });
  }
});
