'use strict';

/**
 * Platform E2E v2 — order kit (Lane-A).
 *
 * A locale-agnostic driver for the storefront checkout that talks to the SAME
 * `/{store}/send-order` endpoint the theme's own "Place order" button posts
 * to (captured live from `window.<GLOBAL>.endpoints.submitOrder`), reusing the
 * page's real session cookies + CSRF token. This is a genuine checkout — not a
 * back-door — so it exercises the full server pricing/order pipeline while
 * staying robust across the 17 storefront engines (whose add-to-cart UIs differ
 * wildly) and across locales (en/ar). v1's `a-order.js` already proves the UI
 * drive in Arabic; v2 layers depth (security/integrity/English) on top of it.
 *
 * Every helper reads ground truth: the storefront commerce runtime for prices +
 * tax + currency, the send-order JSON for success/track/duplicate, and (via the
 * caller) the `orders` row for the stored totals.
 */

/**
 * Load a storefront and return its live commerce runtime — the object the theme
 * bundle exposes globally with `endpoints.submitOrder`. Detected by shape (not by
 * a hard-coded global name) so it works for CRAVE/AURORA/…; throws if the store
 * is not orderable or the runtime is absent.
 *
 * Engine-robust: the theme's item map is found by shape (a `.items` object of
 * priced items), which every engine exposes; the send-order endpoint is taken
 * from `endpoints.submitOrder` when the engine publishes it (cv/spa families) and
 * otherwise derived from the slug (`{base}/{slug}/send-order`, which the
 * `restaurant.submitOrder` route resolves by slug OR id) so the sheet/form engines
 * (aurora/feast/vivid) work too. CSRF comes from the global or the meta tag.
 *
 * @param {import('puppeteer').Page} page
 * @param {string} url absolute storefront URL (use enUrl()/localeUrl())
 * @param {{slug?:string, baseUrl?:string}} [o] slug (for the derived endpoint) + base
 * @returns {Promise<object>} { global, lang, dir, storeId, submitOrder, coupon,
 *   csrf, taxCharge, taxType, taxLabel, currencySign, currencyPos, minOrder,
 *   allowOrder, items:[{id,name,price,hasRequired}] }
 */
async function loadRuntime(page, url, { slug, baseUrl } = {}) {
  const resp = await page.goto(url, { waitUntil: 'networkidle2' });
  if (!resp || resp.status() >= 400) throw new Error(`loadRuntime: ${url} returned ${resp && resp.status()}`);
  slug = slug || url.replace(/^https?:\/\/[^/]+/, '').split('?')[0].split('/').filter(Boolean).pop();
  baseUrl = baseUrl || (url.match(/^https?:\/\/[^/]+/) || [''])[0];
  const rt = await page.evaluate(() => {
    let name = null; let G = null;
    for (const k of Object.keys(window)) {
      try {
        const v = window[k];
        if (v && typeof v === 'object' && v.items && typeof v.items === 'object' && !Array.isArray(v.items)
          && Object.keys(v.items).length) { name = k; G = v; break; }
      } catch (_e) { /* cross-origin window prop */ }
    }
    if (!G) return null;
    const itemsObj = G.items || {};
    const items = Object.keys(itemsObj).map((k) => {
      const it = itemsObj[k];
      const groups = it.optionGroups || it.groups || [];
      return { id: it.id, name: it.name, price: Number(it.price), hasRequired: groups.some((g) => g.required) };
    }).filter((it) => it.id && Number.isFinite(it.price));
    return {
      global: name,
      lang: (document.documentElement.lang || '').toLowerCase(),
      dir: (document.documentElement.dir || '').toLowerCase(),
      storeId: G.storeId,
      submitOrderPublished: (G.endpoints && G.endpoints.submitOrder) || null,
      coupon: (G.endpoints && G.endpoints.coupon) || null,
      csrf: G.csrf || document.querySelector('meta[name="csrf-token"]')?.content || null,
      taxCharge: Number(G.taxCharge || 0),
      taxType: G.taxType || 'percentage',
      taxLabel: G.taxLabel || 'Tax',
      currencySign: G.currencySign || '',
      currencyPos: String(G.currencyPos ?? '1'),
      currencyDecimals: Number(G.currencyDecimals ?? 2),
      minOrder: Number(G.minOrderValue || 0),
      allowOrder: G.allowOrder === undefined ? true : !!G.allowOrder,
      items,
    };
  });
  if (!rt) throw new Error(`loadRuntime: no storefront item runtime (window.*.items) at ${url}`);
  if (!rt.allowOrder) throw new Error(`loadRuntime: store at ${url} is not accepting orders (allowOrder=false)`);
  if (!rt.items.length) throw new Error(`loadRuntime: store at ${url} exposed no menu items`);
  rt.slug = slug;
  // Prefer the engine's published endpoint (already locale-correct). Otherwise
  // derive it from the LOADED url by swapping the trailing slug segment for the
  // restaurant ID (the `{store}` route param binds by id, not slug) while
  // preserving any locale prefix (/en on an ar-default site, none on an
  // en-default site) so the recorded order.locale is stable across lanes.
  if (rt.submitOrderPublished) {
    rt.submitOrder = rt.submitOrderPublished;
  } else if (rt.storeId) {
    rt.submitOrder = `${url.split('?')[0].replace(/\/$/, '').replace(/\/[^/]+$/, '')}/${rt.storeId}/send-order`;
  } else {
    throw new Error(`loadRuntime: engine published no send-order endpoint and exposed no storeId (${url})`);
  }
  return rt;
}

/**
 * Pick `n` simple menu items (no required option group → no modifier to satisfy)
 * whose unit price clears an optional `minSubtotal`. Returns line objects
 * `{ id, quantity }` for the send-order `items` payload.
 * @param {object} rt runtime from loadRuntime
 * @param {{count?:number, quantity?:number}} [o]
 */
function pickSimpleLines(rt, { count = 1, quantity = 1 } = {}) {
  const simple = rt.items.filter((it) => !it.hasRequired && it.price > 0);
  const pool = simple.length ? simple : rt.items.filter((it) => it.price > 0);
  if (!pool.length) throw new Error('pickSimpleLines: no priced items available');
  pool.sort((a, b) => b.price - a.price); // dearest first → clears minimums fast
  const lines = [];
  for (let i = 0; i < count && i < pool.length; i += 1) lines.push({ id: pool[i].id, quantity });
  return lines;
}

/**
 * POST a real order to the storefront's own send-order endpoint from the page
 * context (session cookies + CSRF). `fields` overrides/extends the defaults:
 *   name, ordering-type (delivery|takeaway|on-table|room_service), phone-number,
 *   address, table, pay_via, coupon_code, client_uuid, gift_card_code, use_wallet.
 * `items` is an array of line objects (JSON-encoded into the `items` field, as
 * the controller expects). Returns the parsed JSON envelope
 * `{ success, message, track_url, duplicate, otp_required, status }` plus the
 * raw HTTP `status`.
 *
 * @param {import('puppeteer').Page} page — already on the store (runtime loaded)
 * @param {object} o
 * @param {object} o.rt runtime from loadRuntime (for endpoint + csrf)
 * @param {Array<object>} o.items line objects
 * @param {object} [o.fields] send-order field overrides
 */
async function submitOrder(page, { rt, items, fields = {} }) {
  const body = Object.assign({
    name: 'E2E Diner',
    'ordering-type': 'delivery',
    'phone-number': '+966512345678',
    address: '12 King Fahd Road, Riyadh',
    pay_via: 'pay_on_counter',
    items: JSON.stringify(items),
  }, fields);
  // on-table / room_service drop the address+phone requirement
  if (body['ordering-type'] === 'on-table') { delete body.address; body.table = body.table || '7'; }
  if (body['ordering-type'] === 'room_service') { delete body.address; body.room = body.room || '101'; }
  if (body['ordering-type'] === 'takeaway') { delete body.address; }

  return page.evaluate(async ({ endpoint, csrf, body }) => {
    const fd = new FormData();
    Object.keys(body).forEach((k) => { if (body[k] !== undefined && body[k] !== null) fd.append(k, body[k]); });
    const r = await fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: Object.assign({ Accept: 'application/json' }, csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
      body: fd,
    });
    let json = null;
    try { json = await r.json(); } catch (_e) { json = null; }
    return Object.assign({ status: r.status }, json || { success: false, message: 'non-JSON response' });
  }, { endpoint: rt.submitOrder, csrf: rt.csrf, body });
}

/** Parse the track token out of a track_url (…/order/track/<token>). */
function trackToken(trackUrl) {
  const m = String(trackUrl || '').match(/order\/track\/([^/?#]+)/);
  return m ? m[1] : null;
}

/** Numeric value out of a money string (Arabic-Indic aware handled by platform.normalizeDigits upstream). */
function money(str) {
  const s = String(str || '').replace(/[^\d.,-]/g, '').replace(/,/g, '');
  const m = s.match(/-?\d+(?:\.\d+)?/);
  return m ? parseFloat(m[0]) : NaN;
}

module.exports = { loadRuntime, pickSimpleLines, submitOrder, trackToken, money };
