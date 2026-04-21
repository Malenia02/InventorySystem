<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';

// Load controllers after config
require_once __DIR__ . '/../controllers/CategoryController.php';
require_once __DIR__ . '/../controllers/SubcategoryController.php';
require_once __DIR__ . '/../controllers/ProductController.php';
require_once __DIR__ . '/../controllers/PosConfigController.php';

Middleware::auth()->role(['admin','cashier']);

$categories = CategoryController::all($conn);
$subcategories = SubcategoryController::all($conn, null, 'active');
$products   = ProductController::activeProductsForPOS($conn);
$posConfig  = PosConfigController::get($conn);
$vatRate    = (float) ($posConfig['tax_rate'] ?? 12);
$defaultCashier = trim((string) ($_SESSION['first_name'] ?? '') . ' ' . (string) ($_SESSION['last_name'] ?? ''));
$defaultCashier = $defaultCashier !== '' ? $defaultCashier : (string) ($_SESSION['username'] ?? 'Cashier');

$categoryProductCounts = [];
$totalProductCount = 0;
foreach ($products as $product) {
    $categoryId = (int) ($product['category_id'] ?? 0);
    if ($categoryId > 0) {
        $categoryProductCounts[$categoryId] = ($categoryProductCounts[$categoryId] ?? 0) + 1;
    }
    $totalProductCount++;
}

function categoryIconClass(string $categoryName): string
{
    $normalized = strtolower(trim($categoryName));

    return match (true) {
        str_contains($normalized, 'beverage'),
        str_contains($normalized, 'drink'),
        str_contains($normalized, 'water'),
        str_contains($normalized, 'juice') => 'bi-cup-straw',
        str_contains($normalized, 'snack'),
        str_contains($normalized, 'chips'),
        str_contains($normalized, 'biscuit') => 'bi-emoji-smile',
        str_contains($normalized, 'canned') => 'bi-box-seam',
        str_contains($normalized, 'rice'),
        str_contains($normalized, 'grain') => 'bi-flower1',
        str_contains($normalized, 'bread'),
        str_contains($normalized, 'bakery') => 'bi-basket3',
        default => 'bi-grid',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>POS — Point of Sale</title>

<?php require __DIR__ . '/../components/head.php'; ?>

<!-- Google Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">

<!-- POS Styles -->
<link rel="stylesheet" href="/inventory_system/assets/css/pos-light.css">

</head>
<body>

<?php
require __DIR__ . '/../components/header.php';
require __DIR__ . '/../components/sidebar.php';
?>

<main id="main" class="main">
<div class="pos-wrapper">

  <!-- ============================================================
       LEFT PANEL — PRODUCTS
       ============================================================ -->
  <div class="pos-left">

    <!-- Top bar -->
    <div class="left-topbar">
      <div class="pos-brand">
        <div class="pos-brand-icon">🛍</div>
        <span class="pos-brand-name">Point of Sale</span>
      </div>

      <div class="search-box">
        <i class="bi bi-search search-icon"></i>
        <input type="text" id="productSearch" placeholder="Search products…">
      </div>

      <div class="time-pill" id="timePill">Loading…</div>
    </div>

    <div class="pos-browser">
      <aside class="cat-sidebar" id="categoryBar">
        <div class="cat-sidebar-title">Categories</div>
        <button class="cat-sidebar-item cat-btn active" data-id="all" data-name="All Categories">
          <span class="cat-sidebar-icon"><i class="bi bi-grid"></i></span>
          <span class="cat-sidebar-label">All Categories</span>
          <span class="cat-sidebar-count"><?= $totalProductCount ?></span>
        </button>
        <?php foreach ($categories as $c): ?>
          <?php
          $categoryId = (int) ($c['category_id'] ?? 0);
          $categoryName = (string) ($c['category_name'] ?? 'Category');
          ?>
          <button class="cat-sidebar-item cat-btn" data-id="<?= $categoryId ?>" data-name="<?= htmlspecialchars($categoryName, ENT_QUOTES, 'UTF-8') ?>">
            <span class="cat-sidebar-icon"><i class="bi <?= categoryIconClass($categoryName) ?>"></i></span>
            <span class="cat-sidebar-label"><?= htmlspecialchars($categoryName) ?></span>
            <span class="cat-sidebar-count"><?= (int) ($categoryProductCounts[$categoryId] ?? 0) ?></span>
          </button>
        <?php endforeach; ?>
      </aside>

      <div class="cat-content">
        <div class="pos-toprow">
          <div class="pos-breadcrumb" id="posBrowserTitle">All Categories</div>
          <div class="pos-product-count" id="posProductCount"><?= $totalProductCount ?> items</div>
        </div>

        <div class="subcat-bar is-hidden" id="subcategorySection">
          <div class="subcat-heading">Subcategory</div>
          <div class="subcat-shell" id="subcategoryShell">
            <button class="subcat-arrow" id="subcategoryPrevBtn" type="button" aria-label="Previous subcategories">
              <i class="bi bi-chevron-left"></i>
            </button>
            <div class="subcat-row" id="subcategoryBar">
              <button class="sub-pill subcat-btn active" data-id="all">All</button>
            </div>
            <button class="subcat-arrow" id="subcategoryNextBtn" type="button" aria-label="Next subcategories">
              <i class="bi bi-chevron-right"></i>
            </button>
          </div>
        </div>

    <!-- Product grid -->
        <div class="product-area">
          <div class="product-grid" id="product-grid">
            <?php foreach ($products as $p):
                $regularPrice = (float) ($p['price'] ?? 0);
                $pieceDiscount = isset($p['sale_price'])
                    ? max(0.0, min(100.0, (float) $p['sale_price']))
                    : 0.0;
                $boxDiscount = isset($p['box_sale_price'])
                    ? max(0.0, min(100.0, (float) $p['box_sale_price']))
                    : 0.0;
                $caseDiscount = isset($p['case_sale_price'])
                    ? max(0.0, min(100.0, (float) $p['case_sale_price']))
                    : 0.0;
                $price = $pieceDiscount > 0
                    ? $regularPrice * (1 - ($pieceDiscount / 100))
                    : $regularPrice;
                $boxPrice = isset($p['box_price']) && $p['box_price'] !== null && $p['box_price'] !== ''
                    ? (float) $p['box_price']
                    : null;
                $casePrice = isset($p['case_price']) && $p['case_price'] !== null && $p['case_price'] !== ''
                    ? (float) $p['case_price']
                    : null;
                $piecesPerBox = max(1, (int) ($p['pieces_per_box'] ?? 1));
                $boxesPerCase = max(1, (int) ($p['boxes_per_case'] ?? 1));
                $casePieces = $piecesPerBox * $boxesPerCase;
                $categoryNameLower = strtolower((string) ($p['category_name'] ?? ''));
                $isBeverageCategory = str_contains($categoryNameLower, 'beverage');
                $hasBoxUnit = !$isBeverageCategory && $piecesPerBox > 1 && $boxPrice !== null && $boxPrice > 0;
                $hasCaseUnit = $casePieces > 1 && $casePrice !== null && $casePrice > 0;
                $qty         = (int) $p['quantity'];
                $reorder     = (int) $p['reorder_level'];
                $stockClass  = '';
            if ($qty === 0)              $stockClass = 'stock-out';
            elseif ($qty <= $reorder)    $stockClass = 'stock-low';
        ?>
          <div class="product-card <?= $stockClass ?>"
               data-id="<?= $p['product_id'] ?>"
               data-name="<?= htmlspecialchars($p['product_name']) ?>"
               data-price="<?= $regularPrice ?>"
               data-box-price="<?= $boxPrice !== null ? $boxPrice : '' ?>"
               data-case-price="<?= $casePrice !== null ? $casePrice : '' ?>"
               data-piece-discount="<?= $pieceDiscount ?>"
               data-box-discount="<?= $boxDiscount ?>"
               data-case-discount="<?= $caseDiscount ?>"
               data-photo="<?= htmlspecialchars((string) ($p['photo'] ?: '/assets/uploads/products/images.jpeg'), ENT_QUOTES, 'UTF-8') ?>"
               data-vat="<?= $p['vatable'] ?>"
               data-category="<?= $p['category_id'] ?>"
               data-category-name="<?= htmlspecialchars((string) ($p['category_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
               data-subcategory="<?= (int) ($p['subcategory_id'] ?? 0) ?>"
               data-subcategory-name="<?= htmlspecialchars((string) ($p['subcategory_name'] ?? 'General'), ENT_QUOTES, 'UTF-8') ?>"
               data-pieces-per-box="<?= $piecesPerBox ?>"
               data-boxes-per-case="<?= $boxesPerCase ?>"
               data-qty="<?= $qty ?>"
               data-reorder="<?= $reorder ?>"
               data-stock="<?= $qty ?>">

            <div class="qty-badge">0</div>

            <?php if ($qty === 0): ?>
                <div class="stock-badge out">Out of Stock</div>
            <?php elseif ($qty <= $reorder): ?>
                <div class="stock-badge low">Low Stock · <?= $qty ?> left</div>
            <?php endif; ?>

            <div class="card-media">
              <img src="<?= $p['photo'] ?: '/assets/uploads/products/images.jpeg' ?>"
                   alt="<?= htmlspecialchars($p['product_name']) ?>">
            </div>
            <div class="card-body">
              <div class="card-name"><?= htmlspecialchars($p['product_name']) ?></div>
              <div class="card-subcat"><?= htmlspecialchars((string) ($p['subcategory_name'] ?? 'General')) ?></div>
              <div class="card-price">₱<?= number_format((float)$price, 2) ?></div>
              <div class="card-stock-line">
                <span class="card-unit-note"><?= $qty ?> pcs available</span>
              </div>
              <div class="qty-row">
                <button class="qty-btn decrease">−</button>
                <span class="qty-display qty">0</span>
                <button class="qty-btn increase" <?= $qty === 0 ? 'disabled' : '' ?>>+</button>
              </div>
              <?php if ($hasBoxUnit || $hasCaseUnit): ?>
                <button type="button" class="card-order-pill">Tap to Select Unit</button>
              <?php endif; ?>
              <div class="card-hover-hint" aria-hidden="true"></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Pagination -->
      <div class="pagination-bar" id="productPagination"></div>
    </div>
      </div>
    </div>

  </div>

  <!-- ============================================================
       RIGHT PANEL — CART
       ============================================================ -->
  <div class="pos-right">

    <div class="cart-header">
      <div class="cart-header-top">
        <span class="cart-title">Order Summary</span>
        <span class="cashier-badge">Cashier Mode</span>
      </div>
      <div class="cart-count-bar">
        <div class="dot"></div>
        <span id="cartCountLabel">No items in cart</span>
      </div>
      <div id="cartRecoveryNote" class="small text-muted mt-2" hidden></div>
    </div>

    <div class="cart-list" id="cart-items">
      <div class="cart-empty">
        <div class="cart-empty-icon">🛒</div>
        <p>Cart is empty</p>
      </div>
    </div>

    <div class="cart-summary">
      <div class="summary-line">
        <span class="label">Subtotal</span>
        <span class="value" id="subtotal">₱0.00</span>
      </div>
      <div class="summary-line">
        <span class="label">Discount</span>
        <span class="value discount" id="total-discount">−₱0.00</span>
      </div>
      <div class="summary-line">
        <span class="label">VAT (<?= htmlspecialchars(number_format($vatRate, 2), ENT_QUOTES, 'UTF-8') ?>%)</span>
        <span class="value" id="vat">₱0.00</span>
      </div>

      <div class="summary-divider"></div>

      <div class="summary-total">
        <span class="total-label">Grand Total</span>
        <span class="total-value" id="grand-total">₱0.00</span>
      </div>

      <button class="checkout-btn" id="checkoutBtn">Proceed to Checkout</button>
      <button class="receipt-btn" id="printReceiptBtn">🖨 Print Receipt</button>
    </div>

  </div>

</div>
</main>

<!-- ============================================================
     CHECKOUT MODAL
     ============================================================ -->
<div class="print-modal-overlay" id="checkoutModalOverlay">
  <div class="print-modal" style="width:420px;">

    <div class="print-modal-header">
      <span class="print-modal-title">🧾 Checkout</span>
      <button class="print-modal-close" id="checkoutModalClose">✕</button>
    </div>

    <!-- Order summary inside modal -->
    <div style="background:var(--bg-raised);border-radius:var(--radius-sm);padding:12px 14px;margin-bottom:16px;border:1px solid var(--border);">
      <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-secondary);margin-bottom:6px;">
        <span>Subtotal</span><span id="co-subtotal">₱0.00</span>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-secondary);margin-bottom:6px;">
        <span>Discount</span><span id="co-discount" style="color:var(--accent-red);">−₱0.00</span>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-secondary);margin-bottom:6px;">
        <span>VAT (<?= htmlspecialchars(number_format($vatRate, 2), ENT_QUOTES, 'UTF-8') ?>%)</span><span id="co-vat">₱0.00</span>
      </div>
      <div style="height:1px;background:var(--border);margin:8px 0;"></div>
      <div style="display:flex;justify-content:space-between;align-items:baseline;">
        <span style="font-family:var(--font-display);font-size:14px;color:var(--text-secondary);">Grand Total</span>
        <span id="co-total" style="font-family:var(--font-display);font-size:22px;font-weight:600;color:var(--accent);">₱0.00</span>
      </div>
    </div>

    <!-- Payment method -->
    <div class="print-field">
      <label>Payment Method</label>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:4px;" id="paymentMethods">
        <button class="pay-method-btn active" data-method="cash">💵 Cash</button>
        <button class="pay-method-btn" data-method="gcash">📱 GCash</button>
        <button class="pay-method-btn" data-method="card">💳 Card</button>
        <button class="pay-method-btn" data-method="other">🔖 Other</button>
      </div>
    </div>

    <!-- Cash tendered (shown only for cash) -->
    <div class="print-field" id="cashTenderedField">
      <label>Cash Tendered</label>
      <input type="number" id="cashTendered" placeholder="0.00" min="0" step="0.01">
      <div style="display:flex;justify-content:space-between;margin-top:6px;font-size:12px;">
        <span style="color:var(--text-secondary);">Change</span>
        <span id="changeAmount" style="color:var(--accent-green);font-weight:700;">₱0.00</span>
      </div>
    </div>

    <!-- Status -->
    <div class="print-status" id="checkoutStatus">
      <div class="status-dot"></div>
      <span id="checkoutStatusText">Ready to complete sale</span>
    </div>

    <button class="print-now-btn" id="confirmCheckoutBtn">✓ Confirm & Save Sale</button>

  </div>
</div>

<!-- ============================================================
     PRINT PROMPT MODAL — shown after successful checkout
     ============================================================ -->
<div class="print-modal-overlay" id="printPromptOverlay">
  <div class="print-modal" style="width:340px; text-align:center;">

    <div style="font-size:42px; margin-bottom:12px;">🧾</div>

    <div class="print-modal-title" style="font-size:18px; margin-bottom:8px;">
      Sale Saved!
    </div>

    <p style="font-size:13px; color:var(--text-secondary); margin-bottom:20px; line-height:1.6;">
      Would you like to print<br>the receipt for this transaction?
    </p>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
      <button class="print-now-btn" id="printPromptYes">
        🖨 Yes, Print
      </button>
      <button class="receipt-btn" id="printPromptNo" style="margin-top:0;">
        Skip
      </button>
    </div>

  </div>
</div>

<div class="print-modal-overlay" id="productUnitModalOverlay">
  <div class="print-modal product-unit-modal">
    <div class="print-modal-header product-unit-modal-header">
      <span class="print-modal-title" id="productUnitModalTitle">Select Unit</span>
      <button class="print-modal-close" id="productUnitModalClose">✕</button>
    </div>

    <div class="product-unit-modal-body">
      <div class="product-unit-summary" id="productUnitSummary">
        <div class="product-unit-summary-media">
          <img src="" alt="" id="productUnitModalImage">
        </div>
        <div class="product-unit-summary-copy">
          <div class="product-unit-summary-name" id="productUnitModalName"></div>
          <div class="product-unit-summary-subcat" id="productUnitModalSubcat"></div>
          <div class="product-unit-summary-stock" id="productUnitModalStock"></div>
        </div>
      </div>

      <div class="product-unit-options" id="productUnitOptions"></div>

      <div class="product-unit-quantity">
        <span class="product-unit-quantity-label">Quantity</span>
        <div class="product-unit-stepper">
          <button type="button" class="qty-btn" id="productUnitQtyDecrease">−</button>
          <span class="product-unit-stepper-value" id="productUnitQtyValue">1</span>
          <button type="button" class="qty-btn" id="productUnitQtyIncrease">+</button>
        </div>
        <div class="product-unit-total">
          <span>Total</span>
          <strong id="productUnitModalTotal">₱0.00</strong>
        </div>
      </div>

      <div class="card-packaging-indicator product-unit-packaging" id="productUnitPackaging"></div>

      <button class="print-now-btn" id="productUnitAddToCartBtn" type="button">Add to Cart</button>
    </div>
  </div>
</div>

<div class="print-modal-overlay" id="printModalOverlay">
  <div class="print-modal">

    <div class="print-modal-header">
      <span class="print-modal-title">🖨 Print Receipt</span>
      <button class="print-modal-close" id="printModalClose">✕</button>
    </div>

    <div class="print-field">
      <label>Store Name</label>
      <input type="text" id="pStoreName" placeholder="e.g. My Store" value="<?= htmlspecialchars((string) ($posConfig['store_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="print-field">
      <label>Address</label>
      <input type="text" id="pAddress" placeholder="e.g. 123 Main St, Manila" value="<?= htmlspecialchars((string) ($posConfig['store_address'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="print-field">
      <label>Phone</label>
      <input type="text" id="pPhone" placeholder="e.g. 09XX-XXX-XXXX" value="<?= htmlspecialchars((string) ($posConfig['store_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="print-field">
      <label>Cashier Name</label>
      <input type="text" id="pCashier" placeholder="e.g. Juan Dela Cruz" value="<?= htmlspecialchars($defaultCashier, ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div class="print-modal-divider"></div>

    <div class="print-status" id="printStatus">
      <div class="status-dot"></div>
      <span id="printStatusText">Ready to print</span>
    </div>

    <button class="print-now-btn" id="printNowBtn">Print Receipt</button>

  </div>
</div>

<?php require __DIR__ . '/../components/js_script.php'; ?>

<script>
window.POS_CONFIG = {
    storeName: <?= json_encode((string) ($posConfig['store_name'] ?? 'My Store')) ?>,
    address: <?= json_encode((string) ($posConfig['store_address'] ?? '')) ?>,
    phone: <?= json_encode((string) ($posConfig['store_phone'] ?? '')) ?>,
    cashier: <?= json_encode($defaultCashier) ?>,
    vatRate: <?= json_encode($vatRate) ?>,
    logo: <?= json_encode(PosConfigController::logoUrl($posConfig['logo'] ?? null)) ?>,
    userId: <?= json_encode((int) ($_SESSION['user_id'] ?? 0)) ?>,
};
window.POS_SUBCATEGORIES = <?= json_encode(array_values(array_map(static function (array $subcategory): array {
    return [
        'subcategory_id' => (int) ($subcategory['subcategory_id'] ?? 0),
        'category_id' => (int) ($subcategory['category_id'] ?? 0),
        'subcategory_name' => (string) ($subcategory['subcategory_name'] ?? ''),
    ];
}, $subcategories)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="/inventory_system/assets/js/pos.js"></script>
</body>
</html>

