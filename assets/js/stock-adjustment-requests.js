document.addEventListener("DOMContentLoaded", () => {
  const page = document.querySelector(".stock-request-page");
  if (!page) return;

  const feedback = document.getElementById("stockRequestFeedback");
  const requestList = document.getElementById("stockRequestList");
  const emptyState = document.getElementById("stockRequestEmptyState");
  const requestForm = document.getElementById("stockAdjustmentRequestForm");
  const productSelect = document.getElementById("stockRequestProductSelect");
  const productQty = document.getElementById("stockRequestProductQty");
  const productMeta = document.getElementById("stockRequestProductMeta");
  const directionSelect = document.getElementById("stockRequestDirection");
  const typeSelect = document.getElementById("stockRequestType");
  const supplierWrap = document.getElementById("stockRequestSupplierWrap");
  const supplierSelect = document.getElementById("stockRequestSupplier");
  const filterChips = Array.from(document.querySelectorAll(".request-filter-chip"));
  const activeRole = requestList?.dataset.role || "staff";
  const csrfToken =
    requestList?.dataset.csrf ||
    document.querySelector('input[name="csrf_token"]')?.value ||
    "";

  const typeOptions = {
    stock_in: [
      { value: "delivery_received", label: "Delivery Received" },
      { value: "manual_restock", label: "Manual Restock" },
      { value: "count_correction", label: "Count Correction" },
      { value: "customer_return", label: "Customer Return" },
      { value: "purchase_receive", label: "Purchase Order Receipt" },
    ],
    stock_out: [
      { value: "damaged", label: "Damaged" },
      { value: "expired", label: "Expired" },
      { value: "lost", label: "Lost" },
      { value: "returned_to_supplier", label: "Returned to Supplier" },
      { value: "broken_packaging", label: "Broken Packaging" },
      { value: "count_correction", label: "Count Correction" },
      { value: "other", label: "Other" },
    ],
  };

  let activeFilter =
    filterChips.find((chip) => chip.classList.contains("is-active"))?.dataset.filter || "all";

  const escapeHtml = (value) =>
    String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");

  const nl2br = (value) => escapeHtml(value).replace(/\r?\n/g, "<br>");

  const showFeedback = (type, message) => {
    if (!feedback) return;
    feedback.innerHTML = `<div class="alert alert-${type}">${escapeHtml(message)}</div>`;
  };

  const updateCounts = (counts) => {
    if (!counts) return;
    const map = {
      pending: document.getElementById("stockRequestCountPending"),
      approved: document.getElementById("stockRequestCountApproved"),
      declined: document.getElementById("stockRequestCountDeclined"),
    };

    Object.entries(map).forEach(([key, node]) => {
      if (node && Object.hasOwn(counts, key)) {
        node.textContent = String(counts[key]);
      }
    });
  };

  const syncTypeOptions = () => {
    if (!typeSelect || !directionSelect) return;
    const direction = directionSelect.value === "stock_in" ? "stock_in" : "stock_out";
    typeSelect.innerHTML = typeOptions[direction]
      .map((option) => `<option value="${escapeHtml(option.value)}">${escapeHtml(option.label)}</option>`)
      .join("");

    if (supplierWrap) {
      supplierWrap.classList.toggle("d-none", direction !== "stock_in");
    }
  };

  const syncProductSummary = () => {
    if (!productSelect || !productQty || !productMeta) return;
    const option = productSelect.selectedOptions[0];
    if (!option || !option.value) {
      productQty.textContent = "Choose a product";
      productMeta.textContent = "Live product details will appear here.";
      if (supplierSelect) supplierSelect.value = "";
      return;
    }

    const stock = option.dataset.stock || "0";
    const category = option.dataset.category || "Uncategorized";
    const supplier = option.dataset.supplier || "No supplier";
    productQty.textContent = `${stock} unit(s) on hand`;
    productMeta.textContent = `${category} • ${supplier}`;

    if (supplierSelect) {
      const supplierId = option.dataset.supplierId || "";
      supplierSelect.value = supplierId;
    }
  };

  const applyFilter = (filter) => {
    activeFilter = filter;
    filterChips.forEach((chip) => {
      chip.classList.toggle("is-active", chip.dataset.filter === filter);
    });

    if (!requestList) return;

    const cards = Array.from(requestList.querySelectorAll(".request-card"));
    let visibleCount = 0;

    cards.forEach((card) => {
      const matches = filter === "all" || card.dataset.status === filter;
      card.classList.toggle("d-none", !matches);
      if (matches) visibleCount += 1;
    });

    if (emptyState) {
      emptyState.classList.toggle("d-none", visibleCount !== 0);
    }
  };

  const buildReviewedInfo = (request) => `
    <div class="request-foot__info">
      <span>Reviewed at</span>
      <strong>${escapeHtml(request.reviewed_at_label || "-")}</strong>
    </div>
  `;

  const buildReviewForm = (requestId) => `
    <form class="request-review-form" data-request-id="${requestId}">
      <input type="hidden" name="csrf_token" value="${escapeHtml(csrfToken)}">
      <input type="hidden" name="request_id" value="${requestId}">
      <textarea
        name="review_note"
        class="form-control"
        rows="2"
        placeholder="Optional approval note or required decline reason."
      ></textarea>
      <div class="request-review-actions">
        <button type="submit" name="decision" value="approved" class="btn btn-success">
          Approve &amp; Apply
        </button>
        <button type="submit" name="decision" value="declined" class="btn btn-outline-danger">
          Decline
        </button>
      </div>
    </form>
  `;

  const renderRequestCard = (request) => {
    const footerRight = request.can_review ? buildReviewForm(request.request_id) : buildReviewedInfo(request);
    const appliedRange =
      request.status === "approved" && request.before_quantity !== null && request.after_quantity !== null
        ? `${escapeHtml(request.before_quantity)} → ${escapeHtml(request.after_quantity)}`
        : "-";

    return `
      <article class="request-card" data-request-id="${request.request_id}" data-status="${escapeHtml(request.status)}">
        <div class="request-card__top">
          <div>
            <span class="request-mini-badge ${escapeHtml(request.status_class)}">${escapeHtml(request.status_label)}</span>
            <h3>${escapeHtml(request.product_name)}</h3>
            <p>
              ${escapeHtml(request.direction_label)} <span class="mx-2">|</span>
              Qty: ${escapeHtml(request.quantity)} <span class="mx-2">|</span>
              Type: ${escapeHtml(request.adjustment_type_label)}
            </p>
          </div>
          <div class="request-card__meta">
            <span>Requested</span>
            <strong>${escapeHtml(request.requested_at_label)}</strong>
          </div>
        </div>

        <div class="request-detail-grid">
          <div>
            <span class="request-label">Reason</span>
            <p>${nl2br(request.reason)}</p>
          </div>
          <div>
            <span class="request-label">Review note</span>
            <p>${nl2br(request.review_note || "No review note yet.")}</p>
          </div>
          <div>
            <span class="request-label">Product snapshot</span>
            <p>
              Category: ${escapeHtml(request.category_name)}<br>
              Current stock: ${escapeHtml(request.current_quantity)}
            </p>
          </div>
          <div>
            <span class="request-label">People</span>
            <p>
              Requested by: ${escapeHtml(request.requester_label)}<br>
              Reviewed by: ${escapeHtml(request.reviewer_label || "-")}
            </p>
          </div>
        </div>

        <div class="request-foot">
          <div class="request-foot__info">
            <span>Applied quantity</span>
            <strong>${appliedRange}</strong>
          </div>
          ${footerRight}
        </div>
      </article>
    `;
  };

  const upsertCard = (request, prepend = false) => {
    if (!requestList || !request) return;
    requestList.classList.remove("d-none");

    const existing = requestList.querySelector(`.request-card[data-request-id="${request.request_id}"]`);
    const markup = renderRequestCard(request);

    if (existing) {
      existing.outerHTML = markup;
    } else if (prepend) {
      requestList.insertAdjacentHTML("afterbegin", markup);
    } else {
      requestList.insertAdjacentHTML("beforeend", markup);
    }

    applyFilter(activeFilter);
  };

  const request = async (payload) => {
    const response = await fetch("/inventory_system/http/ajax/stock_adjustment_requests.php", {
      method: "POST",
      body: payload,
      headers: {
        "X-Requested-With": "XMLHttpRequest",
        Accept: "application/json",
      },
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.success) {
      throw new Error(data.error || "Unable to process stock adjustment request.");
    }

    return data;
  };

  filterChips.forEach((chip) => {
    chip.addEventListener("click", (event) => {
      event.preventDefault();
      applyFilter(chip.dataset.filter || "all");
    });
  });

  if (directionSelect) {
    directionSelect.addEventListener("change", syncTypeOptions);
  }

  if (productSelect) {
    productSelect.addEventListener("change", syncProductSummary);
  }

  if (requestForm) {
    requestForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      const submitButton = requestForm.querySelector('button[type="submit"]');
      const originalText = submitButton?.textContent || "Send Request";

      try {
        if (submitButton) {
          submitButton.disabled = true;
          submitButton.textContent = "Sending...";
        }

        const payload = new FormData(requestForm);
        payload.set("action", "submit");
        if (!payload.has("csrf_token")) {
          payload.set("csrf_token", csrfToken);
        }

        const data = await request(payload);
        showFeedback("success", data.message);
        updateCounts(data.counts);
        upsertCard(data.request, true);
        requestForm.reset();
        syncTypeOptions();
        syncProductSummary();
      } catch (error) {
        showFeedback("danger", error.message || "Unable to submit request.");
      } finally {
        if (submitButton) {
          submitButton.disabled = false;
          submitButton.textContent = originalText;
        }
      }
    });
  }

  document.addEventListener("submit", async (event) => {
    const form = event.target.closest(".request-review-form");
    if (!form) return;

    event.preventDefault();
    const submitter = document.activeElement;
    const decision = submitter?.value || "approved";
    const buttons = Array.from(form.querySelectorAll("button"));

    try {
      buttons.forEach((button) => {
        button.disabled = true;
      });

      const payload = new FormData(form);
      payload.set("action", "review");
      payload.set("decision", decision);
      if (!payload.has("csrf_token")) {
        payload.set("csrf_token", csrfToken);
      }

      const data = await request(payload);
      showFeedback("success", data.message);
      updateCounts(data.counts);
      upsertCard(data.request);
    } catch (error) {
      showFeedback("danger", error.message || "Unable to review request.");
    } finally {
      buttons.forEach((button) => {
        button.disabled = false;
      });
    }
  });

  syncTypeOptions();
  syncProductSummary();
  applyFilter(activeFilter);
});
