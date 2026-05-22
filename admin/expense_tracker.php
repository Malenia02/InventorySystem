<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/ExpenseController.php';

Middleware::auth()->role(['admin']);

ExpenseController::ensureSchema($conn);

$expenses = ExpenseController::listExpenses($conn, 200);
$summary = ExpenseController::summary($conn);
$analytics = ExpenseController::analytics($conn, 30);
$csrfToken = Middleware::generateCsrfToken();

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function personLabel(array $row): string
{
    $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
    return $name !== '' ? $name : (string) ($row['username'] ?? 'Admin');
}

function expenseStatusLabel(string $status): string
{
    return match ($status) {
        'unpaid' => 'Unpaid',
        'partially_paid' => 'Partial',
        default => 'Paid',
    };
}

function expenseStatusClass(string $status): string
{
    return match ($status) {
        'unpaid' => 'is-unpaid',
        'partially_paid' => 'is-partial',
        default => 'is-paid',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/../components/head.php'; ?>
    <title>Expense Tracker</title>
    <link rel="stylesheet" href="/inventory_system/assets/css/ops-suite.css">
</head>
<body>
<?php require __DIR__ . '/../components/header.php'; ?>
<?php require __DIR__ . '/../components/sidebar.php'; ?>

<main id="main" class="main ops-page expense-page">
    <section class="ops-hero">
        <div class="ops-hero__body">
            <div>
                <span class="ops-eyebrow">Store Expenses</span>
                <h1 class="ops-title">Expense Tracker</h1>
                <p class="ops-copy">Record operating expenses, watch daily and monthly outflow, and keep non-sales spending visible beside the rest of your store operations.</p>
            </div>
            <div class="ops-hero__stats">
                <div class="ops-stat">
                    <span>Paid cash out</span>
                    <strong id="expensePaid30Total">PHP <?= number_format((float) ($summary['paid_30_total'] ?? 0), 2) ?></strong>
                    <small>payments recorded in 30 days</small>
                </div>
                <div class="ops-stat">
                    <span>Open payables</span>
                    <strong id="expenseOpenTotal">PHP <?= number_format((float) ($summary['open_total'] ?? 0), 2) ?></strong>
                    <small>unpaid or partially paid</small>
                </div>
                <div class="ops-stat">
                    <span>Due soon</span>
                    <strong id="expenseDueSoonTotal">PHP <?= number_format((float) ($summary['due_soon_total'] ?? 0), 2) ?></strong>
                    <small>due in the next 7 days</small>
                </div>
                <div class="ops-stat">
                    <span>Overdue</span>
                    <strong id="expenseOverdueTotal">PHP <?= number_format((float) ($summary['overdue_total'] ?? 0), 2) ?></strong>
                    <small>needs attention</small>
                </div>
            </div>
        </div>
    </section>

    <div id="expenseFeedback" class="mb-3"></div>

    <div class="expense-workspace">
        <section class="ops-panel expense-entry-panel">
            <div class="expense-panel-head">
                <div>
                <span class="ops-eyebrow">New Expense</span>
                    <h2>Log store spending</h2>
                </div>
                <i class="bi bi-wallet2"></i>
            </div>
            <p class="ops-muted mb-3">Record delivery fees, utilities, repairs, supplies, and other operating costs.</p>

                <form id="expenseForm">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="create">
                <div class="expense-form-grid">
                    <div>
                        <label>Expense date</label>
                        <input type="date" name="expense_date" class="form-control" value="<?= e(date('Y-m-d')) ?>" required>
                    </div>
                    <div>
                        <label>Category</label>
                        <input type="text" name="category" class="form-control" maxlength="100" placeholder="Utilities, Delivery, Repairs" required>
                    </div>
                </div>

                    <div class="ops-chip-row expense-chip-row mb-3">
                        <button type="button" class="ops-chip expense-category-chip" data-category="Utilities">Utilities</button>
                        <button type="button" class="ops-chip expense-category-chip" data-category="Delivery">Delivery</button>
                        <button type="button" class="ops-chip expense-category-chip" data-category="Repairs">Repairs</button>
                        <button type="button" class="ops-chip expense-category-chip" data-category="Supplies">Supplies</button>
                    </div>
                    <div class="mb-3">
                        <label>Amount</label>
                        <input type="number" step="0.01" min="0.01" name="amount" class="form-control" placeholder="0.00" required>
                    </div>
                    <div class="expense-form-grid is-two">
                        <div>
                            <label>Payment status</label>
                            <select name="payment_status" id="expensePaymentStatus" class="form-select">
                                <option value="paid">Already paid</option>
                                <option value="unpaid">Pay later</option>
                                <option value="partially_paid">Partially paid</option>
                            </select>
                        </div>
                        <div>
                            <label>Due date</label>
                            <input type="date" name="due_date" class="form-control">
                        </div>
                    </div>
                    <div class="expense-payment-fields">
                        <div>
                            <label>Paid amount</label>
                            <input type="number" step="0.01" min="0.01" name="paid_amount" class="form-control" placeholder="Full amount if paid">
                        </div>
                        <div>
                            <label>Method</label>
                            <input type="text" name="payment_method" class="form-control" maxlength="50" placeholder="Cash, GCash, Bank">
                        </div>
                        <div class="is-wide">
                            <label>Reference no.</label>
                            <input type="text" name="reference_no" class="form-control" maxlength="100" placeholder="Receipt, transfer, or check number">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label>Notes</label>
                    <textarea name="notes" class="form-control" rows="3" maxlength="1000" placeholder="Optional context for this expense entry"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Save Expense</button>
                </form>
            </section>

        <div class="expense-content-stack">
            <section class="ops-table-card expense-analytics-panel mb-4">
                <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                    <div>
                        <span class="ops-eyebrow">Expense Analytics</span>
                        <h2 class="h4 mb-1" style="color:#012970;font-weight:900;">30-day spending pulse</h2>
                        <p class="ops-muted mb-0">See where operating cash is going without opening a report.</p>
                    </div>
                    <div id="expenseAnalyticsMeta" class="ops-muted small">Updates with your visible data.</div>
                </div>

                <div class="expense-analytics-strip">
                    <div>
                        <span>Total visible spend</span>
                        <strong id="expenseAnalyticsTotal">PHP 0.00</strong>
                    </div>
                    <div>
                        <span>Largest visible expense</span>
                        <strong id="expenseLargestEntry">No expenses yet.</strong>
                    </div>
                    <div>
                        <span>Open visible balance</span>
                        <strong id="expenseAnalyticsOpen">PHP 0.00</strong>
                    </div>
                </div>

                <div class="ops-analytics-grid">
                    <div class="ops-analytics-card">
                        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                            <span class="ops-detail-label">Category split</span>
                        </div>
                        <div id="expenseCategoryBreakdown" class="ops-breakdown-list"></div>
                    </div>
                    <div class="ops-analytics-card">
                        <span class="ops-detail-label">Daily trend</span>
                        <div id="expenseDailyTrend" class="expense-trend-list mt-3"></div>
                    </div>
                </div>
            </section>

            <section class="ops-filter-card mb-4">
                <div class="ops-filter-grid">
                    <div class="is-wide">
                        <label>Search category, note, or creator</label>
                        <input type="search" id="expenseSearch" class="form-control" placeholder="Search expenses">
                    </div>
                    <div>
                        <label>Status</label>
                        <select id="expenseStatusFilter" class="form-select">
                            <option value="">All statuses</option>
                            <option value="paid">Paid</option>
                            <option value="unpaid">Unpaid</option>
                            <option value="partially_paid">Partial</option>
                        </select>
                    </div>
                    <div>
                        <label>Date from</label>
                        <input type="date" id="expenseDateFrom" class="form-control">
                    </div>
                    <div>
                        <label>Date to</label>
                        <input type="date" id="expenseDateTo" class="form-control">
                    </div>
                </div>
            </section>

            <section class="ops-table-card">
                <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                    <div>
                        <span class="ops-eyebrow">Recent Entries</span>
                        <h2 class="h4 mb-1" style="color:#012970;font-weight:800;">Expense Journal</h2>
                        <p class="ops-muted mb-0">Latest store spending entries update live after each save.</p>
                    </div>
                    <div class="text-end">
                        <div id="expenseMeta" class="ops-muted small">Showing all loaded expenses.</div>
                        <div id="expenseVisibleTotal" class="fw-bold" style="color:#012970;">Visible total: PHP 0.00</div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle" id="expenseTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Category</th>
                                <th>Notes</th>
                                <th>Status</th>
                                <th>Due</th>
                                <th class="text-end">Amount</th>
                                <th class="text-end">Open</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expenses as $expense): ?>
                                <?php
                                    $status = (string) ($expense['payment_status'] ?? 'paid');
                                    $paidAmount = (float) ($expense['paid_amount'] ?? 0);
                                    $amount = (float) ($expense['amount'] ?? 0);
                                    $openBalance = max(0, $amount - $paidAmount);
                                ?>
                                <tr
                                    data-id="<?= (int) ($expense['expense_id'] ?? 0) ?>"
                                    data-date="<?= e((string) ($expense['expense_date'] ?? '')) ?>"
                                    data-amount="<?= e((string) $amount) ?>"
                                    data-paid="<?= e((string) $paidAmount) ?>"
                                    data-open="<?= e((string) $openBalance) ?>"
                                    data-status="<?= e($status) ?>"
                                    data-due="<?= e((string) ($expense['due_date'] ?? '')) ?>"
                                    data-search="<?= e(strtolower((string) ($expense['category'] ?? '') . ' ' . (string) ($expense['notes'] ?? '') . ' ' . personLabel($expense) . ' ' . (string) ($expense['payment_method'] ?? '') . ' ' . (string) ($expense['reference_no'] ?? ''))) ?>"
                                >
                                    <td><?= e(date('M d, Y', strtotime((string) ($expense['expense_date'] ?? 'now')))) ?></td>
                                    <td>
                                        <strong><?= e((string) ($expense['category'] ?? 'Expense')) ?></strong>
                                        <small class="d-block text-muted">by <?= e(personLabel($expense)) ?></small>
                                    </td>
                                    <td><?= e((string) ($expense['notes'] ?? '-')) ?></td>
                                    <td><span class="expense-status <?= e(expenseStatusClass($status)) ?>"><?= e(expenseStatusLabel($status)) ?></span></td>
                                    <td><?= !empty($expense['due_date']) ? e(date('M d, Y', strtotime((string) $expense['due_date']))) : '<span class="text-muted">-</span>' ?></td>
                                    <td class="text-end fw-bold">PHP <?= number_format($amount, 2) ?></td>
                                    <td class="text-end fw-bold <?= $openBalance > 0 ? 'text-danger' : 'text-success' ?>">PHP <?= number_format($openBalance, 2) ?></td>
                                    <td class="text-end">
                                        <?php if ($openBalance > 0): ?>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-primary expense-pay-btn"
                                                data-id="<?= (int) ($expense['expense_id'] ?? 0) ?>"
                                                data-category="<?= e((string) ($expense['category'] ?? 'Expense')) ?>"
                                                data-open="<?= e((string) $openBalance) ?>"
                                            >Pay</button>
                                        <?php else: ?>
                                            <span class="text-muted small">Settled</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div id="expenseEmpty" class="ops-empty d-none mt-3">No expense entries match the current filters.</div>
            </section>
    </div>
</main>

<div class="modal fade expense-pay-modal" id="expensePayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="expensePayForm">
            <div class="modal-header">
                <div>
                    <span class="ops-eyebrow">Settle Payable</span>
                    <h5 class="modal-title" id="expensePayTitle">Record expense payment</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="mark_paid">
                <input type="hidden" name="expense_id" id="expensePayId">
                <div class="expense-pay-summary">
                    <span>Open balance</span>
                    <strong id="expensePayOpen">PHP 0.00</strong>
                </div>
                <div class="expense-form-grid is-two">
                    <div>
                        <label>Payment amount</label>
                        <input type="number" step="0.01" min="0.01" name="paid_amount" id="expensePayAmount" class="form-control" required>
                    </div>
                    <div>
                        <label>Paid at</label>
                        <input type="datetime-local" name="paid_at" id="expensePaidAt" class="form-control" required>
                    </div>
                </div>
                <div class="expense-form-grid is-two">
                    <div>
                        <label>Method</label>
                        <input type="text" name="payment_method" class="form-control" maxlength="50" placeholder="Cash, GCash, Bank" required>
                    </div>
                    <div>
                        <label>Reference no.</label>
                        <input type="text" name="reference_no" class="form-control" maxlength="100" placeholder="Optional receipt/reference">
                    </div>
                </div>
                <p class="ops-muted small mb-0">If the amount is less than the open balance, the expense stays partially paid.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Payment</button>
            </div>
        </form>
    </div>
</div>

<script>
window.EXPENSE_TRACKER_DATA = <?= json_encode([
    'expenses' => array_map(static function (array $expense): array {
        $name = trim((string) ($expense['first_name'] ?? '') . ' ' . (string) ($expense['last_name'] ?? ''));
        if ($name === '') {
            $name = (string) ($expense['username'] ?? 'Admin');
        }
        return [
            'expense_id' => (int) ($expense['expense_id'] ?? 0),
            'expense_date' => (string) ($expense['expense_date'] ?? ''),
            'category' => (string) ($expense['category'] ?? ''),
            'amount' => (float) ($expense['amount'] ?? 0),
            'notes' => (string) ($expense['notes'] ?? ''),
            'creator' => $name,
            'payment_status' => (string) ($expense['payment_status'] ?? 'paid'),
            'due_date' => (string) ($expense['due_date'] ?? ''),
            'paid_at' => (string) ($expense['paid_at'] ?? ''),
            'paid_amount' => (float) ($expense['paid_amount'] ?? 0),
            'open_balance' => max(0, (float) ($expense['amount'] ?? 0) - (float) ($expense['paid_amount'] ?? 0)),
            'payment_method' => (string) ($expense['payment_method'] ?? ''),
            'reference_no' => (string) ($expense['reference_no'] ?? ''),
        ];
    }, $expenses),
    'summary' => [
        'today_total' => (float) ($summary['today_total'] ?? 0),
        'month_total' => (float) ($summary['month_total'] ?? 0),
        'paid_30_total' => (float) ($summary['paid_30_total'] ?? 0),
        'open_total' => (float) ($summary['open_total'] ?? 0),
        'overdue_total' => (float) ($summary['overdue_total'] ?? 0),
        'due_soon_total' => (float) ($summary['due_soon_total'] ?? 0),
        'partial_open_total' => (float) ($summary['partial_open_total'] ?? 0),
        'total_rows' => (int) ($summary['total_rows'] ?? 0),
        'top_category' => [
            'category' => (string) ($summary['top_category']['category'] ?? ''),
            'total' => (float) ($summary['top_category']['total'] ?? 0),
        ],
    ],
    'analytics' => [
        'category_breakdown' => array_map(static fn(array $row): array => [
            'category' => (string) ($row['category'] ?? ''),
            'total' => (float) ($row['total'] ?? 0),
            'entries' => (int) ($row['entries'] ?? 0),
        ], $analytics['category_breakdown'] ?? []),
        'daily_trend' => array_map(static fn(array $row): array => [
            'expense_date' => (string) ($row['expense_date'] ?? ''),
            'total' => (float) ($row['total'] ?? 0),
        ], $analytics['daily_trend'] ?? []),
        'largest_expense' => $analytics['largest_expense'] ?? null,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<?php require __DIR__ . '/../components/js_script.php'; ?>
<script src="/inventory_system/assets/js/expense-tracker.js"></script>
</body>
</html>
