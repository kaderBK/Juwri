// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Delivery-commission calculator (SEO run W2.1; owner-ui kit page, WI-16f-2).
 * Vanilla, CSP-safe (no inline handlers, no library), progressive: the page is
 * fully readable without JS; this only wires the live estimate + share link.
 * Hooks: data-cc-* on core/resources/views/marketing/tools/commission-calculator.blade.php;
 * the selected market button carries aria-pressed="true" (styled in site-tools.css).
 *
 * Math (currency-agnostic): monthly = orders * ticket * rate; yearly = *12.
 * Rate is the top of each platform's published "up to" range (see
 * marketing/data/commission-defs.php / KEYWORDS.md §Commissions).
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-cc-root]');
  if (!root) return;

  var dataEl = root.querySelector('[data-cc-data]');
  var markets;
  try {
    markets = JSON.parse(dataEl.textContent);
  } catch (e) {
    return;
  }
  var codes = Object.keys(markets);
  if (!codes.length) return;

  var lang = (document.documentElement.getAttribute('lang') || 'en').trim() || 'en';
  var nf;
  try {
    nf = new Intl.NumberFormat(lang, { maximumFractionDigits: 0 });
  } catch (e) {
    nf = new Intl.NumberFormat('en', { maximumFractionDigits: 0 });
  }

  var els = {
    buttons: Array.prototype.slice.call(root.querySelectorAll('[data-cc-market]')),
    orders: root.querySelector('[data-cc-orders]'),
    ticket: root.querySelector('[data-cc-ticket]'),
    ordersOut: root.querySelector('[data-cc-orders-out]'),
    ticketOut: root.querySelector('[data-cc-ticket-out]'),
    monthly: root.querySelector('[data-cc-monthly]'),
    yearly: root.querySelector('[data-cc-yearly]'),
    aggregator: root.querySelector('[data-cc-aggregator]'),
    rate: root.querySelector('[data-cc-rate]'),
    share: root.querySelector('[data-cc-share]'),
    shareLabel: root.querySelector('[data-cc-share-label]')
  };
  if (!els.orders || !els.ticket) return;

  var current = codes[0];

  function money(cc, amount) {
    var m = markets[cc];
    var n = nf.format(Math.round(amount));
    if (m.pos === 'suffix') return n + ' ' + m.symbol;
    return m.symbol + ' ' + n;
  }

  function clampRange(input, val) {
    var min = parseFloat(input.min);
    var max = parseFloat(input.max);
    if (isNaN(val)) return parseFloat(input.value);
    return Math.min(max, Math.max(min, val));
  }

  function setActive(cc) {
    els.buttons.forEach(function (b) {
      var on = b.getAttribute('data-cc-market') === cc;
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  function render() {
    var m = markets[current];
    var orders = parseFloat(els.orders.value) || 0;
    var ticket = parseFloat(els.ticket.value) || 0;
    var monthly = orders * ticket * m.rate;

    if (els.ordersOut) els.ordersOut.textContent = nf.format(orders);
    if (els.ticketOut) els.ticketOut.textContent = money(current, ticket);
    if (els.monthly) els.monthly.textContent = money(current, monthly);
    if (els.yearly) els.yearly.textContent = money(current, monthly * 12);
    if (els.aggregator) els.aggregator.textContent = m.aggregator;
    if (els.rate) els.rate.textContent = m.rate_label;

    syncUrl(orders, ticket);
  }

  function syncUrl(orders, ticket) {
    if (!window.history || !window.history.replaceState) return;
    try {
      var url = new URL(window.location.href);
      url.searchParams.set('m', current);
      url.searchParams.set('o', String(orders));
      url.searchParams.set('t', String(ticket));
      window.history.replaceState(null, '', url.toString());
    } catch (e) { /* older browsers: skip deep-link sync */ }
  }

  // --- wire inputs -------------------------------------------------------
  els.buttons.forEach(function (b) {
    b.addEventListener('click', function () {
      current = b.getAttribute('data-cc-market');
      setActive(current);
      render();
    });
  });
  els.orders.addEventListener('input', render);
  els.ticket.addEventListener('input', render);

  if (els.share) {
    els.share.addEventListener('click', function () {
      var href = window.location.href;
      var done = els.share.getAttribute('data-copied') || 'Copied';
      var orig = els.shareLabel ? els.shareLabel.textContent : '';
      function flash() {
        if (!els.shareLabel) return;
        els.shareLabel.textContent = done;
        els.share.classList.add('is-copied');
        setTimeout(function () {
          els.shareLabel.textContent = orig;
          els.share.classList.remove('is-copied');
        }, 2000);
      }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(href).then(flash, flash);
      } else {
        flash();
      }
    });
  }

  // --- restore from deep link -------------------------------------------
  try {
    var params = new URLSearchParams(window.location.search);
    var pm = params.get('m');
    if (pm && markets[pm]) current = pm;
    var po = parseFloat(params.get('o'));
    var pt = parseFloat(params.get('t'));
    if (!isNaN(po)) els.orders.value = String(clampRange(els.orders, po));
    if (!isNaN(pt)) els.ticket.value = String(clampRange(els.ticket, pt));
  } catch (e) { /* no params — use defaults */ }

  // Seed the ticket default from the chosen market when the URL didn't set it.
  if (!new URLSearchParams(window.location.search).get('t') && markets[current]) {
    els.ticket.value = String(clampRange(els.ticket, markets[current].ticket));
  }
  if (!new URLSearchParams(window.location.search).get('o') && markets[current]) {
    els.orders.value = String(clampRange(els.orders, markets[current].orders));
  }

  setActive(current);
  render();
})();
