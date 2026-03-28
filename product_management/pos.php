<?php
// Load config first (this defines $conn)
require_once $_SERVER['DOCUMENT_ROOT'].'/inventory_system/config/config.php';

// Load controllers after config
require_once $_SERVER['DOCUMENT_ROOT'].'/inventory_system/controllers/CategoryController.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/inventory_system/controllers/ProductController.php';

if (!isset($conn)) {
    die('❌ $conn is NOT defined. config.php did not load correctly.');
}

$categories = CategoryController::all($conn, $table_categories);
$products   = ProductController::activeProductsForPOS($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>POS — Point of Sale</title>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/components/head.php'; ?>

<!-- Google Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">

<!-- POS Styles -->
<link rel="stylesheet" href="/inventory_system/assets/css/pos-light.css">

</head>
<body>

<?php
require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/components/header.php';
require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/components/sidebar.php';
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

    <!-- Category bar -->
    <div class="category-section">
      <div class="category-bar-wrapper">
        <button class="cat-arrow" id="categoryPrev">&#8592;</button>
        <div class="category-bar" id="categoryBar">
          <button class="cat-btn active" data-id="all">All</button>
          <?php foreach ($categories as $c): ?>
            <button class="cat-btn" data-id="<?= $c['category_id'] ?>">
              <?= htmlspecialchars($c['category_name']) ?>
            </button>
          <?php endforeach; ?>
        </div>
        <button class="cat-arrow" id="categoryNext">&#8594;</button>
      </div>
    </div>

    <!-- Product grid -->
    <div class="product-area">
      <div class="product-grid" id="product-grid">
        <?php foreach ($products as $p):
            $price       = $p['on_sale'] ? $p['sale_price'] : $p['price'];
            $qty         = (int) $p['quantity'];
            $reorder     = (int) $p['reorder_level'];
            $stockClass  = '';
            if ($qty === 0)              $stockClass = 'stock-out';
            elseif ($qty <= $reorder)    $stockClass = 'stock-low';
        ?>
          <div class="product-card <?= $stockClass ?>"
               data-id="<?= $p['product_id'] ?>"
               data-name="<?= htmlspecialchars($p['product_name']) ?>"
               data-price="<?= $price ?>"
               data-vat="<?= $p['vatable'] ?>"
               data-category="<?= $p['category_id'] ?>"
               data-qty="<?= $qty ?>"
               data-reorder="<?= $reorder ?>"
               data-stock="<?= $qty ?>">

            <div class="qty-badge">0</div>

            <?php if ($qty === 0): ?>
                <div class="stock-badge out">Out of Stock</div>
            <?php elseif ($qty <= $reorder): ?>
                <div class="stock-badge low">Low Stock · <?= $qty ?> left</div>
            <?php endif; ?>

            <img src="<?= $p['photo'] ?: '/assets/uploads/products/images.jpeg' ?>"
                 alt="<?= htmlspecialchars($p['product_name']) ?>">
            <div class="card-body">
              <div class="card-name"><?= htmlspecialchars($p['product_name']) ?></div>
              <div class="card-price">₱<?= number_format($price, 2) ?></div>
              <div class="qty-row">
                <button class="qty-btn decrease">−</button>
                <span class="qty-display qty">0</span>
                <button class="qty-btn increase" <?= $qty === 0 ? 'disabled' : '' ?>>+</button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Pagination -->
      <div class="pagination-bar" id="productPagination"></div>
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
        <span class="label">VAT (12%)</span>
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
        <span>VAT (12%)</span><span id="co-vat">₱0.00</span>
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
<div class="print-modal-overlay" id="printModalOverlay">
  <div class="print-modal">

    <div class="print-modal-header">
      <span class="print-modal-title">🖨 Print Receipt</span>
      <button class="print-modal-close" id="printModalClose">✕</button>
    </div>

    <div class="print-field">
      <label>Store Name</label>
      <input type="text" id="pStoreName" placeholder="e.g. My Store">
    </div>
    <div class="print-field">
      <label>Address</label>
      <input type="text" id="pAddress" placeholder="e.g. 123 Main St, Manila">
    </div>
    <div class="print-field">
      <label>Phone</label>
      <input type="text" id="pPhone" placeholder="e.g. 09XX-XXX-XXXX">
    </div>
    <div class="print-field">
      <label>Cashier Name</label>
      <input type="text" id="pCashier" placeholder="e.g. Juan Dela Cruz">
    </div>

    <div class="print-modal-divider"></div>

    <div class="print-status" id="printStatus">
      <div class="status-dot"></div>
      <span id="printStatusText">Ready to print</span>
    </div>

    <button class="print-now-btn" id="printNowBtn">Print Receipt</button>

  </div>
</div>

<?php require $_SERVER['DOCUMENT_ROOT'].'/inventory_system/components/js_script.php'; ?>

<script>
// ================================================================
//  CLOCK
// ================================================================
function updateClock() {
    const now  = new Date();
    const opts = { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' };
    document.getElementById('timePill').innerHTML =
        now.toLocaleDateString('en-PH', opts).replace(',', ' &mdash; <span>') + '</span>';
}
updateClock();
setInterval(updateClock, 10000);

// ================================================================
//  DATA
// ================================================================
const productCards = Array.from(document.querySelectorAll('.product-card'));
const cartItemsEl  = document.getElementById('cart-items');
let cart = {};

// ================================================================
//  QTY CONTROLS ON PRODUCT CARDS
// ================================================================
productCards.forEach(card => {
    const name       = card.dataset.name;
    const price      = parseFloat(card.dataset.price);
    const vatable    = Number(card.dataset.vat) === 1;
    const discount   = parseFloat(card.dataset.discount) || 0;
    const product_id = parseInt(card.dataset.id);
    const maxStock   = parseInt(card.dataset.stock) || 0;

    card.querySelector('.increase').addEventListener('click', e => {
        e.stopPropagation();
        if (card.classList.contains('stock-out')) return;

        // Cap at available stock
        const currentQty = cart[name]?.qty || 0;
        if (currentQty >= maxStock) {
            // Shake the button to indicate limit reached
            const btn = card.querySelector('.increase');
            btn.classList.add('shake-limit');
            setTimeout(() => btn.classList.remove('shake-limit'), 500);
            return;
        }

        changeProductQty(card, name, price, vatable, discount, product_id, 1);
    });

    card.querySelector('.decrease').addEventListener('click', e => {
        e.stopPropagation();
        changeProductQty(card, name, price, vatable, discount, product_id, -1);
    });
});

function changeProductQty(card, name, price, vatable, discount, product_id, delta) {
    if (!cart[name]) cart[name] = { qty: 0, price, vatable, discount, product_id };
    const newQty = cart[name].qty + delta;

    if (newQty <= 0) {
        delete cart[name];
        card.querySelector('.qty').textContent       = 0;
        card.querySelector('.qty-badge').textContent = 0;
        card.classList.remove('has-qty');
    } else {
        cart[name].qty = newQty;
        card.querySelector('.qty').textContent       = newQty;
        card.querySelector('.qty-badge').textContent = newQty;
        card.classList.add('has-qty');
    }

    renderCart();
}

// ================================================================
//  RENDER CART
// ================================================================
function renderCart() {
    cartItemsEl.innerHTML = '';
    let subtotal = 0, totalDiscount = 0, totalVAT = 0, itemCount = 0;
    const keys = Object.keys(cart);

    if (keys.length === 0) {
        cartItemsEl.innerHTML = `
            <div class="cart-empty">
                <div class="cart-empty-icon">🛒</div>
                <p>Cart is empty</p>
            </div>`;
        document.getElementById('cartCountLabel').textContent = 'No items in cart';
    } else {
        keys.forEach(item => {
            const { qty, price, vatable, discount } = cart[item];
            const discountedPrice = price * (1 - discount / 100);
            const lineTotal       = qty * discountedPrice;
            subtotal      += lineTotal;
            totalDiscount += qty * (price - discountedPrice);
            if (vatable) totalVAT += lineTotal * 0.12;
            itemCount     += qty;

            const div = document.createElement('div');
            div.className = 'cart-item';
            div.innerHTML = `
                <div class="cart-item-row">
                    <span class="cart-item-name">${item}</span>
                    <span class="cart-item-line-total">₱${lineTotal.toFixed(2)}</span>
                </div>
                <div class="cart-item-meta">
                    ₱${price.toFixed(2)} / unit ${discount > 0 ? `· -${discount}% off` : ''}
                </div>
                <div class="cart-item-controls">
                    <button class="cart-ctrl-btn cart-decrease">−</button>
                    <span class="cart-ctrl-qty">${qty}</span>
                    <button class="cart-ctrl-btn cart-increase">+</button>
                    <button class="cart-ctrl-btn remove" style="margin-left:6px;">✕</button>
                </div>`;
            cartItemsEl.appendChild(div);

            div.querySelector('.cart-increase').addEventListener('click', () => {
                const maxStock = parseInt(
                    document.querySelector(`.product-card[data-name="${CSS.escape(item)}"]`)?.dataset.stock || 9999
                );
                if (cart[item].qty >= maxStock) return;
                cart[item].qty++;
                syncCardQty(item, cart[item].qty);
                renderCart();
            });

            div.querySelector('.cart-decrease').addEventListener('click', () => {
                cart[item].qty--;
                if (cart[item].qty <= 0) { delete cart[item]; syncCardQty(item, 0); }
                else syncCardQty(item, cart[item].qty);
                renderCart();
            });

            div.querySelector('.remove').addEventListener('click', () => {
                delete cart[item];
                syncCardQty(item, 0);
                renderCart();
            });
        });

        document.getElementById('cartCountLabel').textContent =
            `${itemCount} item${itemCount !== 1 ? 's' : ''} · ${keys.length} product${keys.length !== 1 ? 's' : ''}`;
    }

    const grandTotal = subtotal + totalVAT;
    document.getElementById('subtotal').textContent       = `₱${subtotal.toFixed(2)}`;
    document.getElementById('total-discount').textContent = `−₱${totalDiscount.toFixed(2)}`;
    document.getElementById('vat').textContent            = `₱${totalVAT.toFixed(2)}`;
    document.getElementById('grand-total').textContent    = `₱${grandTotal.toFixed(2)}`;

    if (typeof updateReceiptBtn === 'function') updateReceiptBtn();
}

function syncCardQty(name, qty) {
    const card = document.querySelector(`.product-card[data-name="${CSS.escape(name)}"]`);
    if (!card) return;
    card.querySelector('.qty').textContent       = qty;
    card.querySelector('.qty-badge').textContent = qty;
    card.classList.toggle('has-qty', qty > 0);
}

// ================================================================
//  STATE
// ================================================================
let activeCategoryId = 'all';
let searchTerm       = '';
let currentPage      = 1;

function isSidebarOpen() {
    return !document.body.classList.contains('toggle-sidebar');
}

function getItemsPerPage() {
    return isSidebarOpen() ? 15 : 18;
}

const sidebarObserver = new MutationObserver(() => {
    currentPage = 1;
    applyFilters();
});
sidebarObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });

// ================================================================
//  CATEGORY FILTER
// ================================================================
const catBtns = document.querySelectorAll('.cat-btn');
catBtns.forEach(btn => {
    btn.addEventListener('click', () => {
        catBtns.forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        activeCategoryId = btn.dataset.id;
        currentPage = 1;
        applyFilters();
    });
});

const categoryBar = document.getElementById('categoryBar');
document.getElementById('categoryPrev').addEventListener('click', () => categoryBar.scrollBy({ left: -160, behavior: 'smooth' }));
document.getElementById('categoryNext').addEventListener('click', () => categoryBar.scrollBy({ left:  160, behavior: 'smooth' }));

// ================================================================
//  SEARCH
// ================================================================
document.getElementById('productSearch').addEventListener('input', e => {
    searchTerm  = e.target.value.toLowerCase().trim();
    currentPage = 1;
    applyFilters();
});

// ================================================================
//  FILTER + PAGINATION
// ================================================================
function applyFilters() {
    const itemsPerPage = getItemsPerPage();

    const matched = productCards.filter(card => {
        const categoryMatch = activeCategoryId === 'all' || card.dataset.category === activeCategoryId;
        const searchMatch   = searchTerm === '' || card.dataset.name.toLowerCase().includes(searchTerm);
        return categoryMatch && searchMatch;
    });

    productCards.forEach(c => c.style.display = 'none');

    if (activeCategoryId === 'all' && searchTerm === '') {
        matched.forEach(c => c.style.display = '');
        buildPagination(0, 0);
        return;
    }

    const totalPages = Math.ceil(matched.length / itemsPerPage);
    currentPage = Math.min(currentPage, totalPages || 1);

    const start = (currentPage - 1) * itemsPerPage;
    const end   = start + itemsPerPage;
    matched.slice(start, end).forEach(c => c.style.display = '');

    buildPagination(currentPage, totalPages);
}

function buildPagination(active, total) {
    const bar = document.getElementById('productPagination');
    bar.innerHTML = '';
    if (total <= 1) return;

    const mkBtn = (label, page, disabled = false) => {
        const b = document.createElement('button');
        b.className = 'pg-btn' + (page === active ? ' active' : '');
        b.textContent = label;
        b.disabled = disabled;
        b.addEventListener('click', () => { currentPage = page; applyFilters(); });
        bar.appendChild(b);
    };

    mkBtn('«', active - 1, active === 1);
    for (let i = 1; i <= total; i++) mkBtn(i, i);
    mkBtn('»', active + 1, active === total);
}

applyFilters();

// ================================================================
//  PRINT RECEIPT
// ================================================================
const printModalOverlay = document.getElementById('printModalOverlay');
const printReceiptBtn   = document.getElementById('printReceiptBtn');
const printModalClose   = document.getElementById('printModalClose');
const printNowBtn       = document.getElementById('printNowBtn');
const printStatus       = document.getElementById('printStatus');
const printStatusText   = document.getElementById('printStatusText');
const SETTINGS_KEY      = 'pos_print_settings';

function loadPrintSettings() {
    try {
        const s = JSON.parse(localStorage.getItem(SETTINGS_KEY) || '{}');
        if (s.storeName)  document.getElementById('pStoreName').value  = s.storeName;
        if (s.address)    document.getElementById('pAddress').value    = s.address;
        if (s.phone)      document.getElementById('pPhone').value      = s.phone;
        if (s.cashier)    document.getElementById('pCashier').value    = s.cashier;
    } catch(e) {}
}

function savePrintSettings() {
    localStorage.setItem(SETTINGS_KEY, JSON.stringify({
        storeName:  document.getElementById('pStoreName').value,
        address:    document.getElementById('pAddress').value,
        phone:      document.getElementById('pPhone').value,
        cashier:    document.getElementById('pCashier').value,
    }));
}

function setStatus(type, msg) {
    printStatus.className = `print-status ${type}`;
    printStatusText.textContent = msg;
}

printReceiptBtn.addEventListener('click', () => {
    loadPrintSettings();
    setStatus('', 'Ready to print');
    printModalOverlay.classList.add('open');
});

printModalClose.addEventListener('click', () => printModalOverlay.classList.remove('open'));
printModalOverlay.addEventListener('click', e => {
    if (e.target === printModalOverlay) printModalOverlay.classList.remove('open');
});

printNowBtn.addEventListener('click', async () => {
    if (Object.keys(cart).length === 0) {
        setStatus('err', 'Cart is empty');
        return;
    }

    savePrintSettings();

    const storeName  = document.getElementById('pStoreName').value || 'MY STORE';
    const address    = document.getElementById('pAddress').value   || '';
    const phone      = document.getElementById('pPhone').value     || '';
    const cashier    = document.getElementById('pCashier').value   || 'Cashier';
    const date       = new Date().toLocaleString('en-PH', {
                           year: 'numeric', month: 'short', day: 'numeric',
                           hour: '2-digit', minute: '2-digit'
                       });

    // Build items rows
    let itemsHtml = '';
    Object.entries(cart).forEach(([name, item]) => {
        const unitPrice = item.price * (1 - item.discount / 100);
        const lineTotal = (unitPrice * item.qty).toFixed(2);
        itemsHtml += `
            <tr>
                <td class="item-name">${name}${item.discount > 0 ? ` <span class="disc-tag">-${item.discount}%</span>` : ''}</td>
                <td class="item-qty">${item.qty}</td>
                <td class="item-price">₱${unitPrice.toFixed(2)}</td>
                <td class="item-total">₱${lineTotal}</td>
            </tr>`;
    });

    const subtotal   = document.getElementById('subtotal').textContent;
    const discount   = document.getElementById('total-discount').textContent;
    const vat        = document.getElementById('vat').textContent;
    const grandTotal = document.getElementById('grand-total').textContent;

    const receiptHtml = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Receipt — ${storeName}</title>
<style>
    /* ── Reset ── */
    * { margin: 0; padding: 0; box-sizing: border-box; }

    /* ── Screen: center a narrow thermal column on the page ── */
    body {
        background: #e0e0e0;
        display: flex;
        justify-content: center;
        padding: 30px 0 60px;
        font-family: 'Courier New', Courier, monospace;
    }

    .receipt {
        background: #fff;
        width: 302px;          /* 80mm ≈ 302px at 96dpi */
        padding: 16px 14px 20px;
        font-size: 11.5px;
        color: #000;
        line-height: 1.45;
        box-shadow: 0 2px 12px rgba(0,0,0,.15);
    }

    /* ── Header ── */
    .r-store {
        text-align: center;
        font-size: 15px;
        font-weight: bold;
        letter-spacing: .04em;
        text-transform: uppercase;
        margin-bottom: 3px;
    }

    .r-info {
        text-align: center;
        font-size: 10.5px;
        color: #333;
        margin-bottom: 10px;
        line-height: 1.5;
    }

    /* ── Dividers ── */
    .r-dash  { border: none; border-top: 1px dashed #000; margin: 7px 0; }
    .r-solid { border: none; border-top: 1px solid  #000; margin: 7px 0; }
    .r-eq    { border: none; border-top: 2px solid  #000; margin: 7px 0; }

    /* ── Meta ── */
    .r-meta { font-size: 10.5px; margin-bottom: 2px; }

    /* ── Items table ── */
    table { width: 100%; border-collapse: collapse; }

    thead th {
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: .04em;
        padding: 3px 2px;
        border-bottom: 1px solid #000;
    }

    .th-name  { text-align: left;   width: 44%; }
    .th-qty   { text-align: center; width: 10%; }
    .th-price { text-align: right;  width: 22%; }
    .th-total { text-align: right;  width: 24%; }

    .item-name  { padding: 3px 2px; vertical-align: top; word-break: break-word; }
    .item-qty   { text-align: center; padding: 3px 2px; vertical-align: top; }
    .item-price { text-align: right;  padding: 3px 2px; vertical-align: top; }
    .item-total { text-align: right;  padding: 3px 2px; vertical-align: top; font-weight: bold; }

    .disc-tag {
        background: #eee;
        font-size: 9px;
        padding: 0 2px;
        border-radius: 2px;
    }

    /* ── Totals ── */
    .totals { width: 100%; }
    .totals td { padding: 2px 2px; font-size: 11px; }
    .totals .t-label { text-align: left; }
    .totals .t-value { text-align: right; }
    .totals .discount { color: #c00; }

    .grand-row td {
        font-size: 14px;
        font-weight: bold;
        padding-top: 5px;
    }

    /* ── Footer ── */
    .r-footer {
        text-align: center;
        font-size: 10.5px;
        color: #444;
        margin-top: 10px;
        line-height: 1.6;
    }

    .r-footer .thank {
        font-size: 12px;
        font-weight: bold;
        color: #000;
    }

    /* ── Print: remove background, keep narrow column ── */
    @media print {
        @page {
            size: A4 portrait;
            margin: 10mm 0;     /* top/bottom margin only — left/right we handle */
        }

        body {
            background: none;
            padding: 0;
            justify-content: center;
        }

        .receipt {
            box-shadow: none;
            /* Keep 80mm width when printing — centered on A4 */
            width: 302px;
            margin: 0 auto;
        }
    }
</style>
</head>
<body>
<div class="receipt">

    <div class="r-store">${storeName}</div>
    <div class="r-info">
        ${address ? address + '<br>' : ''}
        ${phone   ? 'Tel: ' + phone  : ''}
    </div>

    <hr class="r-eq">

    <div class="r-meta">Date    : ${date}</div>
    <div class="r-meta">Cashier : ${cashier}</div>

    <hr class="r-dash">

    <table>
        <thead>
            <tr>
                <th class="th-name">Item</th>
                <th class="th-qty">Qty</th>
                <th class="th-price">Price</th>
                <th class="th-total">Total</th>
            </tr>
        </thead>
        <tbody>
            ${itemsHtml}
        </tbody>
    </table>

    <hr class="r-dash">

    <table class="totals">
        <tr>
            <td class="t-label">Subtotal</td>
            <td class="t-value">${subtotal}</td>
        </tr>
        <tr>
            <td class="t-label">Discount</td>
            <td class="t-value discount">${discount}</td>
        </tr>
        <tr>
            <td class="t-label">VAT (12%)</td>
            <td class="t-value">${vat}</td>
        </tr>
    </table>

    <hr class="r-solid">

    <table class="totals">
        <tr class="grand-row">
            <td class="t-label">TOTAL</td>
            <td class="t-value">${grandTotal}</td>
        </tr>
    </table>

    <hr class="r-eq">

    <div class="r-footer">
        <div class="thank">Thank you!</div>
        Please come again.<br>
        <small>${date}</small>
    </div>

</div>

<script>
    // Auto-open print dialog then close window
    window.onload = () => {
        window.print();
    };
<\/script>
</body>
</html>`;

    // Open receipt in new window
    const printWindow = window.open('', '_blank', 'width=480,height=700,scrollbars=yes');
    if (!printWindow) {
        setStatus('err', 'Pop-up blocked — allow pop-ups for this site');
        return;
    }
    printWindow.document.write(receiptHtml);
    printWindow.document.close();

    setStatus('ok', '✓ Receipt opened — print dialog will appear');
    setTimeout(() => printModalOverlay.classList.remove('open'), 1800);
});

// ================================================================
//  CHECKOUT MODAL
// ================================================================
const checkoutModalOverlay = document.getElementById('checkoutModalOverlay');
const checkoutModalClose   = document.getElementById('checkoutModalClose');
const confirmCheckoutBtn   = document.getElementById('confirmCheckoutBtn');
const checkoutStatus       = document.getElementById('checkoutStatus');
const checkoutStatusText   = document.getElementById('checkoutStatusText');
const cashTenderedField    = document.getElementById('cashTenderedField');
const cashTenderedInput    = document.getElementById('cashTendered');
const changeAmountEl       = document.getElementById('changeAmount');

let selectedPaymentMethod  = 'cash';
let lastSaleData           = null; // stores last completed sale for receipt

// ================================================================
//  PRINT PROMPT — shown after successful checkout
// ================================================================
function showPrintPrompt() {
    document.getElementById('printPromptOverlay').classList.add('open');
}

function clearCartAndClose() {
    productCards.forEach(card => {
        card.querySelector('.qty').textContent       = 0;
        card.querySelector('.qty-badge').textContent = 0;
        card.classList.remove('has-qty');
    });
    cart = {};
    renderCart();
    document.getElementById('printPromptOverlay').classList.remove('open');
}

document.getElementById('printPromptYes').addEventListener('click', () => {
    document.getElementById('printPromptOverlay').classList.remove('open');
    if (lastSaleData) printReceipt(lastSaleData);
    clearCartAndClose();
});

document.getElementById('printPromptNo').addEventListener('click', () => {
    clearCartAndClose();
});

// Open checkout modal
document.getElementById('checkoutBtn').addEventListener('click', () => {
    if (Object.keys(cart).length === 0) return;

    // Sync totals into modal
    document.getElementById('co-subtotal').textContent = document.getElementById('subtotal').textContent;
    document.getElementById('co-discount').textContent = document.getElementById('total-discount').textContent;
    document.getElementById('co-vat').textContent      = document.getElementById('vat').textContent;
    document.getElementById('co-total').textContent    = document.getElementById('grand-total').textContent;

    // Reset state
    selectedPaymentMethod = 'cash';
    document.querySelectorAll('.pay-method-btn').forEach(b => b.classList.remove('active'));
    document.querySelector('.pay-method-btn[data-method="cash"]').classList.add('active');
    cashTenderedField.style.display = '';
    cashTenderedInput.value = '';
    changeAmountEl.textContent = '₱0.00';
    setCheckoutStatus('', 'Ready to complete sale');

    checkoutModalOverlay.classList.add('open');
});

// Close modal
checkoutModalClose.addEventListener('click', () => checkoutModalOverlay.classList.remove('open'));
checkoutModalOverlay.addEventListener('click', e => {
    if (e.target === checkoutModalOverlay) checkoutModalOverlay.classList.remove('open');
});

// Payment method toggle
document.getElementById('paymentMethods').addEventListener('click', e => {
    const btn = e.target.closest('.pay-method-btn');
    if (!btn) return;
    document.querySelectorAll('.pay-method-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    selectedPaymentMethod = btn.dataset.method;

    // Show cash tendered only for cash
    cashTenderedField.style.display = selectedPaymentMethod === 'cash' ? '' : 'none';
    if (selectedPaymentMethod !== 'cash') changeAmountEl.textContent = '₱0.00';
});

// Calculate change
cashTenderedInput.addEventListener('input', () => {
    const tendered  = parseFloat(cashTenderedInput.value) || 0;
    const totalText = document.getElementById('co-total').textContent.replace('₱', '').replace(',', '');
    const total     = parseFloat(totalText) || 0;
    const change    = tendered - total;
    changeAmountEl.textContent = change >= 0 ? `₱${change.toFixed(2)}` : '₱0.00';
    changeAmountEl.style.color = change >= 0 ? 'var(--accent-green)' : 'var(--accent-red)';
});

function setCheckoutStatus(type, msg) {
    checkoutStatus.className = `print-status ${type}`;
    checkoutStatusText.textContent = msg;
}

// Confirm & save sale
confirmCheckoutBtn.addEventListener('click', async () => {
    if (Object.keys(cart).length === 0) {
        setCheckoutStatus('err', 'Cart is empty');
        return;
    }

    // Validate cash tendered
    if (selectedPaymentMethod === 'cash') {
        const totalText = document.getElementById('co-total').textContent.replace('₱', '').replace(',', '');
        const total     = parseFloat(totalText) || 0;
        const tendered  = parseFloat(cashTenderedInput.value) || 0;
        if (tendered < total) {
            setCheckoutStatus('err', 'Cash tendered is less than total');
            return;
        }
    }

    // Build items array using product_id from cart
    const items = Object.entries(cart).map(([name, item]) => ({
        product_id: item.product_id,
        unit_price: parseFloat((item.price * (1 - item.discount / 100)).toFixed(2)),
        quantity:   item.qty,
    }));

    // Parse totals
    const parseAmt = id => parseFloat(
        document.getElementById(id).textContent.replace('₱','').replace('−','').replace(',','')
    ) || 0;

    const payload = {
        items,
        payment_method:  selectedPaymentMethod,
        total_amount:    parseAmt('grand-total'),
        tax_amount:      parseAmt('vat'),
        discount_amount: parseAmt('total-discount'),
    };

    confirmCheckoutBtn.disabled = true;
    setCheckoutStatus('busy', 'Saving sale…');

    try {
        const res  = await fetch('/inventory_system/checkout.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        });
        const data = await res.json();

        if (data.success) {
            setCheckoutStatus('ok', `✓ Sale #${data.sale_id} saved!`);

            // Store sale data for receipt
            lastSaleData = {
                sale_id:    data.sale_id,
                items:      Object.entries(cart).map(([name, item]) => ({
                    name,
                    qty:      item.qty,
                    discount: item.discount,
                    price:    item.price,
                })),
                subtotal:    document.getElementById('subtotal').textContent,
                discount:    document.getElementById('total-discount').textContent,
                vat:         document.getElementById('vat').textContent,
                grand_total: document.getElementById('grand-total').textContent,
                payment:     selectedPaymentMethod,
                change:      changeAmountEl.textContent,
                date:        new Date().toLocaleString('en-PH', {
                                 year: 'numeric', month: 'short', day: 'numeric',
                                 hour: '2-digit', minute: '2-digit'
                             }),
            };

            // Show print prompt after short delay
            setTimeout(() => {
                checkoutModalOverlay.classList.remove('open');
                showPrintPrompt();
            }, 900);

        } else {
            setCheckoutStatus('err', data.error || 'Checkout failed');
        }
    } catch (err) {
        setCheckoutStatus('err', 'Network error — try again');
    } finally {
        confirmCheckoutBtn.disabled = false;
    }
});

// ================================================================
//  PRINT RECEIPT from sale data
// ================================================================
function printReceipt(sale) {
    const storeName = document.getElementById('pStoreName').value || 'MY STORE';
    const address   = document.getElementById('pAddress').value   || '';
    const phone     = document.getElementById('pPhone').value     || '';
    const cashier   = document.getElementById('pCashier').value   || 'Cashier';

    let itemsHtml = '';
    sale.items.forEach(item => {
        const unitPrice = item.price * (1 - item.discount / 100);
        const lineTotal = (unitPrice * item.qty).toFixed(2);
        itemsHtml += `
            <tr>
                <td class="item-name">${item.name}${item.discount > 0 ? ` <span class="disc-tag">-${item.discount}%</span>` : ''}</td>
                <td class="item-qty">${item.qty}</td>
                <td class="item-price">₱${unitPrice.toFixed(2)}</td>
                <td class="item-total">₱${lineTotal}</td>
            </tr>`;
    });

    const paymentLabel = { cash:'Cash', gcash:'GCash', card:'Card', other:'Other' }[sale.payment] || sale.payment;

    const receiptHtml = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Receipt #${sale.sale_id}</title>
<style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { background:#e0e0e0; display:flex; justify-content:center; padding:30px 0 60px; font-family:'Courier New',Courier,monospace; }
    .receipt { background:#fff; width:302px; padding:16px 14px 20px; font-size:11.5px; color:#000; line-height:1.45; box-shadow:0 2px 12px rgba(0,0,0,.15); }
    .r-store { text-align:center; font-size:15px; font-weight:bold; letter-spacing:.04em; text-transform:uppercase; margin-bottom:3px; }
    .r-info  { text-align:center; font-size:10.5px; color:#333; margin-bottom:10px; line-height:1.5; }
    .r-dash  { border:none; border-top:1px dashed #000; margin:7px 0; }
    .r-solid { border:none; border-top:1px solid  #000; margin:7px 0; }
    .r-eq    { border:none; border-top:2px solid  #000; margin:7px 0; }
    .r-meta  { font-size:10.5px; margin-bottom:2px; }
    .sale-no { text-align:center; font-size:10px; color:#888; margin-bottom:4px; }
    table    { width:100%; border-collapse:collapse; }
    thead th { font-size:10px; text-transform:uppercase; letter-spacing:.04em; padding:3px 2px; border-bottom:1px solid #000; }
    .th-name  { text-align:left; width:44%; } .th-qty { text-align:center; width:10%; }
    .th-price { text-align:right; width:22%; } .th-total { text-align:right; width:24%; }
    .item-name  { padding:3px 2px; vertical-align:top; word-break:break-word; }
    .item-qty   { text-align:center; padding:3px 2px; }
    .item-price { text-align:right;  padding:3px 2px; }
    .item-total { text-align:right;  padding:3px 2px; font-weight:bold; }
    .disc-tag   { background:#eee; font-size:9px; padding:0 2px; border-radius:2px; }
    .totals td  { padding:2px 2px; font-size:11px; }
    .t-label    { text-align:left; } .t-value { text-align:right; }
    .discount   { color:#c00; }
    .grand-row td   { font-size:14px; font-weight:bold; padding-top:5px; }
    .payment-row td { font-size:11px; color:#555; padding-top:3px; }
    .change-row td  { font-size:11px; font-weight:bold; }
    .r-footer   { text-align:center; font-size:10.5px; color:#444; margin-top:10px; line-height:1.6; }
    .r-footer .thank { font-size:12px; font-weight:bold; color:#000; }
    @media print {
        @page { size:A4 portrait; margin:10mm 0; }
        body  { background:none; padding:0; }
        .receipt { box-shadow:none; width:302px; margin:0 auto; }
    }
</style>
</head>
<body>
<div class="receipt">
    <div class="sale-no">Sale #${sale.sale_id}</div>
    <div class="r-store">${storeName}</div>
    <div class="r-info">${address ? address + '<br>' : ''}${phone ? 'Tel: ' + phone : ''}</div>
    <hr class="r-eq">
    <div class="r-meta">Date    : ${sale.date}</div>
    <div class="r-meta">Cashier : ${cashier}</div>
    <hr class="r-dash">
    <table>
        <thead><tr>
            <th class="th-name">Item</th><th class="th-qty">Qty</th>
            <th class="th-price">Price</th><th class="th-total">Total</th>
        </tr></thead>
        <tbody>${itemsHtml}</tbody>
    </table>
    <hr class="r-dash">
    <table class="totals">
        <tr><td class="t-label">Subtotal</td><td class="t-value">${sale.subtotal}</td></tr>
        <tr><td class="t-label">Discount</td><td class="t-value discount">${sale.discount}</td></tr>
        <tr><td class="t-label">VAT (12%)</td><td class="t-value">${sale.vat}</td></tr>
    </table>
    <hr class="r-solid">
    <table class="totals">
        <tr class="grand-row"><td class="t-label">TOTAL</td><td class="t-value">${sale.grand_total}</td></tr>
        <tr class="payment-row"><td class="t-label">Payment</td><td class="t-value">${paymentLabel}</td></tr>
        ${sale.payment === 'cash' ? `<tr class="change-row"><td class="t-label">Change</td><td class="t-value">${sale.change}</td></tr>` : ''}
    </table>
    <hr class="r-eq">
    <div class="r-footer"><div class="thank">Thank you!</div>Please come again.<br><small>${sale.date}</small></div>
</div>
<script>window.onload = () => window.print();<\/script>
</body></html>`;

    const printWindow = window.open('', '_blank', 'width=480,height=700,scrollbars=yes');
    if (!printWindow) {
        alert('Pop-up blocked — please allow pop-ups for this site.');
        return;
    }
    printWindow.document.write(receiptHtml);
    printWindow.document.close();
}

function updateReceiptBtn() {
    const btn = document.getElementById('printReceiptBtn');
    if (btn) btn.classList.toggle('has-items', Object.keys(cart).length > 0);
}

</script>
</body>
</html>