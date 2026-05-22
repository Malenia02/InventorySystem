document.addEventListener("DOMContentLoaded", () => {
  let cards = Array.from(document.querySelectorAll(".approval-card-wrap"));
  if (!cards.length) return;

  const dataStore = window.APPROVAL_CENTER_DATA?.items || {};
  const searchInput = document.getElementById("approvalSearch");
  const typeInput = document.getElementById("approvalType");
  const statusInput = document.getElementById("approvalStatus");
  const chips = Array.from(document.querySelectorAll(".approval-chip"));
  const metaNode = document.getElementById("approvalMeta");
  const emptyNode = document.getElementById("approvalEmpty");
  const pendingNode = document.getElementById("approvalPendingCount");
  const approvedNode = document.getElementById("approvalApprovedCount");
  const declinedNode = document.getElementById("approvalDeclinedCount");
  const sourceNode = document.getElementById("approvalSourceCount");
  const modalElement = document.getElementById("approvalReviewModal");
  const modal = modalElement ? new bootstrap.Modal(modalElement) : null;
  const modalTitle = document.getElementById("approvalReviewTitle");
  const modalBody = document.getElementById("approvalReviewBody");
  const reviewForm = document.getElementById("approvalReviewForm");
  const requestKeyInput = document.getElementById("approvalRequestKey");
  const decisionInput = document.getElementById("approvalDecision");
  const reviewNoteInput = document.getElementById("approvalReviewNote");
  const stepUpPasswordInput = document.getElementById("approvalStepUpPassword");
  const feedbackNode = document.getElementById("approvalReviewFeedback");
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";

  const escapeHtml = (value) =>
    String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");

  const formatDate = (value) => {
    const date = new Date(value || "");
    return Number.isNaN(date.getTime()) ? "-" : date.toLocaleString("en-PH", {
      month: "short",
      day: "2-digit",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    });
  };

  const statusTone = (status) => {
    const normalized = String(status || "pending").toLowerCase();
    if (normalized === "approved") return "is-success";
    if (normalized === "declined") return "is-danger";
    return "is-warning";
  };

  const statusText = (status) => {
    const normalized = String(status || "pending").replace(/_/g, " ");
    return normalized.replace(/\b\w/g, (char) => char.toUpperCase());
  };

  const showFeedback = (type, message) => {
    if (!feedbackNode) return;
    feedbackNode.innerHTML = `<div class="alert alert-${type} mb-0">${escapeHtml(message)}</div>`;
  };

  const clearFeedback = () => {
    if (feedbackNode) feedbackNode.innerHTML = "";
  };

  const applyFilters = (forcedStatus = null) => {
    cards = Array.from(document.querySelectorAll(".approval-card-wrap"));
    const search = (searchInput?.value || "").trim().toLowerCase();
    const type = typeInput?.value || "all";
    const status = forcedStatus || statusInput?.value || "all";
    let visible = 0;
    let visiblePending = 0;
    let visibleApproved = 0;
    let visibleDeclined = 0;
    let visibleShift = 0;
    let visibleStock = 0;

    cards.forEach((card) => {
      const matches =
        (!search || (card.dataset.search || "").includes(search)) &&
        (type === "all" || (card.dataset.type || "") === type) &&
        (status === "all" || (card.dataset.status || "") === status);

      card.classList.toggle("d-none", !matches);
      if (matches) {
        visible += 1;
        if ((card.dataset.status || "") === "pending") visiblePending += 1;
        if ((card.dataset.status || "") === "approved") visibleApproved += 1;
        if ((card.dataset.status || "") === "declined") visibleDeclined += 1;
        if ((card.dataset.type || "") === "shift") visibleShift += 1;
        if ((card.dataset.type || "") === "stock") visibleStock += 1;
      }
    });

    if (metaNode) metaNode.textContent = `Showing ${visible.toLocaleString()} of ${cards.length.toLocaleString()} approval requests.`;
    if (emptyNode) emptyNode.classList.toggle("d-none", visible !== 0);
    if (pendingNode) pendingNode.textContent = visiblePending.toLocaleString();
    if (approvedNode) approvedNode.textContent = visibleApproved.toLocaleString();
    if (declinedNode) declinedNode.textContent = visibleDeclined.toLocaleString();
    if (sourceNode) sourceNode.textContent = `${visibleShift.toLocaleString()} / ${visibleStock.toLocaleString()}`;
  };

  const renderDetailGrid = (item) => {
    const details = item.details || {};
    return Object.entries(details).map(([label, value]) => `
      <div>
        <span class="ops-detail-label">${escapeHtml(label)}</span>
        <p class="mb-0">${escapeHtml(value || "-")}</p>
      </div>
    `).join("");
  };

  const renderPreview = (item) => {
    const canReview = String(item.status || "pending").toLowerCase() === "pending";
    const reviewCopy = canReview
      ? "This request is waiting for an admin decision."
      : `Reviewed by ${item.reviewer ? escapeHtml(item.reviewer) : "admin"}.`;

    return `
      <div class="ops-preview-stack">
        <section class="ops-preview-hero">
          <div>
            <span class="ops-eyebrow">${escapeHtml(item.type === "shift" ? "Shift Review" : "Stock Review")}</span>
            <h3 class="ops-preview-title mb-1">${escapeHtml(item.title || "Approval Request")}</h3>
            <p class="ops-muted mb-0">${escapeHtml(item.subject || "")}</p>
          </div>
          <span class="ops-status ${statusTone(item.status)}">${escapeHtml(statusText(item.status))}</span>
        </section>

        <div class="ops-modal-summary">
          <div class="ops-kpi"><span>Requested By</span><strong>${escapeHtml(item.person || "User")}</strong></div>
          <div class="ops-kpi"><span>Requested At</span><strong>${escapeHtml(formatDate(item.requested_at))}</strong></div>
          <div class="ops-kpi"><span>Source</span><strong>${escapeHtml(String(item.type || "").toUpperCase())}</strong></div>
          <div class="ops-kpi"><span>Status</span><strong>${escapeHtml(statusText(item.status))}</strong></div>
        </div>

        <div class="ops-detail-grid">
          ${renderDetailGrid(item)}
        </div>

        <section class="ops-preview-section">
          <div class="ops-preview-section__head">
            <div>
              <span class="ops-eyebrow">Request Context</span>
              <h4 class="ops-preview-section__title">Reason and review trail</h4>
            </div>
          </div>
          <div class="ops-review-note-grid">
            <div>
              <span class="ops-detail-label">Reason</span>
              <p>${escapeHtml(item.reason || "No reason provided.")}</p>
            </div>
            <div>
              <span class="ops-detail-label">Review status</span>
              <p>${reviewCopy}</p>
              <p class="mb-0"><strong>Review note:</strong> ${escapeHtml(item.review_note || "No note yet.")}</p>
            </div>
          </div>
        </section>
      </div>
    `;
  };

  const openPreview = (key) => {
    const item = dataStore[key];
    if (!item || !modal || !modalBody) return;

    if (modalTitle) modalTitle.textContent = item.subject || "Approval Preview";
    modalBody.innerHTML = renderPreview(item);
    if (requestKeyInput) requestKeyInput.value = key;
    if (decisionInput) decisionInput.value = "";
    if (reviewNoteInput) {
      reviewNoteInput.value = "";
      reviewNoteInput.disabled = String(item.status || "pending").toLowerCase() !== "pending";
    }
    if (stepUpPasswordInput) {
      stepUpPasswordInput.value = "";
      stepUpPasswordInput.disabled = String(item.status || "pending").toLowerCase() !== "pending";
    }
    clearFeedback();

    const canReview = String(item.status || "pending").toLowerCase() === "pending";
    document.querySelectorAll(".approval-decision-btn").forEach((button) => {
      button.disabled = !canReview;
      button.classList.toggle("d-none", !canReview);
    });

    modal.show();
  };

  const endpointForType = (type) => {
    return type === "shift"
      ? "/inventory_system/http/ajax/shift_edit_requests.php"
      : "/inventory_system/http/ajax/stock_adjustment_requests.php";
  };

  const reviewRequest = async (key, decision, note, stepUpPassword) => {
    const item = dataStore[key];
    if (!item) throw new Error("Approval request could not be found.");

    const formData = new FormData();
    formData.append("csrf_token", csrfToken);
    formData.append("action", "review");
    formData.append("request_id", String(item.id || 0));
    formData.append("decision", decision);
    formData.append("review_note", note);
    formData.append("step_up_password", stepUpPassword || "");

    const response = await fetch(endpointForType(item.type), {
      method: "POST",
      body: formData,
      headers: {
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
      },
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.success) {
      throw new Error(data.error || "Unable to review this approval request.");
    }

    return data;
  };

  const updateCardAfterReview = (key, request, decision, note) => {
    const item = dataStore[key];
    if (!item) return;

    const normalizedDecision = String(decision || request?.status || "pending").toLowerCase();
    item.status = normalizedDecision;
    item.review_note = note || request?.review_note || item.review_note || "";
    item.reviewer = request?.reviewer_label || item.reviewer || "Admin";
    item.reviewed_at = new Date().toISOString();
    if (request?.approved_until_label && item.details) {
      item.details["Approved until"] = request.approved_until_label;
    }
    if (typeof request?.before_quantity !== "undefined" && typeof request?.after_quantity !== "undefined" && item.details) {
      item.details["Before / after"] = `${Number(request.before_quantity || 0).toLocaleString()} -> ${Number(request.after_quantity || 0).toLocaleString()}`;
    }

    const card = document.querySelector(`.approval-card-wrap[data-type="${CSS.escape(item.type)}"][data-id="${CSS.escape(String(item.id))}"]`);
    if (!card) return;
    card.dataset.status = normalizedDecision;

    const statusBadge = card.querySelector(".ops-approval-card .ops-status");
    if (statusBadge) {
      statusBadge.className = `ops-status ${statusTone(normalizedDecision)}`;
      statusBadge.textContent = statusText(normalizedDecision);
    }

    const noteNode = card.querySelector(".approval-card-note");
    if (noteNode) {
      noteNode.textContent = `Review note: ${item.review_note || "No note provided."}`;
    }
  };

  document.addEventListener("click", (event) => {
    const previewButton = event.target.closest(".approval-preview-btn");
    if (previewButton) {
      event.preventDefault();
      openPreview(previewButton.dataset.key || "");
      return;
    }

    const decisionButton = event.target.closest(".approval-decision-btn");
    if (decisionButton && reviewForm) {
      event.preventDefault();
      const decision = decisionButton.dataset.decision || "";
      if (decisionInput) decisionInput.value = decision;
      reviewForm.requestSubmit();
    }
  });

  reviewForm?.addEventListener("submit", async (event) => {
    event.preventDefault();
    const key = requestKeyInput?.value || "";
    const decision = decisionInput?.value || "";
    const note = reviewNoteInput?.value.trim() || "";
    const stepUpPassword = stepUpPasswordInput?.value || "";

    if (decision === "declined" && note === "") {
      showFeedback("warning", "Please add a reason before declining this request.");
      reviewNoteInput?.focus();
      return;
    }

    const buttons = Array.from(reviewForm.querySelectorAll("button"));
    buttons.forEach((button) => { button.disabled = true; });
    showFeedback("info", decision === "approved" ? "Approving request..." : "Declining request...");

    try {
      const data = await reviewRequest(key, decision, note, stepUpPassword);
      updateCardAfterReview(key, data.request || {}, decision, note);
      applyFilters();
      showFeedback("success", data.message || "Request reviewed successfully.");
      setTimeout(() => modal?.hide(), 700);
    } catch (error) {
      showFeedback("danger", error.message || "Unable to review this request.");
    } finally {
      buttons.forEach((button) => { button.disabled = false; });
      const item = dataStore[key];
      const canReview = String(item?.status || "pending").toLowerCase() === "pending";
      document.querySelectorAll(".approval-decision-btn").forEach((button) => {
        button.disabled = !canReview;
        button.classList.toggle("d-none", !canReview);
      });
    }
  });

  chips.forEach((chip) => {
    chip.addEventListener("click", () => {
      chips.forEach((item) => item.classList.remove("is-active"));
      chip.classList.add("is-active");
      if (statusInput) statusInput.value = chip.dataset.status || "all";
      applyFilters(chip.dataset.status || "all");
    });
  });

  [searchInput, typeInput, statusInput].forEach((element) => {
    element?.addEventListener("input", () => applyFilters());
    element?.addEventListener("change", () => applyFilters());
  });

  applyFilters();
});
