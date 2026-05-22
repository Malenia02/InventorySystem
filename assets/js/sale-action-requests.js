document.addEventListener("DOMContentLoaded", () => {
  const table = document.getElementById("saleActionRequestTable");
  if (!table) return;

  const rows = Array.from(table.querySelectorAll("tbody tr"));
  const searchInput = document.getElementById("saleReqSearch");
  const statusInput = document.getElementById("saleReqStatus");
  const actionInput = document.getElementById("saleReqAction");
  const dateFromInput = document.getElementById("saleReqDateFrom");
  const dateToInput = document.getElementById("saleReqDateTo");
  const countNode = document.getElementById("saleReqCount");
  const pendingNode = document.getElementById("saleReqPending");
  const approvedNode = document.getElementById("saleReqApproved");
  const declinedNode = document.getElementById("saleReqDeclined");
  const metaNode = document.getElementById("saleReqMeta");
  const emptyNode = document.getElementById("saleReqEmpty");
  const feedback = document.getElementById("saleRequestFeedback");
  const modalElement = document.getElementById("saleRequestReviewModal");
  const reviewModal = modalElement ? new bootstrap.Modal(modalElement) : null;
  const reviewForm = document.getElementById("saleRequestReviewForm");
  const reviewTitle = document.getElementById("saleRequestReviewTitle");
  const reviewRequestId = document.getElementById("saleReviewRequestId");
  const reviewDecision = document.getElementById("saleReviewDecision");
  const reviewNote = document.getElementById("saleReviewNote");
  const reviewStepUpPassword = document.getElementById("saleReviewStepUpPassword");
  const reviewSubmit = document.getElementById("saleReviewSubmit");
  const csrfToken = window.SALE_ACTION_REQUESTS?.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content || "";

  const escapeHtml = (value) =>
    String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");

  const showFeedback = (type, message) => {
    if (!feedback) return;
    feedback.innerHTML = `<div class="alert alert-${type}">${escapeHtml(message)}</div>`;
  };

  const applyFilters = () => {
    const search = (searchInput?.value || "").trim().toLowerCase();
    const status = statusInput?.value || "all";
    const action = actionInput?.value || "all";
    const dateFrom = dateFromInput?.value || "";
    const dateTo = dateToInput?.value || "";
    let visible = 0;
    let pending = 0;
    let approved = 0;
    let declined = 0;

    rows.forEach((row) => {
      const matches =
        (!search || (row.dataset.search || "").includes(search)) &&
        (status === "all" || (row.dataset.status || "") === status) &&
        (action === "all" || (row.dataset.action || "") === action) &&
        (!dateFrom || (row.dataset.date || "") >= dateFrom) &&
        (!dateTo || (row.dataset.date || "") <= dateTo);
      row.classList.toggle("d-none", !matches);
      if (matches) {
        visible += 1;
        if (row.dataset.status === "pending") pending += 1;
        if (row.dataset.status === "approved") approved += 1;
        if (row.dataset.status === "declined") declined += 1;
      }
    });

    if (countNode) countNode.textContent = visible.toLocaleString();
    if (pendingNode) pendingNode.textContent = pending.toLocaleString();
    if (approvedNode) approvedNode.textContent = approved.toLocaleString();
    if (declinedNode) declinedNode.textContent = declined.toLocaleString();
    if (metaNode) metaNode.textContent = `Showing ${visible.toLocaleString()} of ${rows.length.toLocaleString()} requests.`;
    if (emptyNode) emptyNode.classList.toggle("d-none", visible !== 0);
  };

  const submitReview = async () => {
    const decision = reviewDecision?.value || "";
    const requestId = reviewRequestId?.value || "";
    const note = reviewNote?.value || "";
    const stepUpPassword = reviewStepUpPassword?.value || "";
    const originalText = reviewSubmit?.textContent || "Save decision";

    try {
      if (reviewSubmit) {
        reviewSubmit.disabled = true;
        reviewSubmit.textContent = "Saving...";
      }
      const response = await fetch("/inventory_system/http/ajax/sale_action_requests.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-Requested-With": "XMLHttpRequest",
          Accept: "application/json",
        },
        body: JSON.stringify({
          csrf_token: csrfToken,
          action: "review",
          request_id: requestId,
          decision,
          review_note: note,
          step_up_password: stepUpPassword,
        }),
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.success) throw new Error(data.error || "Unable to review this request.");
      showFeedback("success", data.message || "Request updated.");
      reviewModal?.hide();
      window.setTimeout(() => window.location.reload(), 700);
    } catch (error) {
      showFeedback("danger", error.message || "Unable to review this request.");
      if (reviewSubmit) {
        reviewSubmit.disabled = false;
        reviewSubmit.textContent = originalText;
      }
    }
  };

  document.addEventListener("click", (event) => {
    const button = event.target.closest(".sale-req-review");
    if (!button) return;
    if (!reviewModal) return;
    const decision = button.dataset.decision || "";
    if (reviewTitle) reviewTitle.textContent = decision === "approved" ? "Approve Sale Request" : "Decline Sale Request";
    if (reviewRequestId) reviewRequestId.value = button.dataset.requestId || "";
    if (reviewDecision) reviewDecision.value = decision;
    if (reviewNote) reviewNote.value = "";
    if (reviewStepUpPassword) reviewStepUpPassword.value = "";
    if (reviewSubmit) {
      reviewSubmit.disabled = false;
      reviewSubmit.textContent = decision === "approved" ? "Approve request" : "Decline request";
    }
    reviewModal.show();
  });

  reviewForm?.addEventListener("submit", (event) => {
    event.preventDefault();
    submitReview();
  });

  [searchInput, statusInput, actionInput, dateFromInput, dateToInput].forEach((element) => {
    element?.addEventListener("input", applyFilters);
    element?.addEventListener("change", applyFilters);
  });

  applyFilters();
});
