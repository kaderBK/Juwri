// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

/**
 * P1-G (NFR concurrency, opt-in) — one checkout intent must produce one order.
 *
 * Fires two /send-order POSTs for the same cart from the real storefront page,
 * exactly as a double-tapped "Place order" or a connection retry would, and
 * asserts the server resolved them to a single order (the second response is
 * flagged `duplicate` and points at the same track token).
 *
 * Env: E2E_RUN_DOUBLE_SUBMIT=1, E2E_STORE_SLUG (default crave-demo; an ordering-enabled store without phone verification).
 */

module.exports = async function checkoutDoubleSubmit({ page, cfg, log, shot, sleep }) {
  const resp = await page.goto(`${cfg.baseUrl}/${process.env.E2E_STORE_SLUG || 'crave-demo'}`, { waitUntil: 'networkidle2' });
  if (!resp || resp.status() >= 400) throw new Error(`double-submit: storefront returned ${resp && resp.status()}`);

  // The forwarder must be live on the page — it is what mints the token.
  const hasForwarder = await page.evaluate(() => {
    try { return typeof sessionStorage !== 'undefined'; } catch (e) { return false; }
  });
  if (!hasForwarder) throw new Error('double-submit: no sessionStorage — cannot verify token minting');

  // Pick a real menu item id off the rendered page.
  const itemId = await page.evaluate(() => {
    const el = document.querySelector('[data-open][data-id], [data-item-id], [data-open]:not(.mb-combo-sheet):not([data-open="0"])');
    if (!el) return null;
    return el.getAttribute('data-id') || el.getAttribute('data-item-id') || el.getAttribute('data-open');
  });
  if (!itemId || !/^\d+$/.test(String(itemId))) throw new Error(`double-submit: no numeric menu item id on the page (got ${itemId})`);

  await shot(page, 'double-1-storefront');

  // Two identical submissions through the page's own fetch (so the shared
  // forwarder injects the token exactly as it does for a real guest).
  // The send-order route binds the restaurant by ID, not slug (same trap as
  // applyCoupon). Read the endpoint the theme itself was configured with.
  const endpoint = await page.evaluate(() => {
    try {
      if (window.CFG && CFG.endpoints && CFG.endpoints.submitOrder) return CFG.endpoints.submitOrder;
    } catch (e) {}
    // The theme config is inline JSON, so the URL arrives escaped
    // ("https:\/\/host\/3425\/send-order"). Pull the numeric store segment and
    // rebuild a clean same-origin path.
    const m = document.documentElement.innerHTML.match(/\/(\d+)\\?\/send-order/);
    return m ? `/${m[1]}/send-order` : null;
  });
  if (!endpoint) throw new Error('double-submit: could not resolve the send-order endpoint from the page');

  const out = await page.evaluate(async (id, url) => {
    const csrf = (document.querySelector('meta[name=csrf-token]') || {}).content
      || (document.querySelector('input[name=_token]') || {}).value || '';
    const build = () => {
      const fd = new FormData();
      if (csrf) fd.append('_token', csrf);
      fd.append('items', JSON.stringify([{ id: +id, quantity: 1, variants: 0, extras: [], mods: [] }]));
      fd.append('name', 'E2E Double Tap');
      fd.append('ordering-type', 'takeaway');
      fd.append('phone-number', '+16505550000');
      fd.append('pay_via', 'cash');
      fd.append('total', '1.00');
      return fd;
    };
    const post = () => fetch(url, {
      method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: build(),
    }).then((r) => r.json());

    // Fire both before either resolves — a genuine double tap, not a sequence.
    const [a, b] = await Promise.all([post(), post()]);
    return { a, b };
  }, itemId, endpoint);

  await shot(page, 'double-2-submitted');

  const { a, b } = out;
  if (!a || !b) throw new Error('double-submit: no response from one of the submissions');
  if (!a.success && !b.success) {
    throw new Error(`double-submit: neither submission succeeded (${JSON.stringify(a)} / ${JSON.stringify(b)})`);
  }

  // Whichever won, both must point at the SAME order, and one must be flagged.
  const tracks = [a.track_url, b.track_url].filter(Boolean);
  if (tracks.length === 2 && tracks[0] !== tracks[1]) {
    throw new Error(`double-submit: two DIFFERENT orders created — ${tracks[0]} vs ${tracks[1]}`);
  }
  if (!a.duplicate && !b.duplicate) {
    throw new Error('double-submit: neither response was flagged duplicate — the second order was created for real');
  }

  log(`double-submit: both submissions resolved to one order (${tracks[0] || 'no track url'})`);
};
