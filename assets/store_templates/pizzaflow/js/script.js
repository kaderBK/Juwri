// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * PizzaFlow — functional QR-menu JS
 * Mobile-app style: favorites, search, detail sheet, cart, checkout.
 */
(function(){
  'use strict';
  /* HTML-escape for innerHTML templates: names/labels are owner/staff-typed. */
  function jwEsc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  var P = window.PIZZAFLOW || {};
  var root = document.querySelector('.pizzaflow');
  if (!root) return;

  function $(s){ return root.querySelector(s); }
  function $$(s){ return root.querySelectorAll(s); }

  // ── State ─────────────────────────────────────────────────────────
  var favs   = JSON.parse(localStorage.getItem('pf-favs-'+P.slug) || '[]');
  var cart   = JSON.parse(localStorage.getItem('pf-cart-'+P.slug) || '[]');
  var detailId = null, detailQty = 1, detailVar = null, detailExtras = {};

  // ── Helpers ──────────────────────────────────────────────────────
  function fmtPrice(v){
    v = Number(v);
    var s = v.toFixed(0);
    return P.curPos === 'left' ? P.curSign + s : s + P.curSign;
  }
  function saveCart(){ localStorage.setItem('pf-cart-'+P.slug, JSON.stringify(cart)); }
  function cartTotal(){
    return cart.reduce(function(t,i){
      var p = i.price;
      if (i.extras) Object.keys(i.extras).forEach(function(k){ p += i.extras[k]; });
      return t + p * i.qty;
    }, 0);
  }
  function isSoldOut(id){ var m = P.menus[id]; return !!(m && m.is_sold_out); }

  // ── Favorites ────────────────────────────────────────────────────
  function isFav(id){ return favs.indexOf(Number(id)) > -1; }
  function toggleFav(id){
    id = Number(id);
    var idx = favs.indexOf(id);
    if (idx > -1) favs.splice(idx,1); else favs.push(id);
    localStorage.setItem('pf-favs-'+P.slug, JSON.stringify(favs));
    $$('.pf-fav[data-id="'+id+'"]').forEach(function(b){
      b.classList.toggle('is-on', isFav(id));
      var ic = b.querySelector('i');
      ic.className = 'ico ico-' + (isFav(id) ? 'heart-filled' : 'heart');
      ic.innerHTML = isFav(id) ? "<svg width=\"1em\" height=\"1em\" viewBox=\"0 0 24 24\" fill=\"currentColor\" stroke=\"none\" aria-hidden=\"true\" focusable=\"false\"><path d=\"M6.979 3.074a6 6 0 0 1 4.988 1.425l.037 .033l.034 -.03a6 6 0 0 1 4.733 -1.44l.246 .036a6 6 0 0 1 3.364 10.008l-.18 .185l-.048 .041l-7.45 7.379a1 1 0 0 1 -1.313 .082l-.094 -.082l-7.493 -7.422a6 6 0 0 1 3.176 -10.215z\"/></svg>" : "<svg width=\"1em\" height=\"1em\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.9\" stroke-linecap=\"round\" stroke-linejoin=\"round\" aria-hidden=\"true\" focusable=\"false\"><path d=\"M19.5 12.572l-7.5 7.428l-7.5 -7.428a5 5 0 1 1 7.5 -6.566a5 5 0 1 1 7.5 6.572\"/></svg>";
    });
    $$('.pf-card').forEach(function(c){
      if (Number(c.dataset.id) === id) c.classList.toggle('pf-card--fav', isFav(id));
    });
  }

  $$('.pf-fav').forEach(function(b){
    b.addEventListener('click', function(e){ e.stopPropagation(); toggleFav(b.dataset.id); });
  });

  // ── Search ───────────────────────────────────────────────────────
  var searchInput = $('#pf-search-input');
  var searchClear = $('#pf-search-clear');
  if (searchInput) {
    searchInput.addEventListener('input', function(){
      var q = this.value.toLowerCase().trim();
      searchClear.style.display = q ? '' : 'none';
      filterCards();
    });
  }
  if (searchClear) {
    searchClear.addEventListener('click', function(){
      searchInput.value = '';
      searchClear.style.display = 'none';
      searchInput.dispatchEvent(new Event('input'));
    });
  }

  // ── Category chips ──────────────────────────────────────────────
  var activeCat = 'all';
  $$('.pf-chip, .pf-catbar__chip').forEach(function(chip){
    chip.addEventListener('click', function(){
      activeCat = chip.dataset.cat;
      $$('.pf-chip, .pf-catbar__chip').forEach(function(c){ c.classList.remove('active'); });
      chip.classList.add('active');
      filterCards();
    });
  });

  function filterCards(){
    var q = (searchInput?.value || '').toLowerCase().trim();
    var any = false;
    $$('.pf-card').forEach(function(card){
      var name = (card.dataset.name || '').toLowerCase();
      var catOk = (activeCat === 'all' || card.dataset.cat === activeCat);
      var qOk = (!q || name.indexOf(q) > -1);
      card.style.display = (catOk && qOk) ? '' : 'none';
      if (catOk && qOk) any = true;
    });
    var emptyEl = $('#pf-empty');
    if (emptyEl) emptyEl.style.display = any ? 'none' : '';
  }

  // ── Detail sheet ─────────────────────────────────────────────────
  function openDetail(id){
    var m = P.menus && P.menus[id];
    if (!m) return;
    detailId = id; detailQty = 1; detailVar = null; detailExtras = {};

    var sheet = $('#pf-detail-sheet');
    $('#pf-detail-name').textContent = m.name;
    $('#pf-detail-title').textContent = m.name;
    $('#pf-detail-desc').textContent = m.description || '';
    var img = $('#pf-detail-img');
    if (img) img.src = m.image;

    // badges
    var badges = $('#pf-detail-badges');
    if (badges) {
      var html = '';
      if (m.type === 'veg') html += '<span class="pf-badge diet-veg">Veg</span>';
      else if (m.type === 'nonveg') html += '<span class="pf-badge diet-nonveg">Non Veg</span>';
      if (m.is_popular) html += '<span class="pf-badge diet-custom">Popular</span>';
      if (m.is_vegan) html += '<span class="pf-badge diet-vegan">Vegan</span>';
      badges.innerHTML = html;
    }

    renderDetailVariants(m);
    renderDetailExtras(m);
    updateDetailPrice();

    if (sheet) sheet.classList.add('open');
    $('#pf-scrim').classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function closeDetail(){
    var sheet = $('#pf-detail-sheet');
    if (sheet) sheet.classList.remove('open');
    $('#pf-scrim').classList.remove('open');
    document.body.style.overflow = '';
    detailId = null;
  }

  function renderDetailVariants(m){
    var sec = $('#pf-detail-variants-section');
    var cont = $('#pf-detail-variants');
    if (!cont) return;
    cont.innerHTML = '';
    var hasVars = m.variants && m.variants.length;
    if (sec) sec.style.display = hasVars ? '' : 'none';
    if (!hasVars) return;

    m.variants.forEach(function(v){
      // Build label from variantOptions
      var label = 'Option';
      if (v.options && m.variantOptions) {
        var parts = [];
        Object.keys(v.options).forEach(function(voId){
          var vo = m.variantOptions.find(function(o){ return o.id == voId || o.id == Number(voId); });
          if (vo && vo.options && vo.options[v.options[voId]]) {
            parts.push(vo.title + ': ' + vo.options[v.options[voId]]);
          }
        });
        if (parts.length) label = parts.join(', ');
      }
      var el = document.createElement('button');
      el.className = 'pf-opt';
      el.innerHTML = label + ' <span class="pf-opt-price">' + (v.price > m.price ? '+' + fmtPrice(v.price - m.price) : fmtPrice(v.price)) + '</span>';
      el.addEventListener('click', function(){
        cont.querySelectorAll('.pf-opt').forEach(function(o){ o.classList.remove('selected'); });
        el.classList.add('selected');
        detailVar = v;
        updateDetailPrice();
      });
      cont.appendChild(el);
    });
  }

  function renderDetailExtras(m){
    var sec = $('#pf-detail-extras-section');
    var cont = $('#pf-detail-extras');
    if (!cont) return;
    cont.innerHTML = '';
    var hasEx = m.extras && m.extras.length;
    if (sec) sec.style.display = hasEx ? '' : 'none';
    if (!hasEx) return;

    m.extras.forEach(function(ex){
      var el = document.createElement('button');
      el.className = 'pf-opt pf-opt--extra';
      el.innerHTML = ex.title + ' <span class="pf-opt-price">+' + fmtPrice(ex.price) + '</span>';
      el.addEventListener('click', function(){
        if (detailExtras[ex.id]) { delete detailExtras[ex.id]; el.classList.remove('selected'); }
        else { detailExtras[ex.id] = ex.price; el.classList.add('selected'); }
        updateDetailPrice();
      });
      cont.appendChild(el);
    });
  }

  function updateDetailPrice(){
    if (!detailId) return;
    var m = P.menus[detailId];
    if (!m) return;
    var p = detailVar ? detailVar.price : m.price;
    Object.keys(detailExtras).forEach(function(k){ p += detailExtras[k]; });
    p *= detailQty;
    var el = $('#pf-detail-total');
    if (el) el.textContent = fmtPrice(p);
  }

  window.PizzaFlow = window.PizzaFlow || {};
  window.PizzaFlow.openDetail = openDetail;
  window.PizzaFlow.closeDetail = closeDetail;
  window.PizzaFlow.closeSheets = function(){ closeDetail(); closeCart(); };
  window.PizzaFlow.detailQty = function(delta){
    detailQty = Math.max(1, detailQty + delta);
    var el = $('#pf-detail-qty');
    if (el) el.textContent = detailQty;
    updateDetailPrice();
  };

  // ── Add to cart ──────────────────────────────────────────────────
  window.PizzaFlow.addToCart = function(){
    if (!detailId) return;
    var m = P.menus[detailId];
    if (!m || isSoldOut(detailId)) return;

    var existing = cart.find(function(c){
      return c.id === detailId && c.varId === (detailVar ? detailVar.id : 0) && JSON.stringify(c.extras) === JSON.stringify(detailExtras);
    });
    if (existing) { existing.qty += detailQty; }
    else {
      cart.push({
        id: detailId, name: m.name, image: m.image, price: detailVar ? detailVar.price : m.price,
        qty: detailQty, varId: detailVar ? detailVar.id : 0,
        varLabel: detailVar ? (detailVar.name || '') : '',
        extras: Object.assign({}, detailExtras)
      });
    }
    saveCart(); renderCart(); updateCartBar();
    // pulse cart icon
    var badge = $('#pf-cart-badge');
    if (badge) { badge.classList.add('pf-badge-count--pulse'); setTimeout(function(){ badge.classList.remove('pf-badge-count--pulse'); }, 400); }
    closeDetail();
  };

  // ── Cart ─────────────────────────────────────────────────────────
  function renderCart(){
    var itemsEl = $('#pf-cart-items');
    var emptyEl = $('#pf-cart-empty');
    var checkoutEl = $('#pf-checkout');
    if (!itemsEl) return;

    if (!cart.length){
      itemsEl.innerHTML = '';
      if (emptyEl) emptyEl.style.display = '';
      if (checkoutEl) checkoutEl.style.display = 'none';
    } else {
      if (emptyEl) emptyEl.style.display = 'none';
      if (checkoutEl) checkoutEl.style.display = '';
      itemsEl.innerHTML = cart.map(function(item, i){
        var extrasStr = '';
        if (item.extras) {
          var exNames = [];
          Object.keys(item.extras).forEach(function(exId){
            var m = P.menus[item.id];
            var ex = m && m.extras ? m.extras.find(function(e){ return e.id == exId || e.id == Number(exId); }) : null;
            if (ex) exNames.push(ex.title);
          });
          if (exNames.length) extrasStr = '<div class="ci-meta">' + exNames.join(', ') + '</div>';
        }
        return '<div class="pf-cart-item">'+
          '<img src="'+jwEsc(item.image)+'" alt="" data-csp-error="hide">'+
          '<div class="ci-main">'+
            '<div class="ci-name">'+jwEsc(item.name)+'</div>'+
            extrasStr +
            '<div class="ci-foot">'+
              '<div class="pf-qty sm">'+
                '<button type="button" data-pf-act="_cartQty" data-pf-arg="'+i+',-1" aria-label="'+jwEsc((P.text||{}).decrease)+'">−</button>'+
                '<span>'+item.qty+'</span>'+
                '<button type="button" data-pf-act="_cartQty" data-pf-arg="'+i+',1" aria-label="'+jwEsc((P.text||{}).increase)+'">+</button>'+
              '</div>'+
              '<div class="ci-price">'+fmtPrice(item.price * item.qty)+'</div>'+
            '</div>'+
          '</div>'+
          '<button type="button" class="pf-remove" data-pf-act="_cartRemove" data-pf-arg="'+i+'" aria-label="'+jwEsc((P.text||{}).remove)+'"><i class="ico ico-trash" aria-hidden="true"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12.5a1.5 1.5 0 0 0 1.5 1.5h7a1.5 1.5 0 0 0 1.5-1.5L18 7M9 7V4.5A1.5 1.5 0 0 1 10.5 3h3A1.5 1.5 0 0 1 15 4.5V7"/></svg></i></button>'+
        '</div>';
      }).join('');
    }
    updateCartTotals();
  }

  function updateCartBar(){
    var bar = $('#pf-cartbar');
    if (!bar) return;
    var count = cart.reduce(function(s,i){ return s + i.qty; }, 0);
    if (count > 0) {
      bar.style.display = '';
      bar.classList.add('show');
      var cntEl = $('#pf-cartbar-count');
      var totEl = $('#pf-cartbar-total');
      if (cntEl) cntEl.textContent = count + ' items';
      if (totEl) totEl.textContent = fmtPrice(cartTotal());
    } else { bar.style.display = 'none'; bar.classList.remove('show'); }
    var badge = $('#pf-cart-badge');
    if (badge) { badge.style.display = count > 0 ? '' : 'none'; badge.textContent = count; }
  }

  function updateCartTotals(){
    var t = cartTotal();
    var sub = $('#pf-cart-subtotal');
    var totalEl = $('#pf-cart-total');
    var ckTotal = $('#pf-checkout-total');
    if (sub) sub.textContent = fmtPrice(t);
    if (totalEl) totalEl.textContent = fmtPrice(t);
    if (ckTotal) ckTotal.textContent = fmtPrice(t);
  }

  function openCart(){
    var sheet = $('#pf-cart-sheet');
    if (sheet) sheet.classList.add('open');
    $('#pf-scrim').classList.add('open');
    document.body.style.overflow = 'hidden';
    renderCart();
  }
  function closeCart(){
    var sheet = $('#pf-cart-sheet');
    if (sheet) sheet.classList.remove('open');
    $('#pf-scrim').classList.remove('open');
    document.body.style.overflow = '';
  }

  window.PizzaFlow.openCart = openCart;
  window.PizzaFlow.closeCart = closeCart;
  window.PizzaFlow.closeSuccess = function(){ $('#pf-success').style.display = 'none'; closeCart(); };

  window.PizzaFlow._cartQty = function(idx, delta){
    cart[idx].qty = Math.max(1, cart[idx].qty + delta);
    saveCart(); renderCart(); updateCartBar();
  };
  window.PizzaFlow._cartRemove = function(idx){
    cart.splice(idx, 1); saveCart(); renderCart(); updateCartBar();
  };

  // ── Order submission ─────────────────────────────────────────────
  window.PizzaFlow.submitOrder = function(){
    if (!cart.length) return;
    var name = ($('#pf-checkout-name')?.value || '').trim();
    if (!name) { alert('Please enter your name.'); return; }

    var orderType = 'takeaway';
    var typeGroup = $('#pf-ordering-type-group');
    if (typeGroup) {
      var activeBtn = typeGroup.querySelector('.active, .pf-seg__btn.active, button.active');
      if (activeBtn) orderType = activeBtn.dataset.type;
    }

    var payVia = 'pay_on_counter';
    var payGroup = $('#pf-pay-group');
    if (payGroup) {
      var payBtn = payGroup.querySelector('.active, .pf-seg__btn.active, button.active');
      if (payBtn) payVia = payBtn.dataset.pay;
    }

    var table = ($('#pf-checkout-table')?.value || '').trim();
    var phone = ($('#pf-checkout-phone')?.value || '').trim();
    var address = ($('#pf-checkout-address')?.value || '').trim();
    var message = ($('#pf-checkout-message')?.value || '').trim();
    var waOptin = $('#pf-checkout-optin')?.checked ? 1 : 0;

    if (orderType !== 'on-table' && !phone) { alert('Please enter your phone number.'); return; }
    if (orderType === 'delivery' && !address) { alert('Please enter your delivery address.'); return; }

    var items = cart.map(function(item){
      var o = { id: item.id, quantity: item.qty };
      if (item.varId) o.variants = item.varId;
      if (item.extras && Object.keys(item.extras).length) {
        o.extras = Object.keys(item.extras).map(function(k){ return {id: Number(k)}; });
      }
      return o;
    });

    var btn = $('[data-pf-act="submitOrder"]');
    if (btn) { btn.disabled = true; btn.textContent = 'Placing order...'; }

    fetch(P.submitOrderUrl, {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-TOKEN':P.csrf},
      body: JSON.stringify({
        _token: P.csrf,
        items: JSON.stringify(items),
        name: name,
        'ordering-type': orderType,
        table: table,
        'phone-number': phone,
        address: address,
        pay_via: payVia,
        message: message,
        whatsapp_optin: waOptin
      })
    })
    .then(function(r){ return r.json().then(function(d){ return {ok:r.ok,body:d}; }); })
    .then(function(res){
      if (res.body.message && res.body.message.indexOf('http') === 0){
        window.location.href = res.body.message;
      } else if (res.body.whatsapp_url){
        window.location.href = res.body.whatsapp_url;
      } else if (res.body.success || res.ok){
        cart = []; saveCart(); renderCart(); updateCartBar();
        $('#pf-cart-items').innerHTML = '';
        $('#pf-cart-empty').style.display = '';
        $('#pf-checkout').style.display = 'none';
        $('#pf-success').style.display = '';
      } else {
        var errEl = $('#pf-checkout-error');
        if (errEl) { errEl.style.display = ''; errEl.textContent = res.body.message || res.body.error || 'Error'; }
      }
    })
    .catch(function(){
      alert('Network error. Please try again.');
    })
    .finally(function(){
      if (btn) { btn.disabled = false; btn.textContent = 'Place Order'; }
    });
  };

  // ── Order type toggle ────────────────────────────────────────────
  var typeGroup = $('#pf-ordering-type-group');
  if (typeGroup) {
    typeGroup.querySelectorAll('button').forEach(function(btn){
      btn.addEventListener('click', function(){
        typeGroup.querySelectorAll('button').forEach(function(b){ b.classList.remove('active'); });
        this.classList.add('active');
        var t = this.dataset.type;
        var tableF = $('#pf-field-table'), phoneF = $('#pf-field-phone'), addrF = $('#pf-field-address');
        if (tableF) tableF.style.display = t === 'on-table' ? '' : 'none';
        if (phoneF) phoneF.style.display = t !== 'on-table' ? '' : 'none';
        if (addrF) addrF.style.display = t === 'delivery' ? '' : 'none';
      });
    });
    // Init
    var first = typeGroup.querySelector('.active, button.active');
    if (first) first.click();
    else typeGroup.querySelector('button')?.click();
  }

  var payGroup = $('#pf-pay-group');
  if (payGroup) {
    payGroup.querySelectorAll('button').forEach(function(btn){
      btn.addEventListener('click', function(){
        payGroup.querySelectorAll('button').forEach(function(b){ b.classList.remove('active'); b.setAttribute('aria-pressed', 'false'); });
        this.classList.add('active');
        this.setAttribute('aria-pressed', 'true');
      });
    });
  }

  // ── Bottom nav ───────────────────────────────────────────────────
  $$('.pf-nav-item').forEach(function(btn){
    btn.addEventListener('click', function(){
      $$('.pf-nav-item').forEach(function(b){ b.classList.remove('active'); });
      this.classList.add('active');
      var tab = this.dataset.tab;
      if (tab === 'favorites'){
        activeCat = 'all';
        $$('.pf-chip, .pf-catbar__chip').forEach(function(c){ c.classList.remove('active'); });
        var allChip = document.querySelector('.pf-chip[data-cat="all"], .pf-catbar__chip[data-cat="all"]');
        if (allChip) allChip.classList.add('active');
        $$('.pf-card').forEach(function(card){
          card.style.display = isFav(Number(card.dataset.id)) ? '' : 'none';
        });
      } else if (tab === 'popular'){
        var popSec = $('#pf-pop-section');
        if (popSec) popSec.scrollIntoView({behavior:'smooth'});
      } else if (tab === 'menu'){
        activeCat = 'all';
        $$('.pf-chip, .pf-catbar__chip').forEach(function(c){ c.classList.remove('active'); });
        var ac = document.querySelector('.pf-chip[data-cat="all"], .pf-catbar__chip[data-cat="all"]');
        if (ac) ac.classList.add('active');
        filterCards();
      } else if (tab === 'cart'){
        openCart();
      }
    });
  });

  // ── Card click → detail ─────────────────────────────────────────
  $$('.pf-card').forEach(function(card){
    card.addEventListener('click', function(e){
      if (e.target.closest('.pf-fav') || e.target.closest('.pf-add')) return;
      var id = Number(card.dataset.id);
      if (P.menus && P.menus[id]) openDetail(id);
    });
  });

  // ── Delegated actions ───────────────────────────────────────────
  // The storefront CSP is enforced, which blocks inline click-handler attributes: the markup carries data-pf-act (a
  // PizzaFlow method) and data-pf-arg (comma-separated numeric arguments) and this one listener runs them.
  function runAct(el){
    var fn = window.PizzaFlow[el.getAttribute('data-pf-act')];
    if (typeof fn !== 'function') return;
    var raw = el.getAttribute('data-pf-arg');
    fn.apply(null, raw === null || raw === '' ? [] : raw.split(',').map(Number));
  }
  root.addEventListener('click', function(e){
    var el = e.target.closest('[data-pf-act]');
    if (el) { runAct(el); return; }
    var add = e.target.closest('.pf-add');
    if (add && add.dataset.id) openDetail(Number(add.dataset.id));
  });
  root.addEventListener('keydown', function(e){
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var el = e.target.closest('[data-pf-act][role="button"]');
    if (el) { e.preventDefault(); runAct(el); }
  });

  // ── Scrim close ─────────────────────────────────────────────────
  $('#pf-scrim')?.addEventListener('click', function(){ closeDetail(); closeCart(); });

  // ── Init ─────────────────────────────────────────────────────────
  renderCart(); updateCartBar();

  // Add card name to dataset for search
  $$('.pf-card').forEach(function(card){
    var nameEl = card.querySelector('.pf-card-title, .pf-card__name, h3');
    if (nameEl) card.dataset.name = nameEl.textContent;
  });

})();
