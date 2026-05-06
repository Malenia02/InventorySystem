document.addEventListener("DOMContentLoaded", () => {
  const table = document.getElementById("expenseTable");
  if (!table) return;

  const form = document.getElementById("expenseForm");
  const payForm = document.getElementById("expensePayForm");
  const payModalNode = document.getElementById("expensePayModal");
  const payModal = payModalNode && window.bootstrap ? new window.bootstrap.Modal(payModalNode) : null;
  const feedback = document.getElementById("expenseFeedback");
  const searchInput = document.getElementById("expenseSearch");
  const statusFilter = document.getElementById("expenseStatusFilter");
  const dateFromInput = document.getElementById("expenseDateFrom");
  const dateToInput = document.getElementById("expenseDateTo");
  const metaNode = document.getElementById("expenseMeta");
  const visibleTotalNode = document.getElementById("expenseVisibleTotal");
  const paid30TotalNode = document.getElementById("expensePaid30Total");
  const openTotalNode = document.getElementById("expenseOpenTotal");
  const dueSoonTotalNode = document.getElementById("expenseDueSoonTotal");
  const overdueTotalNode = document.getElementById("expenseOverdueTotal");
  const analyticsTotalNode = document.getElementById("expenseAnalyticsTotal");
  const analyticsOpenNode = document.getElementById("expenseAnalyticsOpen");
  const analyticsMetaNode = document.getElementById("expenseAnalyticsMeta");
  const categoryBreakdownNode = document.getElementById("expenseCategoryBreakdown");
  const dailyTrendNode = document.getElementById("expenseDailyTrend");
  const largestEntryNode = document.getElementById("expenseLargestEntry");
  const emptyNode = document.getElementById("expenseEmpty");
  const categoryInput = form?.querySelector('input[name="category"]');
  const statusInput = document.getElementById("expensePaymentStatus");
  const amountInput = form?.querySelector('input[name="amount"]');
  const paidAmountInput = form?.querySelector('input[name="paid_amount"]');
  const categoryChips = Array.from(document.querySelectorAll(".expense-category-chip"));
  const payload = window.EXPENSE_TRACKER_DATA || {};
  const rows = () => Array.from(table.querySelectorAll("tbody tr"));
  let expenseData = Array.isArray(payload.expenses) ? [...payload.expenses] : [];

  const money = (value) => `PHP ${Number(value || 0).toLocaleString("en-PH", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;

  const escapeHtml = (value) =>
    String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");

  const formatDate = (date, fallback = "-") => {
    if (!date) return fallback;
    const parsed = new Date(`${date}T00:00:00`);
    if (Number.isNaN(parsed.getTime())) return fallback;
    return parsed.toLocaleDateString("en-PH", { month: "short", day: "2-digit", year: "numeric" });
  };

  const statusLabel = (status) => ({
    paid: "Paid",
    unpaid: "Unpaid",
    partially_paid: "Partial",
  }[status] || "Paid");

  const statusClass = (status) => ({
    paid: "is-paid",
    unpaid: "is-unpaid",
    partially_paid: "is-partial",
  }[status] || "is-paid");

  const showFeedback = (type, message) => {
    if (!feedback) return;
    feedback.innerHTML = `<div class="alert alert-${type}">${escapeHtml(message)}</div>`;
  };

  const expenseSearchText = (expense) => `${String(expense.category || "").toLowerCase()} ${String(expense.notes || "").toLowerCase()} ${String(expense.creator || "").toLowerCase()} ${String(expense.payment_method || "").toLowerCase()} ${String(expense.reference_no || "").toLowerCase()}`;

  const normalizedExpense = (expense) => {
    const amount = Number(expense.amount || 0);
    const paidAmount = Number(expense.paid_amount || 0);
    return {
      expense_id: Number(expense.expense_id || 0),
      expense_date: expense.expense_date || "",
      category: expense.category || "",
      amount,
      notes: expense.notes || "",
      creator: expense.creator || "Admin",
      payment_status: expense.payment_status || "paid",
      due_date: expense.due_date || "",
      paid_at: expense.paid_at || "",
      paid_amount: paidAmount,
      open_balance: Number.isFinite(Number(expense.open_balance)) ? Number(expense.open_balance) : Math.max(0, amount - paidAmount),
      payment_method: expense.payment_method || "",
      reference_no: expense.reference_no || "",
    };
  };

  const buildRow = (expense) => {
    const item = normalizedExpense(expense);
    const tr = document.createElement("tr");
    tr.dataset.id = String(item.expense_id);
    tr.dataset.date = item.expense_date;
    tr.dataset.amount = String(item.amount);
    tr.dataset.paid = String(item.paid_amount);
    tr.dataset.open = String(item.open_balance);
    tr.dataset.status = item.payment_status;
    tr.dataset.due = item.due_date;
    tr.dataset.search = expenseSearchText(item);
    tr.innerHTML = `
      <td>${escapeHtml(formatDate(item.expense_date))}</td>
      <td>
        <strong>${escapeHtml(item.category || "Expense")}</strong>
        <small class="d-block text-muted">by ${escapeHtml(item.creator || "Admin")}</small>
      </td>
      <td>${escapeHtml(item.notes || "-")}</td>
      <td><span class="expense-status ${statusClass(item.payment_status)}">${escapeHtml(statusLabel(item.payment_status))}</span></td>
      <td>${item.due_date ? escapeHtml(formatDate(item.due_date)) : '<span class="text-muted">-</span>'}</td>
      <td class="text-end fw-bold">${money(item.amount)}</td>
      <td class="text-end fw-bold ${item.open_balance > 0 ? "text-danger" : "text-success"}">${money(item.open_balance)}</td>
      <td class="text-end">${item.open_balance > 0
        ? `<button type="button" class="btn btn-sm btn-primary expense-pay-btn" data-id="${item.expense_id}" data-category="${escapeHtml(item.category || "Expense")}" data-open="${item.open_balance}">Pay</button>`
        : '<span class="text-muted small">Settled</span>'}</td>
    `;
    return tr;
  };

  const rowMatches = (row) => {
    const search = (searchInput?.value || "").trim().toLowerCase();
    const status = statusFilter?.value || "";
    const dateFrom = dateFromInput?.value || "";
    const dateTo = dateToInput?.value || "";

    return (!search || (row.dataset.search || "").includes(search)) &&
      (!status || (row.dataset.status || "") === status) &&
      (!dateFrom || (row.dataset.date || "") >= dateFrom) &&
      (!dateTo || (row.dataset.date || "") <= dateTo);
  };

  const visibleExpenses = () => {
    const search = (searchInput?.value || "").trim().toLowerCase();
    const status = statusFilter?.value || "";
    const dateFrom = dateFromInput?.value || "";
    const dateTo = dateToInput?.value || "";

    return expenseData.filter((expense) => (!search || expenseSearchText(expense).includes(search)) &&
      (!status || expense.payment_status === status) &&
      (!dateFrom || String(expense.expense_date || "") >= dateFrom) &&
      (!dateTo || String(expense.expense_date || "") <= dateTo));
  };

  const applyFilters = () => {
    let visible = 0;
    let visibleTotal = 0;

    rows().forEach((row) => {
      const matches = rowMatches(row);
      row.classList.toggle("d-none", !matches);
      if (matches) {
        visible += 1;
        visibleTotal += Number(row.dataset.amount || 0);
      }
    });

    if (metaNode) metaNode.textContent = `Showing ${visible.toLocaleString()} of ${rows().length.toLocaleString()} loaded expenses.`;
    if (visibleTotalNode) visibleTotalNode.textContent = `Visible total: ${money(visibleTotal)}`;
    if (emptyNode) emptyNode.classList.toggle("d-none", visible !== 0);
  };

  const renderAnalytics = () => {
    const filtered = visibleExpenses();
    const total = filtered.reduce((sum, expense) => sum + Number(expense.amount || 0), 0);
    const openTotal = filtered.reduce((sum, expense) => sum + Number(expense.open_balance || 0), 0);
    const byCategory = new Map();
    const byDay = new Map();
    let largest = null;

    filtered.forEach((expense) => {
      const category = expense.category || "Uncategorized";
      const amount = Number(expense.amount || 0);
      const date = expense.expense_date || "";
      const current = byCategory.get(category) || { category, total: 0, entries: 0 };
      current.total += amount;
      current.entries += 1;
      byCategory.set(category, current);
      byDay.set(date, (byDay.get(date) || 0) + amount);
      if (!largest || amount > Number(largest.amount || 0)) largest = expense;
    });

    const categories = Array.from(byCategory.values()).sort((a, b) => b.total - a.total).slice(0, 6);
    const days = Array.from(byDay.entries()).sort(([a], [b]) => a.localeCompare(b)).slice(-14);
    const maxDay = Math.max(1, ...days.map(([, amount]) => amount));

    if (analyticsTotalNode) analyticsTotalNode.textContent = money(total);
    if (analyticsOpenNode) analyticsOpenNode.textContent = money(openTotal);
    if (analyticsMetaNode) analyticsMetaNode.textContent = `${filtered.length.toLocaleString()} expense row(s) included.`;
    if (largestEntryNode) {
      largestEntryNode.innerHTML = largest
        ? `${escapeHtml(largest.category || "Expense")} · ${money(largest.amount)}`
        : "No expenses yet.";
    }
    if (categoryBreakdownNode) {
      categoryBreakdownNode.innerHTML = categories.length
        ? categories.map((entry) => {
          const pct = total > 0 ? Math.round((entry.total / total) * 100) : 0;
          return `<div class="ops-breakdown-row">
            <div class="ops-breakdown-info"><strong>${escapeHtml(entry.category)}</strong><small>${entry.entries.toLocaleString()} entr${entry.entries === 1 ? "y" : "ies"}</small></div>
            <div class="ops-breakdown-meter"><span style="width:${pct}%"></span></div>
            <strong class="ops-breakdown-amount">${money(entry.total)}</strong>
          </div>`;
        }).join("")
        : `<div class="ops-empty py-4">No expense categories to summarize.</div>`;
    }
    if (dailyTrendNode) {
      dailyTrendNode.innerHTML = days.length
        ? days.map(([date, amount]) => {
          const width = Math.max(8, Math.round((amount / maxDay) * 100));
          const label = new Date(`${date}T00:00:00`).toLocaleDateString("en-PH", { month: "short", day: "2-digit" });
          return `<div class="expense-trend-row" title="${escapeHtml(date)}: ${money(amount)}">
            <span>${escapeHtml(label)}</span>
            <div><i style="width:${width}%"></i></div>
            <strong>${money(amount)}</strong>
          </div>`;
        }).join("")
        : `<div class="ops-empty py-4 w-100">No trend data yet.</div>`;
    }
  };

  const refreshView = () => {
    applyFilters();
    renderAnalytics();
  };

  const applySummary = (summary) => {
    if (!summary) return;
    if (paid30TotalNode) paid30TotalNode.textContent = money(summary.paid_30_total || 0);
    if (openTotalNode) openTotalNode.textContent = money(summary.open_total || 0);
    if (dueSoonTotalNode) dueSoonTotalNode.textContent = money(summary.due_soon_total || 0);
    if (overdueTotalNode) overdueTotalNode.textContent = money(summary.overdue_total || 0);
  };

  const updatePaymentFields = () => {
    if (!statusInput || !form) return;
    const status = statusInput.value;
    const paymentFields = form.querySelector(".expense-payment-fields");
    const methodInput = form.querySelector('input[name="payment_method"]');
    const referenceInput = form.querySelector('input[name="reference_no"]');
    const dueInput = form.querySelector('input[name="due_date"]');
    paymentFields?.classList.toggle("is-muted", status === "unpaid");

    if (status === "paid") {
      if (paidAmountInput && amountInput && !paidAmountInput.value) paidAmountInput.value = amountInput.value || "";
      methodInput?.removeAttribute("disabled");
      referenceInput?.removeAttribute("disabled");
    } else if (status === "unpaid") {
      if (paidAmountInput) paidAmountInput.value = "";
      methodInput?.setAttribute("disabled", "disabled");
      referenceInput?.setAttribute("disabled", "disabled");
      dueInput?.focus();
    } else {
      methodInput?.removeAttribute("disabled");
      referenceInput?.removeAttribute("disabled");
    }
  };

  const submitForm = async (targetForm) => {
    const response = await fetch("/inventory_system/http/ajax/expense_actions.php", {
      method: "POST",
      body: new FormData(targetForm),
      headers: {
        "X-Requested-With": "XMLHttpRequest",
        Accept: "application/json",
      },
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.success) {
      throw new Error(data.error || "Unable to update the expense.");
    }

    return data;
  };

  form?.addEventListener("submit", async (event) => {
    event.preventDefault();
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn?.textContent || "Save Expense";

    try {
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = "Saving...";
      }

      const data = await submitForm(form);
      const expense = normalizedExpense(data.expense || {});
      const tbody = table.querySelector("tbody");
      tbody?.prepend(buildRow(expense));
      expenseData.unshift(expense);
      applySummary(data.summary);
      showFeedback("success", data.message || "Expense saved successfully.");
      form.reset();
      form.querySelector('input[name="expense_date"]').value = new Date().toISOString().slice(0, 10);
      statusInput.value = "paid";
      updatePaymentFields();
      refreshView();
    } catch (error) {
      showFeedback("danger", error.message || "Unable to save the expense.");
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
      }
    }
  });

  payForm?.addEventListener("submit", async (event) => {
    event.preventDefault();
    const submitBtn = payForm.querySelector('button[type="submit"]');
    const originalText = submitBtn?.textContent || "Save Payment";

    try {
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = "Saving...";
      }

      const data = await submitForm(payForm);
      const expense = normalizedExpense(data.expense || {});
      expenseData = expenseData.map((item) => Number(item.expense_id) === expense.expense_id ? expense : item);
      const oldRow = table.querySelector(`tbody tr[data-id="${expense.expense_id}"]`);
      oldRow?.replaceWith(buildRow(expense));
      applySummary(data.summary);
      showFeedback("success", data.message || "Expense payment updated.");
      payModal?.hide();
      refreshView();
    } catch (error) {
      showFeedback("danger", error.message || "Unable to record the payment.");
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
      }
    }
  });

  table.addEventListener("click", (event) => {
    const button = event.target.closest(".expense-pay-btn");
    if (!button) return;

    const open = Number(button.dataset.open || 0);
    document.getElementById("expensePayId").value = button.dataset.id || "";
    document.getElementById("expensePayTitle").textContent = `Pay ${button.dataset.category || "expense"}`;
    document.getElementById("expensePayOpen").textContent = money(open);
    document.getElementById("expensePayAmount").value = open.toFixed(2);
    document.getElementById("expensePaidAt").value = new Date().toISOString().slice(0, 16);
    payModal?.show();
  });

  [searchInput, statusFilter, dateFromInput, dateToInput].forEach((element) => {
    element?.addEventListener("input", refreshView);
    element?.addEventListener("change", refreshView);
  });

  [statusInput, amountInput].forEach((element) => {
    element?.addEventListener("input", updatePaymentFields);
    element?.addEventListener("change", updatePaymentFields);
  });

  categoryChips.forEach((chip) => {
    chip.addEventListener("click", () => {
      if (!categoryInput) return;
      categoryInput.value = chip.dataset.category || "";
      categoryInput.focus();
    });
  });

  expenseData = expenseData.map(normalizedExpense);
  applySummary(payload.summary || null);
  updatePaymentFields();
  refreshView();
});
