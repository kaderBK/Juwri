// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* ============================================================
   Fusion marketplace — mk-checkout.js
   Two modules, registered through window.Mk.register (this file
   NEVER edits mk-core.js):

     "bag"      → /fusion/bag  (bag.blade.php)
                  quote fetch + patch, qty steppers, remove +
                  swipe-to-remove, tip / cutlery / donation,
                  per-store coupon, clear bag, fulfillment sheet.
     "checkout" → /fusion/checkout (checkout.blade.php)
                  contact + inline OTP, address book + geolocation +
                  zone resolve, per-store fulfillment + slot sheet,
                  per-store payment method, place order → settle list.

   LINE IDENTITY (P3.8, critical): the same menu item can sit in the
   bag twice with different configurations, so every write targets
   `line_id`, not `menu_id`. /order-hub/update and /order-hub/remove
   both take an OPTIONAL line_id alongside the required item_id;
   without it they hit the FIRST line of that menu_id and the shopper
   watches the wrong row change. The quote payload carries
   items[].line_id, so this module builds a line_id → {store_id,
   item_id, quantity} index from every quote it receives and resolves
   writes through it. A row that only knows its line_id is therefore
   still fully addressable.

   DOM HOOKS — everything is feature-detected. The bag blade is
   authored in parallel, so both the legacy [data-bag-*] hooks and the
   crave [data-mk-*] hooks are supported; a missing hook is a no-op,
   never a crash.

     bag root  #mk-bag[data-quote-url|data-update-url|data-remove-url|
                       data-slots-url|data-fulfillment-url|
                       data-extras-url|data-coupon-url|data-symbol]
     lines     [data-mk-line="<line_id>"]  (+ optional data-mk-item)
               [data-mk-qty="±1"] stepper · [data-mk-qtyval] display
               [data-mk-remove] · swipe-to-remove on the row
     extras    [data-mk-tip="12%"|"5"] · [data-mk-cutlery] ·
               [data-mk-donation]
     coupon    [data-mk-coupon] (input or wrapper) ·
               [data-mk-coupon-apply] · [data-mk-coupon-remove] ·
               [data-mk-coupon-msg]
     bag-wide  [data-mk-clearbag]
     legacy    [data-bag-store|line|qty|step|eta|fee|subtotal|…]

   ADDRESS EVENT CONTRACT (T1-3 seam): the address sheet may dispatch
     document.dispatchEvent(new CustomEvent('mk:address-changed', {
       detail: { lat, lng, zone_id?, label? } }))
   Both modules listen. Because the shipped sheet does not emit it yet,
   the checkout module ALSO re-reads mk.addresses.index when the sheet
   closes, so "add an address" lands on this page without touching
   mk-account.js.
   ============================================================ */
(function () {
  'use strict';

  /* ---------- helpers ---------- */
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return [].slice.call((root || document).querySelectorAll(sel)); }
  function fmt(n) { return (Math.round((Number(n) || 0) * 100) / 100).toFixed(2); }
  function t(key, fallback) { return (window.MkI18n && window.MkI18n[key]) || fallback; }
  function toast(msg) { if (window.Mk && window.Mk.toast) { window.Mk.toast(msg); } }
  function num(el) { return el ? (parseFloat(String(el.textContent).replace(/[^\d.\-]/g, '')) || 0) : 0; }

  /* Tabbar bag badge — the quote's item_count is the authority. */
  function bagBadge(count) {
    var n = parseInt(count, 10);
    if (isNaN(n)) { return; }
    /* Tabbar copy AND ≥900px topbar copy — see mk-store.js bagBadge(). */
    Array.prototype.forEach.call(document.querySelectorAll('[data-mk-bagcount]'), function (el) {
      el.textContent = n > 99 ? '99+' : String(n);
      el.hidden = n <= 0;
      el.classList.remove('mk-bump');
      void el.offsetWidth;
      el.classList.add('mk-bump');
    });
  }

  /* ============================================================
     module "bag"
     ============================================================ */
  function boot(Mk) {
    var root = document.getElementById('mk-bag');
    if (!root) { return null; }

    var quoteUrl = root.getAttribute('data-quote-url');
    var updateUrl = root.getAttribute('data-update-url');
    /* The bag blade may not expose a remove url; /order-hub/remove is the
       sibling of /order-hub/update, so derive it rather than guessing a path. */
    var removeUrl = root.getAttribute('data-remove-url')
      || (updateUrl ? updateUrl.replace(/\/update(\?|$)/, '/remove$1') : null);
    var couponUrl = root.getAttribute('data-coupon-url'); // worker E; may be absent
    var symbol = root.getAttribute('data-symbol') || '';
    var addr = null;       // {lat,lng} | null
    var busy = false;
    var lines = {};        // line_id → {store_id, item_id, quantity}

    function skeleton(on) { root.classList.toggle('is-quoting', !!on); }

    /* ---- line index (see LINE IDENTITY in the header) ---- */
    function indexLines(data) {
      lines = {};
      (data && data.stores || []).forEach(function (store) {
        (store.items || []).forEach(function (item) {
          if (item.line_id) {
            lines[String(item.line_id)] = {
              store_id: store.store_id,
              item_id: item.item_id,
              quantity: item.quantity
            };
          }
        });
      });
    }

    /* Resolve a DOM row to a writable target. Prefers line_id (exact
       configuration); falls back to the legacy menu_id-first-line row. */
    function target(row) {
      if (!row) { return null; }
      var lineId = parseInt(row.getAttribute('data-mk-line'), 10);
      if (lineId) {
        var known = lines[String(lineId)];
        var itemAttr = parseInt(row.getAttribute('data-mk-item'), 10);
        if (known) {
          return {
            line_id: lineId,
            item_id: itemAttr || known.item_id,
            store_id: known.store_id,
            quantity: known.quantity,
            row: row
          };
        }
        if (itemAttr) {
          var sec = row.closest('[data-mk-store],[data-bag-store]');
          var rid = sec && parseInt(sec.getAttribute('data-mk-store') || sec.getAttribute('data-bag-store'), 10);
          if (rid) {
            return { line_id: lineId, item_id: itemAttr, store_id: rid, quantity: qtyOf(row), row: row };
          }
        }
        return null; // not addressable — never guess and edit the wrong line
      }
      var legacyItem = parseInt(row.getAttribute('data-bag-line'), 10);
      var legacySec = row.closest('[data-bag-store]');
      if (!legacyItem || !legacySec) { return null; }

      return {
        line_id: null,
        item_id: legacyItem,
        store_id: parseInt(legacySec.getAttribute('data-bag-store'), 10),
        quantity: qtyOf(row),
        row: row
      };
    }

    function qtyOf(row) {
      var el = $('[data-mk-qtyval]', row) || $('[data-bag-qty]', row);
      if (!el) {
        /* A [data-mk-qty] node whose value is not a stepper delta is the
           display node (worker F may author it either way). */
        el = $$('[data-mk-qty]', row).filter(function (n) {
          return !parseInt(n.getAttribute('data-mk-qty'), 10);
        })[0];
      }
      return el ? (parseInt(el.textContent, 10) || 0) : 0;
    }

    /* ---- render ---- */
    function patchStore(section, store) {
      var q = function (sel) { return section.querySelector(sel); };

      var eta = q('[data-bag-eta]');
      if (eta) {
        if (store.eta && store.eta.text) {
          eta.hidden = false;
          var tx = q('[data-bag-eta-text]');
          /* The unit is LOCALIZED — hardcoding ' min' rendered "20–30 min" on an
             Arabic page whose blade had correctly written "دقيقة". Reuse the
             template the blade emits. */
          if (tx) {
            var etaTpl = tx.getAttribute('data-eta-tpl') || ':range min';
            tx.textContent = etaTpl.replace(':range', store.eta.text);
          }
        } else {
          eta.hidden = true;
        }
      }

      (store.items || []).forEach(function (item) {
        var row = (item.line_id && section.querySelector('[data-mk-line="' + item.line_id + '"]'))
          || section.querySelector('[data-bag-line="' + item.item_id + '"]');
        if (!row) { return; }
        var qty = $('[data-mk-qtyval]', row) || $('[data-bag-qty]', row);
        if (qty) { qty.textContent = item.quantity; }
        var lt = $('[data-mk-line-total]', row) || $('[data-bag-line-total]', row);
        if (lt) { lt.textContent = fmt(item.line_total); }
      });

      var meter = q('[data-bag-min]');
      if (meter) {
        var mo = store.min_order || { min: 0, met: true, progress: 1, remaining: 0 };
        /* P4.1: this was a progress BAR (always shown, filling up); it is now a
           WARNING, so it must disappear once the minimum is met — otherwise the
           store card carries a bare warning triangle with no text. */
        meter.hidden = !(mo.min > 0) || !!mo.met;
        var fill = q('[data-bag-min-fill]');
        if (fill) {
          fill.style.width = Math.round((mo.progress || 0) * 100) + '%';
          fill.classList.toggle('is-met', !!mo.met);
        }
        var hint = q('[data-bag-min-hint]');
        if (hint) {
          if (mo.met) {
            hint.textContent = hint.getAttribute('data-met-text') || '';
          } else {
            /* The template lives on the warning element, not the hint span. */
            var tpl = hint.getAttribute('data-more-text') || meter.getAttribute('data-more-text') || ':amount / :min';
            hint.textContent = tpl
              .replace(':amount', fmt(mo.remaining) + ' ' + symbol)
              .replace(':min', fmt(mo.min) + ' ' + symbol);
          }
        }
      }

      var fulfil = q('[data-bag-fulfil]');
      if (fulfil) {
        var mode = store.mode || 'delivery';
        fulfil.setAttribute('data-mode', mode);
        $$('[data-bag-mode]', fulfil).forEach(function (b) {
          b.classList.toggle('is-on', b.getAttribute('data-bag-mode') === mode);
        });
        var timeBtn = q('[data-bag-time]');
        var timeText = q('[data-bag-time-text]');
        if (timeText && timeBtn) {
          timeText.textContent = store.scheduled_at || timeBtn.getAttribute('data-asap-text') || 'ASAP';
        }
        /* Dine-in: the table field only exists for that mode; never clobber a
           value the shopper is mid-typing. */
        var tableBox = q('[data-bag-table-box]');
        if (tableBox) {
          tableBox.hidden = mode !== 'dinein';
          var tableInput = q('[data-bag-table]');
          if (tableInput && document.activeElement !== tableInput) {
            tableInput.value = store.table_number || '';
          }
        }
      }

      var fee = q('[data-bag-fee]');
      var feeLabel = q('[data-bag-fee-label]');
      var isPickup = !!(store.fee && store.fee.pickup);
      if (feeLabel) {
        var feeKey = store.mode === 'dinein' ? 'data-dinein-text' : (isPickup ? 'data-pickup-text' : 'data-delivery-text');
        feeLabel.textContent = feeLabel.getAttribute(feeKey) || feeLabel.textContent;
      }
      if (fee) {
        if (isPickup) {
          fee.textContent = fee.getAttribute('data-free-text') || 'Free';
        } else if (store.fee && store.fee.amount !== null && store.fee.amount !== undefined) {
          fee.textContent = (store.fee.is_estimate ? '~ ' : '') + fmt(store.fee.amount) + ' ' + symbol;
        } else {
          fee.textContent = fee.getAttribute('data-address-text') || fee.textContent;
        }
      }
      var sub = q('[data-bag-subtotal]');
      if (sub) { sub.textContent = fmt(store.subtotal); }
      var tot = q('[data-bag-store-total]');
      if (tot) { tot.textContent = fmt(store.total); }

      var tipBox = q('[data-bag-tip]');
      if (tipBox) {
        /* Courier tips are delivery-only — pickup AND dine-in hide the card. */
        tipBox.hidden = (store.mode !== 'delivery');
        var tip = Number(store.tip) || 0;
        var matched = false;
        $$('[data-tip-pct]', tipBox).forEach(function (chip) {
          var pct = parseInt(chip.getAttribute('data-tip-pct'), 10) || 0;
          var amt = Math.round(store.subtotal * pct) / 100;
          var on = pct === 0 ? tip === 0 : Math.abs(tip - amt) < 0.005;
          if (on) { matched = true; }
          chip.classList.toggle('is-on', on);
        });
        var customChip = $('[data-tip-custom]', tipBox);
        if (customChip) { customChip.classList.toggle('is-on', !matched && tip > 0); }
        /* P4.5: keep the tax row in sync — it is part of what the order charges. */
        var taxRow = q('[data-bag-tax-row]');
        var taxVal = Number(store.tax) || 0;
        if (taxRow) { taxRow.hidden = !(taxVal > 0); }
        var taxAmt = q('[data-bag-tax]');
        if (taxAmt) { taxAmt.textContent = fmt(taxVal); }
        var tipRow = q('[data-bag-tip-row]');
        if (tipRow) { tipRow.hidden = !(tip > 0); }
        var tipAmt = q('[data-bag-tip-amount]');
        if (tipAmt) { tipAmt.textContent = fmt(tip); }
      }

      /* Crave tip chips: [data-mk-tip="15%"] / [data-mk-tip="5"]. */
      $$('[data-mk-tip]', section).forEach(function (chip) {
        chip.classList.toggle('is-on', Math.abs(tipValue(chip, store.subtotal) - (Number(store.tip) || 0)) < 0.005);
      });
    }

    function render(data) {
      if (!data || !data.ok) { return; }
      indexLines(data);

      var sections = $$('[data-mk-store],[data-bag-store]', root);
      var byId = {};
      (data.stores || []).forEach(function (s) { byId[s.store_id] = s; });
      var mismatch = data.empty && sections.length > 0;
      sections.forEach(function (section) {
        var store = byId[section.getAttribute('data-mk-store') || section.getAttribute('data-bag-store')];
        if (!store) { mismatch = true; return; }
        var rows = $$('[data-mk-line],[data-bag-line]', section).length;
        if (rows && store.items.length !== rows) { mismatch = true; }
        patchStore(section, store);
      });
      if (mismatch) { window.location.reload(); return; }

      /* data-bag-cta-total included: the sticky Checkout bar was the ONE money
         figure render() never patched, so a mode flip (e.g. delivery → dine-in
         waiving the fee) left a stale total on the primary CTA. */
      $$('[data-bag-grand],[data-mk-grand],[data-bag-cta-total]', root).forEach(function (el) {
        if (data.summary && data.summary.grand_total !== null) { el.textContent = fmt(data.summary.grand_total); }
      });

      var summary = data.summary || {};
      $$('[data-bag-cutlery],[data-mk-cutlery]', root).forEach(function (el) {
        if (el.type === 'checkbox') {
          el.checked = !!summary.cutlery;
        } else {
          el.classList.toggle('is-on', !!summary.cutlery);
          el.setAttribute('aria-pressed', summary.cutlery ? 'true' : 'false');
        }
      });
      $$('[data-bag-donation],[data-mk-donation]', root).forEach(function (btn) {
        if (!summary.donation) { return; }
        btn.classList.toggle('is-on', !!summary.donation.on);
        btn.setAttribute('aria-pressed', summary.donation.on ? 'true' : 'false');
        var amtWrap = $('[data-bag-donation-amount],[data-mk-donation-amount]', btn);
        if (amtWrap) { amtWrap.hidden = !summary.donation.on; }
        var delta = $('[data-bag-donation-delta],[data-mk-donation-delta]', btn);
        if (delta) { delta.textContent = fmt(summary.donation.amount); }
      });

      bagBadge(summary.item_count);
    }

    function quote() {
      if (!quoteUrl) { return Promise.resolve(null); }
      var url = quoteUrl;
      if (addr && typeof addr.lat === 'number' && typeof addr.lng === 'number') {
        url += (url.indexOf('?') === -1 ? '?' : '&') + 'lat=' + encodeURIComponent(addr.lat) + '&lng=' + encodeURIComponent(addr.lng);
      }
      skeleton(true);
      return Mk.get(url)
        .then(function (data) { render(data); return data; })
        .catch(function () { return null; })
        .then(function (d) { skeleton(false); return d; });
    }

    /* ---- writes ---- */
    function setQuantity(tg, next) {
      if (!tg || !updateUrl || busy) { return Promise.resolve(null); }
      busy = true;
      skeleton(true);
      var payload = {
        store_id: tg.store_id,
        item_id: tg.item_id,
        quantity: Math.max(0, next)
      };
      if (tg.line_id) { payload.line_id = tg.line_id; }

      return Mk.post(updateUrl, payload).then(function (res) {
        if (res && res.ok === false && res.error) { toast(res.error); }
        return quote();
      }).catch(function () {
        skeleton(false);
        return null;
      }).then(function (r) { busy = false; return r; });
    }

    function removeLine(tg) {
      if (!tg || busy) { return Promise.resolve(null); }
      /* No remove endpoint exposed → quantity 0 is the same operation. */
      if (!removeUrl) { return setQuantity(tg, 0); }
      busy = true;
      skeleton(true);
      var payload = { store_id: tg.store_id, item_id: tg.item_id };
      if (tg.line_id) { payload.line_id = tg.line_id; }

      return Mk.post(removeUrl, payload).then(function (res) {
        if (res && res.ok === false && res.error) { toast(res.error); }
        return quote();
      }).catch(function () {
        skeleton(false);
        return null;
      }).then(function (r) { busy = false; return r; });
    }

    /* Steppers — legacy [data-bag-step] and crave [data-mk-qty="±1"]. */
    root.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-bag-step],[data-mk-qty]');
      if (!btn || busy) { return; }
      var delta = parseInt(btn.getAttribute('data-bag-step') || btn.getAttribute('data-mk-qty'), 10);
      if (!delta) { return; }              // a display node, not a stepper
      var row = btn.closest('[data-mk-line],[data-bag-line]');
      var tg = target(row);
      if (!tg) { return; }
      setQuantity(tg, tg.quantity + delta);
    });

    /* Explicit remove. */
    root.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-mk-remove]');
      if (!btn || busy) { return; }
      var tg = target(btn.closest('[data-mk-line],[data-bag-line]'));
      if (tg) { removeLine(tg); }
    });

    /* Swipe-to-remove: horizontal drag past 40% of the row (direction-aware
       so RTL swipes the other way), pointer events only. */
    (function swipe() {
      if (!window.PointerEvent) { return; }
      var rtl = document.documentElement.getAttribute('dir') === 'rtl';
      var active = null;

      root.addEventListener('pointerdown', function (e) {
        if (e.pointerType === 'mouse' || busy) { return; }
        var row = e.target.closest && e.target.closest('[data-mk-line]');
        if (!row || e.target.closest('button,a,input,select,textarea')) { return; }
        active = { row: row, x: e.clientX, y: e.clientY, moved: false };
      }, { passive: true });

      root.addEventListener('pointermove', function (e) {
        if (!active) { return; }
        var dx = e.clientX - active.x;
        var dy = e.clientY - active.y;
        if (!active.moved && Math.abs(dy) > Math.abs(dx)) { active = null; return; } // vertical scroll wins
        if (Math.abs(dx) < 6) { return; }
        active.moved = true;
        var travel = rtl ? Math.max(0, dx) : Math.min(0, dx);
        active.row.style.transform = 'translateX(' + travel + 'px)';
        active.row.classList.add('is-swiping');
      }, { passive: true });

      function end(e) {
        if (!active) { return; }
        var row = active.row;
        var dx = e.clientX - active.x;
        var past = Math.abs(dx) > Math.max(72, row.offsetWidth * 0.4)
          && (rtl ? dx > 0 : dx < 0);
        row.style.transform = '';
        row.classList.remove('is-swiping');
        var tg = past ? target(row) : null;
        active = null;
        if (tg) { removeLine(tg); }
      }
      root.addEventListener('pointerup', end, { passive: true });
      root.addEventListener('pointercancel', function () {
        if (active) { active.row.style.transform = ''; active.row.classList.remove('is-swiping'); active = null; }
      }, { passive: true });
    })();

    /* ---- fulfillment (mode pills + slot sheet) ---- */
    var slotsUrl = root.getAttribute('data-slots-url');
    var fulfillmentUrl = root.getAttribute('data-fulfillment-url');
    var sheet = $('[data-slot-sheet]');
    var sheetRid = null;
    var sheetDays = [];

    function currentChoice(section) {
      var fulfil = $('[data-bag-fulfil]', section);
      var timeText = $('[data-bag-time-text]', section);
      var timeBtn = $('[data-bag-time]', section);
      var tableInput = $('[data-bag-table]', section);
      var asap = timeBtn ? (timeBtn.getAttribute('data-asap-text') || 'ASAP') : 'ASAP';
      var label = timeText ? timeText.textContent.trim() : '';
      return {
        mode: (fulfil && fulfil.getAttribute('data-mode')) || 'delivery',
        scheduled_at: (label && label !== asap) ? label : null,
        table_number: tableInput ? tableInput.value.trim() : ''
      };
    }

    function setFulfillment(rid, mode, scheduledAt, tableNumber) {
      if (!fulfillmentUrl || busy) { return Promise.resolve(null); }
      busy = true;
      skeleton(true);
      var payload = { store_id: rid, mode: mode };
      if (scheduledAt) { payload.scheduled_at = scheduledAt; }
      if (mode === 'dinein' && tableNumber) { payload.table_number = tableNumber; }
      if (addr && typeof addr.lat === 'number') { payload.lat = addr.lat; payload.lng = addr.lng; }

      return Mk.post(fulfillmentUrl, payload).then(function (res) {
        if (res && res.ok === false) {
          if (res.error) { toast(res.error); }
          if (res.closed) { openSheet(rid); }
          skeleton(false);
          return null;
        }
        render(res);
        return res;
      }).catch(function () {
        skeleton(false);
        return null;
      }).then(function (r) { busy = false; skeleton(false); return r; });
    }

    function renderSheet(data) {
      if (!sheet) { return; }
      var asapBtn = $('[data-slot-asap]', sheet);
      if (asapBtn) {
        var open = !!(data.asap && data.asap.available);
        asapBtn.disabled = !open;
        if (open && data.asap.eta) {
          asapBtn.textContent = (asapBtn.getAttribute('data-eta-tpl') || 'ASAP · :range min').replace(':range', data.asap.eta);
        } else if (open) {
          asapBtn.textContent = 'ASAP';
        } else {
          asapBtn.textContent = asapBtn.getAttribute('data-closed-text') || 'ASAP';
        }
      }
      sheetDays = data.days || [];
      var tabs = $('[data-slot-days]', sheet);
      var grid = $('[data-slot-grid]', sheet);
      var none = $('[data-slot-none]', sheet);
      if (!tabs || !grid) { return; }
      tabs.innerHTML = '';
      grid.innerHTML = '';
      if (none) { none.hidden = sheetDays.length > 0; }
      sheetDays.forEach(function (day, i) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'mk-slot-day' + (i === 0 ? ' is-on' : '');
        b.textContent = day.label;
        b.setAttribute('data-slot-day', String(i));
        tabs.appendChild(b);
      });
      renderSlots(0);
    }

    function renderSlots(dayIdx) {
      var grid = sheet && $('[data-slot-grid]', sheet);
      if (!grid) { return; }
      grid.innerHTML = '';
      var day = sheetDays[dayIdx];
      if (!day) { return; }
      (day.slots || []).forEach(function (slot) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'mk-slot';
        b.textContent = slot.label;
        b.setAttribute('data-slot-at', slot.at);
        grid.appendChild(b);
      });
    }

    function openSheet(rid) {
      if (!sheet || !slotsUrl) { return; }
      sheetRid = rid;
      sheet.hidden = false;
      Mk.get(slotsUrl + (slotsUrl.indexOf('?') === -1 ? '?' : '&') + 'store_id=' + rid)
        .then(function (data) { if (data && data.ok) { renderSheet(data); } })
        .catch(function () { toast(t('coSlotsFail', 'Could not load times')); });
    }

    function closeSheet() {
      if (sheet) { sheet.hidden = true; }
      sheetRid = null;
    }

    /* Dine-in table number: persist on commit (change), not per keystroke. */
    root.addEventListener('change', function (e) {
      var input = e.target.closest('[data-bag-table]');
      if (!input) { return; }
      var section = input.closest('[data-bag-store]');
      if (!section) { return; }
      var choice = currentChoice(section);
      if (choice.mode !== 'dinein') { return; }
      setFulfillment(parseInt(section.getAttribute('data-bag-store'), 10), 'dinein', choice.scheduled_at, choice.table_number);
    });

    root.addEventListener('click', function (e) {
      var section = e.target.closest('[data-bag-store]');
      var modeBtn = e.target.closest('[data-bag-mode]');
      if (modeBtn && section) {
        var choice = currentChoice(section);
        var mode = modeBtn.getAttribute('data-bag-mode');
        if (mode !== choice.mode) {
          setFulfillment(parseInt(section.getAttribute('data-bag-store'), 10), mode, choice.scheduled_at, choice.table_number);
        }
        return;
      }
      var timeBtn = e.target.closest('[data-bag-time]');
      if (timeBtn && section) {
        openSheet(parseInt(section.getAttribute('data-bag-store'), 10));
      }
    });

    if (sheet) {
      sheet.addEventListener('click', function (e) {
        if (e.target.closest('[data-slot-close]')) { closeSheet(); return; }
        var dayBtn = e.target.closest('[data-slot-day]');
        if (dayBtn) {
          $$('[data-slot-day]', sheet).forEach(function (b) { b.classList.remove('is-on'); });
          dayBtn.classList.add('is-on');
          renderSlots(parseInt(dayBtn.getAttribute('data-slot-day'), 10) || 0);
          return;
        }
        var rid = sheetRid;
        if (!rid) { return; }
        var section = $('[data-bag-store="' + rid + '"]', root);
        var mode = section ? currentChoice(section).mode : 'delivery';
        if (e.target.closest('[data-slot-asap]')) {
          closeSheet();
          setFulfillment(rid, mode, null);
          return;
        }
        var slotBtn = e.target.closest('[data-slot-at]');
        if (slotBtn) {
          closeSheet();
          setFulfillment(rid, mode, slotBtn.getAttribute('data-slot-at'));
        }
      });
    }

    /* ---- extras (tips / cutlery / donation) ---- */
    var extrasUrl = root.getAttribute('data-extras-url');

    function postExtras(payload) {
      if (!extrasUrl || busy) { return Promise.resolve(null); }
      busy = true;
      skeleton(true);
      if (addr && typeof addr.lat === 'number') { payload.lat = addr.lat; payload.lng = addr.lng; }

      return Mk.post(extrasUrl, payload).then(function (res) {
        if (res && res.ok === false) {
          if (res.error) { toast(res.error); }
          return null;
        }
        render(res);
        return res;
      }).catch(function () {
        return null;
      }).then(function (r) { busy = false; skeleton(false); return r; });
    }

    function storeIdOf(el) {
      var sec = el.closest('[data-mk-store],[data-bag-store]');
      return sec ? parseInt(sec.getAttribute('data-mk-store') || sec.getAttribute('data-bag-store'), 10) : 0;
    }
    function subtotalOf(el) {
      var sec = el.closest('[data-mk-store],[data-bag-store]');
      return sec ? num($('[data-mk-subtotal]', sec) || $('[data-bag-subtotal]', sec)) : 0;
    }
    /* "15%" → percent of the store subtotal; "5" → an absolute amount. */
    function tipValue(chip, subtotal) {
      var raw = String(chip.getAttribute('data-mk-tip') || '').trim();
      if (raw.slice(-1) === '%') {
        var pct = parseFloat(raw) || 0;
        return Math.round((subtotal || subtotalOf(chip)) * pct) / 100;
      }
      return Math.round((parseFloat(raw) || 0) * 100) / 100;
    }

    root.addEventListener('click', function (e) {
      var section = e.target.closest('[data-bag-store]');

      var pctChip = e.target.closest('[data-tip-pct]');
      if (pctChip && section) {
        var subtotal = num($('[data-bag-subtotal]', section));
        var pct = parseInt(pctChip.getAttribute('data-tip-pct'), 10) || 0;
        var box = $('[data-tip-custom-box]', section);
        if (box) { box.hidden = true; }
        postExtras({
          store_id: parseInt(section.getAttribute('data-bag-store'), 10),
          tip_amount: Math.round(subtotal * pct) / 100
        });
        return;
      }
      var customChip = e.target.closest('[data-tip-custom]');
      if (customChip && section) {
        var cbox = $('[data-tip-custom-box]', section);
        if (cbox) {
          cbox.hidden = false;
          var input = $('[data-tip-input]', cbox);
          if (input) { input.focus(); }
        }
        return;
      }

      /* Crave tip chip. */
      var mkTip = e.target.closest('[data-mk-tip]');
      if (mkTip) {
        var rid = storeIdOf(mkTip);
        if (rid) { postExtras({ store_id: rid, tip_amount: tipValue(mkTip) }); }
      }
    });

    root.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' || !e.target.closest('[data-tip-input]')) { return; }
      e.preventDefault();
      e.target.blur(); // the blur handler posts
    }, true);

    root.addEventListener('blur', function (e) {
      var input = e.target.closest && e.target.closest('[data-tip-input]');
      if (!input) { return; }
      var section = input.closest('[data-bag-store]');
      if (!section) { return; }
      postExtras({
        store_id: parseInt(section.getAttribute('data-bag-store'), 10),
        tip_amount: Math.max(0, Math.round((parseFloat(input.value) || 0) * 100) / 100)
      });
    }, true);

    root.addEventListener('change', function (e) {
      var cut = e.target.closest && e.target.closest('[data-bag-cutlery],[data-mk-cutlery]');
      if (cut && cut.type === 'checkbox') { postExtras({ cutlery: cut.checked ? 1 : 0 }); }
    });

    root.addEventListener('click', function (e) {
      var cut = e.target.closest('[data-mk-cutlery]');
      if (cut && cut.type !== 'checkbox') {
        postExtras({ cutlery: cut.classList.contains('is-on') ? 0 : 1 });
        return;
      }
      var donate = e.target.closest('[data-bag-donation],[data-mk-donation]');
      if (donate) { postExtras({ donation: donate.classList.contains('is-on') ? 0 : 1 }); }
    });

    /* ---- per-store coupon (worker E: POST/DELETE /fusion/bag/coupon) ---- */
    /* Rendered only when the bag exposes data-coupon-url — never a dead field. */
    function couponMsg(scope, text, bad) {
      var el = $('[data-mk-coupon-msg]', scope) || $('[data-mk-coupon-msg]', root);
      if (!el) { if (text) { toast(text); } return; }
      el.textContent = text || '';
      el.hidden = !text;
      el.classList.toggle('is-bad', !!bad);
      el.classList.toggle('is-ok', !!text && !bad);
    }

    function couponInput(el) {
      var host = el.closest('[data-mk-coupon]') || $('[data-mk-coupon]', el.closest('[data-mk-store],[data-bag-store]') || root);
      if (!host) { return null; }
      return host.tagName === 'INPUT' ? host : $('input', host);
    }

    function sendCoupon(method, el, code) {
      if (!couponUrl || busy) { return; }
      var rid = storeIdOf(el);
      if (!rid) { return; }
      var scope = el.closest('[data-mk-store],[data-bag-store]') || root;
      busy = true;
      skeleton(true);

      fetch(couponUrl, {
        method: method,
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': Mk.csrf
        },
        body: JSON.stringify({ store_id: rid, code: code || '' })
      }).then(function (r) {
        return r.text().then(function (txt) {
          var j = {};
          try { j = txt ? JSON.parse(txt) : {}; } catch (err) { j = {}; }
          return { ok: r.ok, j: j };
        });
      }).then(function (res) {
        /* 422 carries a human `error` string — surface it VERBATIM. */
        if (!res.ok || res.j.ok === false) {
          couponMsg(scope, res.j.error || res.j.message || t('genericError', 'Something went wrong — try again'), true);
          return;
        }
        couponMsg(scope, res.j.message || '', false);
        render(res.j);
      }).catch(function () {
        couponMsg(scope, t('genericError', 'Something went wrong — try again'), true);
      }).then(function () { busy = false; skeleton(false); });
    }

    if (couponUrl) {
      root.addEventListener('click', function (e) {
        var apply = e.target.closest('[data-mk-coupon-apply]');
        if (apply) {
          var input = couponInput(apply);
          var code = input ? String(input.value || '').trim() : '';
          if (code) { sendCoupon('POST', apply, code); }
          return;
        }
        var drop = e.target.closest('[data-mk-coupon-remove]');
        if (drop) {
          var inp = couponInput(drop);
          if (inp) { inp.value = ''; }
          sendCoupon('DELETE', drop, '');
        }
      });
      root.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') { return; }
        var host = e.target.closest && e.target.closest('[data-mk-coupon]');
        if (!host) { return; }
        e.preventDefault();
        var code = String(e.target.value || '').trim();
        if (code) { sendCoupon('POST', e.target, code); }
      });
    }

    /* ---- clear bag ---- */
    /* There is no bulk-clear endpoint: this removes every indexed line through
       the same /order-hub/remove path, then reloads. */
    root.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-mk-clearbag]');
      if (!btn || busy) { return; }
      var ask = btn.getAttribute('data-confirm-text');
      if (ask && !window.confirm(ask)) { return; }

      var rows = $$('[data-mk-line],[data-bag-line]', root).map(target).filter(Boolean);
      if (!rows.length) { return; }
      busy = true;
      skeleton(true);
      var url = removeUrl || updateUrl;
      var chain = Promise.resolve();
      rows.forEach(function (tg) {
        chain = chain.then(function () {
          var payload = { store_id: tg.store_id, item_id: tg.item_id };
          if (tg.line_id) { payload.line_id = tg.line_id; }
          if (!removeUrl) { payload.quantity = 0; }
          return Mk.post(url, payload).catch(function () {});
        });
      });
      chain.then(function () { window.location.reload(); });
    });

    /* ---- address seam ---- */
    document.addEventListener('mk:address-changed', function (e) {
      var d = (e && e.detail) || {};
      addr = (typeof d.lat === 'number' && typeof d.lng === 'number') ? { lat: d.lat, lng: d.lng } : null;
      quote();
    });

    try {
      var def = Mk.modules.addresses && typeof Mk.modules.addresses.getDefault === 'function'
        ? Mk.modules.addresses.getDefault()
        : null;
      if (def && typeof def.lat === 'number' && typeof def.lng === 'number') {
        addr = { lat: def.lat, lng: def.lng };
      }
    } catch (err) { /* graceful without the addresses module */ }
    quote();

    return { refresh: quote, setAddress: function (lat, lng) { addr = { lat: lat, lng: lng }; return quote(); } };
  }

  /* ============================================================
     module "checkout" — /fusion/checkout
     ============================================================ */
  function bootCheckout(Mk) {
    var root = document.getElementById('mk-checkout');
    if (!root) { return null; }

    var d = root.dataset;
    var symbol = d.symbol || '';
    var authed = d.authed === '1';
    var busy = false;

    function el(sel) { return $(sel, root); }
    function val(sel) {
      var e = el(sel);
      return e ? String(e.value || '').trim() : '';
    }
    function setVal(sel, v) {
      var e = el(sel);
      if (e) { e.value = v; }
    }
    function showError(msg) {
      var box = el('[data-co-error]');
      if (box) { box.textContent = msg || ''; box.hidden = !msg; }
      if (msg && !box) { toast(msg); }
    }
    function busyBtn(btn, on) {
      if (!btn) { return; }
      if (on) {
        btn.dataset.coLabel = btn.textContent;
        var b = btn.getAttribute('data-busy-text');
        if (b) { btn.textContent = b; }
        btn.disabled = true;
      } else {
        if (btn.dataset.coLabel) { btn.textContent = btn.dataset.coLabel; }
        btn.disabled = false;
      }
    }
    function pin() {
      var lat = parseFloat(val('[data-co-lat]'));
      var lng = parseFloat(val('[data-co-lng]'));
      return (isFinite(lat) && isFinite(lng)) ? { lat: lat, lng: lng } : null;
    }

    /* ---------- quote patching (4.2 re-prices the whole bag) ---------- */
    function patchQuote(data) {
      if (!data || data.ok === false) { return; }
      if (data.empty) { window.location.reload(); return; }

      var byId = {};
      (data.stores || []).forEach(function (s) { byId[s.store_id] = s; });
      var anyDelivery = false;

      $$('[data-co-store]', root).forEach(function (card) {
        var store = byId[card.getAttribute('data-co-store')];
        if (!store) { window.location.reload(); return; }
        var mode = store.mode || 'delivery';
        /* 'collect' = pickup OR dine-in: no courier, no fee, no address. */
        var collect = mode === 'pickup' || mode === 'dinein';
        var labelKey = mode === 'dinein' ? 'data-dinein-text' : (mode === 'pickup' ? 'data-pickup-text' : 'data-delivery-text');
        if (mode === 'delivery') { anyDelivery = true; }

        var seg = $('[data-co-seg]', card);
        if (seg) {
          seg.setAttribute('data-mode', mode);
          $$('[data-co-mode]', seg).forEach(function (b) {
            var on = b.getAttribute('data-co-mode') === mode;
            b.classList.toggle('is-on', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
          });
        }

        var timeBtn = $('[data-co-time]', card);
        var timeText = $('[data-co-time-text]', card);
        if (timeText) {
          timeText.textContent = store.scheduled_at
            || (timeBtn && timeBtn.getAttribute('data-asap-text')) || 'ASAP';
        }

        /* Dine-in table note (edited in the bag; display-only here). */
        var tableNote = $('[data-co-table]', card);
        if (tableNote) {
          var showTable = mode === 'dinein' && store.table_number;
          tableNote.hidden = !showTable;
        }

        var cod = $('[data-co-codlabel]', card);
        if (cod) {
          cod.textContent = cod.getAttribute(labelKey) || cod.textContent;
        }

        var feeLabel = $('[data-co-feelabel]', card);
        if (feeLabel) {
          feeLabel.textContent = feeLabel.getAttribute(labelKey) || feeLabel.textContent;
        }
        var fee = $('[data-co-fee]', card);
        if (fee) {
          if (collect) {
            fee.textContent = fee.getAttribute('data-free-text') || 'Free';
          } else if (store.fee && store.fee.amount !== null && store.fee.amount !== undefined) {
            fee.textContent = (store.fee.is_estimate ? '~ ' : '') + fmt(store.fee.amount) + ' ' + symbol;
          } else {
            fee.textContent = fee.getAttribute('data-address-text') || '';
          }
        }

        var sub = $('[data-co-subtotal]', card);
        if (sub) { sub.textContent = fmt(store.subtotal); }
        /* P4.5: tax row mirrors Order::computeTax via the quote. */
        var taxRow = $('[data-co-taxrow]', card);
        var taxVal = Number(store.tax) || 0;
        if (taxRow) { taxRow.hidden = !(taxVal > 0); }
        var taxEl = $('[data-co-tax]', card);
        if (taxEl) { taxEl.textContent = fmt(taxVal); }
        var tipRow = $('[data-co-tiprow]', card);
        if (tipRow) { tipRow.hidden = !(Number(store.tip) > 0); }
        var tip = $('[data-co-tip]', card);
        if (tip) { tip.textContent = fmt(store.tip); }
        var total = $('[data-co-total]', card);
        if (total) { total.textContent = fmt(store.total); }
      });

      if (data.summary && data.summary.grand_total !== null && data.summary.grand_total !== undefined) {
        $$('[data-co-grand]', root).forEach(function (g) { g.textContent = fmt(data.summary.grand_total); });
      }
      if (data.summary) { bagBadge(data.summary.item_count); }

      /* An address is only needed while at least one store still delivers. */
      var block = el('[data-co-address-block]');
      if (block) { block.hidden = !anyDelivery; }
    }

    function requote() {
      if (!d.quoteUrl) { return Promise.resolve(null); }
      var url = d.quoteUrl;
      var p = pin();
      if (p) { url += (url.indexOf('?') === -1 ? '?' : '&') + 'lat=' + encodeURIComponent(p.lat) + '&lng=' + encodeURIComponent(p.lng); }

      return Mk.get(url).then(function (data) { patchQuote(data); return data; }).catch(function () { return null; });
    }

    /* ---------- 4.2 fulfillment ---------- */
    var sheet = document.getElementById('mk-co-slots');
    var sheetRid = null;
    var sheetDays = [];

    function modeOf(rid) {
      var card = el('[data-co-store="' + rid + '"]');
      var seg = card && $('[data-co-seg]', card);
      return (seg && seg.getAttribute('data-mode')) || 'delivery';
    }
    function scheduledOf(rid) {
      var card = el('[data-co-store="' + rid + '"]');
      if (!card) { return null; }
      var btn = $('[data-co-time]', card);
      var txt = $('[data-co-time-text]', card);
      var asap = (btn && btn.getAttribute('data-asap-text')) || 'ASAP';
      var label = txt ? txt.textContent.trim() : '';
      return (label && label !== asap) ? label : null;
    }

    function setFulfillment(rid, mode, scheduledAt) {
      if (!d.fulfillmentUrl || busy) { return Promise.resolve(null); }
      busy = true;
      root.classList.add('is-quoting');
      var payload = { store_id: rid, mode: mode };
      if (scheduledAt) { payload.scheduled_at = scheduledAt; }
      var p = pin();
      if (p) { payload.lat = p.lat; payload.lng = p.lng; }

      return Mk.post(d.fulfillmentUrl, payload).then(function (res) {
        if (res && res.ok === false) {
          if (res.error) { toast(res.error); }
          if (res.closed) { openSlots(rid); }
          return null;
        }
        patchQuote(res);
        return res;
      }).catch(function () {
        return null;
      }).then(function (r) { busy = false; root.classList.remove('is-quoting'); return r; });
    }

    function renderSlotDays(data) {
      if (!sheet) { return; }
      var asapBtn = $('[data-coslot-asap]', sheet);
      if (asapBtn) {
        var open = !!(data.asap && data.asap.available);
        asapBtn.disabled = !open;
        if (open && data.asap.eta) {
          asapBtn.textContent = (asapBtn.getAttribute('data-eta-tpl') || 'ASAP · :range min').replace(':range', data.asap.eta);
        } else if (open) {
          asapBtn.textContent = 'ASAP';
        } else {
          asapBtn.textContent = asapBtn.getAttribute('data-closed-text') || 'ASAP';
        }
      }
      sheetDays = data.days || data.dates || [];
      var tabs = $('[data-coslot-days]', sheet);
      var grid = $('[data-coslot-grid]', sheet);
      var none = $('[data-coslot-none]', sheet);
      if (!tabs || !grid) { return; }
      tabs.innerHTML = '';
      grid.innerHTML = '';
      if (none) { none.hidden = sheetDays.length > 0; }
      sheetDays.forEach(function (day, i) {
        /* .mk-seg styles its child buttons directly — no class needed. */
        var b = document.createElement('button');
        b.type = 'button';
        if (i === 0) { b.className = 'is-on'; }
        b.textContent = day.label || day.date;
        b.setAttribute('data-coslot-day', String(i));
        tabs.appendChild(b);
      });
      renderSlotGrid(0);
    }

    function renderSlotGrid(idx) {
      var grid = sheet && $('[data-coslot-grid]', sheet);
      if (!grid) { return; }
      grid.innerHTML = '';
      var day = sheetDays[idx];
      if (!day) { return; }
      (day.slots || []).forEach(function (slot) {
        /* .mk-slotgrid styles its child buttons directly — no class needed. */
        var b = document.createElement('button');
        b.type = 'button';
        b.textContent = slot.label;
        /* SlotService emits `at`; a `value`-shaped payload is accepted too. */
        b.setAttribute('data-coslot-at', slot.at || slot.value);
        if (slot.full) { b.disabled = true; }
        grid.appendChild(b);
      });
    }

    function openSlots(rid) {
      if (!sheet || !d.slotsUrl) { return; }
      sheetRid = rid;
      sheet.hidden = false;
      Mk.get(d.slotsUrl + (d.slotsUrl.indexOf('?') === -1 ? '?' : '&') + 'store_id=' + rid)
        .then(function (data) { if (data && data.ok) { renderSlotDays(data); } })
        .catch(function () { toast(t('coSlotsFail', 'Could not load times')); });
    }
    function closeSlots() {
      if (sheet) { sheet.hidden = true; }
      sheetRid = null;
    }

    root.addEventListener('click', function (e) {
      var card = e.target.closest('[data-co-store]');
      if (!card) { return; }
      var rid = parseInt(card.getAttribute('data-co-store'), 10);

      var modeBtn = e.target.closest('[data-co-mode]');
      if (modeBtn) {
        var mode = modeBtn.getAttribute('data-co-mode');
        if (mode !== modeOf(rid)) { setFulfillment(rid, mode, scheduledOf(rid)); }
        return;
      }
      if (e.target.closest('[data-co-time]')) { openSlots(rid); }
    });

    if (sheet) {
      sheet.addEventListener('click', function (e) {
        if (e.target === sheet || e.target.closest('[data-coslot-close]')) { closeSlots(); return; }
        var dayBtn = e.target.closest('[data-coslot-day]');
        if (dayBtn) {
          $$('[data-coslot-day]', sheet).forEach(function (b) { b.classList.remove('is-on'); });
          dayBtn.classList.add('is-on');
          renderSlotGrid(parseInt(dayBtn.getAttribute('data-coslot-day'), 10) || 0);
          return;
        }
        if (!sheetRid) { return; }
        if (e.target.closest('[data-coslot-asap]')) {
          closeSlots();
          setFulfillment(sheetRid, modeOf(sheetRid), null);
          return;
        }
        var slot = e.target.closest('[data-coslot-at]');
        if (slot) {
          var rid2 = sheetRid;
          closeSlots();
          setFulfillment(rid2, modeOf(rid2), slot.getAttribute('data-coslot-at'));
        }
      });
      sheet.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeSlots(); } });
    }

    /* ---------- 4.6 address book ---------- */
    function zoneMsg(text, bad) {
      var p = el('[data-co-zone-msg]');
      if (!p) { return; }
      p.textContent = text || '';
      p.hidden = !text;
      p.classList.toggle('is-bad', !!bad);
    }

    /* mk.addresses.resolve is auth:shopper — a guest never gets a zone verdict
       (and the blade never renders one for them). */
    function resolveZone() {
      var p = pin();
      if (!d.resolveUrl || !p) { return; }
      var url = d.resolveUrl + (d.resolveUrl.indexOf('?') === -1 ? '?' : '&')
        + 'lat=' + encodeURIComponent(p.lat) + '&lng=' + encodeURIComponent(p.lng);
      Mk.get(url).then(function (res) {
        if (!res || res.ok === false) { zoneMsg(''); return; }
        var box = el('[data-co-zone-msg]');
        if (res.out_of_range) {
          zoneMsg((box && box.getAttribute('data-out-text')) || t('coZoneOut', ''), true);
        } else {
          zoneMsg((box && box.getAttribute('data-in-text')) || '', false);
        }
      }).catch(function () { zoneMsg(''); });
    }

    function selectCard(card) {
      $$('[data-co-addr-card]', root).forEach(function (c) {
        var on = c === card;
        c.classList.toggle('mk-addrcard--on', on);
        c.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      if (!card) { return; }
      setVal('[data-co-address]', card.getAttribute('data-address') || '');
      setVal('[data-co-lat]', card.getAttribute('data-lat') || '');
      setVal('[data-co-lng]', card.getAttribute('data-lng') || '');
      resolveZone();
      requote();  // the fee is zone-based: a new pin re-prices every store
    }

    function renderAddresses(list) {
      var wrap = el('[data-co-addrlist]');
      if (!wrap || !Array.isArray(list)) { return; }
      var current = pin();
      wrap.innerHTML = '';
      list.forEach(function (a) {
        if (a.lat === null || a.lng === null) { return; }
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'mk-addrcard';
        b.setAttribute('data-co-addr-card', '');
        b.setAttribute('data-lat', a.lat);
        b.setAttribute('data-lng', a.lng);
        b.setAttribute('data-address', a.address || '');
        var on = current && Math.abs(current.lat - a.lat) < 1e-6 && Math.abs(current.lng - a.lng) < 1e-6;
        if (on) { b.classList.add('mk-addrcard--on'); }
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
        b.title = a.address || '';

        /* Chip layout mirrors the server-rendered default card: pin + label,
           with the full street line landing in the address input on pick. */
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('class', 'mk-ic');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '1.8');
        svg.setAttribute('aria-hidden', 'true');
        var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', 'M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z');
        svg.appendChild(path);
        b.appendChild(svg);

        var label = document.createElement('span');
        /* out_of_range comes straight from AddressZoneResolver::describe(). */
        label.textContent = (a.label || a.address || '') + (a.out_of_range ? ' ⚠' : '');
        b.appendChild(label);
        wrap.appendChild(b);
      });
      wrap.hidden = !wrap.children.length;
    }

    function loadAddresses() {
      if (!d.addressesUrl) { return Promise.resolve(null); }
      return Mk.get(d.addressesUrl).then(function (res) {
        if (res && res.ok && res.addresses) { renderAddresses(res.addresses); }
        return res;
      }).catch(function () { return null; });
    }

    root.addEventListener('click', function (e) {
      var card = e.target.closest('[data-co-addr-card]');
      if (card) { selectCard(card); }
    });

    /* "Use my location": the browser supplies the pin; mk.geolocate names the
       nearest marketplace city so the shopper can see where they landed. */
    var geoBtn = el('[data-co-geo]');
    if (geoBtn) {
      geoBtn.addEventListener('click', function () {
        if (!navigator.geolocation) {
          toast(geoBtn.getAttribute('data-unsupported-text') || t('coGeoFail', ''));
          return;
        }
        busyBtn(geoBtn, true);
        navigator.geolocation.getCurrentPosition(function (pos) {
          busyBtn(geoBtn, false);
          var lat = pos.coords.latitude;
          var lng = pos.coords.longitude;
          setVal('[data-co-lat]', lat);
          setVal('[data-co-lng]', lng);
          selectCard(null);           // a live pin overrides any saved card
          resolveZone();
          requote();
          if (d.geolocateUrl) {
            Mk.get(d.geolocateUrl + (d.geolocateUrl.indexOf('?') === -1 ? '?' : '&')
              + 'lat=' + encodeURIComponent(lat) + '&lng=' + encodeURIComponent(lng))
              .then(function (res) {
                if (res && res.ok && res.city && res.city.name) { toast(res.city.name); }
                else if (res && res.message) { toast(res.message); }
              }).catch(function () {});
          }
        }, function (err) {
          busyBtn(geoBtn, false);
          toast(err && err.code === 1
            ? (geoBtn.getAttribute('data-denied-text') || t('coGeoFail', ''))
            : t('coGeoFail', 'Could not read your location'));
        }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
      });
    }

    /* Typing an address by hand drops the saved-card selection. */
    var addrInput = el('[data-co-address]');
    if (addrInput) {
      addrInput.addEventListener('input', function () {
        $$('[data-co-addr-card].mk-addrcard--on', root).forEach(function (c) {
          c.classList.remove('mk-addrcard--on');
          c.setAttribute('aria-pressed', 'false');
        });
      });
    }

    /* The shipped address sheet does not emit mk:address-changed yet, so watch
       it close and re-read the list — no cross-file edit needed. */
    document.addEventListener('mk:address-changed', function (e) {
      var det = (e && e.detail) || {};
      if (typeof det.lat === 'number' && typeof det.lng === 'number') {
        setVal('[data-co-lat]', det.lat);
        setVal('[data-co-lng]', det.lng);
        if (det.label && addrInput && !addrInput.value) { addrInput.value = det.label; }
        resolveZone();
        requote();
      }
      loadAddresses();
    });

    if (authed && window.MutationObserver) {
      var addrSheet = document.getElementById('mk-addr-sheet');
      if (addrSheet) {
        new MutationObserver(function () {
          if (addrSheet.hidden) { loadAddresses(); }
        }).observe(addrSheet, { attributes: true, attributeFilter: ['hidden'] });
      }
    }
    if (authed) { loadAddresses(); }

    /* ---------- 4.6 inline OTP (guest → signed in, without leaving) ---------- */
    function otpMsg(text, bad) {
      var p = el('[data-co-otp-msg]');
      if (!p) { if (text) { toast(text); } return; }
      p.textContent = text || '';
      p.classList.toggle('is-bad', !!bad);
    }

    var otpSend = el('[data-co-otp-send]');
    if (otpSend && d.otpUrl) {
      otpSend.addEventListener('click', function () {
        var phone = val('[data-co-phone]');
        if (!phone) {
          toast(t('coOtpNeedPhone', 'Enter your phone number first.'));
          var pe = el('[data-co-phone]');
          if (pe) { pe.focus(); }
          return;
        }
        busyBtn(otpSend, true);
        Mk.post(d.otpUrl, { phone: phone }).then(function (res) {
          busyBtn(otpSend, false);
          var box = el('[data-co-otp]');
          if (res && res.ok) {
            if (box) { box.hidden = false; }
            otpMsg((res.message) || t('coOtpSent', ''), false);
            var code = el('[data-co-otp-code]');
            if (code) { code.focus(); }
          } else {
            otpMsg((res && res.message) || t('genericError', ''), true);
          }
        }).catch(function () {
          busyBtn(otpSend, false);
          otpMsg(t('genericError', 'Something went wrong — try again'), true);
        });
      });
    }

    var otpVerify = el('[data-co-otp-verify]');
    if (otpVerify && d.verifyUrl) {
      otpVerify.addEventListener('click', function () {
        var phone = val('[data-co-phone]');
        var code = val('[data-co-otp-code]');
        if (!phone || !code) { return; }
        busyBtn(otpVerify, true);
        Mk.post(d.verifyUrl, { phone: phone, code: code }).then(function (res) {
          if (res && res.ok) {
            /* The guest cart merges into the shopper's on verify — reload so
               the page re-renders from the shopper's own (merged) bag. */
            window.location.reload();
            return;
          }
          busyBtn(otpVerify, false);
          otpMsg((res && res.message) || t('genericError', ''), true);
        }).catch(function () {
          busyBtn(otpVerify, false);
          otpMsg(t('genericError', 'Something went wrong — try again'), true);
        });
      });
    }
    root.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') { return; }
      if (e.target.closest('[data-co-otp-code]') && otpVerify) { e.preventDefault(); otpVerify.click(); }
    });

    /* ---------- 4.7 chosen payment method per store ---------- */
    function methods() {
      var out = {};
      $$('[data-co-store]', root).forEach(function (card) {
        var on = $('.mk-copay input[type="radio"]:checked', card);
        out[card.getAttribute('data-co-store')] = on ? on.value : 'cod';
      });
      return out;
    }

    /* Reflect the picked row (pure affordance; the radio is the source). */
    root.addEventListener('change', function (e) {
      var radio = e.target.closest('.mk-copay input[type="radio"]');
      if (!radio) { return; }
      var pay = radio.closest('.mk-copay');
      if (!pay) { return; }
      $$('.mk-copay__row', pay).forEach(function (row) {
        row.classList.toggle('is-on', row.contains(radio));
      });
    });

    /* ---------- 4.8 place order ---------- */
    function renderSettle(res) {
      var box = el('[data-co-settle]');
      var list = el('[data-co-settle-list]');
      if (!box || !list) { return; }
      var txt = function (attr, fb) { return list.getAttribute(attr) || fb; };
      list.innerHTML = '';

      (res.results || []).forEach(function (row) {
        var li = document.createElement('li');
        li.className = 'mk-cosettle__row' + (row.ok ? '' : ' is-failed');

        var name = document.createElement('b');
        name.textContent = row.store || ('#' + row.store_id);
        li.appendChild(name);

        var state = document.createElement('span');
        state.className = 'mk-cosettle__state';
        if (!row.ok) {
          state.textContent = row.error || txt('data-failed-text', 'Failed');
          li.appendChild(state);
        } else if (row.payment_next) {
          state.textContent = txt('data-placed-text', 'Order placed');
          li.appendChild(state);
          var pay = document.createElement('a');
          pay.className = 'mk-btn mk-btn--sm';
          pay.href = row.payment_next;
          pay.rel = 'noopener';
          pay.textContent = txt('data-pay-text', 'Pay now');
          li.appendChild(pay);
        } else {
          state.textContent = txt('data-placed-text', 'Order placed') + ' · ' + txt('data-cod-text', 'Pay on delivery');
          li.appendChild(state);
        }

        if (row.ok && row.track_url) {
          var track = document.createElement('a');
          track.className = 'mk-cosettle__track';
          track.href = row.track_url;
          track.textContent = '→';
          track.setAttribute('aria-label', row.store || '');
          li.appendChild(track);
        }
        list.appendChild(li);
      });

      var done = el('[data-co-done-link]');
      if (done && res.done_url) { done.href = res.done_url; done.hidden = false; }
      box.hidden = false;
      box.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    var placeBtn = el('[data-co-place]');
    if (placeBtn && d.placeUrl) {
      placeBtn.addEventListener('click', function () {
        if (busy) { return; }
        showError('');

        var name = val('[data-co-name]');
        var phone = val('[data-co-phone]');
        if (!name || !phone) {
          showError(placeBtn.getAttribute('data-err-name') || '');
          var focus = el(name ? '[data-co-phone]' : '[data-co-name]');
          if (focus) { focus.focus(); }
          return;
        }

        var block = el('[data-co-address-block]');
        var address = val('[data-co-address]');
        if (block && !block.hidden && !address) {
          showError(placeBtn.getAttribute('data-err-address') || '');
          var ai = el('[data-co-address]');
          if (ai) { ai.focus(); }
          return;
        }

        var payload = { name: name, phone: phone, address: address, methods: methods() };
        var p = pin();
        if (p) { payload.lat = p.lat; payload.lng = p.lng; }

        /* Per-store tender INTENTS. These are booleans only — the server
           re-reads the live balance and caps the amount itself, so a client
           number here could never become a cap.
           NOTE: `credits` was missing entirely, which meant the P4.7 store-credit
           checkbox had been dead since it shipped: the shopper ticked it and the
           payload never carried it. */
        var intents = function (attr, toggleSel) {
          var out = {};
          $$('[' + attr + ']').forEach(function (row) {
            var rid = row.getAttribute(attr);
            var box = $(toggleSel, row);
            if (rid && box && box.checked) { out[rid] = 1; }
          });
          return out;
        };
        var credits = intents('data-co-credit', '[data-co-credit-toggle]');
        if (Object.keys(credits).length) { payload.credits = credits; }
        var fusion = intents('data-co-fusion', '[data-co-fusion-toggle]');
        if (Object.keys(fusion).length) { payload.use_fusion_wallet = fusion; }

        busy = true;
        busyBtn(placeBtn, true);

        Mk.post(d.placeUrl, payload).then(function (res) {
          busy = false;
          busyBtn(placeBtn, false);

          if (!res || (!res.ok && !((res.results || []).length))) {
            if (res && res.needs_context) {
              window.location.reload();
              return;
            }
            showError((res && res.error)
              || (res && res.errors && [].concat(res.errors[Object.keys(res.errors)[0]])[0])
              || placeBtn.getAttribute('data-err-generic')
              || t('genericError', 'Could not place your order.'));
            return;
          }

          var rows = res.results || [];
          var anyPay = rows.some(function (r) { return r.payment_next; });
          var anyFail = rows.some(function (r) { return !r.ok; });
          bagBadge(anyFail ? null : 0);

          /* All-COD, all-placed → nothing left to settle, go to confirmation. */
          if (!anyPay && !anyFail && res.done_url) {
            window.location.href = res.done_url;
            return;
          }
          renderSettle(res);
        }).catch(function () {
          busy = false;
          busyBtn(placeBtn, false);
          showError(placeBtn.getAttribute('data-err-generic') || t('genericError', 'Could not place your order.'));
        });
      });
    }

    /* Seed the badge + zone state from what the server already rendered. */
    if (pin()) { resolveZone(); }

    return { requote: requote, loadAddresses: loadAddresses };
  }

  /* ============================================================
     registration
     ============================================================ */
  function init() {
    if (!window.Mk) { return; }
    window.Mk.register('bag', {
      api: null,
      init: function (Mk) { this.api = boot(Mk); },
      refresh: function () { return this.api ? this.api.refresh() : null; },
      setAddress: function (lat, lng) { return this.api ? this.api.setAddress(lat, lng) : null; }
    });
    window.Mk.register('checkout', {
      api: null,
      init: function (Mk) { this.api = bootCheckout(Mk); },
      requote: function () { return this.api ? this.api.requote() : null; }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
