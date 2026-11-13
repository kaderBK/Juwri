// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ============================================================
   Fusion marketplace — mk-store.js
   P3.8 / P3.9 (fusion-crave): the PRODUCT page (gallery, variants,
   choice groups, extras, qty, live price math, add-to-bag) and the
   STORE page interaction layer (category filtering, layout toggle,
   quick-add, favourite items, offer copy, loyalty hydration, the
   reserve / waitlist / review sheets).

   Style follows mk-discovery.js / mk-core.js: one IIFE, ES5-ish,
   registered through window.Mk.register so it never edits mk-core.js.
   Helpers (csrf, toast, motion) come from Mk — nothing is re-implemented.

   Every DOM hook is feature-detected: the store blade is authored in
   parallel, so a missing element is a no-op, never a crash.
   ============================================================ */
(function () {
  'use strict';

  /* ---------- tiny helpers ---------- */
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return [].slice.call((root || document).querySelectorAll(sel)); }
  function reduced() {
    return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  }
  /* Translated strings arrive via window.MkI18n (blade-rendered). Every lookup
     carries an English fallback so a page that forgot a key still works. */
  function t(key, fallback) {
    return (window.MkI18n && window.MkI18n[key]) || fallback;
  }
  function toast(msg) {
    if (window.Mk && window.Mk.toast) { window.Mk.toast(msg); }
  }
  function csrf() {
    if (window.Mk && window.Mk.csrf) { return window.Mk.csrf; }
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.content : '';
  }
  function postJson(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
      body: JSON.stringify(body || {})
    }).then(function (r) {
      return r.text().then(function (txt) {
        var j = {};
        try { j = txt ? JSON.parse(txt) : {}; } catch (e) { j = {}; }
        return { ok: r.ok, status: r.status, j: j };
      });
    });
  }
  /* Trailing-zero trim mirrors the blade's rtrim(rtrim(number_format(..))). */
  function money(n) {
    var v = (Math.round((n || 0) * 100) / 100).toFixed(2);
    return v.indexOf('.') === -1 ? v : v.replace(/\.?0+$/, '');
  }
  function withCurrency(n, currency) {
    return currency ? money(n) + ' ' + currency : money(n);
  }
  function scrollIntoView(el) {
    if (!el || !el.scrollIntoView) { return; }
    el.scrollIntoView({ block: 'center', behavior: reduced() ? 'auto' : 'smooth' });
  }

  /* ---------- bag badge ---------- */
  /* The tabbar badge is the only shared surface between the two halves; the
     server's cart payload is the authority, a local +1 is the fallback. */
  function bagBadge(cart, fallbackDelta) {
    /* Two badges now: the phone tabbar and the ≥900px topbar nav. Only one is
       displayed at a time, but both must hold the right number — a resize
       must not reveal a stale count. */
    var all = document.querySelectorAll('[data-mk-bagcount]');
    var el = all[0];
    if (!el) { return; }
    var n = 0;
    if (cart && typeof cart === 'object') {
      Object.keys(cart).forEach(function (rid) {
        var store = cart[rid];
        var lines = (store && store.items) || (Array.isArray(store) ? store : []);
        (lines || []).forEach(function (l) { n += parseInt(l.quantity, 10) || 0; });
      });
    } else {
      n = (parseInt(el.textContent, 10) || 0) + (fallbackDelta || 0);
    }
    Array.prototype.forEach.call(all, function (b) {
      b.textContent = n > 99 ? '99+' : String(n);
      b.hidden = n <= 0;
      b.classList.remove('mk-bump');
      void b.offsetWidth;
      b.classList.add('mk-bump');
    });
  }

  /* ---------- favourite items (contract 3.7: localStorage only) ---------- */
  /* Key `mk_fav_items`, value = JSON array of "<storeId>:<itemId>" strings.
     Distinct from mk-core's store-level [data-mk-fav] hearts, which hit the
     real mk.account.favorites.toggle endpoint. */
  var FAV_KEY = 'mk_fav_items';
  function favRead() {
    try {
      var a = JSON.parse(localStorage.getItem(FAV_KEY) || '[]');
      return Array.isArray(a) ? a.filter(function (x) { return typeof x === 'string'; }) : [];
    } catch (e) { return []; }
  }
  function favWrite(list) {
    try { localStorage.setItem(FAV_KEY, JSON.stringify(list)); } catch (e) {}
  }
  function favInit() {
    var nodes = $$('[data-mk-favitem]');
    if (!nodes.length) { return; }
    var list = favRead();
    nodes.forEach(function (b) {
      b.classList.toggle('is-on', list.indexOf(b.getAttribute('data-mk-favitem')) !== -1);
      b.setAttribute('aria-pressed', b.classList.contains('is-on') ? 'true' : 'false');
    });
    document.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('[data-mk-favitem]') : null;
      if (!b) { return; }
      e.preventDefault();
      e.stopPropagation();
      var key = b.getAttribute('data-mk-favitem');
      var cur = favRead();
      var at = cur.indexOf(key);
      if (at === -1) { cur.push(key); } else { cur.splice(at, 1); }
      favWrite(cur);
      var on = at === -1;
      /* Same key can be on screen twice (hero fab + grid card). */
      $$('[data-mk-favitem="' + key.replace(/"/g, '\\"') + '"]').forEach(function (n) {
        n.classList.toggle('is-on', on);
        n.setAttribute('aria-pressed', on ? 'true' : 'false');
        n.classList.remove('mk-pop'); void n.offsetWidth; n.classList.add('mk-pop');
      });
    }, true);
  }

  /* ============================================================
     PRODUCT PAGE (P3.8 + P3.9)
     ============================================================ */
  var product = {
    init: function () {
      var root = document.querySelector('[data-mk-product]');
      var cfg = window.MkProduct;
      if (!root || !cfg) { return; }

      product.gallery(root);
      product.options(root, cfg);
      product.qty(root, cfg);
      product.add(root, cfg);
      product.rate(root);
    },

    /* --- hero gallery: scroll → dot sync -----------------------------------
       Index is derived from getBoundingClientRect only, never from scrollLeft:
       RTL browsers disagree on the sign and origin of scrollLeft, but the
       centre-most slide is the current slide in every direction. */
    gallery: function (root) {
      var gal = $('.mk-p__gallery', root);
      var dotWrap = $('.mk-p__dots', root);
      if (!gal || !dotWrap) { return; }
      var imgs = $$('img', gal);
      var dots = $$('i', dotWrap);
      if (imgs.length < 2 || !dots.length) { return; }

      var raf = 0;
      function sync() {
        raf = 0;
        var box = gal.getBoundingClientRect();
        var centre = box.left + (box.width / 2);
        var best = 0;
        var bestDist = Infinity;
        imgs.forEach(function (im, i) {
          var r = im.getBoundingClientRect();
          var d = Math.abs((r.left + (r.width / 2)) - centre);
          if (d < bestDist) { bestDist = d; best = i; }
        });
        dots.forEach(function (d, i) { d.classList.toggle('is-on', i === best); });
      }
      gal.addEventListener('scroll', function () {
        if (raf) { return; }
        raf = window.requestAnimationFrame ? window.requestAnimationFrame(sync) : setTimeout(sync, 60);
      }, { passive: true });
      sync();
    },

    /* --- variants / choice groups / extras ---------------------------------
       Every option is a real <input> (radio for max-1 groups, checkbox
       otherwise) inside its .mk-opt row, so keyboard + form semantics are
       free and the DOM stays the single source of truth for the payload. */
    options: function (root, cfg) {
      var groups = $$('.mk-optgroup', root);
      if (!groups.length) { product.price(root, cfg); return; }

      root.addEventListener('change', function (e) {
        var input = e.target;
        if (!input || input.tagName !== 'INPUT') { return; }
        var group = input.closest('.mk-optgroup');
        if (!group) { return; }

        /* Clamp max-select: the (max+1)-th tick is refused rather than
           silently dropping an earlier choice, so the shopper always sees
           exactly what they picked. Radios are self-clamping at 1. */
        if (input.type === 'checkbox' && input.checked) {
          var max = parseInt(group.getAttribute('data-max'), 10);
          if (max > 0 && $$('input:checked', group).length > max) {
            input.checked = false;
            toast(t('optMax', 'You can choose up to %d').replace('%d', max));
          }
        }
        product.syncGroup(group);
        product.validate(group);
        product.price(root, cfg);
      });

      groups.forEach(function (g) { product.syncGroup(g); });
      product.price(root, cfg);
    },

    /** Mirror input.checked onto the .mk-opt row so the CSS `.is-on` state
        (and the check mark inside .mk-optmark) follows real form state. */
    syncGroup: function (group) {
      $$('.mk-opt', group).forEach(function (row) {
        var input = $('input', row);
        row.classList.toggle('is-on', !!(input && input.checked));
      });
    },

    /** Minimum satisfied? `required` implies min 1 when no explicit min. */
    groupMin: function (group) {
      var min = parseInt(group.getAttribute('data-min'), 10) || 0;
      if (group.getAttribute('data-required') === '1' && min < 1) { min = 1; }
      return min;
    },

    validate: function (group) {
      var min = product.groupMin(group);
      var ok = !min || $$('input:checked', group).length >= min;
      group.classList.toggle('is-invalid', !ok);
      return ok;
    },

    /* --- price math (mirrors the server; the client never sends money) ------
       unit  = (variant ? variant.price : basePrice) + Σ option + Σ extra
       total = unit × qty                                                    */
    unit: function (root, cfg) {
      var base = parseFloat(cfg.basePrice) || 0;
      var variant = $('[data-mk-variant]:checked', root);
      if (variant) { base = parseFloat(variant.getAttribute('data-price')) || 0; }
      var sum = 0;
      $$('.mk-optgroup input:checked', root).forEach(function (i) {
        if (i.hasAttribute('data-mk-variant')) { return; } // absolute, not additive
        sum += parseFloat(i.getAttribute('data-price')) || 0;
      });
      return base + sum;
    },

    price: function (root, cfg) {
      var unit = product.unit(root, cfg);
      var qtyEl = $('.mk-qty output', root);
      var qty = Math.max(1, parseInt(qtyEl ? qtyEl.textContent : '1', 10) || 1);
      var unitEl = $('.mk-p__price', root);
      var totalEl = $('.mk-cta b', root);
      if (unitEl) { unitEl.textContent = withCurrency(unit, cfg.currency); }
      if (totalEl) { totalEl.textContent = withCurrency(unit * qty, cfg.currency); }
      return unit;
    },

    /* --- quantity stepper --- */
    qty: function (root, cfg) {
      var wrap = $('.mk-qty', root);
      if (!wrap) { return; }
      var out = $('output', wrap);
      wrap.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('button[data-mk-qty]') : null;
        if (!b || !out) { return; }
        var step = parseInt(b.getAttribute('data-mk-qty'), 10) || 0;
        var next = Math.min(50, Math.max(1, (parseInt(out.textContent, 10) || 1) + step));
        out.textContent = String(next);
        out.classList.remove('mk-bump'); void out.offsetWidth; out.classList.add('mk-bump');
        product.price(root, cfg);
      });
    },

    /* --- add to bag --------------------------------------------------------
       Payload frozen by P3-CONTRACT "Selections payload (the P3.8 seam)":
       { store_id, item_id, quantity, note, variation,
         options:[{group_id, selected:[label,…]}], extras:[id,…] }
       Labels (not indexes) — resolveOptions() does array_search on labels. */
    add: function (root, cfg) {
      var cta = $('.mk-pcta .mk-cta', root);
      if (!cta) { return; }
      var busy = false;

      cta.addEventListener('click', function () {
        if (busy) { return; }

        /* required/min gate — mark every offender, scroll to the first */
        var firstBad = null;
        $$('.mk-optgroup', root).forEach(function (g) {
          if (!product.validate(g) && !firstBad) { firstBad = g; }
        });
        if (firstBad) {
          scrollIntoView(firstBad);
          toast(t('optRequired', 'Please complete the required choices'));
          return;
        }

        var variant = $('[data-mk-variant]:checked', root);
        var options = [];
        $$('.mk-optgroup[data-kind="group"]', root).forEach(function (g) {
          var labels = $$('input:checked', g).map(function (i) { return i.value; });
          if (labels.length) {
            options.push({ group_id: parseInt(g.getAttribute('data-group'), 10), selected: labels });
          }
        });
        var extras = $$('.mk-optgroup[data-kind="extra"] input:checked', root).map(function (i) {
          return parseInt(i.getAttribute('data-id'), 10);
        }).filter(function (n) { return !!n; });

        var noteEl = $('#mk-p-note', root);
        var note = noteEl ? noteEl.value.trim() : '';
        var qtyEl = $('.mk-qty output', root);
        var qty = Math.max(1, parseInt(qtyEl ? qtyEl.textContent : '1', 10) || 1);

        var payload = {
          store_id: cfg.storeId,
          item_id: cfg.itemId,
          quantity: qty,
          note: note || null,
          variation: variant ? (parseInt(variant.getAttribute('data-id'), 10) || null) : null,
          options: options,
          extras: extras
        };

        busy = true;
        cta.disabled = true;
        var spinner = document.createElement('span');
        spinner.className = 'mk-spinner';
        cta.appendChild(spinner);

        postJson(cfg.addUrl || '/order-hub/add', payload)
          .then(function (res) {
            if (res.ok && res.j.ok !== false) {
              bagBadge(res.j.cart, qty);
              toast(t('added', 'Added to bag'));
              return;
            }
            /* The server is the authority on why an add failed — surface its
               own message (422 → { ok:false, error:"…" }), never a generic one
               that hides a real validation reason. */
            var msg = res.j.error
              || (res.j.errors && Object.keys(res.j.errors).length
                    ? [].concat(res.j.errors[Object.keys(res.j.errors)[0]])[0]
                    : null)
              || res.j.message
              || t('addFailed', 'Could not add — try again');
            toast(msg);
          })
          .catch(function () { toast(t('addFailed', 'Could not add — try again')); })
          .then(function () {
            busy = false;
            cta.disabled = false;
            if (spinner.parentNode) { spinner.parentNode.removeChild(spinner); }
          });
      });
    },

    /* --- inline "rate this dish" (3.9) → restaurant.submitReview ---------- */
    rate: function (root) {
      var wrap = $('.mk-starsin[data-mk-rate]', root);
      if (!wrap) { return; }
      var url = wrap.getAttribute('data-mk-rate');
      var menuId = parseInt(wrap.getAttribute('data-menu'), 10);
      var sent = false;
      wrap.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('button[data-star]') : null;
        if (!b || sent) { return; }
        var n = parseInt(b.getAttribute('data-star'), 10) || 0;
        $$('button', wrap).forEach(function (s, i) { s.classList.toggle('is-on', i < n); });
        sent = true;
        postJson(url, { menu_id: menuId, stars: n })
          .then(function (res) {
            toast(res.j.message || t('rateThanks', 'Thanks for rating!'));
            if (res.j.success === false) { sent = false; }
          })
          .catch(function () { sent = false; toast(t('genericError', 'Something went wrong — try again')); });
      });
    }
  };

  /* ============================================================
     STORE PAGE
     Hooks are all optional — worker B's blade may render any subset.
     ============================================================ */
  var store = {
    init: function () {
      var root = document.querySelector('.mk-store');
      if (!root) { return; }
      store.filters(root);
      store.layout(root);
      store.quickAdd(root);
      store.offers(root);
      store.loyalty();
      store.stars();
      store.sheets();
      store.follow(root);
    },

    /* --- follow / unfollow (6.1) ------------------------------------------
       Until P6.1 there was NO handler for [data-mk-follow] at all — the button
       rendered and did nothing. It now hits the real mk.follows.toggle, which
       answers {following, followers}; the count node is live-updated from the
       response rather than requiring a reload. A signed-out shopper is sent to
       the auth sheet instead of silently failing. ------------------------- */
    follow: function (root) {
      var btn = root.querySelector('[data-mk-follow]');
      if (!btn) { return; }
      var url = btn.getAttribute('data-url');
      if (!url) { return; }

      btn.addEventListener('click', function () {
        if (document.body.dataset.mkAuth !== '1') {
          var login = document.querySelector('[data-mk-login]');
          if (login) { login.click(); } else { toast(t('signInFirst', 'Sign in to follow this store.')); }
          return;
        }
        if (btn.disabled) { return; }
        btn.disabled = true;

        postJson(url, { store_id: parseInt(btn.getAttribute('data-mk-follow'), 10) })
          .then(function (r) {
            if (!r.ok || r.j.ok === false) {
              toast((r.j && (r.j.error || r.j.message)) || t('genericError', 'Something went wrong.'));
              return;
            }
            var on = !!r.j.following;
            btn.classList.toggle('is-on', on);
            btn.textContent = on
              ? (btn.getAttribute('data-label-on') || 'Following')
              : (btn.getAttribute('data-label-off') || 'Follow');
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');

            var count = root.querySelector('[data-mk-followers]');
            if (count && typeof r.j.followers === 'number') {
              count.textContent = String(r.j.followers);
              count.parentElement.hidden = r.j.followers <= 0;
            }
          })
          .catch(function () { toast(t('genericError', 'Something went wrong.')); })
          .then(function () { btn.disabled = false; });
      });
    },

    /* --- star picker inside a sheet form (6.5) ----------------------------
       [data-mk-stars] wraps button[data-star]; the hidden
       [data-mk-stars-value] input in the same form is the ONLY thing posted,
       so the drawn rating and the submitted rating cannot drift apart.
       Bound on document: the sheets live outside .mk-store. */
    stars: function () {
      document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-mk-stars] button[data-star]') : null;
        if (!b) { return; }
        store.starsPaint(b.closest('[data-mk-stars]'), parseInt(b.getAttribute('data-star'), 10) || 0);
      });
    },

    starsPaint: function (wrap, n) {
      if (!wrap) { return; }
      $$('button[data-star]', wrap).forEach(function (s) {
        s.classList.toggle('is-on', (parseInt(s.getAttribute('data-star'), 10) || 0) <= n);
      });
      var scope = wrap.closest('form');
      var input = scope ? $('[data-mk-stars-value]', scope) : null;
      if (input) { input.value = String(n); }
    },

    /** Re-paint after form.reset() so the stars follow the restored input. */
    starsSync: function (form) {
      $$('[data-mk-stars]', form).forEach(function (w) {
        var input = $('[data-mk-stars-value]', form);
        store.starsPaint(w, parseInt(input ? input.value : '', 10) || 0);
      });
    },

    /* --- category / sub-category chips ------------------------------------
       Chips: [data-mk-cat="<id|''>"] and [data-mk-subcat="<id>"] (the latter
       carrying data-parent). Filterable nodes: any [data-cat] (and optional
       [data-subcat]). '' means "all". */
    filters: function (root) {
      var cats = $$('[data-mk-cat]', root);
      var subs = $$('[data-mk-subcat]', root);
      if (!cats.length && !subs.length) { return; }
      var state = { cat: '', sub: '' };

      function apply() {
        $$('[data-cat]', root).forEach(function (n) {
          var okCat = !state.cat || n.getAttribute('data-cat') === state.cat;
          var okSub = !state.sub || n.getAttribute('data-subcat') === state.sub;
          n.hidden = !(okCat && okSub);
        });
        /* Hide a section that lost every child. */
        $$('[data-mk-catsec]', root).forEach(function (sec) {
          sec.hidden = !$$('[data-cat]', sec).some(function (n) { return !n.hidden; });
        });
        cats.forEach(function (c) { c.classList.toggle('is-on', c.getAttribute('data-mk-cat') === state.cat); });
        subs.forEach(function (s) {
          var mine = !state.cat || s.getAttribute('data-parent') === state.cat;
          s.hidden = !mine;
          s.classList.toggle('is-on', s.getAttribute('data-mk-subcat') === state.sub);
        });
        var subBar = $('[data-mk-subbar]', root);
        if (subBar) {
          subBar.hidden = !subs.some(function (s) { return !s.hidden; });
        }
      }

      root.addEventListener('click', function (e) {
        var c = e.target.closest ? e.target.closest('[data-mk-cat]') : null;
        if (c) {
          state.cat = c.getAttribute('data-mk-cat') || '';
          state.sub = '';
          apply();
          return;
        }
        var s = e.target.closest ? e.target.closest('[data-mk-subcat]') : null;
        if (s) {
          var id = s.getAttribute('data-mk-subcat') || '';
          state.sub = state.sub === id ? '' : id; // second tap clears
          apply();
        }
      });
      apply();
    },

    /* --- grid ↔ list toggle (persisted) --- */
    layout: function (root) {
      var btn = $('[data-mk-layout]', root);
      var grid = $('[data-mk-grid]', root) || $('.mk-igrid', root);
      if (!grid) { return; }
      var KEY = 'mk_store_layout';
      var saved = null;
      try { saved = localStorage.getItem(KEY); } catch (e) {}
      function apply(mode) {
        grid.classList.toggle('is-list', mode === 'list');
        if (btn) { btn.setAttribute('aria-pressed', mode === 'list' ? 'true' : 'false'); }
      }
      apply(saved === 'list' ? 'list' : 'grid');
      if (!btn) { return; }
      btn.addEventListener('click', function () {
        var next = grid.classList.contains('is-list') ? 'grid' : 'list';
        apply(next);
        try { localStorage.setItem(KEY, next); } catch (e) {}
      });
    },

    /* --- quick-add for option-less items ---------------------------------
       Items WITH options carry no [data-mk-add]; their card links straight to
       the product page (mk.product), so this path only ever posts a plain
       item that the server can price without a selection. */
    quickAdd: function (root) {
      var cfg = window.MkStorePage || {};
      root.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-mk-add]') : null;
        if (!b || b.disabled) { return; }
        e.preventDefault();
        e.stopPropagation();
        if (cfg.closed) { toast(t('closed', 'This store is closed right now.')); return; }
        b.disabled = true;
        postJson(cfg.addUrl || '/order-hub/add', {
          store_id: cfg.id || parseInt(root.getAttribute('data-store'), 10),
          item_id: parseInt(b.getAttribute('data-mk-add'), 10),
          quantity: 1
        }).then(function (res) {
          if (res.ok && res.j.ok !== false) {
            bagBadge(res.j.cart, 1);
            b.classList.remove('mk-pop'); void b.offsetWidth; b.classList.add('mk-pop');
            toast(t('added', 'Added to bag'));
          } else {
            toast(res.j.error || res.j.message || t('addFailed', 'Could not add — try again'));
          }
        }).catch(function () { toast(t('addFailed', 'Could not add — try again')); })
          .then(function () { b.disabled = false; });
      });
    },

    /* --- offer code copy (clipboard API + execCommand fallback) --- */
    offers: function (root) {
      root.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.mk-offer__copy') : null;
        if (!b) { return; }
        e.preventDefault();
        var code = b.getAttribute('data-code') || b.getAttribute('data-mk-copy') || '';
        if (!code) { return; }
        function done() {
          var was = b.textContent;
          b.textContent = t('copied', 'Copied');
          setTimeout(function () { b.textContent = was; }, 1600);
          toast(t('copiedCode', 'Code copied') + ' — ' + code);
        }
        function legacy() {
          try {
            var ta = document.createElement('textarea');
            ta.value = code;
            ta.setAttribute('readonly', 'readonly');
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            var ok = document.execCommand('copy');
            document.body.removeChild(ta);
            return ok;
          } catch (err) { return false; }
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(code).then(done, function () { if (legacy()) { done(); } });
        } else if (legacy()) {
          done();
        }
      });
    },

    /* --- loyalty card hydration (auth:customer) ---------------------------
       The card's CONFIG is server-rendered; the BALANCE is auth-gated. A 401
       or 403 reveals the sign-in CTA — a signed-out shopper must never be
       shown a fabricated balance. */
    loyalty: function () {
      var card = document.querySelector('[data-mk-loyalty]');
      if (!card) { return; }
      var url = card.getAttribute('data-url');
      if (!url) { return; }
      var live = $('[data-mk-loyalty-live]', card);
      var signin = $('[data-mk-loyalty-signin]', card);

      function gate() {
        /* Guest: keep the demo-parity serif row visible — it holds the "–"
           placeholder from the blade, never a fabricated balance. */
        if (signin) { signin.hidden = false; }
      }

      fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) {
          if (r.status === 401 || r.status === 403) { gate(); return null; }
          if (!r.ok) { return null; }
          return r.json();
        })
        .then(function (j) {
          if (!j) { return; }
          if (j.active === false) { card.hidden = true; return; }
          if (signin) { signin.hidden = true; }
          if (live) { live.hidden = false; }
          var bal = $('[data-mk-loyalty-balance]', card);
          if (bal) { bal.textContent = String(j.balance != null ? j.balance : 0); }
          var sub = $('[data-mk-loyalty-sub]', card);
          if (sub && j.reward_description) { sub.textContent = j.reward_description; }
          var stamps = $$('.mk-stamp', card);
          if (stamps.length) {
            var n = parseInt(j.balance, 10) || 0;
            stamps.forEach(function (s, i) { s.classList.toggle('is-on', i < n); });
          }
          var cta = $('[data-mk-loyalty-cta]', card);
          if (cta) { cta.hidden = !j.ready_to_redeem; }
        })
        .catch(function () { gate(); });
    },

    /* --- sheets: reserve / waitlist / review / chat -----------------------
       Opening is generic: [data-mk-sheet="<sheet-id>"]. Each sheet holds a
       REAL <form action="…"> posting to a REAL endpoint; we intercept it only
       to keep the shopper on the page and render the server's answer. */
    sheets: function () {
      /* Server-flashed answers: the blade marks a sheet [data-mk-autoopen]
         when it re-rendered with session('reservation_success') /
         session('waitlist_joined') / validation errors. Without this the
         no-JS path — and the redirect path above, which reloads on purpose —
         would land on a page whose answer is hidden inside a closed sheet. */
      $$('.mk-sheet[data-mk-autoopen]').forEach(function (sheet) {
        sheet.hidden = false;
        var focus = $('.mk-result:not([hidden])', sheet) || $('.mk-field__err', sheet);
        if (focus) { scrollIntoView(focus); }
      });

      document.addEventListener('click', function (e) {
        var open = e.target.closest ? e.target.closest('[data-mk-sheet]') : null;
        if (open) {
          var sheet = document.getElementById(open.getAttribute('data-mk-sheet'));
          if (sheet) {
            e.preventDefault();
            sheet.hidden = false;
            var first = sheet.querySelector('input, textarea, select');
            if (first && !reduced()) { setTimeout(function () { first.focus(); }, 120); }
          }
          return;
        }
        var close = e.target.closest ? e.target.closest('[data-mk-sheet-close]') : null;
        if (close) {
          var owner = close.closest('.mk-sheet');
          if (owner) { owner.hidden = true; }
          return;
        }
        /* backdrop */
        if (e.target.classList && e.target.classList.contains('mk-sheet')) {
          e.target.hidden = true;
        }
      });

      document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        $$('.mk-sheet').forEach(function (s) { s.hidden = true; });
      });

      $$('form[data-mk-ajax]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
          e.preventDefault();
          var btn = form.querySelector('[type="submit"]');
          var result = $('.mk-result', form.closest('.mk-sheet') || form) || $('.mk-result', form);
          if (btn) { btn.disabled = true; }
          fetch(form.action, {
            method: (form.method || 'POST').toUpperCase(),
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() },
            body: new FormData(form),
            credentials: 'same-origin',
            /* Do NOT follow. A 302 here means the endpoint took its no-JS path
               and flashed the answer to the session — following it would burn
               that flash on a response we then throw away, and (worse) hand us
               a 200 HTML body we could mistake for success. */
            redirect: 'manual'
          }).then(function (r) {
            if (r.type === 'opaqueredirect' || r.status === 0 || (r.status >= 300 && r.status < 400)) {
              return { redirected: true };
            }
            /* Only a real JSON body may be read as a result. Anything else
               (error page, expired session, login wall) leaves j === null. */
            var ct = r.headers.get('content-type') || '';
            return r.text().then(function (txt) {
              var j = null;
              if (ct.toLowerCase().indexOf('json') !== -1) {
                try { j = txt ? JSON.parse(txt) : {}; } catch (err) { j = null; }
              }
              return { ok: r.ok, status: r.status, j: j };
            });
          }).then(function (res) {
            /* The server redirected: it holds the truth (the real queue
               position, or a validation error) in the session flash and we
               cannot see it. Reload so the page renders the server's own
               answer — the blade re-opens the sheet from
               session('waitlist_joined') / session('reservation_success').
               Never fabricate a position or a confirmation here. */
            if (res.redirected) { window.location.reload(); return; }
            if (res.j === null) {
              toast(t('genericError', 'Something went wrong — try again'));
              return;
            }
            if (!res.ok) {
              var err = res.j.message
                || (res.j.errors && Object.keys(res.j.errors).length
                      ? [].concat(res.j.errors[Object.keys(res.j.errors)[0]])[0]
                      : null)
                || t('genericError', 'Something went wrong — try again');
              toast(err);
              return;
            }
            /* Some endpoints answer 200 with { success:false } — e.g.
               submitMenuReview's "You have already reviewed this item."
               That is a REFUSAL; surface the server's own wording and leave
               the form as the shopper filled it. */
            if (res.j.success === false) {
              if (result) { result.hidden = true; }
              toast(res.j.message || t('genericError', 'Something went wrong — try again'));
              return;
            }
            /* Waitlist answers with its real queue position. */
            if (result && res.j.position != null) {
              result.hidden = false;
              result.innerHTML = '';
              var big = document.createElement('span');
              big.className = 'big';
              big.textContent = '#' + res.j.position;
              result.appendChild(big);
              var line = document.createElement('span');
              line.textContent = res.j.wait_minutes != null
                ? t('waitApprox', 'About %d min wait').replace('%d', res.j.wait_minutes)
                : '';
              result.appendChild(line);
              form.reset();
              store.starsSync(form);
              return;
            }
            if (result) {
              result.hidden = false;
              result.textContent = res.j.message || t('sheetDone', 'Thank you — we have your request.');
            } else {
              toast(res.j.message || t('sheetDone', 'Thank you — we have your request.'));
            }
            form.reset();
            store.starsSync(form);
          }).catch(function () {
            toast(t('genericError', 'Something went wrong — try again'));
          }).then(function () {
            if (btn) { btn.disabled = false; }
          });
        });
      });
    }
  };

  /* ---------- module ---------- */
  var mod = {
    init: function () {
      favInit();
      product.init();
      store.init();
    }
  };

  if (window.Mk && window.Mk.register) {
    window.Mk.register('store', mod);
  } else {
    document.addEventListener('DOMContentLoaded', function () {
      if (window.Mk && window.Mk.register) { window.Mk.register('store', mod); }
    });
  }
})();
