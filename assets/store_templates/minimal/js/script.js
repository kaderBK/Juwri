// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ============================================================
   Minimal template — cart, detail sheet, checkout
   ============================================================ */
(function () {
  'use strict';
  /* HTML-escape for innerHTML templates: names/labels are owner/staff-typed. */
  function jwEsc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }


  const CFG = window.MINIMAL || {};
  const ITEMS = CFG.items || {};
  const ORDER_URL = CFG.orderUrl || '';
  const DELIVERY_CHARGE = CFG.deliveryCharge || 0;
  const TAX_CHARGE = CFG.taxCharge || 0;
  const MIN_ORDER = CFG.minOrder || 0;
  const ALLOW_ORDER = CFG.allowOrder || false;
  const ALLOW_TABLE = CFG.allowTable || false;
  const ALLOW_TAKEAWAY = CFG.allowTakeaway || false;
  const ALLOW_DELIVERY = CFG.allowDelivery || false;
  const ALLOW_PAYMENT = CFG.allowPayment || false;
  const CUR_SIGN = CFG.curSign || '$';
  const CUR_POS  = CFG.curPos  || '1';
  const CUR_DP   = CFG.currencyDecimals ?? 2;
  const TABLE_NO = CFG.tableNo || '';
  const CSRF = CFG.csrf || '';
  const LANG = CFG.lang || {};

  /* ── Cart state ── */
  let cart = []; // [{id, qty, variantId, extras:[id,...], unitPrice, name}]

  /* ── Utilities ── */
  function fmt(n) {
    const s = parseFloat(n).toFixed(CUR_DP);
    return CUR_POS === '1' ? CUR_SIGN + s : s + CUR_SIGN;
  }

  function cartTotal() {
    return cart.reduce((sum, r) => sum + r.unitPrice * r.qty, 0);
  }

  function cartCount() {
    return cart.reduce((sum, r) => sum + r.qty, 0);
  }

  function unitPrice(item, variantId, extraIds) {
    let base = item.price;
    if (variantId) {
      const v = (item.variants || []).find(v => v.id === variantId);
      if (v) base = v.price;
    }
    const extras = (item.extras || []).filter(e => (extraIds || []).includes(e.id));
    return base + extras.reduce((s, e) => s + e.price, 0);
  }

  function updateCartBtn() {
    const cnt = cartCount();
    const total = cartTotal();
    const btn = document.getElementById('mn-cart-toggle');
    if (!btn) return;
    btn.querySelector('.mn-cart-count').textContent = cnt;
    btn.querySelector('.mn-cart-total').textContent = cnt > 0 ? ' · ' + fmt(total) : '';
    btn.style.display = cnt > 0 ? 'flex' : 'none';
  }

  function updateAddBtns() {
    document.querySelectorAll('[data-mn-item]').forEach(btn => {
      const id = parseInt(btn.dataset.mnItem, 10);
      const inCart = cart.some(r => r.id === id);
      btn.classList.toggle('in-cart', inCart);
      btn.textContent = inCart ? '✓' : '+';
    });
  }

  /* ── Detail sheet ── */
  let currentItem = null;
  let detailQty = 1;
  let selectedVariantId = null;
  let selectedExtras = [];

  function openDetail(itemId) {
    const item = ITEMS[itemId];
    if (!item || item.is_sold_out) return;
    currentItem = item;
    detailQty = 1;
    selectedVariantId = item.variants && item.variants.length ? item.variants[0].id : null;
    selectedExtras = [];

    const sheet = document.getElementById('mn-detail-sheet');
    if (!sheet) return;

    // Image
    const img = sheet.querySelector('.mn-detail-img');
    if (img) {
      img.src = item.img || '';
      img.style.display = item.img ? 'block' : 'none';
    }

    // Name / desc / price
    sheet.querySelector('.mn-detail-name').textContent = item.name || '';
    sheet.querySelector('.mn-detail-desc').textContent = item.desc || '';

    // Variants
    const vWrap = sheet.querySelector('.mn-detail-variants');
    const vContainer = sheet.querySelector('.mn-variant-list');
    if (vContainer) {
      vContainer.innerHTML = '';
      if (item.variants && item.variants.length > 1) {
        vWrap.style.display = 'block';
        item.variants.forEach(v => {
          const el = document.createElement('label');
          el.className = 'mn-variant-option';
          if (v.id === selectedVariantId) el.classList.add('selected');
          el.innerHTML = `<input type="radio" name="mn-variant" value="${v.id}" ${v.id === selectedVariantId ? 'checked' : ''}>
            <span class="mn-variant-label">${jwEsc(v.label)}</span>
            <span class="mn-variant-price">${fmt(v.price)}</span>`;
          el.addEventListener('change', () => {
            selectedVariantId = v.id;
            document.querySelectorAll('.mn-variant-option').forEach(o => o.classList.remove('selected'));
            el.classList.add('selected');
            refreshDetailPrice();
          });
          vContainer.appendChild(el);
        });
      } else {
        vWrap.style.display = 'none';
      }
    }

    // Extras
    const eWrap = sheet.querySelector('.mn-detail-extras');
    const eContainer = sheet.querySelector('.mn-extra-list');
    if (eContainer) {
      eContainer.innerHTML = '';
      if (item.extras && item.extras.length) {
        eWrap.style.display = 'block';
        item.extras.forEach(e => {
          const el = document.createElement('label');
          el.className = 'mn-extra-option';
          el.innerHTML = `<input type="checkbox" value="${e.id}">
            <span class="mn-extra-label">${jwEsc(e.title)}</span>
            <span class="mn-extra-price">+${fmt(e.price)}</span>`;
          el.querySelector('input').addEventListener('change', ev => {
            if (ev.target.checked) {
              selectedExtras.push(e.id);
            } else {
              selectedExtras = selectedExtras.filter(x => x !== e.id);
            }
            refreshDetailPrice();
          });
          eContainer.appendChild(el);
        });
      } else {
        eWrap.style.display = 'none';
      }
    }

    // Qty
    detailQty = 1;
    const qtyEl = sheet.querySelector('.mn-qty-val');
    if (qtyEl) qtyEl.textContent = '1';

    refreshDetailPrice();
    openSheet('mn-detail-overlay');
  }

  function refreshDetailPrice() {
    const sheet = document.getElementById('mn-detail-sheet');
    if (!sheet || !currentItem) return;
    const price = unitPrice(currentItem, selectedVariantId, selectedExtras);
    const addBtn = sheet.querySelector('.mn-add-btn');
    if (addBtn) addBtn.textContent = LANG.addToCart + ' · ' + fmt(price * detailQty);
    sheet.querySelector('.mn-detail-price').textContent = fmt(price);
  }

  /* ── Sheet open/close helpers ── */
  function openSheet(overlayId) {
    const el = document.getElementById(overlayId);
    if (el) el.classList.add('open');
    document.body.style.overflow = 'hidden';
  }
  function closeSheet(overlayId) {
    const el = document.getElementById(overlayId);
    if (el) el.classList.remove('open');
    document.body.style.overflow = '';
  }

  /* ── Cart sheet ── */
  function renderCart() {
    const listEl = document.getElementById('mn-cart-list');
    const emptyEl = document.getElementById('mn-cart-empty');
    const footerEl = document.getElementById('mn-cart-footer');
    if (!listEl) return;

    if (cart.length === 0) {
      listEl.innerHTML = '';
      if (emptyEl) emptyEl.style.display = 'block';
      if (footerEl) footerEl.style.display = 'none';
      return;
    }
    if (emptyEl) emptyEl.style.display = 'none';
    if (footerEl) footerEl.style.display = 'block';

    listEl.innerHTML = cart.map((r, i) => `
      <div class="mn-cart-item">
        <div class="mn-cart-item-name">
          ${escHtml(r.name)}
          ${r.variantLabel ? '<br><small style="color:var(--mn-muted);font-weight:400">' + escHtml(r.variantLabel) + '</small>' : ''}
          ${r.extrasLabel  ? '<br><small style="color:var(--mn-muted);font-weight:400">' + escHtml(r.extrasLabel) + '</small>' : ''}
          <span style="color:var(--mn-muted);font-weight:400;font-size:13px"> ×${r.qty}</span>
        </div>
        <span class="mn-cart-item-price">${fmt(r.unitPrice * r.qty)}</span>
        <button class="mn-cart-item-remove" data-idx="${i}" aria-label="${escHtml(CFG.lang.remove || 'Remove')}">✕</button>
      </div>
    `).join('');

    listEl.querySelectorAll('[data-idx]').forEach(btn => {
      btn.addEventListener('click', () => {
        cart.splice(parseInt(btn.dataset.idx, 10), 1);
        renderCart();
        updateCartBtn();
        updateAddBtns();
      });
    });

    // Totals
    const sub = cartTotal();
    let grand = sub;
    const orderType = document.querySelector('.mn-type-btn.active')?.dataset.type || 'on-table';
    const delivAmt = orderType === 'delivery' ? DELIVERY_CHARGE : 0;
    grand += delivAmt + TAX_CHARGE;

    document.getElementById('mn-total-sub').textContent = fmt(sub);
    document.getElementById('mn-total-delivery').textContent = fmt(delivAmt);
    document.getElementById('mn-total-tax').textContent = fmt(TAX_CHARGE);
    document.getElementById('mn-total-grand').textContent = fmt(grand);
  }

  /* ── Add to cart from detail ── */
  function addToCartFromDetail() {
    if (!currentItem) return;
    const price = unitPrice(currentItem, selectedVariantId, selectedExtras);

    const variantObj = selectedVariantId
      ? (currentItem.variants || []).find(v => v.id === selectedVariantId)
      : null;
    const extrasObjs = (currentItem.extras || []).filter(e => selectedExtras.includes(e.id));

    cart.push({
      id: currentItem.id,
      qty: detailQty,
      variantId: selectedVariantId || 0,
      variantLabel: variantObj ? variantObj.label : '',
      extras: selectedExtras.slice(),
      extrasLabel: extrasObjs.map(e => e.title).join(', '),
      unitPrice: price,
      name: currentItem.name,
    });

    closeSheet('mn-detail-overlay');
    updateCartBtn();
    updateAddBtns();
    renderCart();
  }

  /* ── Checkout submit ── */
  function buildItemsJson() {
    return JSON.stringify(cart.map(r => ({
      id: r.id,
      quantity: r.qty,
      variants: r.variantId || 0,
      extras: r.extras.map(id => ({ id })),
    })));
  }

  function submitOrder() {
    const alertEl = document.getElementById('mn-checkout-alert');
    const submitBtn = document.getElementById('mn-submit-btn');
    const orderType = document.querySelector('.mn-type-btn.active')?.dataset.type || 'on-table';
    const payVia = document.querySelector('input[name="mn-pay"]:checked')?.value || 'pay_on_counter';
    const name = document.getElementById('mn-field-name')?.value?.trim() || '';
    const phone = document.getElementById('mn-field-phone')?.value?.trim() || '';
    const table = document.getElementById('mn-field-table')?.value?.trim() || TABLE_NO || '';
    const address = document.getElementById('mn-field-address')?.value?.trim() || '';
    const note = document.getElementById('mn-field-note')?.value?.trim() || '';

    // Validate
    if (cart.length === 0) return;
    if (!name) { showAlert(LANG.nameRequired || 'Name is required.'); return; }
    if (orderType !== 'on-table' && !phone) { showAlert(LANG.phoneRequired || 'Phone is required.'); return; }
    if (orderType === 'delivery' && !address) { showAlert(LANG.addressRequired || 'Address is required.'); return; }
    if (MIN_ORDER > 0 && cartTotal() < MIN_ORDER) {
      showAlert((LANG.minOrder || 'Minimum order is') + ' ' + fmt(MIN_ORDER));
      return;
    }

    hideAlert();
    if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = LANG.placing || 'Placing order…'; }

    const fd = new FormData();
    fd.append('_token', CSRF);
    fd.append('items', buildItemsJson());
    fd.append('name', name);
    fd.append('ordering-type', orderType);
    fd.append('table', table);
    fd.append('phone-number', phone);
    fd.append('address', address);
    fd.append('message', note);
    fd.append('pay_via', payVia);

    fetch(ORDER_URL, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          cart = [];
          updateCartBtn();
          updateAddBtns();
          closeSheet('mn-cart-overlay');
          if (data.message && data.message.startsWith('http')) {
            location.href = data.message;
          } else if (data.whatsapp_url) {
            location.href = data.whatsapp_url;
          } else {
            showAlert(data.message || LANG.orderPlaced || 'Order placed! Thank you.', true);
          }
        } else {
          showAlert(data.message || LANG.orderFailed || 'Could not place order. Try again.');
          if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = LANG.placeOrder || 'Place Order'; }
        }
      })
      .catch(() => {
        showAlert(LANG.networkError || 'Network error. Please try again.');
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = LANG.placeOrder || 'Place Order'; }
      });
  }

  function showAlert(msg, success) {
    const el = document.getElementById('mn-checkout-alert');
    if (!el) return;
    el.textContent = msg;
    el.className = 'mn-alert visible' + (success ? ' success' : '');
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
  function hideAlert() {
    const el = document.getElementById('mn-checkout-alert');
    if (el) el.className = 'mn-alert';
  }

  function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  /* ── Category scrollspy ── */
  function initScrollSpy() {
    const sections = document.querySelectorAll('[data-mn-section]');
    if (!sections.length) return;
    const obs = new IntersectionObserver(entries => {
      entries.forEach(e => {
        if (e.isIntersecting) {
          const id = e.target.dataset.mnSection;
          document.querySelectorAll('.mn-catbtn').forEach(b => {
            b.classList.toggle('active', b.dataset.catId === id);
          });
        }
      });
    }, { rootMargin: '-50% 0px -45% 0px' });
    sections.forEach(s => obs.observe(s));
  }

  /* ── Search ── */
  function initSearch() {
    const input = document.getElementById('mn-search');
    if (!input) return;
    input.addEventListener('input', () => {
      const q = input.value.toLowerCase().trim();
      document.querySelectorAll('.mn-item').forEach(el => {
        const name = (el.dataset.itemName || '').toLowerCase();
        el.style.display = (!q || name.includes(q)) ? '' : 'none';
      });
      document.querySelectorAll('.mn-section').forEach(sec => {
        const visible = [...sec.querySelectorAll('.mn-item')].some(el => el.style.display !== 'none');
        sec.style.display = visible ? '' : 'none';
      });
    });
  }

  /* ── Init ── */
  document.addEventListener('DOMContentLoaded', () => {
    // Category buttons scroll to section
    document.querySelectorAll('.mn-catbtn').forEach(btn => {
      btn.addEventListener('click', () => {
        const sec = document.querySelector('[data-mn-section="' + btn.dataset.catId + '"]');
        if (sec) sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });

    // Item card → open detail sheet
    document.querySelectorAll('.mn-item:not(.sold-out)').forEach(el => {
      el.addEventListener('click', e => {
        if (e.target.closest('[data-mn-item]')) return; // add btn handled separately
        const id = parseInt(el.dataset.itemId, 10);
        if (!isNaN(id) && ALLOW_ORDER) openDetail(id);
      });
    });

    // Add buttons on item rows
    document.querySelectorAll('[data-mn-item]').forEach(btn => {
      btn.addEventListener('click', e => {
        e.stopPropagation();
        if (!ALLOW_ORDER) return;
        const id = parseInt(btn.dataset.mnItem, 10);
        const item = ITEMS[id];
        if (!item || item.is_sold_out) return;
        // If has variants/extras → open detail. Otherwise add directly.
        const hasVariants = item.variants && item.variants.length > 1;
        const hasExtras   = item.extras && item.extras.length > 0;
        if (hasVariants || hasExtras) {
          openDetail(id);
        } else {
          // Add directly
          const existing = cart.find(r => r.id === id && r.variantId === 0 && r.extras.length === 0);
          if (existing) {
            existing.qty += 1;
          } else {
            cart.push({
              id, qty: 1, variantId: 0, variantLabel: '', extras: [], extrasLabel: '',
              unitPrice: item.price, name: item.name,
            });
          }
          updateCartBtn();
          updateAddBtns();
          renderCart();
        }
      });
    });

    // Cart toggle button
    const cartToggle = document.getElementById('mn-cart-toggle');
    if (cartToggle) {
      cartToggle.addEventListener('click', () => {
        renderCart();
        openSheet('mn-cart-overlay');
      });
    }

    // Overlay click closes
    ['mn-detail-overlay', 'mn-cart-overlay'].forEach(id => {
      const el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('click', e => {
        if (e.target === el) closeSheet(id);
      });
    });

    // Close buttons
    document.querySelectorAll('[data-mn-close]').forEach(btn => {
      btn.addEventListener('click', () => closeSheet(btn.dataset.mnClose));
    });

    // Detail qty
    document.getElementById('mn-qty-dec')?.addEventListener('click', () => {
      if (detailQty > 1) { detailQty--; document.querySelector('.mn-qty-val').textContent = detailQty; refreshDetailPrice(); }
    });
    document.getElementById('mn-qty-inc')?.addEventListener('click', () => {
      detailQty++; document.querySelector('.mn-qty-val').textContent = detailQty; refreshDetailPrice();
    });

    // Add to cart from detail
    document.getElementById('mn-detail-add')?.addEventListener('click', addToCartFromDetail);

    // Order type buttons
    document.querySelectorAll('.mn-type-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.mn-type-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const type = btn.dataset.type;
        const tableField = document.getElementById('mn-table-wrap');
        const phoneField = document.getElementById('mn-phone-wrap');
        const addressField = document.getElementById('mn-address-wrap');
        if (tableField) tableField.style.display = type === 'on-table' ? 'block' : 'none';
        if (phoneField) phoneField.style.display = type !== 'on-table' ? 'block' : 'none';
        if (addressField) addressField.style.display = type === 'delivery' ? 'block' : 'none';
        renderCart(); // refresh totals (delivery charge)
      });
    });

    // Payment method select
    document.querySelectorAll('.mn-pay-label').forEach(lbl => {
      lbl.querySelector('input')?.addEventListener('change', () => {
        document.querySelectorAll('.mn-pay-label').forEach(l => l.classList.remove('selected'));
        lbl.classList.add('selected');
      });
    });

    // Submit
    document.getElementById('mn-submit-btn')?.addEventListener('click', submitOrder);

    initScrollSpy();
    initSearch();
    updateCartBtn();
  });
})();
