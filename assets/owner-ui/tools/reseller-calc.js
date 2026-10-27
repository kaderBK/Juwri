// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Reseller / white-label margin calculator (owner-ui kit page, WI-16f-2).
 * Pure client-side math: (your price − wholesale seat) × clients. Vanilla,
 * CSP-safe (no inline handlers, no library). Hooks: data-rc-* on
 * core/resources/views/marketing/tools/reseller-calculator.blade.php. The
 * below-cost warning text comes translated from data-rc-below (":cost" is
 * replaced with the seat cost).
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-rc-root]');
  if (!root) return;

  var seatEl = root.querySelector('[data-rc-seat]');
  var priceEl = root.querySelector('[data-rc-price]');
  var clientsEl = root.querySelector('[data-rc-clients]');
  if (!seatEl || !priceEl || !clientsEl) return;

  var out = {
    clients: root.querySelector('[data-rc-clients-out]'),
    monthly: root.querySelector('[data-rc-monthly]'),
    yearly: root.querySelector('[data-rc-yearly]'),
    per: root.querySelector('[data-rc-permargin]'),
    note: root.querySelector('[data-rc-note]')
  };

  var money2 = function (n) { // rate: always 2 decimals
    return '$' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  };
  var moneyWhole = function (n) { // totals: whole dollars, thousands-separated
    return '$' + Math.round(n).toLocaleString();
  };

  var noteDefault = out.note ? out.note.textContent : '';
  var noteBelow = out.note ? (out.note.getAttribute('data-rc-below') || '') : '';

  function calc() {
    var seat = Math.max(0, parseFloat(seatEl.value) || 0);
    var price = Math.max(0, parseFloat(priceEl.value) || 0);
    var clients = Math.max(0, parseInt(clientsEl.value, 10) || 0);
    var per = price - seat;
    var monthly = per * clients;

    if (out.clients) out.clients.textContent = clients;
    if (out.per) out.per.textContent = money2(per);
    if (out.monthly) out.monthly.textContent = moneyWhole(monthly);
    if (out.yearly) out.yearly.textContent = moneyWhole(monthly * 12);
    if (out.note) {
      out.note.textContent = (per < 0 && noteBelow)
        ? noteBelow.replace(':cost', money2(seat))
        : noteDefault;
    }
  }

  [seatEl, priceEl, clientsEl].forEach(function (el) {
    el.addEventListener('input', calc);
    el.addEventListener('change', calc);
  });
  calc();
})();
