const POS_SUBCATEGORIES = window.POS_SUBCATEGORIES || [];

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function peso(value) {
    return `₱${Number(value || 0).toFixed(2)}`;
}

function parseDisplayedAmount(text) {
    return parseFloat(String(text || '').replace(/[^\d.-]/g, '')) || 0;
}

function formatTransactionNo(saleId, dateValue = null) {
    const numericSaleId = Number(saleId || 0);
    if (!Number.isFinite(numericSaleId) || numericSaleId <= 0) {
        return 'SALE-00000000-000000';
    }

    const sourceDate = dateValue ? new Date(dateValue) : new Date();
    const safeDate = Number.isNaN(sourceDate.getTime()) ? new Date() : sourceDate;
    const year = safeDate.getFullYear();
    const month = String(safeDate.getMonth() + 1).padStart(2, '0');
    const day = String(safeDate.getDate()).padStart(2, '0');

    return `SALE-${year}${month}${day}-${String(numericSaleId).padStart(6, '0')}`;
}

function updateClock() {
    const now = new Date();
    const opts = { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' };
    const pill = document.getElementById('timePill');
    if (!pill) return;
    pill.innerHTML = now.toLocaleDateString('en-PH', opts).replace(',', ' &mdash; <span>') + '</span>';
}
updateClock();
setInterval(updateClock, 10000);

function sendCheckoutNotificationUpdate(type = 'sale_success') {
    if (window.socket && window.socket.readyState === WebSocket.OPEN) {
        const userId = Number(POS_CONFIG.userId || 0);
        const payload = {
            event: 'notification_update',
            type,
            target_roles: ['admin'],
        };

        if (Number.isFinite(userId) && userId > 0) {
            payload.target_user_ids = [userId];
        }

        window.socket.send(JSON.stringify(payload));
    }
}

const productCards = Array.from(document.querySelectorAll('.product-card'));
const cartItemsEl = document.getElementById('cart-items');
const cartRecoveryNoteEl = document.getElementById('cartRecoveryNote');
const cart = {};
const CART_STORAGE_KEY = `pos_cart_${String(POS_CONFIG.userId || 'guest')}`;
const CART_STORAGE_MAX_AGE_MS = 1000 * 60 * 60 * 24;

function cartKey(productId, unitType) {
    return `${productId}:${unitType}`;
}

function setCartRecoveryNote(message = '', tone = 'muted') {
    if (!cartRecoveryNoteEl) return;

    if (!message) {
        cartRecoveryNoteEl.hidden = true;
        cartRecoveryNoteEl.textContent = '';
        cartRecoveryNoteEl.className = 'small text-muted mt-2';
        return;
    }

    const toneClass = tone === 'warning'
        ? 'text-warning'
        : tone === 'success'
            ? 'text-success'
            : 'text-muted';

    cartRecoveryNoteEl.hidden = false;
    cartRecoveryNoteEl.textContent = message;
    cartRecoveryNoteEl.className = `small ${toneClass} mt-2`;
}

function buildCartLineItem(data, unit, qty) {
    return {
        product_id: data.productId,
        name: data.name,
        qty,
        price: unit.regularPrice,
        vatable: data.vatable,
        discount: unit.discount || 0,
        unit_type: unit.unitType,
        unit_label: unit.unitLabel,
        unit_multiplier: unit.multiplier,
    };
}

function serializeCart() {
    return Object.values(cart).map((item) => ({
        product_id: item.product_id,
        unit_type: item.unit_type,
        qty: item.qty,
    }));
}

function clearStoredCart() {
    try {
        localStorage.removeItem(CART_STORAGE_KEY);
    } catch (_) {}
}

function persistCart() {
    try {
        const items = serializeCart();
        if (items.length === 0) {
            clearStoredCart();
            return;
        }

        localStorage.setItem(CART_STORAGE_KEY, JSON.stringify({
            saved_at: Date.now(),
            items,
        }));
    } catch (_) {}
}

function restoreCartFromStorage() {
    try {
        const raw = localStorage.getItem(CART_STORAGE_KEY);
        if (!raw) return;

        const payload = JSON.parse(raw);
        const savedAt = Number(payload?.saved_at || 0);
        const storedItems = Array.isArray(payload?.items) ? payload.items : [];

        if (!storedItems.length) {
            clearStoredCart();
            return;
        }

        if (!savedAt || (Date.now() - savedAt) > CART_STORAGE_MAX_AGE_MS) {
            clearStoredCart();
            setCartRecoveryNote('Saved cart expired and was cleared.', 'warning');
            return;
        }

        const cardByProductId = new Map(productCards.map((card) => [parseInt(card.dataset.id || '0', 10), card]));
        const reservedBaseQty = {};
        let restoredLines = 0;
        let adjustedLines = 0;

        storedItems.forEach((storedItem) => {
            const productId = parseInt(storedItem?.product_id || '0', 10);
            const unitType = String(storedItem?.unit_type || 'piece');
            const requestedQty = Math.max(0, parseInt(storedItem?.qty || '0', 10));

            if (productId <= 0 || requestedQty <= 0) {
                return;
            }

            const card = cardByProductId.get(productId);
            if (!card || card.classList.contains('stock-out')) {
                adjustedLines += 1;
                return;
            }

            const data = getCardData(card);
            const unit = data.units[unitType];
            if (!unit) {
                adjustedLines += 1;
                return;
            }

            const reserved = reservedBaseQty[productId] || 0;
            const remainingBaseStock = Math.max(0, data.maxStock - reserved);
            const allowedQty = Math.min(requestedQty, Math.floor(remainingBaseStock / unit.multiplier));

            if (allowedQty <= 0) {
                adjustedLines += 1;
                return;
            }

            if (allowedQty < requestedQty) {
                adjustedLines += 1;
            }

            cart[cartKey(productId, unit.unitType)] = buildCartLineItem(data, unit, allowedQty);
            reservedBaseQty[productId] = reserved + (allowedQty * unit.multiplier);
            restoredLines += 1;
        });

        if (restoredLines > 0) {
            setCartRecoveryNote(
                adjustedLines > 0
                    ? 'Cart restored with stock adjustments based on current inventory.'
                    : 'Cart restored from your last unfinished sale.',
                adjustedLines > 0 ? 'warning' : 'success'
            );
        } else {
            clearStoredCart();
            setCartRecoveryNote('Saved cart could not be restored with current stock.', 'warning');
        }
    } catch (_) {
        clearStoredCart();
    }
}

function getCardData(card) {
    const piecesPerBox = Math.max(1, parseInt(card.dataset.piecesPerBox || '1', 10));
    const boxesPerCase = Math.max(1, parseInt(card.dataset.boxesPerCase || '1', 10));
    const casePieces = piecesPerBox * boxesPerCase;
    const categoryName = String(card.dataset.categoryName || '').toLowerCase();
    const isBeverageCategory = categoryName.includes('beverage');
    const piecePrice = parseFloat(card.dataset.price || '0') || 0;
    const boxPrice = card.dataset.boxPrice !== '' ? parseFloat(card.dataset.boxPrice) : NaN;
    const casePrice = card.dataset.casePrice !== '' ? parseFloat(card.dataset.casePrice) : NaN;
    const pieceDiscount = parseFloat(card.dataset.pieceDiscount || card.dataset.discount || '0') || 0;
    const boxDiscount = parseFloat(card.dataset.boxDiscount || '0') || 0;
    const caseDiscount = parseFloat(card.dataset.caseDiscount || '0') || 0;
    const hasBoxUnit = !isBeverageCategory && piecesPerBox > 1 && Number.isFinite(boxPrice) && boxPrice > 0;
    const hasCaseUnit = casePieces > 1 && Number.isFinite(casePrice) && casePrice > 0;

    return {
        productId: parseInt(card.dataset.id || '0', 10),
        name: card.dataset.name || '',
        vatable: Number(card.dataset.vat || '0') === 1,
        maxStock: parseInt(card.dataset.stock || '0', 10) || 0,
        units: {
            piece: {
                unitType: 'piece',
                unitLabel: 'Piece',
                multiplier: 1,
                regularPrice: piecePrice,
                discount: pieceDiscount,
            },
            ...(hasBoxUnit ? {
                box: {
                    unitType: 'box',
                    unitLabel: 'Box',
                    multiplier: piecesPerBox,
                    regularPrice: boxPrice,
                    discount: boxDiscount,
                },
            } : {}),
            ...(hasCaseUnit ? {
                case: {
                    unitType: 'case',
                    unitLabel: 'Case',
                    multiplier: casePieces,
                    regularPrice: casePrice,
                    discount: caseDiscount,
                },
            } : {}),
        },
    };
}

function getDefaultUnitType(data) {
    return Object.keys(data.units)[0] || 'piece';
}

function getUnitByType(card, unitType) {
    const data = getCardData(card);
    return data.units[unitType] || data.units[getDefaultUnitType(data)] || data.units.piece;
}

function getProductBaseQtyInCart(productId) {
    return Object.values(cart)
        .filter((item) => item.product_id === productId)
        .reduce((sum, item) => sum + (item.qty * item.unit_multiplier), 0);
}

function getVisibleQtyForProduct(productId) {
    return Object.values(cart)
        .filter((item) => item.product_id === productId)
        .reduce((sum, item) => sum + item.qty, 0);
}

function updateCardPrice(card) {
    const data = getCardData(card);
    const selectedUnit = data.units[getDefaultUnitType(data)] || data.units.piece;
    const priceEl = card.querySelector('.card-price');
    if (!priceEl) return;

    const effectivePrice = selectedUnit.regularPrice * (1 - ((selectedUnit.discount || 0) / 100));
    priceEl.textContent = peso(effectivePrice);
}

function buildCardHoverHint(card) {
    const data = getCardData(card);
    const unitLabels = Object.values(data.units).map((unit) => unit.unitLabel);

    if (unitLabels.length <= 1) {
        return 'Tap this product to add it to the cart.';
    }

    if (unitLabels.length === 2) {
        return `Tap to select by ${unitLabels[0]} or ${unitLabels[1]}.`;
    }

    const lastUnit = unitLabels[unitLabels.length - 1];
    const initialUnits = unitLabels.slice(0, -1).join(', ');
    return `Tap to select by ${initialUnits}, or ${lastUnit}.`;
}

function updateCardVisualState(card) {
    const productId = parseInt(card.dataset.id || '0', 10);
    const data = getCardData(card);
    const primaryUnitType = getDefaultUnitType(data);
    const primaryItem = cart[cartKey(productId, primaryUnitType)];
    const currentQty = primaryItem ? primaryItem.qty : 0;
    const totalQty = getVisibleQtyForProduct(productId);

    const qtyEl = card.querySelector('.qty');
    if (qtyEl) {
        qtyEl.textContent = currentQty;
    }
    card.querySelector('.qty-badge').textContent = totalQty;
    card.classList.toggle('has-qty', totalQty > 0);
    card.classList.toggle('show-unit-selector', totalQty > 0 && !!card.querySelector('.card-order-pill'));
    card.querySelector('.card-hover-hint').textContent = buildCardHoverHint(card);
    updateCardPrice(card);
}

function updateAllCards(productId = null) {
    productCards.forEach((card) => {
        const cardProductId = parseInt(card.dataset.id || '0', 10);
        if (productId !== null && cardProductId !== productId) {
            return;
        }
        updateCardVisualState(card);
    });
}

function applyCartQuantity(card, unitType, delta) {
    if (card.classList.contains('stock-out')) return false;

    const data = getCardData(card);
    const selectedUnit = getUnitByType(card, unitType);
    const key = cartKey(data.productId, selectedUnit.unitType);
    const existing = cart[key] || {
        product_id: data.productId,
        name: data.name,
        qty: 0,
        price: selectedUnit.regularPrice,
        vatable: data.vatable,
        discount: selectedUnit.discount || 0,
        unit_type: selectedUnit.unitType,
        unit_label: selectedUnit.unitLabel,
        unit_multiplier: selectedUnit.multiplier,
    };

    const nextBaseQty = getProductBaseQtyInCart(data.productId) + (delta * selectedUnit.multiplier);
    if (delta > 0 && nextBaseQty > data.maxStock) {
        return false;
    }

    existing.qty += delta;

    if (existing.qty <= 0) {
        delete cart[key];
    } else {
        existing.price = selectedUnit.regularPrice;
        existing.discount = selectedUnit.discount || 0;
        existing.unit_type = selectedUnit.unitType;
        existing.unit_label = selectedUnit.unitLabel;
        existing.unit_multiplier = selectedUnit.multiplier;
        cart[key] = existing;
    }

    updateAllCards(data.productId);
    setCartRecoveryNote('');
    renderCart();
    return true;
}

function renderCart() {
    if (!cartItemsEl) return;

    cartItemsEl.innerHTML = '';
    let subtotal = 0;
    let totalDiscount = 0;
    let totalVAT = 0;
    let itemCount = 0;
    const keys = Object.keys(cart);

    if (keys.length === 0) {
        cartItemsEl.innerHTML = `
            <div class="cart-empty">
                <div class="cart-empty-icon">🛒</div>
                <p>Cart is empty</p>
            </div>`;
        document.getElementById('cartCountLabel').textContent = 'No items in cart';
    } else {
        keys.forEach((key) => {
            const item = cart[key];
            const discountedUnitPrice = item.price * (1 - item.discount / 100);
            const lineTotal = item.qty * discountedUnitPrice;
            subtotal += lineTotal;
            totalDiscount += item.qty * (item.price - discountedUnitPrice);
            if (item.vatable) {
                totalVAT += lineTotal * (Number(POS_CONFIG.vatRate || 12) / 100);
            }
            itemCount += item.qty;

            const row = document.createElement('div');
            row.className = 'cart-item';
            row.innerHTML = `
                <div class="cart-item-row">
                    <span class="cart-item-name">${escapeHtml(item.name)} <small>(${escapeHtml(item.unit_label)})</small></span>
                    <span class="cart-item-line-total">${peso(lineTotal)}</span>
                </div>
                <div class="cart-item-meta">
                    ${peso(item.price)} / ${escapeHtml(item.unit_label.toLowerCase())}${item.discount > 0 ? ` · -${item.discount}% off` : ''} · ${item.unit_multiplier} pc${item.unit_multiplier === 1 ? '' : 's'} each
                </div>
                <div class="cart-item-controls">
                    <button class="cart-ctrl-btn cart-decrease">−</button>
                    <span class="cart-ctrl-qty">${item.qty}</span>
                    <button class="cart-ctrl-btn cart-increase">+</button>
                    <button class="cart-ctrl-btn remove" style="margin-left:6px;">✕</button>
                </div>
            `;
            cartItemsEl.appendChild(row);

            row.querySelector('.cart-increase')?.addEventListener('click', () => {
                const maxStock = parseInt(document.querySelector(`.product-card[data-id="${item.product_id}"]`)?.dataset.stock || '0', 10);
                if ((getProductBaseQtyInCart(item.product_id) + item.unit_multiplier) > maxStock) return;
                item.qty += 1;
                updateAllCards(item.product_id);
                setCartRecoveryNote('');
                renderCart();
            });

            row.querySelector('.cart-decrease')?.addEventListener('click', () => {
                item.qty -= 1;
                if (item.qty <= 0) {
                    delete cart[key];
                }
                updateAllCards(item.product_id);
                setCartRecoveryNote('');
                renderCart();
            });

            row.querySelector('.remove')?.addEventListener('click', () => {
                delete cart[key];
                updateAllCards(item.product_id);
                setCartRecoveryNote('');
                renderCart();
            });
        });

        document.getElementById('cartCountLabel').textContent =
            `${itemCount} item${itemCount !== 1 ? 's' : ''} · ${keys.length} line${keys.length !== 1 ? 's' : ''}`;
    }

    const grandTotal = subtotal + totalVAT;
    document.getElementById('subtotal').textContent = peso(subtotal);
    document.getElementById('total-discount').textContent = `−${peso(totalDiscount)}`;
    document.getElementById('vat').textContent = peso(totalVAT);
    document.getElementById('grand-total').textContent = peso(grandTotal);

    persistCart();
    updateReceiptBtn();
}

restoreCartFromStorage();
renderCart();

const productUnitModalOverlay = document.getElementById('productUnitModalOverlay');
const productUnitModalClose = document.getElementById('productUnitModalClose');
const productUnitModalTitle = document.getElementById('productUnitModalTitle');
const productUnitModalImage = document.getElementById('productUnitModalImage');
const productUnitModalName = document.getElementById('productUnitModalName');
const productUnitModalSubcat = document.getElementById('productUnitModalSubcat');
const productUnitModalStock = document.getElementById('productUnitModalStock');
const productUnitOptions = document.getElementById('productUnitOptions');
const productUnitQtyDecrease = document.getElementById('productUnitQtyDecrease');
const productUnitQtyIncrease = document.getElementById('productUnitQtyIncrease');
const productUnitQtyValue = document.getElementById('productUnitQtyValue');
const productUnitModalTotal = document.getElementById('productUnitModalTotal');
const productUnitPackaging = document.getElementById('productUnitPackaging');
const productUnitAddToCartBtn = document.getElementById('productUnitAddToCartBtn');

let activeProductModalCard = null;
let activeProductModalUnitType = 'piece';
let activeProductModalQty = 1;

function getUnitAvailableCount(data, unit) {
    return Math.floor(data.maxStock / unit.multiplier);
}

function getEffectiveUnitPrice(unit) {
    return unit.regularPrice * (1 - ((unit.discount || 0) / 100));
}

function renderProductUnitModal() {
    if (!activeProductModalCard) return;

    const data = getCardData(activeProductModalCard);
    const selectedUnit = data.units[activeProductModalUnitType] || data.units[getDefaultUnitType(data)];
    const units = Object.values(data.units);

    productUnitModalTitle.textContent = data.name;
    productUnitModalName.textContent = data.name;
    productUnitModalSubcat.textContent = activeProductModalCard.dataset.subcategoryName || 'General';
    productUnitModalStock.textContent = `${data.maxStock} pcs in stock`;
    productUnitModalImage.src = activeProductModalCard.dataset.photo || '';
    productUnitModalImage.alt = data.name;

    productUnitOptions.innerHTML = units.map((unit) => {
        const available = getUnitAvailableCount(data, unit);
        const isActive = unit.unitType === activeProductModalUnitType;
        return `
            <button type="button" class="product-unit-option${isActive ? ' active' : ''}" data-unit-type="${unit.unitType}">
                <span class="product-unit-option-label">${escapeHtml(unit.unitLabel)}</span>
                <strong class="product-unit-option-price">${peso(getEffectiveUnitPrice(unit))}</strong>
                <small class="product-unit-option-stock">${available} available</small>
            </button>
        `;
    }).join('');

    productUnitQtyValue.textContent = String(activeProductModalQty);
    productUnitModalTotal.textContent = peso(getEffectiveUnitPrice(selectedUnit) * activeProductModalQty);

    const packagingBits = [];
    if (data.units.box) {
        packagingBits.push(`<span>1 Box = ${data.units.box.multiplier} pcs</span>`);
    }
    if (data.units.case) {
        packagingBits.push(`<span>1 Case = ${data.units.case.multiplier} pcs</span>`);
    }
    productUnitPackaging.innerHTML = packagingBits.join('');
    productUnitPackaging.style.display = packagingBits.length > 0 ? '' : 'none';

    productUnitQtyDecrease.disabled = activeProductModalQty <= 1;
    productUnitQtyIncrease.disabled = (getProductBaseQtyInCart(data.productId) + ((activeProductModalQty + 1) * selectedUnit.multiplier)) > data.maxStock;
    productUnitAddToCartBtn.disabled = getUnitAvailableCount(data, selectedUnit) <= 0;
}

function openProductUnitModal(card) {
    if (!card || card.classList.contains('stock-out')) return;

    activeProductModalCard = card;
    const data = getCardData(card);
    activeProductModalUnitType = getDefaultUnitType(data);
    activeProductModalQty = 1;
    renderProductUnitModal();
    productUnitModalOverlay?.classList.add('open');
}

function closeProductUnitModal() {
    productUnitModalOverlay?.classList.remove('open');
    activeProductModalCard = null;
}

productUnitOptions?.addEventListener('click', (event) => {
    const button = event.target.closest('.product-unit-option');
    if (!button || !activeProductModalCard) return;
    activeProductModalUnitType = button.dataset.unitType || activeProductModalUnitType;
    activeProductModalQty = 1;
    renderProductUnitModal();
});

productUnitQtyDecrease?.addEventListener('click', () => {
    if (activeProductModalQty <= 1) return;
    activeProductModalQty -= 1;
    renderProductUnitModal();
});

productUnitQtyIncrease?.addEventListener('click', () => {
    if (!activeProductModalCard) return;
    const data = getCardData(activeProductModalCard);
    const selectedUnit = data.units[activeProductModalUnitType] || data.units[getDefaultUnitType(data)];
    const nextBaseQty = getProductBaseQtyInCart(data.productId) + ((activeProductModalQty + 1) * selectedUnit.multiplier);
    if (nextBaseQty > data.maxStock) return;
    activeProductModalQty += 1;
    renderProductUnitModal();
});

productUnitAddToCartBtn?.addEventListener('click', () => {
    if (!activeProductModalCard) return;

    const applied = applyCartQuantity(activeProductModalCard, activeProductModalUnitType, activeProductModalQty);
    if (applied) {
        closeProductUnitModal();
    }
});

productUnitModalClose?.addEventListener('click', closeProductUnitModal);
productUnitModalOverlay?.addEventListener('click', (event) => {
    if (event.target === productUnitModalOverlay) {
        closeProductUnitModal();
    }
});

productCards.forEach((card) => {
    card.addEventListener('click', () => {
        openProductUnitModal(card);
    });

    card.querySelector('.increase')?.addEventListener('click', (event) => {
        event.stopPropagation();
        const data = getCardData(card);
        applyCartQuantity(card, getDefaultUnitType(data), 1);
    });

    card.querySelector('.decrease')?.addEventListener('click', (event) => {
        event.stopPropagation();
        const data = getCardData(card);
        applyCartQuantity(card, getDefaultUnitType(data), -1);
    });

    card.querySelector('.card-order-pill')?.addEventListener('click', (event) => {
        event.stopPropagation();
        openProductUnitModal(card);
    });

    updateCardVisualState(card);
});

let activeCategoryId = 'all';
let activeSubcategoryId = 'all';
let searchTerm = '';
let currentPage = 1;

function normalizeFilterId(value) {
    const id = String(value ?? '').trim();
    return id === '' || id === '0' ? 'all' : id;
}

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

const categoryBar = document.getElementById('categoryBar');
const subcategoryBar = document.getElementById('subcategoryBar');
const subcategorySection = document.getElementById('subcategorySection');
const subcategoryShell = document.getElementById('subcategoryShell');
const subcategoryPrevBtn = document.getElementById('subcategoryPrevBtn');
const subcategoryNextBtn = document.getElementById('subcategoryNextBtn');
const posBrowserTitle = document.getElementById('posBrowserTitle');
const posProductCount = document.getElementById('posProductCount');

function setActiveButton(container, selector, activeId) {
    const normalizedActiveId = normalizeFilterId(activeId);
    container?.querySelectorAll(selector).forEach((button) => {
        button.classList.toggle('active', normalizeFilterId(button.dataset.id) === normalizedActiveId);
    });
}

function updateSubcategoryOverflow() {
    if (!subcategoryBar || !subcategoryShell || !subcategoryPrevBtn || !subcategoryNextBtn) return;

    const canScroll = subcategoryBar.scrollWidth > subcategoryBar.clientWidth + 4;
    const atStart = subcategoryBar.scrollLeft <= 4;
    const atEnd = subcategoryBar.scrollLeft + subcategoryBar.clientWidth >= subcategoryBar.scrollWidth - 4;

    subcategoryShell.classList.toggle('has-overflow', canScroll);
    subcategoryShell.classList.toggle('show-left-fade', canScroll && !atStart);
    subcategoryShell.classList.toggle('show-right-fade', canScroll && !atEnd);
    subcategoryPrevBtn.disabled = !canScroll || atStart;
    subcategoryNextBtn.disabled = !canScroll || atEnd;
}

function renderSubcategoryBar() {
    if (!subcategoryBar || !subcategorySection) return;

    if (activeCategoryId === 'all') {
        activeSubcategoryId = 'all';
        subcategoryBar.innerHTML = '<button class="sub-pill subcat-btn active" data-id="all">All</button>';
        subcategorySection.classList.add('is-hidden');
        updateSubcategoryOverflow();
        return;
    }

    const scoped = POS_SUBCATEGORIES.filter((subcategory) => (
        normalizeFilterId(subcategory.category_id) === activeCategoryId
    ));

    if (!scoped.some((subcategory) => normalizeFilterId(subcategory.subcategory_id) === activeSubcategoryId)) {
        activeSubcategoryId = 'all';
    }

    subcategoryBar.innerHTML = `
        <button class="sub-pill subcat-btn ${activeSubcategoryId === 'all' ? 'active' : ''}" data-id="all">All</button>
        ${scoped.map((subcategory) => `
            <button class="sub-pill subcat-btn ${normalizeFilterId(subcategory.subcategory_id) === activeSubcategoryId ? 'active' : ''}" data-id="${subcategory.subcategory_id}">
                ${escapeHtml(subcategory.subcategory_name)}
            </button>
        `).join('')}
    `;

    subcategorySection.classList.toggle('is-hidden', scoped.length === 0);
    subcategoryBar.scrollLeft = 0;
    updateSubcategoryOverflow();
}

function updateBrowserHeader(matchedCount = null) {
    const activeCategoryButton = categoryBar?.querySelector(`.cat-btn[data-id="${CSS.escape(activeCategoryId)}"]`);
    const categoryName = activeCategoryButton?.dataset.name || 'All Categories';
    let title = categoryName;

    if (activeSubcategoryId !== 'all') {
        const activeSubcategoryButton = subcategoryBar?.querySelector(`.subcat-btn[data-id="${CSS.escape(activeSubcategoryId)}"]`);
        const subcategoryName = activeSubcategoryButton?.textContent?.trim();
        if (subcategoryName) {
            title = `${categoryName} / ${subcategoryName}`;
        }
    }

    if (posBrowserTitle) posBrowserTitle.textContent = title;
    if (posProductCount && matchedCount !== null) {
        posProductCount.textContent = `${matchedCount} item${matchedCount === 1 ? '' : 's'}`;
    }
}

categoryBar?.addEventListener('click', (event) => {
    const button = event.target.closest('.cat-btn');
    if (!button) return;
    activeCategoryId = normalizeFilterId(button.dataset.id);
    activeSubcategoryId = 'all';
    currentPage = 1;
    setActiveButton(categoryBar, '.cat-btn', activeCategoryId);
    renderSubcategoryBar();
    applyFilters();
});

subcategoryBar?.addEventListener('click', (event) => {
    const button = event.target.closest('.subcat-btn');
    if (!button) return;
    activeSubcategoryId = normalizeFilterId(button.dataset.id);
    currentPage = 1;
    setActiveButton(subcategoryBar, '.subcat-btn', activeSubcategoryId);
    applyFilters();
});

subcategoryBar?.addEventListener('scroll', updateSubcategoryOverflow);
subcategoryPrevBtn?.addEventListener('click', () => subcategoryBar.scrollBy({ left: -220, behavior: 'smooth' }));
subcategoryNextBtn?.addEventListener('click', () => subcategoryBar.scrollBy({ left: 220, behavior: 'smooth' }));
window.addEventListener('resize', updateSubcategoryOverflow);

document.getElementById('productSearch')?.addEventListener('input', (event) => {
    searchTerm = event.target.value.toLowerCase().trim();
    currentPage = 1;
    applyFilters();
});

function buildPagination(active, total) {
    const bar = document.getElementById('productPagination');
    if (!bar) return;
    bar.innerHTML = '';
    if (total <= 1) return;

    const makeBtn = (label, page, disabled = false) => {
        const button = document.createElement('button');
        button.className = 'pg-btn' + (page === active ? ' active' : '');
        button.textContent = label;
        button.disabled = disabled;
        button.addEventListener('click', () => {
            currentPage = page;
            applyFilters();
        });
        bar.appendChild(button);
    };

    makeBtn('«', active - 1, active === 1);
    for (let page = 1; page <= total; page++) {
        makeBtn(page, page);
    }
    makeBtn('»', active + 1, active === total);
}

function applyFilters() {
    const itemsPerPage = getItemsPerPage();
    const matched = productCards.filter((card) => {
        const categoryId = normalizeFilterId(card.dataset.category);
        const subcategoryId = normalizeFilterId(card.dataset.subcategory);
        const categoryMatch = activeCategoryId === 'all' || categoryId === activeCategoryId;
        const subcategoryMatch = activeSubcategoryId === 'all' || subcategoryId === activeSubcategoryId;
        const searchMatch = searchTerm === '' || (card.dataset.name || '').toLowerCase().includes(searchTerm);
        return categoryMatch && subcategoryMatch && searchMatch;
    });

    updateBrowserHeader(matched.length);
    productCards.forEach((card) => { card.style.display = 'none'; });

    if (activeCategoryId === 'all' && activeSubcategoryId === 'all' && searchTerm === '') {
        matched.forEach((card) => { card.style.display = ''; });
        buildPagination(0, 0);
        return;
    }

    const totalPages = Math.ceil(matched.length / itemsPerPage);
    currentPage = Math.min(currentPage, totalPages || 1);
    const start = (currentPage - 1) * itemsPerPage;
    const end = start + itemsPerPage;

    matched.slice(start, end).forEach((card) => { card.style.display = ''; });
    buildPagination(currentPage, totalPages);
}

renderSubcategoryBar();
applyFilters();

const printModalOverlay = document.getElementById('printModalOverlay');
const printReceiptBtn = document.getElementById('printReceiptBtn');
const printModalClose = document.getElementById('printModalClose');
const printNowBtn = document.getElementById('printNowBtn');
const printStatus = document.getElementById('printStatus');
const printStatusText = document.getElementById('printStatusText');
const SETTINGS_KEY = 'pos_print_settings';

function loadPrintSettings() {
    try {
        const settings = JSON.parse(localStorage.getItem(SETTINGS_KEY) || '{}');
        document.getElementById('pStoreName').value = settings.storeName || POS_CONFIG.storeName || '';
        document.getElementById('pAddress').value = settings.address || POS_CONFIG.address || '';
        document.getElementById('pPhone').value = settings.phone || POS_CONFIG.phone || '';
        document.getElementById('pCashier').value = settings.cashier || POS_CONFIG.cashier || '';
    } catch (_) {}
}

function savePrintSettings() {
    localStorage.setItem(SETTINGS_KEY, JSON.stringify({
        storeName: document.getElementById('pStoreName').value,
        address: document.getElementById('pAddress').value,
        phone: document.getElementById('pPhone').value,
        cashier: document.getElementById('pCashier').value,
    }));
}

function setPrintStatus(type, msg) {
    if (!printStatus || !printStatusText) return;
    printStatus.className = `print-status ${type}`;
    printStatusText.textContent = msg;
}

function buildReceiptHtml({ title, itemsHtml, subtotal, discount, vat, grandTotal, paymentLabel = '', change = '', date }) {
    const storeName = document.getElementById('pStoreName').value || 'MY STORE';
    const address = document.getElementById('pAddress').value || '';
    const phone = document.getElementById('pPhone').value || '';
    const cashier = document.getElementById('pCashier').value || 'Cashier';
    const logo = POS_CONFIG.logo || '';

    return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>${escapeHtml(title)}</title>
<style>
*{box-sizing:border-box}body{background:#e0e0e0;display:flex;justify-content:center;padding:30px 0 60px;font-family:'Courier New',Courier,monospace}.receipt{background:#fff;width:302px;padding:16px 14px 20px;font-size:11.5px;color:#000;line-height:1.45;box-shadow:0 2px 12px rgba(0,0,0,.15)}.r-logo{text-align:center;margin-bottom:6px}.r-logo img{max-width:72px;max-height:72px;object-fit:contain}.r-store{text-align:center;font-size:15px;font-weight:bold;letter-spacing:.04em;text-transform:uppercase;margin-bottom:3px}.r-info{text-align:center;font-size:10.5px;color:#333;margin-bottom:10px;line-height:1.5}.r-meta{font-size:10.5px;margin-bottom:2px}.r-dash{border:none;border-top:1px dashed #000;margin:7px 0}.r-solid{border:none;border-top:1px solid #000;margin:7px 0}.r-eq{border:none;border-top:2px solid #000;margin:7px 0}.sale-no{text-align:center;font-size:10px;color:#888;margin-bottom:4px}table{width:100%;border-collapse:collapse}thead th{font-size:10px;text-transform:uppercase;letter-spacing:.04em;padding:3px 2px;border-bottom:1px solid #000}.th-name{text-align:left;width:44%}.th-qty{text-align:center;width:10%}.th-price{text-align:right;width:22%}.th-total{text-align:right;width:24%}.item-name{padding:3px 2px;vertical-align:top;word-break:break-word}.item-qty{text-align:center;padding:3px 2px}.item-price{text-align:right;padding:3px 2px}.item-total{text-align:right;padding:3px 2px;font-weight:bold}.disc-tag{background:#eee;font-size:9px;padding:0 2px;border-radius:2px}.totals td{padding:2px 2px;font-size:11px}.t-label{text-align:left}.t-value{text-align:right}.discount{color:#c00}.grand-row td{font-size:14px;font-weight:bold;padding-top:5px}.r-footer{text-align:center;font-size:10.5px;color:#444;margin-top:10px;line-height:1.6}.thank{font-size:12px;font-weight:bold;color:#000}@media print{@page{size:A4 portrait;margin:10mm 0}body{background:none;padding:0}.receipt{box-shadow:none;width:302px;margin:0 auto}}</style>
</head>
<body>
<div class="receipt">
<div class="sale-no">${escapeHtml(title)}</div>
${logo ? `<div class="r-logo"><img src="${escapeHtml(logo)}" alt="Store logo"></div>` : ''}
<div class="r-store">${escapeHtml(storeName)}</div>
<div class="r-info">${address ? `${escapeHtml(address)}<br>` : ''}${phone ? `Tel: ${escapeHtml(phone)}` : ''}</div>
<hr class="r-eq">
<div class="r-meta">Date    : ${escapeHtml(date)}</div>
<div class="r-meta">Cashier : ${escapeHtml(cashier)}</div>
<hr class="r-dash">
<table><thead><tr><th class="th-name">Item</th><th class="th-qty">Qty</th><th class="th-price">Price</th><th class="th-total">Total</th></tr></thead><tbody>${itemsHtml}</tbody></table>
<hr class="r-dash">
<table class="totals">
<tr><td class="t-label">Subtotal</td><td class="t-value">${subtotal}</td></tr>
<tr><td class="t-label">Discount</td><td class="t-value discount">${discount}</td></tr>
<tr><td class="t-label">VAT (${Number(POS_CONFIG.vatRate || 12)}%)</td><td class="t-value">${vat}</td></tr>
</table>
<hr class="r-solid">
<table class="totals">
<tr class="grand-row"><td class="t-label">TOTAL</td><td class="t-value">${grandTotal}</td></tr>
${paymentLabel ? `<tr><td class="t-label">Payment</td><td class="t-value">${escapeHtml(paymentLabel)}</td></tr>` : ''}
${change ? `<tr><td class="t-label">Change</td><td class="t-value">${escapeHtml(change)}</td></tr>` : ''}
</table>
<hr class="r-eq">
<div class="r-footer"><div class="thank">Thank you!</div>Please come again.<br><small>${escapeHtml(date)}</small></div>
</div>
<script>window.onload=()=>window.print();<\/script>
</body>
</html>`;
}

function openReceiptWindow(receiptHtml) {
    const printWindow = window.open('', '_blank', 'width=480,height=700,scrollbars=yes');
    if (!printWindow) return false;
    printWindow.document.write(receiptHtml);
    printWindow.document.close();
    return true;
}

printReceiptBtn?.addEventListener('click', () => {
    loadPrintSettings();
    setPrintStatus('', 'Ready to print');
    printModalOverlay?.classList.add('open');
});

printModalClose?.addEventListener('click', () => printModalOverlay?.classList.remove('open'));
printModalOverlay?.addEventListener('click', (event) => {
    if (event.target === printModalOverlay) {
        printModalOverlay.classList.remove('open');
    }
});

printNowBtn?.addEventListener('click', () => {
    const keys = Object.keys(cart);
    if (keys.length === 0) {
        setPrintStatus('err', 'Cart is empty');
        return;
    }

    savePrintSettings();
    const date = new Date().toLocaleString('en-PH', {
        year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
    });

    const itemsHtml = keys.map((key) => {
        const item = cart[key];
        const discountedUnitPrice = item.price * (1 - item.discount / 100);
        return `<tr><td class="item-name">${escapeHtml(item.name)} (${escapeHtml(item.unit_label)})${item.discount > 0 ? ` <span class="disc-tag">-${item.discount}%</span>` : ''}</td><td class="item-qty">${item.qty}</td><td class="item-price">${peso(discountedUnitPrice)}</td><td class="item-total">${peso(discountedUnitPrice * item.qty)}</td></tr>`;
    }).join('');

    const receiptHtml = buildReceiptHtml({
        title: 'POS Receipt',
        itemsHtml,
        subtotal: document.getElementById('subtotal').textContent,
        discount: document.getElementById('total-discount').textContent,
        vat: document.getElementById('vat').textContent,
        grandTotal: document.getElementById('grand-total').textContent,
        date,
    });

    if (!openReceiptWindow(receiptHtml)) {
        setPrintStatus('err', 'Pop-up blocked — allow pop-ups for this site');
        return;
    }

    setPrintStatus('ok', 'Receipt opened — print dialog will appear');
    setTimeout(() => printModalOverlay?.classList.remove('open'), 1800);
});

const checkoutModalOverlay = document.getElementById('checkoutModalOverlay');
const checkoutModalClose = document.getElementById('checkoutModalClose');
const confirmCheckoutBtn = document.getElementById('confirmCheckoutBtn');
const checkoutStatus = document.getElementById('checkoutStatus');
const checkoutStatusText = document.getElementById('checkoutStatusText');
const cashTenderedField = document.getElementById('cashTenderedField');
const cashTenderedInput = document.getElementById('cashTendered');
const changeAmountEl = document.getElementById('changeAmount');

let selectedPaymentMethod = 'cash';
let lastSaleData = null;

function setCheckoutStatus(type, msg) {
    if (!checkoutStatus || !checkoutStatusText) return;
    checkoutStatus.className = `print-status ${type}`;
    checkoutStatusText.textContent = msg;
}

function showPrintPrompt() {
    document.getElementById('printPromptOverlay')?.classList.add('open');
}

function clearCartAndClose() {
    Object.keys(cart).forEach((key) => delete cart[key]);
    clearStoredCart();
    setCartRecoveryNote('');
    updateAllCards();
    renderCart();
    document.getElementById('printPromptOverlay')?.classList.remove('open');
}

document.getElementById('printPromptYes')?.addEventListener('click', () => {
    document.getElementById('printPromptOverlay')?.classList.remove('open');
    if (lastSaleData) {
        printReceipt(lastSaleData);
    }
    clearCartAndClose();
});

document.getElementById('printPromptNo')?.addEventListener('click', () => {
    clearCartAndClose();
});

function renderSaleBreakdown(sale) {
    const body = document.getElementById('saleBreakdownBody');
    const title = document.getElementById('saleBreakdownTitle');
    const meta = document.getElementById('saleBreakdownMeta');

    if (!body || !sale) return;

    title.textContent = sale.transaction_no || formatTransactionNo(sale.sale_id, sale.date);
    meta.textContent = `${sale.date || ''} | ${String(sale.payment || 'cash').toUpperCase()} payment`;

    const rows = sale.items.map((item) => {
        const discountedUnitPrice = Number(item.price || 0) * (1 - Number(item.discount || 0) / 100);
        const lineTotal = discountedUnitPrice * Number(item.qty || 0);
        return `
            <tr>
                <td>
                    <strong>${escapeHtml(item.name)}</strong>
                    <span style="display:block;color:var(--text-secondary);font-size:12px;">${escapeHtml(item.unit_label || 'piece')}${Number(item.discount || 0) > 0 ? ` | ${Number(item.discount)}% discount` : ''}</span>
                </td>
                <td style="text-align:right;">${Number(item.qty || 0).toLocaleString()}</td>
                <td style="text-align:right;">${peso(discountedUnitPrice)}</td>
                <td style="text-align:right;font-weight:800;">${peso(lineTotal)}</td>
            </tr>
        `;
    }).join('');

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
                <tbody>${rows || '<tr><td colspan="4" style="text-align:center;color:var(--text-secondary);padding:18px;">No items found.</td></tr>'}</tbody>
            </table>
        </div>
        ${sale.payment === 'cash' ? `<div style="margin-top:12px;color:var(--text-secondary);font-weight:700;">Change: <strong style="color:var(--accent-green);">${escapeHtml(sale.change)}</strong></div>` : ''}
    `;
}

document.getElementById('printPromptDetails')?.addEventListener('click', () => {
    if (!lastSaleData) return;
    renderSaleBreakdown(lastSaleData);
    document.getElementById('saleBreakdownOverlay')?.classList.add('open');
});

document.getElementById('saleBreakdownClose')?.addEventListener('click', () => {
    document.getElementById('saleBreakdownOverlay')?.classList.remove('open');
});

document.getElementById('checkoutBtn')?.addEventListener('click', () => {
    if (Object.keys(cart).length === 0) return;

    document.getElementById('co-subtotal').textContent = document.getElementById('subtotal').textContent;
    document.getElementById('co-discount').textContent = document.getElementById('total-discount').textContent;
    document.getElementById('co-vat').textContent = document.getElementById('vat').textContent;
    document.getElementById('co-total').textContent = document.getElementById('grand-total').textContent;

    selectedPaymentMethod = 'cash';
    document.querySelectorAll('.pay-method-btn').forEach((button) => button.classList.remove('active'));
    document.querySelector('.pay-method-btn[data-method="cash"]')?.classList.add('active');
    if (cashTenderedField) cashTenderedField.style.display = '';
    if (cashTenderedInput) cashTenderedInput.value = '';
    if (changeAmountEl) changeAmountEl.textContent = peso(0);
    setCheckoutStatus('', 'Ready to complete sale');

    checkoutModalOverlay?.classList.add('open');
});

checkoutModalClose?.addEventListener('click', () => checkoutModalOverlay?.classList.remove('open'));
checkoutModalOverlay?.addEventListener('click', (event) => {
    if (event.target === checkoutModalOverlay) {
        checkoutModalOverlay.classList.remove('open');
    }
});

document.getElementById('paymentMethods')?.addEventListener('click', (event) => {
    const button = event.target.closest('.pay-method-btn');
    if (!button) return;

    document.querySelectorAll('.pay-method-btn').forEach((node) => node.classList.remove('active'));
    button.classList.add('active');
    selectedPaymentMethod = button.dataset.method || 'cash';

    if (cashTenderedField) {
        cashTenderedField.style.display = selectedPaymentMethod === 'cash' ? '' : 'none';
    }
    if (selectedPaymentMethod !== 'cash' && changeAmountEl) {
        changeAmountEl.textContent = peso(0);
    }
});

cashTenderedInput?.addEventListener('input', () => {
    const tendered = parseFloat(cashTenderedInput.value || '0') || 0;
    const total = parseDisplayedAmount(document.getElementById('co-total').textContent);
    const change = tendered - total;
    changeAmountEl.textContent = change >= 0 ? peso(change) : peso(0);
    changeAmountEl.style.color = change >= 0 ? 'var(--accent-green)' : 'var(--accent-red)';
});

confirmCheckoutBtn?.addEventListener('click', async () => {
    const items = Object.values(cart);
    if (items.length === 0) {
        setCheckoutStatus('err', 'Cart is empty');
        return;
    }

    if (selectedPaymentMethod === 'cash') {
        const total = parseDisplayedAmount(document.getElementById('co-total').textContent);
        const tendered = parseFloat(cashTenderedInput.value || '0') || 0;
        if (tendered < total) {
            setCheckoutStatus('err', 'Cash tendered is less than total');
            return;
        }
    }

    const payload = {
        items: items.map((item) => ({
            product_id: item.product_id,
            quantity: item.qty,
            unit_type: item.unit_type,
            unit_multiplier: item.unit_multiplier,
            unit_price: parseFloat((item.price * (1 - item.discount / 100)).toFixed(2)),
        })),
        payment_method: selectedPaymentMethod,
        total_amount: parseDisplayedAmount(document.getElementById('grand-total').textContent),
        tax_amount: parseDisplayedAmount(document.getElementById('vat').textContent),
        discount_amount: parseDisplayedAmount(document.getElementById('total-discount').textContent),
        csrf_token: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
    };

    confirmCheckoutBtn.disabled = true;
    setCheckoutStatus('busy', 'Saving sale…');

    try {
        const response = await fetch('/inventory_system/http/ajax/checkout.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': payload.csrf_token,
            },
            body: JSON.stringify(payload),
        });

        const data = await response.json();
        if (!data.success) {
            setCheckoutStatus('err', data.error || 'Checkout failed');
            if (data.notification_type) {
                sendCheckoutNotificationUpdate(data.notification_type);
            }
            return;
        }

        const transactionNo = data.transaction_no || formatTransactionNo(data.sale_id);
        setCheckoutStatus('ok', `${transactionNo} saved`);
        if (data.notification_type) {
            sendCheckoutNotificationUpdate(data.notification_type);
        }
        clearStoredCart();
        lastSaleData = {
            sale_id: data.sale_id,
            transaction_no: transactionNo,
            items: items.map((item) => ({
                name: item.name,
                qty: item.qty,
                discount: item.discount,
                price: item.price,
                unit_label: item.unit_label,
            })),
            subtotal: document.getElementById('subtotal').textContent,
            discount: document.getElementById('total-discount').textContent,
            vat: document.getElementById('vat').textContent,
            grand_total: document.getElementById('grand-total').textContent,
            payment: selectedPaymentMethod,
            change: changeAmountEl.textContent,
            date: new Date().toLocaleString('en-PH', {
                year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
            }),
        };

        setTimeout(() => {
            checkoutModalOverlay?.classList.remove('open');
            showPrintPrompt();
        }, 900);
    } catch (_) {
        setCheckoutStatus('err', 'Network error — try again');
    } finally {
        confirmCheckoutBtn.disabled = false;
    }
});

function printReceipt(sale) {
    const itemsHtml = sale.items.map((item) => {
        const discountedUnitPrice = item.price * (1 - item.discount / 100);
        return `<tr><td class="item-name">${escapeHtml(item.name)} (${escapeHtml(item.unit_label)})${item.discount > 0 ? ` <span class="disc-tag">-${item.discount}%</span>` : ''}</td><td class="item-qty">${item.qty}</td><td class="item-price">${peso(discountedUnitPrice)}</td><td class="item-total">${peso(discountedUnitPrice * item.qty)}</td></tr>`;
    }).join('');

    const paymentLabel = {
        cash: 'Cash',
        gcash: 'GCash',
        card: 'Card',
        other: 'Other',
    }[sale.payment] || sale.payment;

    const receiptHtml = buildReceiptHtml({
        title: sale.transaction_no || formatTransactionNo(sale.sale_id, sale.date),
        itemsHtml,
        subtotal: sale.subtotal,
        discount: sale.discount,
        vat: sale.vat,
        grandTotal: sale.grand_total,
        paymentLabel,
        change: sale.payment === 'cash' ? sale.change : '',
        date: sale.date,
    });

    if (!openReceiptWindow(receiptHtml)) {
        alert('Pop-up blocked — please allow pop-ups for this site.');
    }
}

function updateReceiptBtn() {
    const btn = document.getElementById('printReceiptBtn');
    btn?.classList.toggle('has-items', Object.keys(cart).length > 0);
}
