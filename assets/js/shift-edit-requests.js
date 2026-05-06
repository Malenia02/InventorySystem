document.addEventListener("DOMContentLoaded", () => {
  const page = document.querySelector(".shift-request-page");
  if (!page) {
    return;
  }

  const feedback = document.getElementById("shiftRequestFeedback");
  const requestList = document.getElementById("requestList");
  const emptyState = document.getElementById("requestEmptyState");
  const selectedShiftActionArea = document.getElementById("selectedShiftActionArea");
  const selectedShiftStateBadge = document.getElementById("selectedShiftStateBadge");
  const filterChips = Array.from(document.querySelectorAll(".request-filter-chip"));
  const activeRole = requestList?.dataset.role || "cashier";
  const csrfToken =
    requestList?.dataset.csrf ||
    document.querySelector('input[name="csrf_token"]')?.value ||
    "";

  let activeFilter = filterChips.find((chip) => chip.classList.contains("is-active"))?.dataset.filter || "all";

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
      pending: document.getElementById("requestCountPending"),
      approved: document.getElementById("requestCountApproved"),
      declined: document.getElementById("requestCountDeclined"),
    };

    Object.entries(map).forEach(([key, node]) => {
      if (node && Object.hasOwn(counts, key)) {
        node.textContent = String(counts[key]);
      }
    });
  };

  const setBadgeState = (badge, statusLabel) => {
    if (!badge) return;
    badge.classList.remove("is-pending", "is-approved", "is-declined");

    if (statusLabel === "Editable") {
      badge.classList.add("is-approved");
      badge.textContent = "Editable";
      return;
    }

    badge.classList.add("is-pending");
    badge.textContent = statusLabel;
  };

  const buildReviewedInfo = (request) => `
    <div class="request-foot__info">
      <span>Reviewed by</span>
      <strong>${escapeHtml(request.reviewer_label || "-")}</strong>
    </div>
  `;

  const buildReviewForm = (requestId) => `
    <form method="POST" class="request-review-form" data-request-id="${requestId}">
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
          Approve Unlock
        </button>
        <button type="submit" name="decision" value="declined" class="btn btn-outline-danger">
          Decline
        </button>
        <input type="hidden" name="review_shift_edit_request" value="1">
      </div>
    </form>
  `;

  const renderRequestCard = (request) => {
    const showTarget = activeRole === "admin";
    const footerRight = request.can_review ? buildReviewForm(request.request_id) : buildReviewedInfo(request);

    return `
      <article class="request-card" data-request-id="${request.request_id}" data-status="${escapeHtml(request.status)}">
        <div class="request-card__top">
          <div>
            <span class="request-mini-badge ${escapeHtml(request.status_class)}">${escapeHtml(request.status_label)}</span>
            <h3>${escapeHtml(request.shift_date_label)}</h3>
            <p>
              Cashier: ${escapeHtml(request.requester_label)}
              ${showTarget ? `<span class="mx-2">|</span>Target: ${escapeHtml(request.target_label)}` : ""}
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
            <p>${nl2br(request.request_reason)}</p>
          </div>
          <div>
            <span class="request-label">Review note</span>
            <p>${nl2br(request.review_note || "No review note yet.")}</p>
          </div>
        </div>

        <div class="request-foot">
          <div class="request-foot__info">
            <span>Approved until</span>
            <strong>${escapeHtml(request.approved_until_label || "-")}</strong>
          </div>
          ${footerRight}
        </div>
      </article>
    `;
  };

  const applyFilter = (filter) => {
    activeFilter = filter;
    filterChips.forEach((chip) => {
      chip.classList.toggle("is-active", chip.dataset.filter === filter);
    });

    if (!requestList) {
      return;
    }

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

    const url = new URL(window.location.href);
    url.searchParams.set("status", filter);
    window.history.replaceState({}, "", url);
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
    const response = await fetch("/inventory_system/http/ajax/shift_edit_requests.php", {
      method: "POST",
      body: payload,
      headers: {
        "X-Requested-With": "XMLHttpRequest",
        Accept: "application/json",
      },
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.success) {
      throw new Error(data.error || "Unable to save shift edit request.");
    }

    return data;
  };

  filterChips.forEach((chip) => {
    chip.addEventListener("click", (event) => {
      event.preventDefault();
      applyFilter(chip.dataset.filter || "all");
    });
  });

  const shiftEditRequestForm = document.getElementById("shiftEditRequestForm");
  if (shiftEditRequestForm) {
    shiftEditRequestForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      const submitButton = shiftEditRequestForm.querySelector('button[type="submit"]');
      const originalText = submitButton?.textContent || "Send Request to Owner/Admin";

      try {
        if (submitButton) {
          submitButton.disabled = true;
          submitButton.textContent = "Sending...";
        }

        const payload = new FormData(shiftEditRequestForm);
        payload.set("action", "submit");
        if (!payload.has("csrf_token")) {
          payload.set("csrf_token", csrfToken);
        }

        const data = await request(payload);
        showFeedback("success", data.message);
        updateCounts(data.counts);
        upsertCard(data.request, true);

        if (selectedShiftActionArea) {
          selectedShiftActionArea.innerHTML = `
            <div class="alert alert-warning mb-0">
              Request sent successfully. Waiting for owner/admin approval before this shift can be edited again.
            </div>
          `;
        }
        setBadgeState(selectedShiftStateBadge, "Pending");
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
      buttons.forEach((button) => {
        button.disabled = false;
      });
    }
  });

  applyFilter(activeFilter);
});
