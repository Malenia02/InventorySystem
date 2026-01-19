<?php
require $_SERVER['DOCUMENT_ROOT'].'/inventory_system/controllers/CategoryController.php';
require $_SERVER['DOCUMENT_ROOT'].'/inventory_system/controllers/ProductController.php';

$categories = CategoryController::all();
$products = ProductController::allProducts($conn); // ✅ pass $conn

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>POS System</title>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/components/head.php'; ?>
<style>
body {
    background: #121212;
    color: #fff;
    margin: 0;
}

/* LEFT */
.pos-left {
    background: #181818;
    padding: 15px;
    height: 100vh;
    overflow-y: auto;
}

/* CATEGORY BAR */
.category-bar {
    display: flex;
    gap: 10px;
    margin-bottom: 15px;
}

.category {
    background: #2a2a2a;
    border: none;
    color: #fff;
    padding: 10px 18px;
    border-radius: 10px;
    cursor: pointer;
}

.category.active {
    background: #2563eb;
}

/* PRODUCT GRID */
.product-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 15px;
}

/* PRODUCT CARD */
.product-card {
    background: #1f1f1f;
    border-radius: 15px;
    padding: 10px;
    text-align: center;
}

.product-card img {
    width: 100%;
    height: 200px;
    object-fit: cover;
    border-radius: 12px;
}

.product-card h6 {
    margin-top: 8px;
    font-size: 14px;
}

.price {
    color: #4ade80;
    font-weight: bold;
}

/* QUANTITY */
.qty-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 8px;
}

.qty-controls button {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background: #333;
    color: #fff;
    cursor: pointer;
}

.qty-controls span {
    min-width: 20px;
    text-align: center;
}

/* RIGHT */
.pos-right {
    background: #101010;
    padding: 15px;
    display: flex;
    flex-direction: column;
    height: 100vh;
}

.cart-items {
    flex: 1;
    overflow-y: auto;
    margin-top: 15px;
}

.cart-summary {
    background: #1a1a1a;
    padding: 15px;
    border-radius: 15px;
}

.cart-summary .total {
    font-size: 18px;
    font-weight: bold;
}

.cart-item {
    border-bottom: 1px solid #333;
    padding-bottom: 5px;
}

.cart-item .cart-qty-controls {
    display: flex;
    gap: 5px;
    margin-top: 2px;
}

.cart-item .cart-qty-controls button {
    width: 24px;
    height: 24px;
    font-size: 16px;
    padding: 0;
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
  <div class="category-bar">
    <button class="category active" data-id="all">All</button>
    <?php foreach ($categories as $c): ?>
        <button class="category" data-id="<?= $c['category_id'] ?>">
            <?= htmlspecialchars($c['category_name']) ?>
        </button>
    <?php endforeach; ?>
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
                <div class="text-muted" style="font-size:12px">
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

</script>
</body>
</html>
