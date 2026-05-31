"use strict";

/* ─── Globals from PHP ─────────────────────────────────────────────────────── */
const POS_SUBCATEGORIES = Array.isArray(window.POS_SUBCATEGORIES)
    ? window.POS_SUBCATEGORIES
    : [];

/* ─── CSRF (primary: POS_CONFIG, fallback: meta tag) ────────────────────────── */
function getCsrfToken() {
    const fromConfig = String(window.POS_CONFIG?.csrfToken || "").trim();
    if (fromConfig) return fromConfig;
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";
}

/* ─── Utility helpers ──────────────────────────────────────────────────────── */
function escapeHtml(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function peso(value) {
    return `₱${Number(value || 0).toFixed(2)}`;
}

function parseDisplayedAmount(text) {
    return parseFloat(String(text || "").replace(/[^\d.-]/g, "")) || 0;
}

function formatTransactionNo(saleId, dateValue = null) {
    const id = Number(saleId || 0);
    if (!Number.isFinite(id) || id <= 0) return "SALE-00000000-000000";
    const d   = dateValue ? new Date(dateValue) : new Date();
    const src = isNaN(d.getTime()) ? new Date() : d;
    const y   = src.getFullYear();
    const m   = String(src.getMonth() + 1).padStart(2, "0");
    const day = String(src.getDate()).padStart(2, "0");
    return `SALE-${y}${m}${day}-${String(id).padStart(6, "0")}`;
}

function formatUnitLabel(unitType, quantity = 1) {
    const n   = String(unitType || "piece").toLowerCase();
    const qty = Number(quantity || 0);
    if (n === "box")  return qty === 1 ? "Box"  : "Boxes";
    if (n === "case") return qty === 1 ? "Case" : "Cases";
    return qty === 1 ? "Piece" : "Pieces";
}

function packagingDetailText(item) {
    const type       = String(item?.unit_type   || "piece").toLowerCase();
    const multiplier = Number(item?.unit_multiplier || 1);
    if (type === "case" && multiplier > 1) return `(1 Case = ${multiplier.toLocaleString()} pcs)`;
    if (type === "box"  && multiplier > 1) return `(1 Box = ${multiplier.toLocaleString()} pcs)`;
    return "";
}

function saleBreakdownPackagingText(item) {
    const type       = String(item?.unit_type       || "piece").toLowerCase();
    const multiplier = Number(item?.unit_multiplier || 1);
    const qty        = Number(item?.qty             || 0);
    const total      = qty * multiplier;
    const primary    = `${qty} ${formatUnitLabel(type, qty)}`;
    if (type === "case" && multiplier > 1) {
        return { primary, secondary: `1 Case = ${multiplier.toLocaleString()} pcs`, total: `Total: ${total.toLocaleString()} pcs` };
    }
    if (type === "box" && multiplier > 1) {
        return { primary, secondary: `1 Box = ${multiplier.toLocaleString()} pcs`,  total: `Total: ${total.toLocaleString()} pcs` };
    }
    return { primary, secondary: "", total: `Total: ${total.toLocaleString()} pcs` };
}

/* ─── Clock ────────────────────────────────────────────────────────────────── */
function updateClock() {
    const pill = document.getElementById("timePill");
    if (!pill) return;
    const now  = new Date();
    const opts = { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" };
    pill.innerHTML = now.toLocaleDateString("en-PH", opts).replace(",", " &mdash; <span>") + "</span>";
}
updateClock();
setInterval(updateClock, 10_000);

/* ─── WebSocket notification ───────────────────────────────────────────────── */
function sendCheckoutNotificationUpdate(type = "sale_success") {
    if (window.socket?.readyState !== WebSocket.OPEN) return;
    const userId  = Number(window.POS_CONFIG?.userId || 0);
    const payload = { event: "notification_update", type, target_roles: ["admin"] };
    if (Number.isFinite(userId) && userId > 0) payload.target_user_ids = [userId];
    window.socket.send(JSON.stringify(payload));
}

/* ─── Product cards & SKU map ──────────────────────────────────────────────── */
const productCards = Array.from(document.querySelectorAll(".product-card"));

/**
 * SKU Map for O(1) barcode lookup.
 * The old version used Array.find() — O(n) per scan.
 * With 2 000 products that's up to 2 000 comparisons per scan event.
 */
const skuMap = new Map(
    productCards
        .filter(c => c.dataset.sku?.trim())
        .map(c => [c.dataset.sku.trim().toLowerCase(), c])
);

/* ─── Cart ─────────────────────────────────────────────────────────────────── */
const cart             = {};
const cartItemsEl      = document.getElementById("cart-items");
const cartRecoveryEl   = document.getElementById("cartRecoveryNote");
const CART_KEY         = `pos_cart_${String(window.POS_CONFIG?.userId || "guest")}`;
const CART_MAX_AGE_MS  = 1000 * 60 * 60 * 24; // 24 h

function cartKey(productId, unitType) {
    return `${productId}:${unitType}`;
}

function setCartRecoveryNote(message = "", tone = "muted") {
    if (!cartRecoveryEl) return;
    if (!message) {
        cartRecoveryEl.hidden = true;
        cartRecoveryEl.textContent = "";
        cartRecoveryEl.className = "cart-recovery-note";
        return;
    }
    const cls = tone === "warning" ? "text-warning"
              : tone === "success" ? "text-success"
              : "";
    cartRecoveryEl.hidden = false;
    cartRecoveryEl.textContent = message;
    cartRecoveryEl.className = `cart-recovery-note ${cls}`.trim();
}

function clearStoredCart() {
    try { localStorage.removeItem(CART_KEY); } catch (_) {}
}

function persistCart() {
    try {
        const items = Object.values(cart).map(i => ({
            product_id: i.product_id,
            unit_type:  i.unit_type,
            qty:        i.qty,
        }));
        if (!items.length) { clearStoredCart(); return; }
        localStorage.setItem(CART_KEY, JSON.stringify({ saved_at: Date.now(), items }));
    } catch (_) {}
}

function getProductBaseQtyInCart(productId) {
    return Object.values(cart)
        .filter(i => i.product_id === productId)
        .reduce((s, i) => s + i.qty * i.unit_multiplier, 0);
}

function getVisibleQtyForProduct(productId) {
    return Object.values(cart)
        .filter(i => i.product_id === productId)
        .reduce((s, i) => s + i.qty, 0);
}

/* ─── Card data extraction ─────────────────────────────────────────────────── */
function getCardData(card) {
    const piecesPerBox = Math.max(1, parseInt(card.dataset.piecesPerBox || "1", 10));
    const boxesPerCase = Math.max(1, parseInt(card.dataset.boxesPerCase || "1", 10));
    const casePieces   = piecesPerBox * boxesPerCase;
    const isBeverage   = String(card.dataset.categoryName || "").toLowerCase().includes("beverage");
    const piecePrice   = parseFloat(card.dataset.price   || "0") || 0;
    const boxPrice     = card.dataset.boxPrice  !== "" ? parseFloat(card.dataset.boxPrice)  : NaN;
    const casePrice    = card.dataset.casePrice !== "" ? parseFloat(card.dataset.casePrice) : NaN;
    const pieceDisc    = parseFloat(card.dataset.pieceDiscount || "0") || 0;
    const boxDisc      = parseFloat(card.dataset.boxDiscount   || "0") || 0;
    const caseDisc     = parseFloat(card.dataset.caseDiscount  || "0") || 0;
    const hasBox       = !isBeverage && piecesPerBox > 1 && Number.isFinite(boxPrice)  && boxPrice  > 0;
    const hasCase      = casePieces  > 1 && Number.isFinite(casePrice) && casePrice > 0;

    return {
        productId:    parseInt(card.dataset.id    || "0", 10),
        name:         card.dataset.name           || "",
        vatable:      Number(card.dataset.vat     || "0") === 1,
        maxStock:     parseInt(card.dataset.stock || "0", 10) || 0,
        piecesPerBox,
        boxesPerCase,
        units: {
            piece: { unitType: "piece", unitLabel: "Piece", multiplier: 1,          regularPrice: piecePrice, discount: pieceDisc },
            ...(hasBox  ? { box:  { unitType: "box",  unitLabel: "Box",  multiplier: piecesPerBox, regularPrice: boxPrice,   discount: boxDisc  } } : {}),
            ...(hasCase ? { case: { unitType: "case", unitLabel: "Case", multiplier: casePieces,   regularPrice: casePrice,  discount: caseDisc } } : {}),
        },
    };
}

function getDefaultUnitType(data) {
    return Object.keys(data.units)[0] || "piece";
}

function getUnitByType(card, unitType) {
    const data = getCardData(card);
    return data.units[unitType] || data.units[getDefaultUnitType(data)] || data.units.piece;
}

/* ─── Card visual state ────────────────────────────────────────────────────── */
function buildCardHoverHint(data) {
    const labels = Object.values(data.units).map(u => u.unitLabel);
    if (labels.length <= 1) return "Tap to add to cart.";
    if (labels.length === 2) return `Tap to select by ${labels[0]} or ${labels[1]}.`;
    return `Tap to select by ${labels.slice(0, -1).join(", ")}, or ${labels[labels.length - 1]}.`;
}

function updateCardVisualState(card) {
    const pid       = parseInt(card.dataset.id || "0", 10);
    const data      = getCardData(card);
    const primary   = cart[cartKey(pid, getDefaultUnitType(data))];
    const visibleQty = getVisibleQtyForProduct(pid);
    const displayQty = primary ? primary.qty : 0;

    const qtyEl  = card.querySelector(".qty");
    if (qtyEl)   qtyEl.textContent = displayQty;

    const badge  = card.querySelector(".qty-badge");
    if (badge)   badge.textContent = visibleQty;

    card.classList.toggle("has-qty",          visibleQty > 0);
    card.classList.toggle("show-unit-selector", visibleQty > 0 && !!card.querySelector(".card-order-pill"));

    const hint = card.querySelector(".card-hover-hint");
    if (hint)  hint.textContent = buildCardHoverHint(data);

    const priceEl = card.querySelector(".card-price");
    if (priceEl) {
        const unit   = data.units[getDefaultUnitType(data)] || data.units.piece;
        const eff    = unit.regularPrice * (1 - (unit.discount || 0) / 100);
        priceEl.textContent = peso(eff);
    }
}

function updateAllCards(productId = null) {
    for (const card of productCards) {
        if (productId !== null && parseInt(card.dataset.id || "0", 10) !== productId) continue;
        updateCardVisualState(card);
    }
}

/* ─── Cart mutation ────────────────────────────────────────────────────────── */
function applyCartQuantity(card, unitType, delta) {
    if (card.classList.contains("stock-out")) return false;
    const data   = getCardData(card);
    const unit   = getUnitByType(card, unitType);
    const key    = cartKey(data.productId, unit.unitType);
    const existing = cart[key] || {
        product_id: data.productId, name: data.name, qty: 0,
        price: unit.regularPrice, vatable: data.vatable,
        discount: unit.discount || 0, unit_type: unit.unitType,
        unit_label: unit.unitLabel, unit_multiplier: unit.multiplier,
    };

    const nextBase = getProductBaseQtyInCart(data.productId) + delta * unit.multiplier;
    if (delta > 0 && nextBase > data.maxStock) return false;

    existing.qty += delta;
    if (existing.qty <= 0) {
        delete cart[key];
    } else {
        existing.price          = unit.regularPrice;
        existing.discount       = unit.discount || 0;
        existing.unit_type      = unit.unitType;
        existing.unit_label     = unit.unitLabel;
        existing.unit_multiplier = unit.multiplier;
        cart[key] = existing;
    }

    updateAllCards(data.productId);
    setCartRecoveryNote("");
    renderCart();
    return true;
}

/* ─── Render cart ──────────────────────────────────────────────────────────── */
function renderCart() {
    if (!cartItemsEl) return;
    cartItemsEl.innerHTML = "";

    let subtotal = 0, totalDiscount = 0, totalVAT = 0, itemCount = 0;
    const keys   = Object.keys(cart);
    const vatRate = Number(window.POS_CONFIG?.vatRate || 12);

    if (!keys.length) {
        cartItemsEl.innerHTML = `
            <div class="cart-empty">
                <div class="cart-empty-icon">🛒</div>
                <p>Cart is empty</p>
            </div>`;
        document.getElementById("cartCountLabel").textContent = "No items in cart";
    } else {
        for (const key of keys) {
            const item            = cart[key];
            const discUnit        = item.price * (1 - item.discount / 100);
            const lineTotal       = item.qty * discUnit;
            subtotal              += lineTotal;
            totalDiscount         += item.qty * (item.price - discUnit);
            if (item.vatable)      totalVAT += lineTotal * (vatRate / 100);
            itemCount             += item.qty;

            const row = document.createElement("div");
            row.className = "cart-item";
            row.innerHTML = `
                <div class="cart-item-row">
                    <span class="cart-item-name">
                        ${escapeHtml(item.name)}
                        <small>(${escapeHtml(item.unit_label)})</small>
                    </span>
                    <span class="cart-item-line-total">${escapeHtml(peso(lineTotal))}</span>
                </div>
                <div class="cart-item-meta">
                    ${escapeHtml(peso(item.price))} / ${escapeHtml(item.unit_label.toLowerCase())}
                    ${item.discount > 0 ? ` · -${item.discount}% off` : ""}
                    · ${item.unit_multiplier} pc${item.unit_multiplier === 1 ? "" : "s"} each
                </div>
                <div class="cart-item-controls">
                    <button class="cart-ctrl-btn cart-decrease" type="button">−</button>
                    <span class="cart-ctrl-qty">${item.qty}</span>
                    <button class="cart-ctrl-btn cart-increase" type="button">+</button>
                    <button class="cart-ctrl-btn remove" type="button" style="margin-left:6px;">✕</button>
                </div>`;

            row.querySelector(".cart-increase").addEventListener("click", () => {
                const card = document.querySelector(`.product-card[data-id="${CSS.escape(String(item.product_id))}"]`);
                const maxStock = parseInt(card?.dataset.stock || "0", 10);
                if ((getProductBaseQtyInCart(item.product_id) + item.unit_multiplier) > maxStock) return;
                item.qty++;
                updateAllCards(item.product_id);
                setCartRecoveryNote("");
                renderCart();
            });

            row.querySelector(".cart-decrease").addEventListener("click", () => {
                item.qty--;
                if (item.qty <= 0) delete cart[key];
                updateAllCards(item.product_id);
                setCartRecoveryNote("");
                renderCart();
            });

            row.querySelector(".remove").addEventListener("click", () => {
                delete cart[key];
                updateAllCards(item.product_id);
                setCartRecoveryNote("");
                renderCart();
            });

            cartItemsEl.appendChild(row);
        }

        const lines = keys.length;
        document.getElementById("cartCountLabel").textContent =
            `${itemCount} item${itemCount !== 1 ? "s" : ""} · ${lines} line${lines !== 1 ? "s" : ""}`;
    }

    const grandTotal = subtotal + totalVAT;
    document.getElementById("subtotal").textContent       = peso(subtotal);
    document.getElementById("total-discount").textContent  = `−${peso(totalDiscount)}`;
    document.getElementById("vat").textContent             = peso(totalVAT);
    document.getElementById("grand-total").textContent     = peso(grandTotal);

    persistCart();
    updateReceiptBtn();
}

function updateReceiptBtn() {
    document.getElementById("printReceiptBtn")
        ?.classList.toggle("has-items", Object.keys(cart).length > 0);
}

/* ─── Cart recovery ────────────────────────────────────────────────────────── */
function restoreCartFromStorage() {
    try {
        const raw = localStorage.getItem(CART_KEY);
        if (!raw) return;
        const payload    = JSON.parse(raw);
        const savedAt    = Number(payload?.saved_at || 0);
        const stored     = Array.isArray(payload?.items) ? payload.items : [];
        if (!stored.length) { clearStoredCart(); return; }
        if (!savedAt || Date.now() - savedAt > CART_MAX_AGE_MS) {
            clearStoredCart();
            setCartRecoveryNote("Saved cart expired and was cleared.", "warning");
            return;
        }

        const byId      = new Map(productCards.map(c => [parseInt(c.dataset.id || "0", 10), c]));
        const reserved  = {};
        let   restored  = 0, adjusted = 0;

        for (const s of stored) {
            const pid     = parseInt(s?.product_id || "0", 10);
            const utype   = String(s?.unit_type   || "piece");
            const reqQty  = Math.max(0, parseInt(s?.qty || "0", 10));
            if (pid <= 0 || reqQty <= 0) continue;
            const card = byId.get(pid);
            if (!card || card.classList.contains("stock-out")) { adjusted++; continue; }
            const data = getCardData(card);
            const unit = data.units[utype];
            if (!unit) { adjusted++; continue; }
            const rem  = Math.max(0, data.maxStock - (reserved[pid] || 0));
            const allow = Math.min(reqQty, Math.floor(rem / unit.multiplier));
            if (allow <= 0) { adjusted++; continue; }
            if (allow < reqQty) adjusted++;
            cart[cartKey(pid, unit.unitType)] = {
                product_id: pid, name: data.name, qty: allow,
                price: unit.regularPrice, vatable: data.vatable,
                discount: unit.discount || 0, unit_type: unit.unitType,
                unit_label: unit.unitLabel, unit_multiplier: unit.multiplier,
            };
            reserved[pid] = (reserved[pid] || 0) + allow * unit.multiplier;
            restored++;
        }

        if (restored > 0) {
            setCartRecoveryNote(
                adjusted > 0
                    ? "Cart restored with stock adjustments."
                    : "Cart restored from your last unfinished sale.",
                adjusted > 0 ? "warning" : "success"
            );
        } else {
            clearStoredCart();
            setCartRecoveryNote("Saved cart could not be restored.", "warning");
        }
    } catch (_) { clearStoredCart(); }
}

restoreCartFromStorage();
renderCart();

/* ─── Product unit modal ───────────────────────────────────────────────────── */
const puOverlay      = document.getElementById("productUnitModalOverlay");
const puClose        = document.getElementById("productUnitModalClose");
const puTitle        = document.getElementById("productUnitModalTitle");
const puImage        = document.getElementById("productUnitModalImage");
const puName         = document.getElementById("productUnitModalName");
const puSubcat       = document.getElementById("productUnitModalSubcat");
const puStock        = document.getElementById("productUnitModalStock");
const puOptions      = document.getElementById("productUnitOptions");
const puQtyDec       = document.getElementById("productUnitQtyDecrease");
const puQtyInc       = document.getElementById("productUnitQtyIncrease");
const puQtyVal       = document.getElementById("productUnitQtyValue");
const puTotal        = document.getElementById("productUnitModalTotal");
const puPackaging    = document.getElementById("productUnitPackaging");
const puAddBtn       = document.getElementById("productUnitAddToCartBtn");

let activeModalCard     = null;
let activeModalUnitType = "piece";
let activeModalQty      = 1;

function getEffectivePrice(unit) {
    return unit.regularPrice * (1 - (unit.discount || 0) / 100);
}

function renderProductUnitModal() {
    if (!activeModalCard) return;
    const data   = getCardData(activeModalCard);
    const unit   = data.units[activeModalUnitType] || data.units[getDefaultUnitType(data)];
    const units  = Object.values(data.units);

    if (puTitle)  puTitle.textContent  = escapeHtml(data.name);
    if (puName)   puName.textContent   = data.name;
    if (puSubcat) puSubcat.textContent = activeModalCard.dataset.subcategoryName || "General";
    if (puStock)  puStock.textContent  = `${data.maxStock} pcs in stock`;
    if (puImage)  { puImage.src = activeModalCard.dataset.photo || ""; puImage.alt = data.name; }

    if (puOptions) {
        puOptions.innerHTML = units.map(u => {
            const avail  = Math.floor(data.maxStock / u.multiplier);
            const active = u.unitType === activeModalUnitType;
            return `
                <button type="button"
                        class="product-unit-option${active ? " active" : ""}"
                        data-unit-type="${escapeHtml(u.unitType)}">
                    <span class="product-unit-option-label">${escapeHtml(u.unitLabel)}</span>
                    <strong class="product-unit-option-price">${escapeHtml(peso(getEffectivePrice(u)))}</strong>
                    <small class="product-unit-option-stock">${avail} available</small>
                </button>`;
        }).join("");
    }

    if (puQtyVal)  puQtyVal.textContent  = String(activeModalQty);
    if (puTotal)   puTotal.textContent   = peso(getEffectivePrice(unit) * activeModalQty);

    if (puPackaging) {
        const bits = [];
        if (data.units.box)  bits.push(`<span>1 Box = ${data.units.box.multiplier} pcs</span>`);
        if (data.units.case) bits.push(`<span>1 Case = ${data.units.case.multiplier} pcs</span>`);
        puPackaging.innerHTML    = bits.join("");
        puPackaging.style.display = bits.length ? "" : "none";
    }

    if (puQtyDec) puQtyDec.disabled = activeModalQty <= 1;
    if (puQtyInc) {
        const nextBase = getProductBaseQtyInCart(data.productId) + (activeModalQty + 1) * unit.multiplier;
        puQtyInc.disabled = nextBase > data.maxStock;
    }
    if (puAddBtn) puAddBtn.disabled = Math.floor(data.maxStock / unit.multiplier) <= 0;
}

function openProductUnitModal(card) {
    if (!card || card.classList.contains("stock-out")) return;
    activeModalCard     = card;
    activeModalUnitType = getDefaultUnitType(getCardData(card));
    activeModalQty      = 1;
    renderProductUnitModal();
    puOverlay?.classList.add("open");
}

function closeProductUnitModal() {
    puOverlay?.classList.remove("open");
    activeModalCard = null;
}

puOptions?.addEventListener("click", e => {
    const btn = e.target.closest(".product-unit-option");
    if (!btn || !activeModalCard) return;
    activeModalUnitType = btn.dataset.unitType || activeModalUnitType;
    activeModalQty      = 1;
    renderProductUnitModal();
});

puQtyDec?.addEventListener("click", () => {
    if (activeModalQty <= 1) return;
    activeModalQty--;
    renderProductUnitModal();
});

puQtyInc?.addEventListener("click", () => {
    if (!activeModalCard) return;
    const data = getCardData(activeModalCard);
    const unit = data.units[activeModalUnitType] || data.units[getDefaultUnitType(data)];
    if (getProductBaseQtyInCart(data.productId) + (activeModalQty + 1) * unit.multiplier > data.maxStock) return;
    activeModalQty++;
    renderProductUnitModal();
});

puAddBtn?.addEventListener("click", () => {
    if (!activeModalCard) return;
    if (applyCartQuantity(activeModalCard, activeModalUnitType, activeModalQty)) {
        closeProductUnitModal();
    }
});

puClose?.addEventListener("click", closeProductUnitModal);
puOverlay?.addEventListener("click", e => { if (e.target === puOverlay) closeProductUnitModal(); });

/* ─── Product card events ──────────────────────────────────────────────────── */
for (const card of productCards) {
    card.addEventListener("click", () => openProductUnitModal(card));

    card.querySelector(".increase")?.addEventListener("click", e => {
        e.stopPropagation();
        applyCartQuantity(card, getDefaultUnitType(getCardData(card)), 1);
    });

    card.querySelector(".decrease")?.addEventListener("click", e => {
        e.stopPropagation();
        applyCartQuantity(card, getDefaultUnitType(getCardData(card)), -1);
    });

    card.querySelector(".card-order-pill")?.addEventListener("click", e => {
        e.stopPropagation();
        openProductUnitModal(card);
    });

    updateCardVisualState(card);
}

/* ─── Category & subcategory filter ───────────────────────────────────────── */
const categoryBar       = document.getElementById("categoryBar");
const subcategoryBar    = document.getElementById("subcategoryBar");
const subcategorySection = document.getElementById("subcategorySection");
const subcategoryShell  = document.getElementById("subcategoryShell");
const subcatPrevBtn     = document.getElementById("subcategoryPrevBtn");
const subcatNextBtn     = document.getElementById("subcategoryNextBtn");
const posBrowserTitle   = document.getElementById("posBrowserTitle");
const posProductCount   = document.getElementById("posProductCount");

let activeCategoryId    = "all";
let activeSubcategoryId = "all";
let searchTerm          = "";
let currentPage         = 1;

function normalizeId(v) {
    const s = String(v ?? "").trim();
    return s === "" || s === "0" ? "all" : s;
}

function isSidebarOpen() {
    return !document.body.classList.contains("toggle-sidebar");
}

function getItemsPerPage() {
    return isSidebarOpen() ? 15 : 18;
}

new MutationObserver(() => { currentPage = 1; applyFilters(); })
    .observe(document.body, { attributes: true, attributeFilter: ["class"] });

function setActiveButton(container, selector, activeId) {
    const norm = normalizeId(activeId);
    container?.querySelectorAll(selector).forEach(btn => {
        btn.classList.toggle("active", normalizeId(btn.dataset.id) === norm);
    });
}

function updateSubcategoryOverflow() {
    if (!subcategoryBar || !subcategoryShell || !subcatPrevBtn || !subcatNextBtn) return;
    const canScroll = subcategoryBar.scrollWidth > subcategoryBar.clientWidth + 4;
    const atStart   = subcategoryBar.scrollLeft <= 4;
    const atEnd     = subcategoryBar.scrollLeft + subcategoryBar.clientWidth >= subcategoryBar.scrollWidth - 4;
    subcategoryShell.classList.toggle("has-overflow",     canScroll);
    subcategoryShell.classList.toggle("show-left-fade",   canScroll && !atStart);
    subcategoryShell.classList.toggle("show-right-fade",  canScroll && !atEnd);
    subcatPrevBtn.disabled = !canScroll || atStart;
    subcatNextBtn.disabled = !canScroll || atEnd;
}

function renderSubcategoryBar() {
    if (!subcategoryBar || !subcategorySection) return;
    if (activeCategoryId === "all") {
        activeSubcategoryId = "all";
        subcategoryBar.innerHTML = `<button class="sub-pill subcat-btn active" data-id="all">All</button>`;
        subcategorySection.classList.add("is-hidden");
        updateSubcategoryOverflow();
        return;
    }
    const scoped = POS_SUBCATEGORIES.filter(s => normalizeId(s.category_id) === activeCategoryId);
    if (!scoped.some(s => normalizeId(s.subcategory_id) === activeSubcategoryId)) {
        activeSubcategoryId = "all";
    }
    subcategoryBar.innerHTML =
        `<button class="sub-pill subcat-btn ${activeSubcategoryId === "all" ? "active" : ""}" data-id="all">All</button>` +
        scoped.map(s => `
            <button class="sub-pill subcat-btn ${normalizeId(s.subcategory_id) === activeSubcategoryId ? "active" : ""}"
                    data-id="${escapeHtml(String(s.subcategory_id))}">
                ${escapeHtml(s.subcategory_name)}
            </button>`).join("");
    subcategorySection.classList.toggle("is-hidden", scoped.length === 0);
    subcategoryBar.scrollLeft = 0;
    updateSubcategoryOverflow();
}

function updateBrowserHeader(count = null) {
    const catBtn   = categoryBar?.querySelector(`.cat-btn[data-id="${CSS.escape(activeCategoryId)}"]`);
    let   title    = catBtn?.dataset.name || "All Categories";
    if (activeSubcategoryId !== "all") {
        const subBtn = subcategoryBar?.querySelector(`.subcat-btn[data-id="${CSS.escape(activeSubcategoryId)}"]`);
        const sname  = subBtn?.textContent?.trim();
        if (sname) title += ` / ${sname}`;
    }
    if (posBrowserTitle)  posBrowserTitle.textContent  = title;
    if (posProductCount && count !== null) {
        posProductCount.textContent = `${count} item${count === 1 ? "" : "s"}`;
    }
}

categoryBar?.addEventListener("click", e => {
    const btn = e.target.closest(".cat-btn");
    if (!btn) return;
    activeCategoryId    = normalizeId(btn.dataset.id);
    activeSubcategoryId = "all";
    currentPage         = 1;
    setActiveButton(categoryBar, ".cat-btn", activeCategoryId);
    renderSubcategoryBar();
    applyFilters();
});

subcategoryBar?.addEventListener("click", e => {
    const btn = e.target.closest(".subcat-btn");
    if (!btn) return;
    activeSubcategoryId = normalizeId(btn.dataset.id);
    currentPage         = 1;
    setActiveButton(subcategoryBar, ".subcat-btn", activeSubcategoryId);
    applyFilters();
});

subcategoryBar?.addEventListener("scroll", updateSubcategoryOverflow);
subcatPrevBtn?.addEventListener("click", () => subcategoryBar.scrollBy({ left: -200, behavior: "smooth" }));
subcatNextBtn?.addEventListener("click", () => subcategoryBar.scrollBy({ left: 200,  behavior: "smooth" }));
window.addEventListener("resize", updateSubcategoryOverflow);

/* ─── Search — debounced 150 ms ────────────────────────────────────────────── */
let _searchTimer;
document.getElementById("productSearch")?.addEventListener("input", e => {
    clearTimeout(_searchTimer);
    _searchTimer = setTimeout(() => {
        searchTerm  = e.target.value.toLowerCase().trim();
        currentPage = 1;
        applyFilters();
    }, 150);
});

/* ─── Barcode scan — O(1) via skuMap ──────────────────────────────────────── */
const barcodeInput = document.getElementById("barcodeScanInput");

barcodeInput?.addEventListener("keydown", e => {
    if (e.key !== "Enter") return;
    e.preventDefault();
    const val = String(barcodeInput.value || "").trim().toLowerCase();
    if (!val) return;

    const matched = skuMap.get(val);   // O(1)

    if (!matched) {
        barcodeInput.classList.add("is-invalid");
        barcodeInput.value = "";
        barcodeInput.placeholder = "No product matched that barcode / SKU";
        setTimeout(() => {
            barcodeInput.classList.remove("is-invalid");
            barcodeInput.placeholder = "Scan barcode / SKU and press Enter";
        }, 1800);
        return;
    }

    // Switch category/subcategory to show the matched product
    activeCategoryId    = normalizeId(matched.dataset.category);
    activeSubcategoryId = normalizeId(matched.dataset.subcategory);
    searchTerm          = "";
    currentPage         = 1;
    const searchEl = document.getElementById("productSearch");
    if (searchEl) searchEl.value = "";
    setActiveButton(categoryBar, ".cat-btn", activeCategoryId);
    renderSubcategoryBar();
    setActiveButton(subcategoryBar, ".subcat-btn", activeSubcategoryId);
    applyFilters();

    matched.scrollIntoView({ behavior: "smooth", block: "center" });
    matched.classList.add("ring-focus");
    setTimeout(() => matched.classList.remove("ring-focus"), 1400);

    const data = getCardData(matched);
    if (Object.keys(data.units).length > 1) {
        openProductUnitModal(matched);
    } else {
        applyCartQuantity(matched, Object.keys(data.units)[0] || "piece", 1);
    }

    barcodeInput.value = "";
});

/* ─── Apply filters + pagination ───────────────────────────────────────────── */
function buildPagination(active, total) {
    const bar = document.getElementById("productPagination");
    if (!bar) return;
    bar.innerHTML = "";
    if (total <= 1) return;

    const make = (label, page, disabled = false) => {
        const btn = document.createElement("button");
        btn.className = `pg-btn${page === active ? " active" : ""}`;
        btn.textContent = label;
        btn.disabled    = disabled;
        btn.addEventListener("click", () => { currentPage = page; applyFilters(); });
        bar.appendChild(btn);
    };

    make("«", active - 1, active === 1);
    for (let p = 1; p <= total; p++) make(p, p);
    make("»", active + 1, active === total);
}

function applyFilters() {
    const perPage = getItemsPerPage();
    const matched = productCards.filter(card => {
        const catOk  = activeCategoryId    === "all" || normalizeId(card.dataset.category)    === activeCategoryId;
        const subOk  = activeSubcategoryId === "all" || normalizeId(card.dataset.subcategory) === activeSubcategoryId;
        const srchOk = !searchTerm
            || (card.dataset.name || "").toLowerCase().includes(searchTerm)
            || (card.dataset.sku  || "").toLowerCase().includes(searchTerm);
        return catOk && subOk && srchOk;
    });

    updateBrowserHeader(matched.length);

    for (const card of productCards) card.style.display = "none";

    // No filters — show all without pagination
    if (activeCategoryId === "all" && activeSubcategoryId === "all" && !searchTerm) {
        for (const card of matched) card.style.display = "";
        buildPagination(0, 0);
        return;
    }

    const totalPages = Math.ceil(matched.length / perPage);
    currentPage      = Math.min(currentPage, totalPages || 1);
    const start      = (currentPage - 1) * perPage;

    matched.slice(start, start + perPage).forEach(c => c.style.display = "");
    buildPagination(currentPage, totalPages);
}

renderSubcategoryBar();
applyFilters();

/* ─── Print receipt modal ──────────────────────────────────────────────────── */
const SETTINGS_KEY = "pos_print_settings";

function loadPrintSettings() {
    try {
        const s = JSON.parse(localStorage.getItem(SETTINGS_KEY) || "{}");
        document.getElementById("pStoreName").value = s.storeName || window.POS_CONFIG?.storeName || "";
        document.getElementById("pAddress").value   = s.address   || window.POS_CONFIG?.address   || "";
        document.getElementById("pPhone").value     = s.phone     || window.POS_CONFIG?.phone     || "";
        document.getElementById("pCashier").value   = s.cashier   || window.POS_CONFIG?.cashier   || "";
    } catch (_) {}
}

function savePrintSettings() {
    localStorage.setItem(SETTINGS_KEY, JSON.stringify({
        storeName: document.getElementById("pStoreName")?.value || "",
        address:   document.getElementById("pAddress")?.value   || "",
        phone:     document.getElementById("pPhone")?.value     || "",
        cashier:   document.getElementById("pCashier")?.value   || "",
    }));
}

function setPrintStatus(type, msg) {
    const el   = document.getElementById("printStatus");
    const text = document.getElementById("printStatusText");
    if (!el || !text) return;
    el.className     = `print-status ${type}`;
    text.textContent = msg;
}

function buildReceiptHtml({ title, transactionNo = "", itemsHtml, subtotal, discount, vat, grandTotal, paymentLabel = "", change = "", date }) {
    const store   = escapeHtml(document.getElementById("pStoreName")?.value || "MY STORE");
    const address = escapeHtml(document.getElementById("pAddress")?.value   || "");
    const phone   = escapeHtml(document.getElementById("pPhone")?.value     || "");
    const cashier = escapeHtml(document.getElementById("pCashier")?.value   || "Cashier");
    const logo    = escapeHtml(window.POS_CONFIG?.logo || "");
    const vatRate = Number(window.POS_CONFIG?.vatRate || 12);

    return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>${escapeHtml(title)}</title>
<style>
*{box-sizing:border-box}body{background:#e0e0e0;display:flex;justify-content:center;padding:30px 0 60px;font-family:'Courier New',Courier,monospace}.receipt{background:#fff;width:302px;padding:16px 14px 20px;font-size:11.5px;color:#000;line-height:1.45;box-shadow:0 2px 12px rgba(0,0,0,.15)}.r-store{text-align:center;font-size:15px;font-weight:bold;letter-spacing:.04em;text-transform:uppercase;margin-bottom:3px}.r-info{text-align:center;font-size:10.5px;color:#333;margin-bottom:10px;line-height:1.5}.r-logo{text-align:center;margin-bottom:6px}.r-logo img{max-width:72px;max-height:72px;object-fit:contain}.r-meta{font-size:10.5px;margin-bottom:2px}.r-dash{border:none;border-top:1px dashed #000;margin:7px 0}.r-solid{border:none;border-top:1px solid #000;margin:7px 0}.r-eq{border:none;border-top:2px solid #000;margin:7px 0}.sale-no{text-align:center;font-size:10px;color:#888;margin-bottom:4px}table{width:100%;border-collapse:collapse}thead th{font-size:10px;text-transform:uppercase;letter-spacing:.04em;padding:3px 2px;border-bottom:1px solid #000}.th-name{text-align:left;width:44%}.th-qty{text-align:center;width:10%}.th-price{text-align:right;width:22%}.th-total{text-align:right;width:24%}.item-name{padding:3px 2px;vertical-align:top;word-break:break-word}.item-qty{text-align:center;padding:3px 2px}.item-price{text-align:right;padding:3px 2px}.item-total{text-align:right;padding:3px 2px;font-weight:bold}.disc-tag{background:#eee;font-size:9px;padding:0 2px;border-radius:2px}.totals td{padding:2px 2px;font-size:11px}.t-label{text-align:left}.t-value{text-align:right}.discount{color:#c00}.grand-row td{font-size:14px;font-weight:bold;padding-top:5px}.r-footer{text-align:center;font-size:10.5px;color:#444;margin-top:10px;line-height:1.6}.thank{font-size:12px;font-weight:bold;color:#000}@media print{@page{size:A4 portrait;margin:10mm 0}body{background:none;padding:0}.receipt{box-shadow:none;width:302px;margin:0 auto}}
</style>
</head>
<body>
<div class="receipt">
<div class="sale-no">${escapeHtml(title)}</div>
${transactionNo ? `<div class="sale-no">TXN: ${escapeHtml(transactionNo)}</div>` : ""}
${logo ? `<div class="r-logo"><img src="${logo}" alt="logo"></div>` : ""}
<div class="r-store">${store}</div>
<div class="r-info">${address ? address + "<br>" : ""}${phone ? "Tel: " + phone : ""}</div>
<hr class="r-eq">
<div class="r-meta">Date    : ${escapeHtml(date)}</div>
<div class="r-meta">Cashier : ${cashier}</div>
<hr class="r-dash">
<table><thead><tr>
<th class="th-name">Item</th>
<th class="th-qty">Qty</th>
<th class="th-price">Price</th>
<th class="th-total">Total</th>
</tr></thead><tbody>${itemsHtml}</tbody></table>
<hr class="r-dash">
<table class="totals">
<tr><td class="t-label">Subtotal</td><td class="t-value">${escapeHtml(subtotal)}</td></tr>
<tr><td class="t-label">Discount</td><td class="t-value discount">${escapeHtml(discount)}</td></tr>
<tr><td class="t-label">VAT (${vatRate}%)</td><td class="t-value">${escapeHtml(vat)}</td></tr>
</table>
<hr class="r-solid">
<table class="totals">
<tr class="grand-row"><td class="t-label">TOTAL</td><td class="t-value">${escapeHtml(grandTotal)}</td></tr>
${paymentLabel ? `<tr><td class="t-label">Payment</td><td class="t-value">${escapeHtml(paymentLabel)}</td></tr>` : ""}
${change       ? `<tr><td class="t-label">Change</td><td class="t-value">${escapeHtml(change)}</td></tr>`       : ""}
</table>
<hr class="r-eq">
<div class="r-footer"><div class="thank">Thank you!</div>Please come again.<br><small>${escapeHtml(date)}</small></div>
</div>
<script>window.onload=()=>window.print();<\/script>
</body>
</html>`;
}

function openReceiptWindow(html) {
    const w = window.open("", "_blank", "width=480,height=700,scrollbars=yes");
    if (!w) return false;
    w.document.write(html);
    w.document.close();
    return true;
}

const printModalOverlay = document.getElementById("printModalOverlay");
document.getElementById("printReceiptBtn")?.addEventListener("click", () => {
    loadPrintSettings();
    setPrintStatus("", "Ready to print");
    printModalOverlay?.classList.add("open");
});

document.getElementById("printModalClose")?.addEventListener("click", () => printModalOverlay?.classList.remove("open"));
printModalOverlay?.addEventListener("click", e => { if (e.target === printModalOverlay) printModalOverlay.classList.remove("open"); });

document.getElementById("printNowBtn")?.addEventListener("click", () => {
    if (!Object.keys(cart).length) { setPrintStatus("err", "Cart is empty"); return; }
    savePrintSettings();
    const date = new Date().toLocaleString("en-PH", { year: "numeric", month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" });
    const itemsHtml = Object.values(cart).map(item => {
        const du  = item.price * (1 - item.discount / 100);
        const pkg = packagingDetailText(item);
        return `<tr>
            <td class="item-name">${escapeHtml(item.name)} (${escapeHtml(item.unit_label)})${item.discount > 0 ? ` <span class="disc-tag">-${item.discount}%</span>` : ""}${pkg ? `<div style="font-size:9px;color:#555;">${escapeHtml(pkg)}</div>` : ""}</td>
            <td class="item-qty">${item.qty}</td>
            <td class="item-price">${escapeHtml(peso(du))}</td>
            <td class="item-total">${escapeHtml(peso(du * item.qty))}</td>
        </tr>`;
    }).join("");

    if (!openReceiptWindow(buildReceiptHtml({
        title: "POS Receipt", transactionNo: "", itemsHtml,
        subtotal:   document.getElementById("subtotal").textContent,
        discount:   document.getElementById("total-discount").textContent,
        vat:        document.getElementById("vat").textContent,
        grandTotal: document.getElementById("grand-total").textContent,
        date,
    }))) { setPrintStatus("err", "Pop-up blocked — allow pop-ups for this site"); return; }

    setPrintStatus("ok", "Receipt opened");
    setTimeout(() => printModalOverlay?.classList.remove("open"), 1800);
});

/* ─── Checkout modal ───────────────────────────────────────────────────────── */
const checkoutOverlay  = document.getElementById("checkoutModalOverlay");
const checkoutClose    = document.getElementById("checkoutModalClose");
const confirmBtn       = document.getElementById("confirmCheckoutBtn");
const cashField        = document.getElementById("cashTenderedField");
const cashInput        = document.getElementById("cashTendered");
const changeEl         = document.getElementById("changeAmount");

let selectedPayment = "cash";
let lastSaleData    = null;

function setCheckoutStatus(type, msg) {
    const el   = document.getElementById("checkoutStatus");
    const text = document.getElementById("checkoutStatusText");
    if (!el || !text) return;
    el.className     = `print-status ${type}`;
    text.textContent = msg;
}

document.getElementById("checkoutBtn")?.addEventListener("click", () => {
    if (!Object.keys(cart).length) return;
    document.getElementById("co-subtotal").textContent  = document.getElementById("subtotal").textContent;
    document.getElementById("co-discount").textContent  = document.getElementById("total-discount").textContent;
    document.getElementById("co-vat").textContent       = document.getElementById("vat").textContent;
    document.getElementById("co-total").textContent     = document.getElementById("grand-total").textContent;
    selectedPayment = "cash";
    document.querySelectorAll(".pay-method-btn").forEach(b => b.classList.remove("active"));
    document.querySelector('.pay-method-btn[data-method="cash"]')?.classList.add("active");
    if (cashField)  cashField.style.display = "";
    if (cashInput)  cashInput.value         = "";
    if (changeEl)   changeEl.textContent    = peso(0);
    setCheckoutStatus("", "Ready to complete sale");
    checkoutOverlay?.classList.add("open");
});

checkoutClose?.addEventListener("click", () => checkoutOverlay?.classList.remove("open"));
checkoutOverlay?.addEventListener("click", e => { if (e.target === checkoutOverlay) checkoutOverlay.classList.remove("open"); });

document.getElementById("paymentMethods")?.addEventListener("click", e => {
    const btn = e.target.closest(".pay-method-btn");
    if (!btn) return;
    document.querySelectorAll(".pay-method-btn").forEach(b => b.classList.remove("active"));
    btn.classList.add("active");
    selectedPayment = btn.dataset.method || "cash";
    if (cashField) cashField.style.display = selectedPayment === "cash" ? "" : "none";
    if (selectedPayment !== "cash" && changeEl) changeEl.textContent = peso(0);
});

cashInput?.addEventListener("input", () => {
    const tendered = parseFloat(cashInput.value || "0") || 0;
    const total    = parseDisplayedAmount(document.getElementById("co-total")?.textContent || "");
    const change   = tendered - total;
    changeEl.textContent = change >= 0 ? peso(change) : peso(0);
    changeEl.style.color = change >= 0 ? "var(--accent-green)" : "var(--accent-red)";
});

confirmBtn?.addEventListener("click", async () => {
    const items = Object.values(cart);
    if (!items.length) { setCheckoutStatus("err", "Cart is empty"); return; }

    const total = parseDisplayedAmount(document.getElementById("grand-total")?.textContent || "");
    if (total <= 0) { setCheckoutStatus("err", "Order total must be greater than zero"); return; }

    if (selectedPayment === "cash") {
        const tendered = parseFloat(cashInput?.value || "0") || 0;
        if (tendered < total) { setCheckoutStatus("err", "Cash tendered is less than total"); return; }
    }

    const payload = {
        items: items.map(i => ({
            product_id:      i.product_id,
            quantity:        i.qty,
            unit_type:       i.unit_type,
            unit_multiplier: i.unit_multiplier,
            unit_price:      parseFloat((i.price * (1 - i.discount / 100)).toFixed(2)),
        })),
        payment_method:  selectedPayment,
        total_amount:    parseDisplayedAmount(document.getElementById("grand-total")?.textContent   || ""),
        tax_amount:      parseDisplayedAmount(document.getElementById("vat")?.textContent           || ""),
        discount_amount: parseDisplayedAmount(document.getElementById("total-discount")?.textContent || ""),
        csrf_token:      getCsrfToken(),
    };

    if (confirmBtn) confirmBtn.disabled = true;
    setCheckoutStatus("busy", "Saving sale…");

    try {
        const res  = await fetch("/inventory_system/http/ajax/checkout.php", {
            method:  "POST",
            headers: {
                "Content-Type":    "application/json",
                "Accept":          "application/json",
                "X-Requested-With": "XMLHttpRequest",
                "X-CSRF-Token":    payload.csrf_token,
            },
            body: JSON.stringify(payload),
        });
        const data = await res.json();

        if (!data.success) {
            setCheckoutStatus("err", data.error || "Checkout failed");
            if (data.notification_type) sendCheckoutNotificationUpdate(data.notification_type);
            return;
        }

        const txn = data.transaction_no || formatTransactionNo(data.sale_id);
        setCheckoutStatus("ok", `${txn} saved`);
        if (data.notification_type) sendCheckoutNotificationUpdate(data.notification_type);
        clearStoredCart();

        lastSaleData = {
            sale_id:     data.sale_id,
            transaction_no: txn,
            items:       items.map(i => ({ ...i })),
            subtotal:    document.getElementById("subtotal")?.textContent      || "",
            discount:    document.getElementById("total-discount")?.textContent || "",
            vat:         document.getElementById("vat")?.textContent            || "",
            grand_total: document.getElementById("grand-total")?.textContent    || "",
            payment:     selectedPayment,
            change:      changeEl?.textContent || "",
            date:        new Date().toLocaleString("en-PH", {
                year: "numeric", month: "short", day: "numeric", hour: "2-digit", minute: "2-digit",
            }),
        };

        setTimeout(() => {
            checkoutOverlay?.classList.remove("open");
            document.getElementById("printPromptOverlay")?.classList.add("open");
        }, 900);

    } catch (_) {
        setCheckoutStatus("err", "Network error — please try again");
    } finally {
        if (confirmBtn) confirmBtn.disabled = false;
    }
});

/* ─── Print prompt ─────────────────────────────────────────────────────────── */
function printReceipt(sale) {
    const labelMap = { cash: "Cash", gcash: "GCash", card: "Card", other: "Other" };
    const itemsHtml = sale.items.map(item => {
        const du  = item.price * (1 - item.discount / 100);
        const pkg = packagingDetailText(item);
        return `<tr>
            <td class="item-name">${escapeHtml(item.name)} (${escapeHtml(item.unit_label)})${item.discount > 0 ? ` <span class="disc-tag">-${item.discount}%</span>` : ""}${pkg ? `<div style="font-size:9px;color:#555;">${escapeHtml(pkg)}</div>` : ""}</td>
            <td class="item-qty">${item.qty}</td>
            <td class="item-price">${escapeHtml(peso(du))}</td>
            <td class="item-total">${escapeHtml(peso(du * item.qty))}</td>
        </tr>`;
    }).join("");

    if (!openReceiptWindow(buildReceiptHtml({
        title:        "POS Receipt",
        transactionNo: sale.transaction_no || formatTransactionNo(sale.sale_id, sale.date),
        itemsHtml,
        subtotal:     sale.subtotal,
        discount:     sale.discount,
        vat:          sale.vat,
        grandTotal:   sale.grand_total,
        paymentLabel: labelMap[sale.payment] || sale.payment,
        change:       sale.payment === "cash" ? sale.change : "",
        date:         sale.date,
    }))) {
        alert("Pop-up blocked — please allow pop-ups for this site.");
    }
}

function clearCartAndClose() {
    for (const k of Object.keys(cart)) delete cart[k];
    clearStoredCart();
    setCartRecoveryNote("");
    updateAllCards();
    renderCart();
    document.getElementById("printPromptOverlay")?.classList.remove("open");
}

document.getElementById("printPromptYes")?.addEventListener("click", () => {
    document.getElementById("printPromptOverlay")?.classList.remove("open");
    if (lastSaleData) printReceipt(lastSaleData);
    clearCartAndClose();
});

document.getElementById("printPromptNo")?.addEventListener("click", clearCartAndClose);

/* ─── Sale breakdown modal ─────────────────────────────────────────────────── */
function renderSaleBreakdown(sale) {
    const body  = document.getElementById("saleBreakdownBody");
    const title = document.getElementById("saleBreakdownTitle");
    const meta  = document.getElementById("saleBreakdownMeta");
    if (!body || !sale) return;

    if (title) title.textContent = sale.transaction_no || formatTransactionNo(sale.sale_id, sale.date);
    if (meta)  meta.textContent  = `${sale.date || ""} | ${String(sale.payment || "cash").toUpperCase()} payment`;

    const rows = (sale.items || []).map(item => {
        const du  = Number(item.price || 0) * (1 - Number(item.discount || 0) / 100);
        const pkg = saleBreakdownPackagingText(item);
        return `<tr>
            <td>
                <strong>${escapeHtml(item.name)}</strong>
                <span style="display:block;color:var(--text-secondary);font-size:12px;">
                    ${escapeHtml(item.unit_label || "Piece")}
                    ${Number(item.discount || 0) > 0 ? ` | ${Number(item.discount)}% discount` : ""}
                </span>
                <span style="display:block;color:var(--text-secondary);font-size:11px;">${escapeHtml(pkg.primary || "")}</span>
                ${pkg.secondary ? `<span style="display:block;color:var(--text-secondary);font-size:11px;">${escapeHtml(pkg.secondary)}</span>` : ""}
                <span style="display:block;color:var(--text-secondary);font-size:11px;font-weight:700;">${escapeHtml(pkg.total || "")}</span>
            </td>
            <td style="text-align:right;">${Number(item.qty || 0).toLocaleString()}</td>
            <td style="text-align:right;">${escapeHtml(peso(du))}</td>
            <td style="text-align:right;font-weight:800;">${escapeHtml(peso(du * Number(item.qty || 0)))}</td>
        </tr>`;
    }).join("");

    body.innerHTML = `
        <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px;">
            <div class="sale-breakdown-chip"><span>Subtotal</span><strong>${escapeHtml(sale.subtotal)}</strong></div>
            <div class="sale-breakdown-chip"><span>Discount</span><strong>${escapeHtml(sale.discount)}</strong></div>
            <div class="sale-breakdown-chip"><span>VAT</span><strong>${escapeHtml(sale.vat)}</strong></div>
            <div class="sale-breakdown-chip"><span>Total</span><strong>${escapeHtml(sale.grand_total)}</strong></div>
        </div>
        <div style="max-height:360px;overflow:auto;border:1px solid var(--border);border-radius:14px;">
            <table class="sale-breakdown-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th style="text-align:right;">Qty</th>
                        <th style="text-align:right;">Unit Price</th>
                        <th style="text-align:right;">Line Total</th>
                    </tr>
                </thead>
                <tbody>${rows || '<tr><td colspan="4" style="text-align:center;color:var(--text-secondary);padding:18px;">No items.</td></tr>'}</tbody>
            </table>
        </div>
        ${sale.payment === "cash" ? `<div style="margin-top:12px;color:var(--text-secondary);font-weight:700;">Change: <strong style="color:var(--accent-green);">${escapeHtml(sale.change)}</strong></div>` : ""}`;
}

document.getElementById("printPromptDetails")?.addEventListener("click", () => {
    if (!lastSaleData) return;
    renderSaleBreakdown(lastSaleData);
    document.getElementById("saleBreakdownOverlay")?.classList.add("open");
});

document.getElementById("saleBreakdownClose")?.addEventListener("click", () => {
    document.getElementById("saleBreakdownOverlay")?.classList.remove("open");
});