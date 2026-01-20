<?php
// Load config first (this defines $conn)
require_once $_SERVER['DOCUMENT_ROOT'].'/inventory_system/config/config.php';

// Load controllers after config
require_once $_SERVER['DOCUMENT_ROOT'].'/inventory_system/controllers/CategoryController.php';
require_once $_SERVER['DOCUMENT_ROOT'].'/inventory_system/controllers/ProductController.php';

// Safety check (optional but recommended while debugging)
if (!isset($conn)) {
    die('❌ $conn is NOT defined. config.php did not load correctly.');
}

// Now this is SAFE
$categories = CategoryController::all($conn, $table_categories);
$products = ProductController::activeProductsForPOS($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>POS System</title>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/components/head.php'; ?>
<style>
/* ================= DARK THEME BASE ================= */
body {
    background: #0d0d0d;
    color: #eee;
    margin: 0;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

/* ================= LEFT PANEL ================= */
.pos-left {
    background: #1b1b1b;
    padding: 15px;
    height: 100vh;
    overflow-y: auto;
    border-right: 1px solid #2c2c2c;
}

/* ================= CATEGORY BAR ================= */
.category-bar-wrapper {
    display: flex;
    align-items: center;
    gap: 5px;
    margin-bottom: 30px;
}

.category-bar {
    display: flex;
    overflow-x: auto;
    gap: 10px;
    scroll-behavior: smooth;
}

.category-bar::-webkit-scrollbar {
    height: 6px;
}

.category-bar::-webkit-scrollbar-thumb {
    background: #444;
    border-radius: 4px;
}

.category-bar::-webkit-scrollbar-track {
    background: #1b1b1b;
}

.category {
    background: #2b2b2b;
    border: none;
    color: #fff;
    padding: 10px 18px;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.2s ease-in-out;
    white-space: nowrap;
    font-size: 14px;
}

.category:hover {
    background: #3a3a3a;
}

.category.active {
    background: #2563eb;
    color: #fff;
}

/* ================= CATEGORY ARROWS ================= */
.category-arrow {
    background: #2b2b2b;
    border: none;
    color: #fff;
    padding: 6px 10px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 18px;
    transition: all 0.2s ease-in-out;
}

.category-arrow:hover {
    background: #3a3a3a;
}

.category-arrow:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

/* ================= PRODUCT GRID ================= */
.product-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); /* smaller cards */
    gap: 12px;
}

/* ================= PRODUCT CARD ================= */
.product-card {
    background: #212121;
    border-radius: 12px;      /* slightly smaller radius */
    padding: 8px;             /* less padding */
    text-align: center;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.product-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 15px rgba(0, 0, 0, 0.5);
}

.product-card img {
    width: 100%;
    height: 150px;             /* smaller height */
    object-fit: cover;
    border-radius: 10px;
    border: 1px solid #333;
}

.product-card h6 {
    margin-top: 6px;
    font-size: 12px;           /* smaller font */
    color: #fff;
}

.price {
    color: #4ade80;
    font-weight: bold;
    font-size: 12px;           /* smaller price text */
}

/* ================= QUANTITY CONTROLS ================= */
.qty-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 6px;
}

.qty-controls button {
    width: 28px;               /* smaller buttons */
    height: 28px;
    font-size: 12px;
}

.qty-controls span {
    min-width: 18px;           /* smaller quantity display */
    text-align: center;
}

/* ================= RIGHT PANEL ================= */
.pos-right {
    background: #141414;
    padding: 15px;
    display: flex;
    flex-direction: column;
    height: 90vh;
    border-left: 1px solid #2c2c2c;
}

/* ================= CART ITEMS ================= */
.cart-items {
    flex: 1;
    overflow-y: auto;
    margin-top: 15px;
}

.cart-item {
    border-bottom: 1px solid #333;
    padding: 5px 0;
    font-size: 14px;
}

.cart-summary {
    background: #1d1d1d;
    padding: 15px;
    border-radius: 15px;
    margin-top: 10px;
}

.cart-summary .total {
    font-size: 18px;
    font-weight: bold;
}

.cart-item .cart-qty-controls {
    display: flex;
    gap: 5px;
    margin-top: 2px;
}

.cart-item .cart-qty-controls button {
    width: 24px;
    height: 24px;
    font-size: 12px;
    padding: 0;
    border-radius: 4px;
    border: none;
    background: #333;
    color: #fff;
    cursor: pointer;
    transition: background 0.2s ease;
}

.cart-item .cart-qty-controls button:hover {
    background: #444;
}

/* ================= SEARCH BOX ================= */
.product-search-wrapper {
    position: relative;
    width: 30%;
    margin-left: auto; /* push it to the right */
    margin-bottom: 10px;
}

.product-search {
    width: 100%;
    padding: 6px 10px 6px 30px; /* left padding for icon space */
    border-radius: 8px;
    border: 1px solid #333;
    background: #1a1a1a;
    color: #fff;
    font-size: 13px;
}

.product-search::placeholder {
    color: #aaa;
}

.search-icon {
    position: absolute;
    left: 8px;
    top: 50%;
    transform: translateY(-50%);
    color: #aaa;
    font-size: 14px;
    pointer-events: none; /* icon won't block input */
}



/*PAGINATION FOR PRODUCTS */
/* Admin-style pagination dark theme */
.pagination {
    margin: 10px 0;
    padding-left: 0;
    list-style: none;
    display: flex;
    gap: 5px;
}

.pagination .page-item {
    display: inline-block;
}

.pagination .page-link {
    color: #fff;
    background-color: #2a2a2a;
    border: 1px solid #333;
    padding: 5px 10px;
    border-radius: 5px;
    cursor: pointer;
    text-decoration: none;
}

.pagination .page-item.active .page-link {
    background-color: #2563eb;
    border-color: #2563eb;
}

.pagination .page-link:hover {
    background-color: #333;
    border-color: #444;
}



</style>
</head>
<body>
<?php 
require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/components/header.php';
require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/components/sidebar.php';
?>

<main id="main" class="main">
<div class="container-fluid pos-container">
<div class="row vh-100">

  <!-- LEFT SIDE -->
  <div class="col-lg-8 pos-left">

    <!-- Categories -->
 <div class="category-bar-wrapper d-flex align-items-center">
    <button class="category-arrow" id="categoryPrev">&#8592;</button>
    
    <div class="category-bar flex-grow-1" id="categoryBar">
        <button class="category active" data-id="all">All</button>
        <?php foreach ($categories as $c): ?>
            <button class="category" data-id="<?= $c['category_id'] ?>">
                <?= htmlspecialchars($c['category_name']) ?>
            </button>
        <?php endforeach; ?>
    </div>
    
    <button class="category-arrow" id="categoryNext">&#8594;</button>
</div>


<!-- Product Search (above product grid) -->
<div class="product-search-wrapper">
    <input type="text" id="productSearch" class="product-search" placeholder="Search products...">
    <i class="bi bi-search search-icon"></i>
</div>


<div class="product-grid" id="product-grid">
    <?php foreach ($products as $p):
        $price = $p['on_sale'] ? $p['sale_price'] : $p['price'];
    ?>
        <div class="product-card"
             data-id="<?= $p['product_id'] ?>"
             data-name="<?= htmlspecialchars($p['product_name']) ?>"
             data-price="<?= $price ?>"
             data-vat="<?= $p['vatable'] ?>"
             data-category="<?= $p['category_id'] ?>">
            <img src="<?= $p['photo'] ?: '/assets/uploads/products/images.jpeg' ?>">
            <h6><?= htmlspecialchars($p['product_name']) ?></h6>
            <span class="price">₱<?= number_format($price,2) ?></span>
            <div class="qty-controls">
                <button class="decrease">-</button>
                <span class="qty">0</span>
                <button class="increase">+</button>
            </div>
        </div>
    <?php endforeach; ?>
    
</div>
<!-- Product pagination -->
<nav aria-label="Product pagination" class="mt-2">
    <ul class="pagination justify-content-center" id="productPagination">
        <!-- JS will generate page numbers here -->
    </ul>
</nav>


  </div>

  <!-- RIGHT SIDE -->
  <div class="col-lg-4 pos-right">

    <div class="cart-header">
      <h5>Customer</h5>
      <small>Cashier Mode</small>
    </div>

    <div class="cart-items" id="cart-items">
      <p class="text-center">No items yet</p>
    </div>

    <div class="cart-summary">
      <div class="d-flex justify-content-between">
        <span>Subtotal</span><span id="subtotal">₱0.00</span>
      </div>
      <div class="d-flex justify-content-between">
        <span>Total Discount</span><span id="total-discount">₱0.00</span>
      </div>
      <div class="d-flex justify-content-between">
        <span>VAT (12%)</span><span id="vat">₱0.00</span>
      </div>
      <hr>
      <div class="d-flex justify-content-between total">
        <span>Grand Total</span><span id="grand-total">₱0.00</span>
      </div>

      <button id="checkoutBtn" class="btn btn-primary w-100 mt-2">Checkout</button>
      <button class="btn btn-outline-light w-100 mt-2">Print Receipt</button>
    </div>

  </div>
</div>
</div>
</main>

<?php require $_SERVER['DOCUMENT_ROOT'].'/inventory_system/components/js_script.php'; ?>

<script>
// ===== POS CART & CATEGORY FILTER =====
const products = document.querySelectorAll('.product-card');
const cartItems = document.getElementById('cart-items');
const categoriesBtns = document.querySelectorAll('.category');

let cart = {}; // { "Product Name": { qty, price, vatable, discount } }

// ===== CART BUTTONS =====
products.forEach(product => {
    const name = product.dataset.name;
    const price = parseFloat(product.dataset.price);
    const vatable = Number(product.dataset.vat) === 1; // VAT fixed
    const discount = parseFloat(product.dataset.discount) || 0;

    const increaseBtn = product.querySelector('.increase');
    const decreaseBtn = product.querySelector('.decrease');
    const qtySpan = product.querySelector('.qty');

    increaseBtn.addEventListener('click', () => {
        qtySpan.textContent = parseInt(qtySpan.textContent) + 1;
        updateCart(name, price, vatable, discount, 1);
    });

    decreaseBtn.addEventListener('click', () => {
        let currentQty = parseInt(qtySpan.textContent);
        if(currentQty > 0){
            qtySpan.textContent = currentQty - 1;
            updateCart(name, price, vatable, discount, -1);
        }
    });
});

// ===== UPDATE CART =====
function updateCart(name, price, vatable, discount, change){
    if(!cart[name]) cart[name] = { qty: 0, price, vatable, discount };
    cart[name].qty += change;

    if(cart[name].qty <= 0){
        delete cart[name];
        document.querySelector(`.product-card[data-name="${name}"] .qty`).textContent = 0;
    }

    renderCart();
}

// ===== RENDER CART =====
function renderCart(){
    cartItems.innerHTML = '';
    let subtotal = 0;
    let totalDiscount = 0;
    let totalVAT = 0;

    if(Object.keys(cart).length === 0){
        cartItems.innerHTML = '<p class="text-center">No items yet</p>';
    } else {
        for(let item in cart){
            const { qty, price, vatable, discount } = cart[item];
            const discountedPrice = price * (1 - discount/100);
            const lineTotal = qty * discountedPrice;

            subtotal += lineTotal;
            totalDiscount += qty * (price - discountedPrice);
            if(vatable) totalVAT += lineTotal * 0.12;

            // Create cart item row
            const div = document.createElement('div');
            div.classList.add('cart-item', 'mb-2');
            div.innerHTML = `
                <div class="d-flex justify-content-between">
                    <span>${item} x ${qty}</span>
                    <span>₱${lineTotal.toFixed(2)}</span>
                </div>
                <div class="text-muted" style="font-size:10px">
                    Unit Price: ₱${price.toFixed(2)} ${discount > 0 ? `(-${discount}%)` : ''}
                </div>
                <div class="cart-qty-controls">
                    <button class="cart-decrease">-</button>
                    <button class="cart-increase">+</button>
                </div>
            `;
            cartItems.appendChild(div);

            // Cart row increase
            div.querySelector('.cart-increase').addEventListener('click', () => {
                cart[item].qty += 1;
                document.querySelector(`.product-card[data-name="${item}"] .qty`).textContent = cart[item].qty;
                renderCart();
            });

            // Cart row decrease
            div.querySelector('.cart-decrease').addEventListener('click', () => {
                cart[item].qty -= 1;
                if(cart[item].qty <= 0){
                    delete cart[item];
                    document.querySelector(`.product-card[data-name="${item}"] .qty`).textContent = 0;
                } else {
                    document.querySelector(`.product-card[data-name="${item}"] .qty`).textContent = cart[item].qty;
                }
                renderCart();
            });
        }
    }

    const grandTotal = subtotal + totalVAT;

    document.getElementById('subtotal').textContent = `₱${subtotal.toFixed(2)}`;
    document.getElementById('total-discount').textContent = `₱${totalDiscount.toFixed(2)}`;
    document.getElementById('vat').textContent = `₱${totalVAT.toFixed(2)}`;
    document.getElementById('grand-total').textContent = `₱${grandTotal.toFixed(2)}`;
}

// ===== CATEGORY FILTER =====
categoriesBtns.forEach(btn => {
    btn.addEventListener('click', () => {
        categoriesBtns.forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        const categoryId = btn.dataset.id;

        products.forEach(product => {
            if(categoryId === 'all' || product.dataset.category === categoryId){
                product.style.display = 'block';
            } else {
                product.style.display = 'none';
            }
        });
    });
});


const categoryBar = document.getElementById('categoryBar');
const prevBtn = document.getElementById('categoryPrev');
const nextBtn = document.getElementById('categoryNext');

const scrollAmount = 150; // pixels to scroll per click

prevBtn.addEventListener('click', () => {
    categoryBar.scrollBy({ left: -scrollAmount, behavior: 'smooth' });
});

nextBtn.addEventListener('click', () => {
    categoryBar.scrollBy({ left: scrollAmount, behavior: 'smooth' });
});

// Optional: disable arrow if cannot scroll further
function updateArrowState() {
    prevBtn.disabled = categoryBar.scrollLeft <= 0;
    nextBtn.disabled = categoryBar.scrollLeft + categoryBar.clientWidth >= categoryBar.scrollWidth;
}

categoryBar.addEventListener('scroll', updateArrowState);
window.addEventListener('resize', updateArrowState);
updateArrowState();



// ===== PRODUCT SEARCH =====
const productSearch = document.getElementById('productSearch');

productSearch.addEventListener('input', () => {
    const searchTerm = productSearch.value.toLowerCase();

    products.forEach(product => {
        const productName = product.dataset.name.toLowerCase();
        
        // Only show if product name starts with search term
        if (productName.startsWith(searchTerm)) {
            product.style.display = 'block';
        } else {
            product.style.display = 'none';
        }
    });
});



// ===== PRODUCT PAGINATION =====
const productsWrapper = Array.from(document.querySelectorAll('.product-card'));
const productPagination = document.getElementById('productPagination');
const itemsPerPage = 10;
let currentProductPage = 1;
const totalProductPages = Math.ceil(productsWrapper.length / itemsPerPage);

function renderProducts(page) {
    const start = (page - 1) * itemsPerPage;
    const end = start + itemsPerPage;

    productsWrapper.forEach((product, index) => {
        product.style.display = index >= start && index < end ? 'block' : 'none';
    });

    renderProductPagination(page);
}

function renderProductPagination(activePage) {
    productPagination.innerHTML = '';

    // Previous button
    const prevLi = document.createElement('li');
    prevLi.className = `page-item ${activePage === 1 ? 'disabled' : ''}`;
    prevLi.innerHTML = `<a class="page-link" href="#">&laquo;</a>`;
    prevLi.addEventListener('click', (e) => {
        e.preventDefault();
        if(activePage > 1) renderProducts(activePage - 1);
    });
    productPagination.appendChild(prevLi);

    // Page numbers
    for (let i = 1; i <= totalProductPages; i++) {
        const li = document.createElement('li');
        li.className = `page-item ${i === activePage ? 'active' : ''}`;
        li.innerHTML = `<a class="page-link" href="#">${i}</a>`;
        li.addEventListener('click', (e) => {
            e.preventDefault();
            renderProducts(i);
        });
        productPagination.appendChild(li);
    }

    // Next button
    const nextLi = document.createElement('li');
    nextLi.className = `page-item ${activePage === totalProductPages ? 'disabled' : ''}`;
    nextLi.innerHTML = `<a class="page-link" href="#">&raquo;</a>`;
    nextLi.addEventListener('click', (e) => {
        e.preventDefault();
        if(activePage < totalProductPages) renderProducts(activePage + 1);
    });
    productPagination.appendChild(nextLi);
}

// Initial render
renderProducts(currentProductPage);


</script>
</body>
</html>
