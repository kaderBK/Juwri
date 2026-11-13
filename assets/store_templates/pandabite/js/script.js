// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

(function () {
  'use strict';
  /* HTML-escape for innerHTML templates: names/labels are owner/staff-typed. */
  function jwEsc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }


  const CART_KEY = 'pb_cart_' + (window.location.pathname.replace(/\//g, '_') || 'default');
  const DURATION = 5000;

  const pb = window.PB || {};
  const items = pb.items || {};

  let cart = {};
  let currentItem = null;
  let currentQty = 1;
  let currentVariant = 0;
  let currentExtras = [];

  /* ============================================================
     Helpers
     ============================================================ */
  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)); }

  const CUR_DP = pb.currencyDecimals ?? 2;

  function fmtPrice(n) {
    const s = Number(n).toFixed(CUR_DP);
    return pb.curPos === '1' ? pb.curSign + s : s + pb.curSign;
  }

  function saveCart() { sessionStorage.setItem(CART_KEY, JSON.stringify(cart)); }
  function loadCart() {
    try { cart = JSON.parse(sessionStorage.getItem(CART_KEY) || '{}'); } catch (e) { cart = {}; }
  }

  function getItemPrice(item) {
    let price = item.price;
    if (currentVariant && item.variants) {
      const v = item.variants.find(v => v.id === currentVariant);
      if (v) price = v.price;
    }
    if (currentExtras && item.extras) {
      currentExtras.forEach(eid => {
        const ex = item.extras.find(e => e.id === eid);
        if (ex) price += ex.price;
      });
    }
    return price;
  }

  function cartLineKey(itemId, variantId, extras) {
    return itemId + '|' + (variantId || 0) + '|' + extras.slice().sort().join(',');
  }

  /* ============================================================
     Slider
     ============================================================ */
  let slideIndex = 0;
  let slideTimer = null;
  const track = $('#pb-hero-track');
  const slides = $$('.pb-hero__slide');
  const dots = $$('.pb-hero__dots button');

  function showSlide(i) {
    if (!slides.length) return;
    slideIndex = (i + slides.length) % slides.length;
    track.style.transform = 'translateX(' + (-slideIndex * 100) + '%)';
    slides.forEach((s, idx) => {
      s.classList.toggle('is-active', idx === slideIndex);
      s.setAttribute('aria-hidden', idx === slideIndex ? 'false' : 'true');
    });
    dots.forEach((d, idx) => {
      d.classList.toggle('active', idx === slideIndex);
      d.setAttribute('aria-current', idx === slideIndex ? 'true' : 'false');
    });
  }

  function nextSlide() { showSlide(slideIndex + 1); }
  function startSlider() {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    clearInterval(slideTimer);
    slideTimer = setInterval(nextSlide, DURATION);
  }
  function stopSlider() { clearInterval(slideTimer); }

  if (slides.length) {
    showSlide(0);
    startSlider();
    dots.forEach((d, i) => d.addEventListener('click', () => { showSlide(i); startSlider(); }));

    let touchStartX = 0;
    track.addEventListener('touchstart', e => { touchStartX = e.touches[0].clientX; stopSlider(); }, {passive: true});
    track.addEventListener('touchend', e => {
      const diff = e.changedTouches[0].clientX - touchStartX;
      if (Math.abs(diff) > 40) showSlide(slideIndex + (diff < 0 ? 1 : -1));
      startSlider();
    }, {passive: true});
    track.addEventListener('mouseenter', stopSlider);
    track.addEventListener('mouseleave', startSlider);
    document.addEventListener('keydown', e => {
      if (e.key === 'ArrowRight') { showSlide(slideIndex + 1); startSlider(); }
      if (e.key === 'ArrowLeft') { showSlide(slideIndex - 1); startSlider(); }
    });
  }

  /* ============================================================
     Category filter
     ============================================================ */
  const catButtons = $$('.pb-category-tile');
  const productCards = $$('.pb-product-card');

  function updateEmptyState() {
    const anyVisible = productCards.some(c => c.style.display !== 'none');
    const empty = $('#pb-empty');
    if (empty) empty.style.display = anyVisible ? 'none' : '';
  }

  catButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      const cat = btn.dataset.category;
      catButtons.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      productCards.forEach(card => {
        const show = cat === 'all' || String(card.dataset.category) === cat;
        card.style.display = show ? '' : 'none';
      });
      updateEmptyState();
      $('#pb-products').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  /* ============================================================
     Search
     ============================================================ */
  const searchOverlay = $('#pb-search-overlay');
  const searchInput = $('#pb-search-input');
  const searchResults = $('#pb-search-results');

  function openSearch() {
    if (!searchOverlay) return;
    searchOverlay.classList.add('is-open');
    searchOverlay.setAttribute('aria-hidden', 'false');
    searchInput.focus();
    renderSearchResults('');
  }
  function closeSearch() {
    if (!searchOverlay) return;
    searchOverlay.classList.remove('is-open');
    searchOverlay.setAttribute('aria-hidden', 'true');
    searchInput.value = '';
    productCards.forEach(c => c.style.display = '');
    updateEmptyState();
  }

  function renderSearchResults(q) {
    const term = q.trim().toLowerCase();
    const matched = productCards.filter(card => !term || (card.dataset.search || '').includes(term));
    searchResults.innerHTML = '';
    if (!matched.length) {
      searchResults.innerHTML = '<div class="pb-empty"><div class="pb-empty__icon">🔍</div><p>' + (pb.strings.noItems || 'No items found.') + '</p></div>';
      return;
    }
    const grid = document.createElement('div');
    grid.className = 'pb-product-grid';
    matched.forEach(card => grid.appendChild(card.cloneNode(true)));
    searchResults.appendChild(grid);
  }

  const searchTriggers = $$('.pb-header__search, .pb-search-pill');
  searchTriggers.forEach(t => t.addEventListener('click', openSearch));
  $('#pb-search-close').addEventListener('click', closeSearch);
  searchInput.addEventListener('input', () => renderSearchResults(searchInput.value));

  /* ============================================================
     Sheets
     ============================================================ */
  function openSheet(id) {
    const sheet = $('#' + id);
    if (!sheet) return;
    sheet.classList.add('is-open');
    sheet.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }
  function closeSheet(id) {
    const sheet = $('#' + id);
    if (!sheet) return;
    sheet.classList.remove('is-open');
    sheet.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  document.addEventListener('click', e => {
    const close = e.target.closest('[data-close-sheet]');
    if (close) {
      const sheet = close.closest('.pb-sheet');
      if (sheet) closeSheet(sheet.id);
    }
  });

  /* ============================================================
     Item sheet
     ============================================================ */
  function openItem(itemId) {
    const item = items[itemId];
    if (!item) return;
    currentItem = item;
    currentQty = 1;
    currentVariant = item.variants && item.variants.length ? item.variants[0].id : 0;
    currentExtras = [];

    $('#pb-item-name').textContent = item.name;
    $('#pb-item-desc').textContent = item.desc || '';
    $('#pb-item-img').innerHTML = item.img
      ? '<img src="' + jwEsc(item.img) + '" alt="' + jwEsc(item.name) + '" data-csp-error="hide">'
      : '<div style="display:flex;align-items:center;justify-content:center;height:100%;font-size:60px;">🍽️</div>';
    $('#pb-qty-value').textContent = '1';

    const variantsWrap = $('#pb-item-variants');
    const variantList = $('#pb-variant-list');
    if (item.variants && item.variants.length) {
      variantsWrap.style.display = '';
      variantList.innerHTML = item.variants.map(v => {
        const checked = v.id === currentVariant ? 'checked' : '';
        return '<label class="pb-option"><span>' + jwEsc(v.label) + ' — ' + fmtPrice(v.price) + '</span><input type="radio" name="pb-variant" value="' + v.id + '" ' + checked + '></label>';
      }).join('');
      variantList.querySelectorAll('input').forEach(r => r.addEventListener('change', () => {
        currentVariant = parseInt(r.value, 10);
        updateItemPrice();
      }));
    } else {
      variantsWrap.style.display = 'none';
    }

    const extrasWrap = $('#pb-item-extras');
    const extraList = $('#pb-extra-list');
    if (item.extras && item.extras.length) {
      extrasWrap.style.display = '';
      extraList.innerHTML = item.extras.map(e => {
        return '<label class="pb-option"><span>' + jwEsc(e.title) + ' +' + fmtPrice(e.price) + '</span><input type="checkbox" name="pb-extra" value="' + e.id + '"></label>';
      }).join('');
      extraList.querySelectorAll('input').forEach(c => c.addEventListener('change', () => {
        currentExtras = Array.from(extraList.querySelectorAll('input:checked')).map(i => parseInt(i.value, 10));
        updateItemPrice();
      }));
    } else {
      extrasWrap.style.display = 'none';
    }

    updateItemPrice();
    openSheet('pb-item-sheet');
  }

  function updateItemPrice() {
    if (!currentItem) return;
    $('#pb-item-price').textContent = fmtPrice(getItemPrice(currentItem));
  }

  $('#pb-qty-minus').addEventListener('click', () => { if (currentQty > 1) { currentQty--; $('#pb-qty-value').textContent = currentQty; updateItemPrice(); } });
  $('#pb-qty-plus').addEventListener('click', () => { currentQty++; $('#pb-qty-value').textContent = currentQty; updateItemPrice(); });

  $('#pb-item-add').addEventListener('click', () => {
    if (!currentItem || currentItem.is_sold_out) return;
    const key = cartLineKey(currentItem.id, currentVariant, currentExtras);
    const unitPrice = getItemPrice(currentItem);
    if (cart[key]) {
      cart[key].qty += currentQty;
    } else {
      cart[key] = {
        itemId: currentItem.id,
        name: currentItem.name,
        img: currentItem.img,
        price: unitPrice,
        basePrice: currentItem.price,
        variantId: currentVariant,
        variantLabel: (currentItem.variants || []).find(v => v.id === currentVariant)?.label || '',
        extras: currentExtras.map(eid => currentItem.extras.find(e => e.id === eid)).filter(Boolean),
        qty: currentQty
      };
    }
    saveCart();
    renderCart();
    closeSheet('pb-item-sheet');
  });

  productCards.forEach(card => {
    card.addEventListener('click', e => {
      if (e.target.closest('.pb-product-card__add')) return;
      openItem(card.dataset.itemId);
    });
    const addBtn = card.querySelector('.pb-product-card__add');
    if (addBtn) {
      addBtn.addEventListener('click', e => {
        e.stopPropagation();
        const item = items[addBtn.dataset.itemId];
        if (!item || item.is_sold_out) return;
        if ((item.variants && item.variants.length) || (item.extras && item.extras.length)) {
          openItem(addBtn.dataset.itemId);
        } else {
          const key = cartLineKey(item.id, 0, []);
          cart[key] = {
            itemId: item.id,
            name: item.name,
            img: item.img,
            price: item.price,
            basePrice: item.price,
            variantId: 0,
            variantLabel: '',
            extras: [],
            qty: (cart[key]?.qty || 0) + 1
          };
          saveCart();
          renderCart();
        }
      });
    }
  });

  /* ============================================================
     Cart
     ============================================================ */
  function renderCart() {
    const cartItems = Object.values(cart);
    const count = cartItems.reduce((sum, c) => sum + c.qty, 0);
    const total = cartItems.reduce((sum, c) => sum + (c.price * c.qty), 0);

    const badge = $('#pb-nav-badge');
    badge.textContent = count;
    badge.style.display = count > 0 ? 'flex' : 'none';

    $('#pb-cart-total').textContent = fmtPrice(total);

    const list = $('#pb-cart-items');
    if (!cartItems.length) {
      list.innerHTML = '<p class="pb-cart-sheet__empty">' + (pb.strings.cartEmpty || 'Your cart is empty.') + '</p>';
      return;
    }

    list.innerHTML = cartItems.map(c => {
      const meta = [c.variantLabel, ...c.extras.map(e => e.title)].filter(Boolean).join(' · ');
      return '<div class="pb-cart-item" data-key="' + cartLineKey(c.itemId, c.variantId, c.extras.map(e => e.id)) + '">' +
        '<div class="pb-cart-item__info">' +
          '<div class="pb-cart-item__name">' + jwEsc(c.name) + '</div>' +
          '<div class="pb-cart-item__meta">' + jwEsc(meta) + '</div>' +
          '<div style="font-weight:800;color:var(--pb-accent);margin-top:4px;">' + fmtPrice(c.price * c.qty) + '</div>' +
        '</div>' +
        '<div class="pb-cart-item__qty">' +
          '<button type="button" data-action="minus">−</button>' +
          '<span>' + c.qty + '</span>' +
          '<button type="button" data-action="plus">+</button>' +
        '</div>' +
      '</div>';
    }).join('');

    list.querySelectorAll('.pb-cart-item__qty button').forEach(btn => {
      btn.addEventListener('click', () => {
        const key = btn.closest('.pb-cart-item').dataset.key;
        if (!cart[key]) return;
        if (btn.dataset.action === 'plus') cart[key].qty++;
        else cart[key].qty--;
        if (cart[key].qty <= 0) delete cart[key];
        saveCart();
        renderCart();
      });
    });
  }

  $('#pb-cart-checkout').addEventListener('click', () => {
    closeSheet('pb-cart-sheet');
    openSheet('pb-order-sheet');
  });

  /* ============================================================
     Bottom nav
     ============================================================ */
  $$('.pb-bottom-nav__item').forEach(btn => {
    btn.addEventListener('click', () => {
      const tab = btn.dataset.tab;
      $$('.pb-bottom-nav__item').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      if (tab === 'search') openSearch();
      if (tab === 'cart') openSheet('pb-cart-sheet');
      if (tab === 'menu') window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  });

  /* ============================================================
     Checkout form
     ============================================================ */
  const orderType = $('#pb-order-type');
  const fieldTable = $('#pb-field-table');
  const fieldPhone = $('#pb-field-phone');
  const fieldAddress = $('#pb-field-address');

  function updateOrderFields() {
    if (!orderType) return;
    const type = orderType.value;
    if (fieldTable) fieldTable.style.display = type === 'on-table' ? 'flex' : 'none';
    if (fieldPhone) fieldPhone.style.display = type === 'on-table' ? 'none' : 'flex';
    if (fieldAddress) fieldAddress.style.display = type === 'delivery' ? 'flex' : 'none';
  }

  if (orderType) {
    orderType.addEventListener('change', updateOrderFields);
    updateOrderFields();
  }

  $('#pb-order-form').addEventListener('submit', e => {
    e.preventDefault();
    const errorEl = $('#pb-order-error');
    errorEl.textContent = '';

    const cartItems = Object.values(cart);
    if (!cartItems.length) { errorEl.textContent = 'Cart is empty'; return; }

    const total = cartItems.reduce((sum, c) => sum + (c.price * c.qty), 0);
    if (pb.minOrder && total < pb.minOrder) {
      errorEl.textContent = (pb.strings.minOrderError || 'Minimum order is') + ' ' + fmtPrice(pb.minOrder);
      return;
    }

    const form = e.target;
    const payload = {
      items: JSON.stringify(cartItems.map(c => ({
        id: c.itemId,
        quantity: c.qty,
        variants: c.variantId || 0,
        extras: c.extras.map(e => ({ id: e.id }))
      }))),
      name: form.name.value,
      'ordering-type': orderType ? orderType.value : 'takeaway',
      table: form.table ? form.table.value : '',
      'phone-number': form['phone-number'] ? form['phone-number'].value : '',
      address: form.address ? form.address.value : '',
      pay_via: form.pay_via.value,
      coupon_code: form.coupon_code.value,
      message: form.message.value,
      _token: pb.csrf
    };

    fetch(pb.orderUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      body: new URLSearchParams(payload)
    })
    .then(r => r.json())
    .then(data => {
      if (!data.success) {
        errorEl.textContent = data.message || 'Order failed';
        return;
      }
      cart = {};
      saveCart();
      renderCart();
      closeSheet('pb-order-sheet');
      if (data.message) window.location.href = data.message;
      else if (data.whatsapp_url) window.location.href = data.whatsapp_url;
      else alert(pb.strings.orderSuccess || 'Order placed successfully');
    })
    .catch(err => {
      console.error(err);
      errorEl.textContent = 'Network error. Please try again.';
    });
  });

  /* ============================================================
     Init
     ============================================================ */
  loadCart();
  renderCart();
})();
