document.addEventListener("DOMContentLoaded", () => {
  const table = document.getElementById("stockMovementAuditTable");
  if (!table) return;

  const rows = Array.from(table.querySelectorAll("tbody tr"));
  const searchInput = document.getElementById("auditSearch");
  const productInput = document.getElementById("auditProduct");
  const actionInput = document.getElementById("auditAction");
  const actorInput = document.getElementById("auditActor");
  const supplierInput = document.getElementById("auditSupplier");
  const referenceInput = document.getElementById("auditReference");
  const dateFromInput = document.getElementById("auditDateFrom");
  const dateToInput = document.getElementById("auditDateTo");
  const visibleCountNode = document.getElementById("auditVisibleCount");
  const productCountNode = document.getElementById("auditProductCount");
  const stockInNode = document.getElementById("auditStockInCount");
  const stockOutNode = document.getElementById("auditStockOutCount");
  const metaNode = document.getElementById("auditMeta");
  const netChangeNode = document.getElementById("auditNetChange");
  const emptyNode = document.getElementById("stockMovementAuditEmpty");

  const applyFilters = () => {
    const search = (searchInput?.value || "").trim().toLowerCase();
    const productId = productInput?.value || "all";
    const action = actionInput?.value || "all";
    const actor = actorInput?.value || "all";
    const supplier = supplierInput?.value || "all";
    const reference = referenceInput?.value || "all";
    const dateFrom = dateFromInput?.value || "";
    const dateTo = dateToInput?.value || "";
    const products = new Set();
    let visible = 0;
    let stockIn = 0;
    let stockOut = 0;
    let netChange = 0;

    rows.forEach((row) => {
      const change = Number(row.dataset.change || 0);
      const matches =
        (!search || (row.dataset.search || "").includes(search)) &&
        (productId === "all" || (row.dataset.productId || "") === productId) &&
        (action === "all" || (row.dataset.action || "") === action) &&
        (actor === "all" || (row.dataset.actor || "") === actor) &&
        (supplier === "all" || (row.dataset.supplier || "") === supplier) &&
        (reference === "all" || (row.dataset.reference || "") === reference) &&
        (!dateFrom || (row.dataset.date || "") >= dateFrom) &&
        (!dateTo || (row.dataset.date || "") <= dateTo);

      row.classList.toggle("d-none", !matches);

      if (matches) {
        visible += 1;
        products.add(row.dataset.productId || "");
        netChange += change;
        if (change > 0) stockIn += change;
        if (change < 0) stockOut += Math.abs(change);
      }
    });

    if (visibleCountNode) visibleCountNode.textContent = visible.toLocaleString();
    if (productCountNode) productCountNode.textContent = products.size.toLocaleString();
    if (stockInNode) stockInNode.textContent = stockIn.toLocaleString();
    if (stockOutNode) stockOutNode.textContent = stockOut.toLocaleString();
    if (metaNode) metaNode.textContent = `Showing ${visible.toLocaleString()} of ${rows.length.toLocaleString()} stock movements.`;
    if (netChangeNode) netChangeNode.textContent = `Net change: ${netChange > 0 ? "+" : ""}${netChange.toLocaleString()} pcs`;
    if (emptyNode) emptyNode.classList.toggle("d-none", visible !== 0);
  };

  [searchInput, productInput, actionInput, actorInput, supplierInput, referenceInput, dateFromInput, dateToInput].forEach((element) => {
    element?.addEventListener("input", applyFilters);
    element?.addEventListener("change", applyFilters);
  });

  applyFilters();
});
