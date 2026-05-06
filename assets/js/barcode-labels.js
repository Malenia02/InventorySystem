document.addEventListener("DOMContentLoaded", () => {
  const table = document.getElementById("barcodeLabelTable");
  if (!table) return;

  const rows = Array.from(table.querySelectorAll("tbody tr"));
  const searchInput = document.getElementById("barcodeSearch");
  const statusInput = document.getElementById("barcodeStatus");
  const copiesInput = document.getElementById("barcodeCopies");
  const selectVisibleBtn = document.getElementById("barcodeSelectVisible");
  const printBtn = document.getElementById("barcodePrintSelected");
  const toggleAll = document.getElementById("barcodeToggleAll");
  const visibleCountNode = document.getElementById("barcodeVisibleCount");
  const selectedCountNode = document.getElementById("barcodeSelectedCount");
  const metaNode = document.getElementById("barcodeMeta");
  const emptyNode = document.getElementById("barcodeEmpty");

  const escapeHtml = (value) =>
    String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");

  const visibleRows = () => rows.filter((row) => !row.classList.contains("d-none"));
  const selectedRows = () => rows.filter((row) => row.querySelector(".barcode-label-check")?.checked);

  const updateSelected = () => {
    if (selectedCountNode) selectedCountNode.textContent = selectedRows().length.toLocaleString();
  };

  const applyFilters = () => {
    const search = (searchInput?.value || "").trim().toLowerCase();
    const status = statusInput?.value || "all";
    let visible = 0;

    rows.forEach((row) => {
      const matches =
        (!search || (row.dataset.search || "").includes(search)) &&
        (status === "all" || (row.dataset.status || "") === status);
      row.classList.toggle("d-none", !matches);
      if (matches) visible += 1;
    });

    if (visibleCountNode) visibleCountNode.textContent = visible.toLocaleString();
    if (metaNode) metaNode.textContent = `Showing ${visible.toLocaleString()} of ${rows.length.toLocaleString()} products.`;
    if (emptyNode) emptyNode.classList.toggle("d-none", visible !== 0);
    updateSelected();
  };

  const buildLabels = () => {
    const copies = Math.max(1, Math.min(24, Number(copiesInput?.value || 1)));
    const labels = [];
    selectedRows().forEach((row) => {
      for (let i = 0; i < copies; i += 1) {
        labels.push({
          name: row.dataset.productName || "Product",
          sku: row.dataset.sku || "",
          price: row.dataset.price || "0.00",
        });
      }
    });
    return labels;
  };

  const printLabels = () => {
    const labels = buildLabels();
    if (!labels.length) {
      if (window.Swal) {
        window.Swal.fire({ icon: "info", title: "No labels selected", text: "Choose at least one ready product first." });
      } else {
        window.alert("Choose at least one ready product first.");
      }
      return;
    }

    const labelHtml = labels.map((label) => `
      <article class="label">
        <strong>${escapeHtml(label.name)}</strong>
        <div class="barcode">${escapeHtml(label.sku)}</div>
        <small>SKU ${escapeHtml(label.sku)} | PHP ${escapeHtml(label.price)}</small>
      </article>
    `).join("");

    const win = window.open("", "_blank", "width=900,height=760,scrollbars=yes");
    if (!win) {
      if (window.Swal) window.Swal.fire({ icon: "error", title: "Pop-up blocked", text: "Allow pop-ups to print labels." });
      return;
    }

    win.document.write(`<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Barcode Labels</title>
<style>
*{box-sizing:border-box}body{margin:0;padding:18px;font-family:Arial,sans-serif;background:#f3f6fb}.sheet{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.label{height:118px;background:#fff;border:1px dashed #9daeca;border-radius:12px;padding:12px;text-align:center;break-inside:avoid}.label strong{display:block;font-size:13px;line-height:1.2;color:#012970;min-height:32px}.barcode{font-family:"Courier New",monospace;font-size:22px;font-weight:900;letter-spacing:2px;margin:10px 0 4px;padding:8px;background:repeating-linear-gradient(90deg,#111 0 2px,#fff 2px 5px);color:transparent;border-radius:4px}.label small{color:#556987}@media print{@page{margin:8mm}body{background:#fff;padding:0}.sheet{gap:6px}.label{box-shadow:none}}
</style></head><body><main class="sheet">${labelHtml}</main><script>window.addEventListener("load",()=>window.print());<\/script></body></html>`);
    win.document.close();
  };

  [searchInput, statusInput].forEach((element) => {
    element?.addEventListener("input", applyFilters);
    element?.addEventListener("change", applyFilters);
  });

  table.addEventListener("change", (event) => {
    if (event.target.matches(".barcode-label-check")) updateSelected();
  });

  toggleAll?.addEventListener("change", () => {
    visibleRows().forEach((row) => {
      const check = row.querySelector(".barcode-label-check");
      if (check && !check.disabled) check.checked = toggleAll.checked;
    });
    updateSelected();
  });

  selectVisibleBtn?.addEventListener("click", () => {
    visibleRows().forEach((row) => {
      const check = row.querySelector(".barcode-label-check");
      if (check && !check.disabled) check.checked = true;
    });
    updateSelected();
  });

  printBtn?.addEventListener("click", printLabels);
  applyFilters();
});
