<?php
$stockCoverageDays = null;
$stockCoverageOutOfStock = 0;

foreach ($reorderWatchlist as $item) {
  $qty = (int) ($item['quantity'] ?? 0);
  $cover = $item['cover_days'] ?? null;

  if ($qty === 0) {
    $stockCoverageOutOfStock++;
    $stockCoverageDays = $stockCoverageDays === null ? 0.0 : min($stockCoverageDays, 0.0);
  }

  if ($cover !== null) {
    $cover = (float) $cover;
    $stockCoverageDays = $stockCoverageDays === null ? $cover : min($stockCoverageDays, $cover);
  }
}

if ($stockCoverageDays === null) {
  $stockCoverageValue = 'N/A';
  $stockCoverageNote = empty($reorderWatchlist)
    ? 'No items need replenishment right now'
    : 'No recent sales history in the watchlist';
} else {
  $stockCoverageValue = number_format($stockCoverageDays, 1) . 'd';
  $stockCoverageNote = $stockCoverageOutOfStock > 0
    ? number_format($stockCoverageOutOfStock) . ' out-of-stock item(s) in watchlist'
    : 'Soonest depletion in the watchlist';
}
?>

<div class="col-12">
  <div class="card occ-card p-0 overflow-hidden">

    <div class="occ-header">
      <div>
        <div class="occ-title">Owner command center</div>
        <div class="occ-subtitle">Live store pulse - <?= htmlspecialchars(strtolower(periodLabel($salesPeriod)), ENT_QUOTES, 'UTF-8') ?></div>
      </div>
      <div class="occ-period-tabs" id="occPeriodTabs">
        <a class="occ-ptab <?= $salesPeriod === 'today' ? 'active' : '' ?>" href="<?= filterUrl('sales_period', 'today') ?>">Today</a>
        <a class="occ-ptab <?= $salesPeriod === 'week'  ? 'active' : '' ?>" href="<?= filterUrl('sales_period', 'week') ?>">This week</a>
        <a class="occ-ptab <?= $salesPeriod === 'month' ? 'active' : '' ?>" href="<?= filterUrl('sales_period', 'month') ?>">This month</a>
      </div>
    </div>

    <div class="occ-pulse-grid">
      <div class="occ-pulse-tile">
        <div class="occ-pulse-label">
          <span class="occ-pulse-icon occ-icon-blue"><i class="bi bi-cart3"></i></span>
          Sales
          <?php if ($salesData['change'] > 0): ?>
            <span class="occ-trend <?= $salesData['direction'] === 'up' ? 'occ-trend-up' : 'occ-trend-down' ?>">
              <?= $salesData['direction'] === 'up' ? '&uarr;' : '&darr;' ?> <?= $salesData['change'] ?>%
            </span>
          <?php endif; ?>
        </div>
        <div class="occ-pulse-val"><?= number_format((int) ($salesData['current'] ?? 0)) ?></div>
        <div class="occ-pulse-note">Completed transactions</div>
      </div>

      <div class="occ-pulse-tile">
        <div class="occ-pulse-label">
          <span class="occ-pulse-icon occ-icon-amber"><i class="bi bi-box-seam"></i></span>
          To replenish
        </div>
        <div class="occ-pulse-val"><?= number_format(count($reorderWatchlist)) ?></div>
        <div class="occ-pulse-note">Low-stock and out-of-stock items</div>
      </div>

      <div class="occ-pulse-tile">
        <div class="occ-pulse-label">
          <span class="occ-pulse-icon occ-icon-amber"><i class="bi bi-exclamation-triangle"></i></span>
          Out of stock
        </div>
        <div class="occ-pulse-val"><?= number_format((int) $outOfStock) ?></div>
        <div class="occ-pulse-note">Current out-of-stock items &middot; <?= number_format((int) $lowStockCount) ?> low stock</div>
      </div>

      <div class="occ-pulse-tile">
        <div class="occ-pulse-label">
          <span class="occ-pulse-icon occ-icon-blue"><i class="bi bi-hourglass-split"></i></span>
          Days left
        </div>
        <div class="occ-pulse-val"><?= htmlspecialchars($stockCoverageValue, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="occ-pulse-note"><?= htmlspecialchars($stockCoverageNote, ENT_QUOTES, 'UTF-8') ?></div>
      </div>
    </div>

    <div class="occ-panels-grid">
      <div class="occ-panel">
        <div class="occ-panel-head">
          <span class="occ-panel-title">Reorder watchlist</span>
          <span class="occ-badge occ-badge-warn"><?= count($reorderWatchlist) ?> active</span>
        </div>

        <?php if (empty($reorderWatchlist)): ?>
          <div class="occ-empty">No urgent reorder suggestions right now.</div>
        <?php else: ?>
          <div class="occ-list-head occ-col-reorder">
            <span>Product</span>
            <span class="text-end">Stock</span>
            <span class="text-end">Cover</span>
            <span class="text-end">Suggest</span>
          </div>
          <?php foreach ($reorderWatchlist as $item):
            $qty      = (int) ($item['quantity'] ?? 0);
            $isOut    = $qty === 0;
            $cover    = $item['cover_days'] ?? null;
            $suggest  = (int) ($item['recommended_pieces'] ?? 0);
            $coverPct = $cover ? min(100, ($cover / 7) * 100) : 0;
            $coverColor = ($cover === null) ? '#E24B4A' : ($cover < 2 ? '#E24B4A' : ($cover < 4 ? '#BA7517' : '#1D9E75'));
          ?>
            <div class="occ-list-row occ-col-reorder">
              <div>
                <div class="occ-row-name"><?= htmlspecialchars($item['product_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                <div class="occ-row-sub"><?= htmlspecialchars($item['category_name'] ?? 'Uncategorized', ENT_QUOTES, 'UTF-8') ?></div>
              </div>
              <div class="text-end">
                <span class="occ-stock-chip <?= $isOut ? 'chip-out' : 'chip-low' ?>"><?= $qty ?></span>
              </div>
              <div>
                <div class="occ-row-val <?= $isOut ? 'val-danger' : 'val-warn' ?>" style="text-align:right">
                  <?= $cover !== null ? number_format((float) $cover, 1) . 'd' : 'No sales' ?>
                </div>
                <?php if ($cover !== null): ?>
                  <div class="occ-cover-bar">
                    <div class="occ-cover-fill" style="width:<?= $coverPct ?>%;background:<?= $coverColor ?>"></div>
                  </div>
                <?php endif; ?>
              </div>
              <div class="occ-row-val text-end"><?= number_format($suggest) ?> pcs</div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="occ-panel">
        <div class="occ-panel-head">
          <span class="occ-panel-title">Top cashiers</span>
          <span class="occ-badge occ-badge-info"><?= htmlspecialchars(periodLabel($salesPeriod), ENT_QUOTES, 'UTF-8') ?></span>
        </div>

        <?php if (empty($cashierHighlights)): ?>
          <div class="occ-empty">No cashier sales for this period.</div>
        <?php else: ?>
          <div class="occ-list-head occ-col-cashier">
            <span>Cashier</span>
            <span class="text-end">Sales</span>
            <span class="text-end">Revenue</span>
          </div>
          <?php foreach ($cashierHighlights as $i => $cashier):
            $cname = trim(($cashier['first_name'] ?? '') . ' ' . ($cashier['last_name'] ?? '')) ?: ($cashier['username'] ?? 'Unknown');
          ?>
            <div class="occ-list-row occ-col-cashier">
              <div style="display:flex;align-items:center;gap:8px;min-width:0">
                <span class="occ-rank <?= $i === 0 ? 'occ-rank-gold' : '' ?>"><?= $i + 1 ?></span>
                <div style="min-width:0">
                  <div class="occ-row-name"><?= htmlspecialchars($cname, ENT_QUOTES, 'UTF-8') ?></div>
                  <div class="occ-row-sub"><?= number_format((int) ($cashier['items_sold'] ?? 0)) ?> items</div>
                </div>
              </div>
              <div class="occ-row-val text-end"><?= number_format((int) ($cashier['sale_count'] ?? 0)) ?></div>
              <div class="occ-row-val text-end">&#8369;<?= number_format((float) ($cashier['total_revenue'] ?? 0), 2) ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="occ-panel">
        <div class="occ-panel-head">
          <span class="occ-panel-title">Slow-moving products</span>
          <span class="occ-badge occ-badge-neutral"><?= count($slowMovingWatchlist) ?> tracked</span>
        </div>

        <?php if (empty($slowMovingWatchlist)): ?>
          <div class="occ-empty">No slow-moving products right now.</div>
        <?php else: ?>
          <div class="occ-list-head occ-col-slow">
            <span>Product</span>
            <span class="text-end">30d sold</span>
            <span class="text-end">Last sale</span>
          </div>
          <?php foreach ($slowMovingWatchlist as $item):
            $sold = (int) ($item['total_pieces_sold_30d'] ?? 0);
            $soldCls = $sold === 0 ? 'val-danger' : ($sold <= 2 ? 'val-warn' : '');
          ?>
            <div class="occ-list-row occ-col-slow">
              <div>
                <div class="occ-row-name"><?= htmlspecialchars($item['product_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                <div class="occ-row-sub"><?= htmlspecialchars($item['category_name'] ?? 'Uncategorized', ENT_QUOTES, 'UTF-8') ?></div>
              </div>
              <div class="occ-row-val <?= $soldCls ?> text-end"><?= number_format($sold) ?> pcs</div>
              <div class="occ-row-val val-muted text-end">
                <?= empty($item['last_sale_date']) ? 'No sale' : date('M d', strtotime((string) $item['last_sale_date'])) ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="occ-panel">
        <div class="occ-panel-head">
          <span class="occ-panel-title">Dormant products</span>
          <span class="occ-badge occ-badge-danger"><?= number_format((int) $dormantProductCount) ?> in 30 days</span>
        </div>

        <?php if (empty($dormantProducts)): ?>
          <div class="occ-empty">Every active product has a recent sale.</div>
        <?php else: ?>
          <div class="occ-list-head occ-col-dormant">
            <span>Product</span>
            <span class="text-end">Last sale</span>
            <span class="text-end">Stock</span>
          </div>
          <?php foreach ($dormantProducts as $item): ?>
            <div class="occ-list-row occ-col-dormant">
              <div>
                <div class="occ-row-name"><?= htmlspecialchars($item['product_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                <div class="occ-row-sub"><?= htmlspecialchars($item['category_name'] ?? 'Uncategorized', ENT_QUOTES, 'UTF-8') ?></div>
              </div>
              <div class="occ-row-val val-muted text-end">
                <?= empty($item['last_sale_date']) ? 'No sale' : date('M d', strtotime((string) $item['last_sale_date'])) ?>
              </div>
              <div class="occ-row-val text-end"><?= number_format((int) ($item['quantity'] ?? 0)) ?></div>
            </div>
          <?php endforeach; ?>

          <?php if ($paymentLeader !== null): ?>
            <div class="occ-pay-leader">
              <span class="occ-row-sub">Top payment - <?= htmlspecialchars(strtolower(periodLabel($payPeriod)), ENT_QUOTES, 'UTF-8') ?></span>
              <span class="occ-row-name">
                <?= htmlspecialchars(ucfirst((string) ($paymentLeader['payment_method'] ?? 'Unknown')), ENT_QUOTES, 'UTF-8') ?>
                <span class="occ-row-sub ms-1">&middot; &#8369;<?= number_format((float) ($paymentLeader['total'] ?? 0), 2) ?></span>
              </span>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>
