(function () {
  const main = document.getElementById("main");
  const stateScriptId = "dashboardState";
  if (!main) return;

  let recentTableInstance = null;

  function getState() {
    const script = document.getElementById(stateScriptId);
    if (!script) return null;

    try {
      return JSON.parse(script.textContent || "{}");
    } catch (err) {
      console.error("Failed to parse dashboard state:", err);
      return null;
    }
  }

  function getPeriodData(state) {
    const isCashier = !!state.isCashier;
    return {
      salesSeries: isCashier ? state.cashierChartSales || [] : state.chartSales || [],
      revenueSeries: isCashier ? state.cashierChartRevenue || [] : state.chartRevenue || [],
      categories: isCashier ? state.cashierChartDates || [] : state.chartDates || [],
      paymentLabels: isCashier ? state.cashierPayLabels || [] : state.payLabels || [],
      paymentTotals: isCashier ? state.cashierPayTotals || [] : state.payTotals || [],
      paymentLeader: isCashier ? state.cashierPaymentLeader || null : state.paymentLeader || null,
      activityLimit: state.activityLimit || 6,
      isCashier,
    };
  }

  function destroyReportsChart() {
    if (window.dashboardReportsChart && typeof window.dashboardReportsChart.destroy === "function") {
      window.dashboardReportsChart.destroy();
    }
    window.dashboardReportsChart = null;
  }

  function destroyPaymentChart() {
    const node = document.querySelector("#paymentChart");
    if (!node) return;

    const existing = echarts.getInstanceByDom(node);
    if (existing) {
      existing.dispose();
    }
  }

  function renderReportsChart(state) {
    const node = document.querySelector("#reportsChart");
    if (!node || typeof ApexCharts === "undefined") return;

    destroyReportsChart();

    const data = getPeriodData(state);
    if (!data.categories.length) {
      node.innerHTML = '<p class="text-center text-muted py-5">No chart data available for this period.</p>';
      return;
    }

    window.dashboardReportsChart = new ApexCharts(node, {
      series: [
        { name: "Sales", data: data.salesSeries },
        { name: "Revenue", data: data.revenueSeries },
      ],
      chart: {
        height: 350,
        type: "area",
        toolbar: { show: false },
      },
      markers: { size: 4 },
      colors: ["#4154f1", "#2eca6a"],
      fill: {
        type: "gradient",
        gradient: { shadeIntensity: 1, opacityFrom: 0.3, opacityTo: 0.4, stops: [0, 90, 100] },
      },
      dataLabels: { enabled: false },
      stroke: { curve: "smooth", width: 2 },
      xaxis: {
        type: "datetime",
        categories: data.categories,
      },
      tooltip: { x: { format: "MMM dd, yyyy" } },
    });

    window.dashboardReportsChart.render();
  }

  function renderPaymentChart(state) {
    const node = document.querySelector("#paymentChart");
    if (!node || typeof echarts === "undefined") return;

    destroyPaymentChart();

    const data = getPeriodData(state);
    if (!data.paymentLabels.length) {
      node.innerHTML = `<p class="text-center text-muted py-5">No payment activity for this period.</p>`;
      return;
    }

    const chart = echarts.init(node);
    chart.setOption({
      tooltip: { trigger: "item", formatter: "{b}: ₱{c} ({d}%)" },
      legend: { top: "5%", left: "center" },
      series: [{
        name: "Payment",
        type: "pie",
        radius: ["40%", "70%"],
        avoidLabelOverlap: false,
        label: { show: false, position: "center" },
        emphasis: { label: { show: true, fontSize: "18", fontWeight: "bold" } },
        labelLine: { show: false },
        data: data.paymentLabels.map((label, index) => ({ name: label, value: data.paymentTotals[index] || 0 })),
      }],
    });
  }

  function reinitRecentSalesTable() {
    const table = document.querySelector("table.datatable");
    if (!table || typeof simpleDatatables === "undefined" || !simpleDatatables.DataTable) return;

    if (recentTableInstance && typeof recentTableInstance.destroy === "function") {
      try {
        recentTableInstance.destroy();
      } catch (err) {
        console.warn("Failed to destroy previous dashboard table:", err);
      }
    }

    recentTableInstance = new simpleDatatables.DataTable(table, {
      searchable: true,
      fixedHeight: false,
      perPage: 10,
      perPageSelect: [5, 10, 15, ["All", -1]],
      labels: {
        placeholder: "Search...",
        perPage: "{select} entries per page",
        noRows: "No matching records found",
        info: "Showing {start} to {end} of {rows} entries",
      },
    });
  }

  function renderDashboard(options = {}) {
    const { reinitTables = false } = options;
    const state = getState();
    if (!state) return;

    renderReportsChart(state);
    renderPaymentChart(state);

    if (reinitTables) {
      reinitRecentSalesTable();
    }
  }

  async function loadDashboard(url, pushState = true) {
    document.body.classList.add("dashboard-loading");
    try {
      const response = await fetch(url, {
        credentials: "same-origin",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          "Accept": "text/html",
        },
      });

      if (!response.ok) {
        throw new Error(`Dashboard refresh failed (${response.status})`);
      }

      const html = await response.text();
      const doc = new DOMParser().parseFromString(html, "text/html");
      const nextMain = doc.getElementById("main");

      if (!nextMain) {
        throw new Error("Dashboard main container not found.");
      }

      const currentMain = document.getElementById("main");
      if (!currentMain) {
        throw new Error("Current dashboard main container not found.");
      }

      destroyReportsChart();
      destroyPaymentChart();
      currentMain.innerHTML = nextMain.innerHTML;

      if (pushState) {
        history.pushState({}, "", url);
      }

      renderDashboard({ reinitTables: true });
    } catch (error) {
      console.error(error);
      window.location.href = url;
    } finally {
      document.body.classList.remove("dashboard-loading");
    }
  }

  function isDashboardFilterLink(link) {
    if (!link || !link.href) return false;
    if (link.getAttribute("href") === "#") return false;

    const inMain = link.closest("main#main");
    const inFilter = link.closest(".filter");
    const inTabs = link.closest(".occ-period-tabs");
    return !!(inMain && (inFilter || inTabs));
  }

  document.addEventListener("click", (event) => {
    const link = event.target.closest("a[href]");
    if (!link || !isDashboardFilterLink(link)) return;

    event.preventDefault();
    loadDashboard(link.href);
  });

  window.addEventListener("popstate", () => {
    loadDashboard(window.location.href, false);
  });

  window.dashboardRefresh = loadDashboard;
  window.dashboardRender = renderDashboard;

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => renderDashboard());
  } else {
    renderDashboard();
  }
})();
