// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';
/* P3-13 (THEME-CONTRACT §5.2) — the server quote for the premium (D11) themes. Every amount below the cart lines
   (subtotal, discount, delivery, tax, tip, total) comes from POST {store}/quote, priced on the same path as the
   checkout the theme posts to; the theme's own sum is only shown until the server answers (marked aria-busy).

   A theme calls  mbQuote.update(key, url, body, render)  whenever its cart, order type, coupon or tip changes:
     key     one name per totals area ('cart', 'checkout') — only the newest answer per key is rendered;
     url     the quote endpoint (the store's `restaurant.quote` route, or the goods cart quote);
     body    the fields the theme posts to its checkout (items, ordering-type, coupon_code, tip_amount, branch_id…;
             `_token` = the CSRF token when the page has no csrf-token meta);
     render  function (quote) — draws quote.rows (label, amount.formatted) and quote.total.formatted.
   `combos` and `tip_amount` are added from the page like the checkout's field forwarder adds them, so the quote sees
   the same cart the order will. Errors (offline, a line no longer sold) leave the estimate in place: the checkout
   itself refuses or re-prices, never this script. Loaded by the D11 theme views only (never on a payment page). */
(function (w, d) {
    var seq = {};
    var timers = {};

    function token() {
        var m = d.querySelector('meta[name="csrf-token"]');
        if (m && m.getAttribute('content')) return m.getAttribute('content');
        var i = d.querySelector('input[name="_token"]');
        return i ? i.value : '';
    }

    function pageField(name) {
        var el = d.querySelector('[name="' + name + '"]');
        if (!el) return '';
        if (el.type === 'checkbox') return el.checked ? '1' : '';
        return el.value || '';
    }

    function payload(body) {
        var out = {};
        Object.keys(body || {}).forEach(function (k) {
            if (body[k] !== undefined && body[k] !== null && body[k] !== '') out[k] = body[k];
        });
        ['combos', 'tip_amount'].forEach(function (k) {
            if (out[k] === undefined) {
                var v = pageField(k);
                if (v !== '') out[k] = v;
            }
        });
        /* The forwarder turns a charge-to-room order into room service; the quote follows it. */
        if (pageField('tender') === 'charge_to_room') out['ordering-type'] = 'room_service';
        return out;
    }

    function request(url, body) {
        var data = payload(body);
        var csrf = data._token || token();
        delete data._token;
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(data)
        }).then(function (r) {
            if (!r.ok) return null;
            return r.json().then(function (j) { return (j && j.quote) || null; });
        }).catch(function () { return null; });
    }

    w.mbQuote = {
        /** The quote URL next to a theme's checkout URL ({store}/send-order → {store}/quote). */
        urlFrom: function (submitOrderUrl) {
            return String(submitOrderUrl || '').replace(/\/send-order(?=$|[?#])/, '/quote');
        },
        /** Debounced, newest-wins quote of one totals area. `busy` elements get aria-busy while it runs. */
        update: function (key, url, body, render, busy) {
            if (!url || typeof render !== 'function') return;
            var my = (seq[key] = (seq[key] || 0) + 1);
            var marks = [].concat(busy || []).filter(Boolean);
            marks.forEach(function (el) { el.setAttribute('aria-busy', 'true'); });
            clearTimeout(timers[key]);
            timers[key] = setTimeout(function () {
                request(url, body).then(function (q) {
                    if (my !== seq[key]) return;
                    marks.forEach(function (el) { el.removeAttribute('aria-busy'); });
                    if (q && q.total) {
                        try { render(q); } catch (e) { /* keep the estimate */ }
                        try { d.dispatchEvent(new CustomEvent('mb:quote', { detail: { key: key, quote: q } })); } catch (e) {}
                    }
                });
            }, 120);
        },
        /** The quote rows as [{key, label, formatted, negative}] (informational rows flagged, never summed here). */
        rows: function (q) {
            return ((q && q.rows) || []).map(function (r) {
                return {
                    key: r.key,
                    label: r.label,
                    formatted: r.amount_label || (r.amount && r.amount.formatted) || '',
                    negative: !!(r.amount && r.amount.minor < 0),
                    informational: !!r.informational
                };
            });
        },
        /** Text-safe helper for themes that build rows as HTML strings. */
        esc: function (s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }
    };
})(window, document);
