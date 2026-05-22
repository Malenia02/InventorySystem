document.addEventListener("DOMContentLoaded", () => {
  const table = document.getElementById("reorderPlannerTable");
  if (!table) return;

  const rows = Array.from(table.querySelectorAll("tbody tr"));
  const searchInput = document.getElementById("reorderSearch");
  const urgencyInput = document.getElementById("reorderUrgency");
  const coverageInput = document.getElementById("reorderCoverage");
  const metaNode = document.getElementById("reorderMeta");
  const piecesNode = document.getElementById("reorderPiecesTotal");
  const criticalNode = document.getElementById("reorderCriticalCount");
  const visibleSummaryNode = document.getElementById("reorderVisibleSummary");
  const emptyNode = document.getElementById("reorderEmpty");

  const applyFilters = () => {
    const search = (searchInput?.value || "").trim().toLowerCase();
    const urgency = urgencyInput?.value || "all";
    const coverage = coverageInput?.value || "all";
    let visible = 0;
    let suggestedTotal = 0;
    let criticalVisible = 0;

    rows.forEach((row) => {
      const searchMatch = !search || (row.dataset.search || "").includes(search);
      const urgencyMatch = urgency === "all" || (row.dataset.urgency || "") === urgency;
      const coverValue = row.dataset.cover || "none";
      const coverNumber = coverValue === "none" ? null : Number(coverValue);
      const coverageMatch =
        coverage === "all" ||
        (coverage === "none" && coverValue === "none") ||
        (coverage === "lt2" && coverNumber !== null && coverNumber < 2) ||
        (coverage === "lt7" && coverNumber !== null && coverNumber < 7);

      const show = searchMatch && urgencyMatch && coverageMatch;
      row.classList.toggle("d-none", !show);
      if (show) {
        visible += 1;
        suggestedTotal += Number(row.dataset.suggested || 0);
        if ((row.dataset.urgency || "") === "critical") criticalVisible += 1;
      }
    });

    if (metaNode) metaNode.textContent = `Showing ${visible.toLocaleString()} of ${rows.length.toLocaleString()} reorder suggestions.`;
    if (piecesNode) piecesNode.textContent = suggestedTotal.toLocaleString();
    if (criticalNode) criticalNode.textContent = criticalVisible.toLocaleString();
    if (visibleSummaryNode) visibleSummaryNode.textContent = `Visible suggested intake: ${suggestedTotal.toLocaleString()} pcs`;
    if (emptyNode) emptyNode.classList.toggle("d-none", visible !== 0);
  };

  [searchInput, urgencyInput, coverageInput].forEach((element) => {
    element?.addEventListener("input", applyFilters);
    element?.addEventListener("change", applyFilters);
  });

  applyFilters();
});
