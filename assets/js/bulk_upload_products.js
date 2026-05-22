const bulkUploadForm = document.getElementById("bulkUploadProductsForm");
const bulkUploadSubmitBtn = document.getElementById("bulkUploadSubmitBtn");
const bulkUploadResults = document.getElementById("bulkUploadResults");
const bulkProductCards = document.getElementById("bulkProductCards");
const bulkCardTemplate = document.getElementById("bulkProductCardTemplate");
const addBulkButtons = document.querySelectorAll("#addBulkProductCardBtn, #addBulkProductCardBtnBottom");
const bulkUploadCsrf = document.querySelector('#bulkUploadProductsForm input[name="csrf_token"]')?.value || "";

let bulkCardIndex = 0;

const bulkCreateToast = Swal.mixin({
  toast: true,
  position: "top-end",
  showConfirmButton: false,
  timer: 3500,
  timerProgressBar: true
});

function showBulkCreateToast(message, icon = "success") {
  bulkCreateToast.fire({ icon, title: message });
}

function sendBulkCreateWS(event = "notification_update", type = "product") {
  if (window.socket && window.socket.readyState === WebSocket.OPEN) {
    window.socket.send(JSON.stringify({ event, type }));
  }
}

function escapeBulkHtml(value) {
  const div = document.createElement("div");
  div.textContent = value ?? "";
  return div.innerHTML;
}

function postBulkCreate(formData) {
  if (!formData.has("csrf_token")) {
    formData.append("csrf_token", bulkUploadCsrf);
  }

  return fetch("/inventory_system/http/ajax/product_actions.php", {
    method: "POST",
    headers: {
      "X-Requested-With": "XMLHttpRequest"
    },
    body: formData
  }).then(async (response) => {
    const text = await response.text();

    try {
      return JSON.parse(text);
    } catch (error) {
      console.error("Invalid JSON response:", text);
      throw new Error("Server returned invalid JSON.");
    }
  });
}

function updateBulkCardNumbers() {
  const cards = bulkProductCards?.querySelectorAll(".bulk-product-card") || [];
  cards.forEach((card, index) => {
    const numberEl = card.querySelector(".bulk-product-number");
    if (numberEl) {
      numberEl.textContent = String(index + 1);
    }

    const removeBtn = card.querySelector(".remove-bulk-card-btn");
    if (removeBtn) {
      removeBtn.disabled = cards.length === 1;
    }
  });
}

function cacheBulkSubcategoryOptions(select) {
  if (!select) {
    return [];
  }

  return Array.from(select.options).map((option) => ({
    value: option.value,
    label: option.textContent,
    categoryId: option.dataset.categoryId || ""
  }));
}

function populateBulkSubcategorySelect(select, options, categoryId, selectedValue = "") {
  if (!select) {
    return;
  }

  const normalizedCategory = String(categoryId || "");
  const normalizedSelected = String(selectedValue || "");
  const filteredOptions = options.filter((option) => (
    option.value === "" || option.categoryId === normalizedCategory
  ));

  select.innerHTML = filteredOptions.map((option) => {
    const selected = option.value === normalizedSelected ? " selected" : "";
    return `<option value="${escapeBulkHtml(option.value)}"${selected}>${escapeBulkHtml(option.label)}</option>`;
  }).join("");

  if (!Array.from(select.options).some((option) => option.value === normalizedSelected)) {
    select.value = "";
  }
}

function isBulkBeverageCategory(select) {
  const label = select?.options?.[select.selectedIndex]?.textContent || "";
  return label.toLowerCase().includes("beverage");
}

function applyBulkUnitRules(card, categorySelect) {
  if (!card || !categorySelect) {
    return;
  }

  const beverageMode = isBulkBeverageCategory(categorySelect);

  card.querySelectorAll(".bulk-box-field").forEach((fieldWrap) => {
    fieldWrap.classList.toggle("d-none", beverageMode);

    fieldWrap.querySelectorAll("input, select, textarea").forEach((field) => {
      field.disabled = beverageMode;
      if (beverageMode) {
        field.value = "";
      }
    });
  });

  card.querySelectorAll('.bulk-unit-note[data-unit-note="beverage"]').forEach((note) => {
    note.classList.toggle("d-none", !beverageMode);
  });
}

function addBulkCard() {
  if (!bulkProductCards || !bulkCardTemplate) {
    return;
  }

  const html = bulkCardTemplate.innerHTML
    .replaceAll("__INDEX__", String(bulkCardIndex))
    .replaceAll("__NUMBER__", String(bulkProductCards.children.length + 1));

  const wrapper = document.createElement("div");
  wrapper.innerHTML = html.trim();
  const card = wrapper.firstElementChild;

  if (!card) {
    return;
  }

  const photoInput = card.querySelector(".bulk-photo-input");
  const photoPreview = card.querySelector(".bulk-photo-preview");
  const categorySelect = card.querySelector(".bulk-category-select");
  const subcategorySelect = card.querySelector(".bulk-subcategory-select");
  const subcategoryOptions = cacheBulkSubcategoryOptions(subcategorySelect);

  if (photoInput && photoPreview) {
    photoInput.addEventListener("change", (event) => {
      const file = event.target.files?.[0];
      if (!file) {
        photoPreview.src = "/inventory_system/assets/img/card.jpg";
        return;
      }

      const reader = new FileReader();
      reader.onload = (loadEvent) => {
        photoPreview.src = loadEvent.target?.result || "/inventory_system/assets/img/card.jpg";
      };
      reader.readAsDataURL(file);
    });
  }

  if (categorySelect && subcategorySelect) {
    populateBulkSubcategorySelect(
      subcategorySelect,
      subcategoryOptions,
      categorySelect.value,
      subcategorySelect.value
    );
    applyBulkUnitRules(card, categorySelect);

    categorySelect.addEventListener("change", () => {
      populateBulkSubcategorySelect(
        subcategorySelect,
        subcategoryOptions,
        categorySelect.value,
        ""
      );
      applyBulkUnitRules(card, categorySelect);
    });
  }

  const removeBtn = card.querySelector(".remove-bulk-card-btn");
  if (removeBtn) {
    removeBtn.addEventListener("click", () => {
      card.remove();
      updateBulkCardNumbers();
    });
  }

  bulkProductCards.appendChild(card);
  bulkCardIndex += 1;
  updateBulkCardNumbers();
}

function resetBulkCards() {
  if (!bulkProductCards) {
    return;
  }

  bulkProductCards.innerHTML = "";
  bulkCardIndex = 0;
  addBulkCard();
}

function renderBulkCreateResults(result) {
  if (!bulkUploadResults) {
    return;
  }

  const createdCount = Number(result.created_count || 0);
  const errorCount = Number(result.error_count || 0);
  const errors = Array.isArray(result.errors) ? result.errors : [];
  const alertClass = createdCount > 0 && errorCount === 0 ? "alert-success" : "alert-warning";

  const rowsHtml = errors.map((item) => `
    <tr>
      <td>${escapeBulkHtml(item.row)}</td>
      <td>${escapeBulkHtml(item.product || "-")}</td>
      <td>${escapeBulkHtml(item.error || "Unknown error")}</td>
    </tr>
  `).join("");

  bulkUploadResults.innerHTML = `
    <div class="alert ${alertClass}">
      <div class="fw-semibold mb-1">${escapeBulkHtml(result.message || "Bulk create finished.")}</div>
      <div>Created: <strong>${createdCount}</strong> | Failed: <strong>${errorCount}</strong></div>
    </div>
    ${rowsHtml ? `
      <div class="card border-0 shadow-sm">
        <div class="card-body">
          <h6 class="fw-bold mb-3">Products that need attention</h6>
          <div class="table-responsive">
            <table class="table table-sm table-bordered bulk-result-table mb-0">
              <thead>
                <tr>
                  <th style="width: 90px;">Form</th>
                  <th style="width: 220px;">Product</th>
                  <th>Issue</th>
                </tr>
              </thead>
              <tbody>${rowsHtml}</tbody>
            </table>
          </div>
        </div>
      </div>
    ` : ""}
  `;
}

addBulkButtons.forEach((button) => {
  button.addEventListener("click", addBulkCard);
});

if (bulkUploadForm) {
  resetBulkCards();

  bulkUploadForm.addEventListener("submit", (event) => {
    event.preventDefault();

    const totalCards = bulkProductCards?.querySelectorAll(".bulk-product-card").length || 0;
    if (totalCards === 0) {
      showBulkCreateToast("Add at least one product form first.", "warning");
      return;
    }

    const formData = new FormData(bulkUploadForm);
    formData.append("bulk_create_products", "1");

    if (bulkUploadSubmitBtn) {
      bulkUploadSubmitBtn.disabled = true;
      bulkUploadSubmitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';
    }

    if (bulkUploadResults) {
      bulkUploadResults.innerHTML = "";
    }

    postBulkCreate(formData)
      .then((result) => {
        renderBulkCreateResults(result);

        if (result.success) {
          showBulkCreateToast(result.message || "Products created successfully.", "success");
          sendBulkCreateWS(result.event, result.type);
          resetBulkCards();
        } else {
          showBulkCreateToast(result.message || result.error || "Bulk create finished with issues.", "warning");
        }
      })
      .catch((error) => {
        console.error(error);

        if (bulkUploadResults) {
          bulkUploadResults.innerHTML = `
            <div class="alert alert-danger mb-0">
              Unable to save the products right now. Please try again.
            </div>
          `;
        }

        showBulkCreateToast("Server error while saving products.", "error");
      })
      .finally(() => {
        if (bulkUploadSubmitBtn) {
          bulkUploadSubmitBtn.disabled = false;
          bulkUploadSubmitBtn.innerHTML = '<i class="bi bi-check2-circle me-1"></i>Save All Products';
        }
      });
  });
}
