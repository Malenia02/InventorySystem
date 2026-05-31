<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../middleware/Middleware.php';
require_once __DIR__ . '/../controllers/CategoryController.php';
require_once __DIR__ . '/../controllers/SubcategoryController.php';
require_once __DIR__ . '/../controllers/ProductController.php';
require_once __DIR__ . '/../controllers/PosConfigController.php';
require_once __DIR__ . '/../controllers/ShiftClosingController.php';

Middleware::auth()->role(['admin', 'cashier']);

$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
$isAdmin       = strtolower((string) ($_SESSION['role'] ?? '')) === 'admin';

// Shift guard — cashiers must have an open shift
if (!$isAdmin && !ShiftClosingController::hasOpenShiftForToday($conn, $sessionUserId)) {
    $_SESSION['shift_closing_flash'] = [
        'error' => 'Start your shift first before opening POS.',
    ];
    header('Location: /inventory_system/shift_closing.php');
    exit;
}

// ── CSRF token (exposed via <meta> tag so JS checkout fetch can read it) ──────
$csrfToken = Middleware::generateCsrfToken();

// ── Data loading ──────────────────────────────────────────────────────────────
try {
    $categories    = CategoryController::all($conn, 'active');
    $subcategories = SubcategoryController::all($conn, null, 'active');
    $products      = ProductController::activeProductsForPOS($conn);
    $posConfig     = PosConfigController::get($conn);
} catch (Throwable $e) {
    error_log('[pos.php] ' . $e->getMessage());
    $categories    = [];
    $subcategories = [];
    $products      = [];
    $posConfig     = [];
    $dataError     = 'Failed to load POS data. Please refresh.';
}

$vatRate        = (float) ($posConfig['tax_rate'] ?? 12);
$defaultCashier = trim(
    (string) ($_SESSION['first_name'] ?? '') . ' ' . (string) ($_SESSION['last_name'] ?? '')
);
$defaultCashier = $defaultCashier !== ''
    ? $defaultCashier
    : (string) ($_SESSION['username'] ?? 'Cashier');

// ── Category product counts (for sidebar badges) ──────────────────────────────
$categoryProductCounts = [];
$totalProductCount     = 0;
foreach ($products as $product) {
    $cid = (int) ($product['category_id'] ?? 0);
    if ($cid > 0) {
        $categoryProductCounts[$cid] = ($categoryProductCounts[$cid] ?? 0) + 1;
    }
    $totalProductCount++;
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function categoryIconClass(string $name): string
{
    $n = strtolower(trim($name));
    return match (true) {
        str_contains($n, 'beverage'),
        str_contains($n, 'drink'),
        str_contains($n, 'water'),
        str_contains($n, 'juice')   => 'bi-cup-straw',
        str_contains($n, 'snack'),
        str_contains($n, 'chips'),
        str_contains($n, 'biscuit') => 'bi-emoji-smile',
        str_contains($n, 'canned')  => 'bi-box-seam',
        str_contains($n, 'rice'),
        str_contains($n, 'grain')   => 'bi-flower1',
        str_contains($n, 'bread'),
        str_contains($n, 'bakery')  => 'bi-basket3',
        default                     => 'bi-grid',
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

    <!--
        SECURITY: CSRF token in meta tag so JS fetch calls can read it.
        pos.js reads: document.querySelector('meta[name="csrf-token"]').content
    -->
    <meta name="csrf-token" content="<?= e($csrfToken) ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="/inventory_system/assets/css/pos.css">
</head>
<body>

<?php
require __DIR__ . '/../components/header.php';
require __DIR__ . '/../components/sidebar.php';
?>

<main id="main" class="main pos-page">

<?php if (!empty($dataError)): ?>
    <div style="position:fixed;top:0;left:0;right:0;z-index:9999;background:#7f1d1d;color:#fecaca;padding:10px 20px;font-size:13px;text-align:center;">
        <i class="bi bi-exclamation-triangle me-2"></i><?= e($dataError) ?>
        <a href="javascript:location.reload()" style="color:#fecaca;margin-left:12px;text-decoration:underline;">Reload</a>
    </div>
<?php endif; ?>

<div class="pos-wrapper">

    <!-- ================================================================
         LEFT PANEL — PRODUCT BROWSER
         ================================================================ -->
    <div class="pos-left">

        <!-- Top bar -->
        <div class="left-topbar">
            <div class="pos-brand">
                <div class="pos-brand-icon">🛍</div>
                <span class="pos-brand-name">Point of Sale</span>
            </div>

            <div class="search-box">
                <i class="bi bi-search search-icon"></i>
                <input type="text" id="productSearch" placeholder="Search products…" autocomplete="off">
            </div>

            <div class="search-box">
                <i class="bi bi-upc-scan search-icon"></i>
                <input type="text" id="barcodeScanInput"
                       placeholder="Scan barcode / SKU and press Enter"
                       autocomplete="off">
            </div>

            <div class="time-pill" id="timePill">Loading…</div>
        </div>

        <!-- Browser: category sidebar + product grid -->
        <div class="pos-browser">

            <!-- Category sidebar -->
            <aside class="cat-sidebar" id="categoryBar">
                <div class="cat-sidebar-title">Categories</div>

                <button class="cat-sidebar-item cat-btn active"
                        data-id="all" data-name="All Categories">
                    <span class="cat-sidebar-icon"><i class="bi bi-grid"></i></span>
                    <span class="cat-sidebar-label">All</span>
                    <span class="cat-sidebar-count"><?= $totalProductCount ?></span>
                </button>

                <?php foreach ($categories as $c):
                    $cid   = (int) ($c['category_id'] ?? 0);
                    $cname = (string) ($c['category_name'] ?? 'Category');
                ?>
                    <button class="cat-sidebar-item cat-btn"
                            data-id="<?= $cid ?>"
                            data-name="<?= e($cname) ?>">
                        <span class="cat-sidebar-icon">
                            <i class="bi <?= categoryIconClass($cname) ?>"></i>
                        </span>
                        <span class="cat-sidebar-label"><?= e($cname) ?></span>
                        <span class="cat-sidebar-count">
                            <?= (int) ($categoryProductCounts[$cid] ?? 0) ?>
                        </span>
                    </button>
                <?php endforeach; ?>
            </aside>

            <!-- Content area -->
            <div class="cat-content">

                <!-- Breadcrumb row -->
                <div class="pos-toprow">
                    <div class="pos-breadcrumb" id="posBrowserTitle">All Categories</div>
                    <div class="pos-product-count" id="posProductCount">
                        <?= $totalProductCount ?> items
                    </div>
                </div>

                <!-- Subcategory pill bar -->
                <div class="subcat-bar is-hidden" id="subcategorySection">
                    <div class="subcat-heading">Subcategory</div>
                    <div class="subcat-shell" id="subcategoryShell">
                        <button class="subcat-arrow" id="subcategoryPrevBtn"
                                type="button" aria-label="Previous">
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        <div class="subcat-row" id="subcategoryBar">
                            <button class="sub-pill subcat-btn active" data-id="all">All</button>
                        </div>
                        <button class="subcat-arrow" id="subcategoryNextBtn"
                                type="button" aria-label="Next">
                            <i class="bi bi-chevron-right"></i>
                        </button>
                    </div>
                </div>

                <!-- Product grid + pagination -->
                <div class="product-area">
                    <div class="product-grid" id="product-grid">
                        <?php foreach ($products as $p):
                            $regularPrice  = (float) ($p['price'] ?? 0);
                            $pieceDiscount = max(0.0, min(100.0, (float) ($p['sale_price']      ?? 0)));
                            $boxDiscount   = max(0.0, min(100.0, (float) ($p['box_sale_price']  ?? 0)));
                            $caseDiscount  = max(0.0, min(100.0, (float) ($p['case_sale_price'] ?? 0)));
                            $displayPrice  = $pieceDiscount > 0
                                ? $regularPrice * (1 - $pieceDiscount / 100)
                                : $regularPrice;

                            $boxPrice  = (isset($p['box_price'])  && $p['box_price']  !== null && $p['box_price']  !== '')
                                ? (float) $p['box_price']  : null;
                            $casePrice = (isset($p['case_price']) && $p['case_price'] !== null && $p['case_price'] !== '')
                                ? (float) $p['case_price'] : null;

                            $piecesPerBox = max(1, (int) ($p['pieces_per_box']  ?? 1));
                            $boxesPerCase = max(1, (int) ($p['boxes_per_case']  ?? 1));
                            $casePieces   = $piecesPerBox * $boxesPerCase;

                            $catNameLower    = strtolower((string) ($p['category_name'] ?? ''));
                            $isBeverage      = str_contains($catNameLower, 'beverage');
                            $hasBoxUnit      = !$isBeverage && $piecesPerBox > 1 && $boxPrice !== null && $boxPrice > 0;
                            $hasCaseUnit     = $casePieces > 1 && $casePrice !== null && $casePrice > 0;
                            $qty             = (int) ($p['quantity'] ?? 0);
                            $reorder         = (int) ($p['reorder_level'] ?? 0);

                            $stockClass = '';
                            if ($qty === 0)         $stockClass = 'stock-out';
                            elseif ($reorder > 0 && $qty <= $reorder) $stockClass = 'stock-low';
                        ?>
                            <div class="product-card <?= $stockClass ?>"
                                 data-id="<?= (int) $p['product_id'] ?>"
                                 data-name="<?= e($p['product_name']) ?>"
                                 data-sku="<?= e((string) ($p['sku'] ?? '')) ?>"
                                 data-price="<?= $regularPrice ?>"
                                 data-box-price="<?= $boxPrice !== null ? $boxPrice : '' ?>"
                                 data-case-price="<?= $casePrice !== null ? $casePrice : '' ?>"
                                 data-piece-discount="<?= $pieceDiscount ?>"
                                 data-box-discount="<?= $boxDiscount ?>"
                                 data-case-discount="<?= $caseDiscount ?>"
                                 data-photo="<?= e((string) ($p['photo'] ?: '/inventory_system/assets/img/card.jpg')) ?>"
                                 data-vat="<?= (int) ($p['vatable'] ?? 0) ?>"
                                 data-category="<?= (int) ($p['category_id'] ?? 0) ?>"
                                 data-category-name="<?= e((string) ($p['category_name'] ?? '')) ?>"
                                 data-subcategory="<?= (int) ($p['subcategory_id'] ?? 0) ?>"
                                 data-subcategory-name="<?= e((string) ($p['subcategory_name'] ?? 'General')) ?>"
                                 data-pieces-per-box="<?= $piecesPerBox ?>"
                                 data-boxes-per-case="<?= $boxesPerCase ?>"
                                 data-qty="<?= $qty ?>"
                                 data-reorder="<?= $reorder ?>"
                                 data-stock="<?= $qty ?>">

                                <!-- Qty badge (shown when item is in cart) -->
                                <div class="qty-badge">0</div>

                                <!-- Stock overlays -->
                                <?php if ($qty === 0): ?>
                                    <div class="stock-badge out">Out of Stock</div>
                                <?php elseif ($reorder > 0 && $qty <= $reorder): ?>
                                    <div class="stock-badge low">Low · <?= $qty ?> left</div>
                                <?php endif; ?>

                                <!-- Product image -->
                                <div class="card-media">
                                    <img src="<?= e((string) ($p['photo'] ?: '/inventory_system/assets/img/card.jpg')) ?>"
                                         alt="<?= e($p['product_name']) ?>"
                                         loading="lazy">
                                </div>

                                <!-- Product info -->
                                <div class="card-body">
                                    <div class="card-name"><?= e($p['product_name']) ?></div>
                                    <div class="card-subcat">
                                        <?= e((string) ($p['subcategory_name'] ?? 'General')) ?>
                                    </div>
                                    <div class="card-price">₱<?= number_format($displayPrice, 2) ?></div>
                                    <div class="card-stock-line">
                                        <span class="card-unit-note"><?= $qty ?> pcs</span>
                                        <?php if ($pieceDiscount > 0): ?>
                                            <span class="card-discount-tag">-<?= number_format($pieceDiscount, 0) ?>%</span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Qty stepper -->
                                    <div class="qty-row">
                                        <button class="qty-btn decrease" type="button">−</button>
                                        <span class="qty-display qty">0</span>
                                        <button class="qty-btn increase" type="button"
                                                <?= $qty === 0 ? 'disabled' : '' ?>>+</button>
                                    </div>

                                    <!-- Multi-unit selector pill -->
                                    <?php if ($hasBoxUnit || $hasCaseUnit): ?>
                                        <button type="button" class="card-order-pill">
                                            <i class="bi bi-layers"></i> Select unit
                                        </button>
                                    <?php endif; ?>

                                    <div class="card-hover-hint" aria-hidden="true"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div><!-- /#product-grid -->

                    <div class="pagination-bar" id="productPagination"></div>
                </div><!-- /.product-area -->
            </div><!-- /.cat-content -->
        </div><!-- /.pos-browser -->
    </div><!-- /.pos-left -->


    <!-- ================================================================
         RIGHT PANEL — CART
         ================================================================ -->
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
            <div id="cartRecoveryNote" class="cart-recovery-note" hidden></div>
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
                <span class="label">VAT (<?= e(number_format($vatRate, 2)) ?>%)</span>
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

    </div><!-- /.pos-right -->

</div><!-- /.pos-wrapper -->
</main>


<!-- ================================================================
     CHECKOUT MODAL
     ================================================================ -->
<div class="print-modal-overlay" id="checkoutModalOverlay">
    <div class="print-modal" style="width:420px;">

        <div class="print-modal-header">
            <span class="print-modal-title">🧾 Checkout</span>
            <button class="print-modal-close" id="checkoutModalClose">✕</button>
        </div>

        <!-- Order summary inside modal -->
        <div class="co-summary-card">
            <div class="co-summary-line">
                <span>Subtotal</span><span id="co-subtotal">₱0.00</span>
            </div>
            <div class="co-summary-line">
                <span>Discount</span>
                <span id="co-discount" class="co-discount">−₱0.00</span>
            </div>
            <div class="co-summary-line">
                <span>VAT (<?= e(number_format($vatRate, 2)) ?>%)</span>
                <span id="co-vat">₱0.00</span>
            </div>
            <div class="co-summary-divider"></div>
            <div class="co-summary-total">
                <span>Grand Total</span>
                <span id="co-total">₱0.00</span>
            </div>
        </div>

        <!-- Payment method -->
        <div class="print-field">
            <label>Payment Method</label>
            <div class="pay-method-grid" id="paymentMethods">
                <button class="pay-method-btn active" data-method="cash">💵 Cash</button>
                <button class="pay-method-btn" data-method="gcash">📱 GCash</button>
                <button class="pay-method-btn" data-method="card">💳 Card</button>
                <button class="pay-method-btn" data-method="other">🔖 Other</button>
            </div>
        </div>

        <!-- Cash tendered -->
        <div class="print-field" id="cashTenderedField">
            <label>Cash Tendered</label>
            <input type="number" id="cashTendered" placeholder="0.00" min="0" step="0.01">
            <div class="cash-change-row">
                <span>Change</span>
                <span id="changeAmount" class="change-amount">₱0.00</span>
            </div>
        </div>

        <!-- Status -->
        <div class="print-status" id="checkoutStatus">
            <div class="status-dot"></div>
            <span id="checkoutStatusText">Ready to complete sale</span>
        </div>

        <button class="print-now-btn" id="confirmCheckoutBtn">✓ Confirm &amp; Save Sale</button>
    </div>
</div>


<!-- ================================================================
     PRINT PROMPT — shown after successful checkout
     ================================================================ -->
<div class="print-modal-overlay" id="printPromptOverlay">
    <div class="print-modal" style="width:340px;text-align:center;">
        <div style="font-size:42px;margin-bottom:12px;">🧾</div>
        <div class="print-modal-title" style="font-size:18px;margin-bottom:8px;">Sale Saved!</div>
        <p style="font-size:13px;color:var(--text-secondary);margin-bottom:20px;line-height:1.6;">
            Would you like to print<br>the receipt for this transaction?
        </p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <button class="print-now-btn" id="printPromptYes">🖨 Yes, Print</button>
            <button class="receipt-btn" id="printPromptNo" style="margin-top:0;">Skip</button>
        </div>
        <button class="receipt-btn" id="printPromptDetails" style="margin-top:10px;width:100%;">
            View sale breakdown
        </button>
    </div>
</div>


<!-- ================================================================
     SALE BREAKDOWN MODAL
     ================================================================ -->
<div class="print-modal-overlay" id="saleBreakdownOverlay">
    <div class="print-modal" style="width:min(720px,94vw);">
        <div class="print-modal-header">
            <div>
                <div class="print-modal-title" id="saleBreakdownTitle">Sale Breakdown</div>
                <p style="margin:4px 0 0;color:var(--text-secondary);font-size:13px;" id="saleBreakdownMeta"></p>
            </div>
            <button class="print-modal-close" id="saleBreakdownClose">close</button>
        </div>
        <div id="saleBreakdownBody" style="margin-top:14px;"></div>
    </div>
</div>


<!-- ================================================================
     PRODUCT UNIT SELECTOR MODAL
     ================================================================ -->
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
                    <div class="product-unit-summary-name"  id="productUnitModalName"></div>
                    <div class="product-unit-summary-subcat" id="productUnitModalSubcat"></div>
                    <div class="product-unit-summary-stock"  id="productUnitModalStock"></div>
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
            <div class="card-packaging-indicator product-unit-packaging"
                 id="productUnitPackaging"></div>
            <button class="print-now-btn" id="productUnitAddToCartBtn" type="button">
                Add to Cart
            </button>
        </div>
    </div>
</div>


<!-- ================================================================
     PRINT RECEIPT MODAL
     ================================================================ -->
<div class="print-modal-overlay" id="printModalOverlay">
    <div class="print-modal">
        <div class="print-modal-header">
            <span class="print-modal-title">🖨 Print Receipt</span>
            <button class="print-modal-close" id="printModalClose">✕</button>
        </div>
        <div class="print-field">
            <label>Store Name</label>
            <input type="text" id="pStoreName"
                   value="<?= e((string) ($posConfig['store_name'] ?? '')) ?>"
                   placeholder="e.g. My Store">
        </div>
        <div class="print-field">
            <label>Address</label>
            <input type="text" id="pAddress"
                   value="<?= e((string) ($posConfig['store_address'] ?? '')) ?>"
                   placeholder="e.g. 123 Main St, Manila">
        </div>
        <div class="print-field">
            <label>Phone</label>
            <input type="text" id="pPhone"
                   value="<?= e((string) ($posConfig['store_phone'] ?? '')) ?>"
                   placeholder="e.g. 09XX-XXX-XXXX">
        </div>
        <div class="print-field">
            <label>Cashier Name</label>
            <input type="text" id="pCashier"
                   value="<?= e($defaultCashier) ?>"
                   placeholder="e.g. Juan Dela Cruz">
        </div>
        <div class="print-modal-divider"></div>
        <div class="print-status" id="printStatus">
            <div class="status-dot"></div>
            <span id="printStatusText">Ready to print</span>
        </div>
        <button class="print-now-btn" id="printNowBtn">Print Receipt</button>
    </div>
</div>


<!-- ================================================================
     INLINE CONFIG FOR JS
     All user-controlled strings are json_encode'd (XSS-safe).
     ================================================================ -->
<?php require __DIR__ . '/../components/js_script.php'; ?>

<script>
window.POS_CONFIG = {
    storeName : <?= json_encode((string) ($posConfig['store_name']    ?? 'My Store'), JSON_UNESCAPED_UNICODE) ?>,
    address   : <?= json_encode((string) ($posConfig['store_address'] ?? ''),         JSON_UNESCAPED_UNICODE) ?>,
    phone     : <?= json_encode((string) ($posConfig['store_phone']   ?? ''),         JSON_UNESCAPED_UNICODE) ?>,
    cashier   : <?= json_encode($defaultCashier,                                      JSON_UNESCAPED_UNICODE) ?>,
    vatRate   : <?= json_encode($vatRate) ?>,
    logo      : <?= json_encode(PosConfigController::logoUrl($posConfig['logo'] ?? null)) ?>,
    userId    : <?= json_encode($sessionUserId) ?>,
    csrfToken : <?= json_encode($csrfToken) ?>,
};

/*
 * Subcategories for the JS filter bar.
 * Using json_encode with JSON_HEX_TAG prevents any injection even if
 * a subcategory name contains <script> characters.
 */
window.POS_SUBCATEGORIES = <?= json_encode(
    array_values(array_map(static fn(array $s): array => [
        'subcategory_id'   => (int) ($s['subcategory_id'] ?? 0),
        'category_id'      => (int) ($s['category_id']    ?? 0),
        'subcategory_name' => (string) ($s['subcategory_name'] ?? ''),
    ], $subcategories)),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG
) ?>;
</script>
<script src="/inventory_system/assets/js/pos.js"></script>
</body>
</html>