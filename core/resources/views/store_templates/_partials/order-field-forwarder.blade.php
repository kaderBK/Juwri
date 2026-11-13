{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  T1/T2/T5 field forwarder. Storefront themes submit the order through a compiled
  script.js bundle (not in this repo) that may build an explicit payload rather than
  serializing the <form>. This inline interceptor guarantees the newer checkout fields
  ride along regardless of the bundle: it hooks fetch + XHR, and on the POST to
  {store}/send-order injects any present scheduled_at / gift_card_code / curbside /
  vehicle_* inputs (by name) into the outgoing body (FormData, urlencoded, or JSON).
  Idempotent — never overwrites a value the bundle already set.
--}}
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    // WIRING-RUN G3: `use_wallet` joins the list. Themes that render the pay-with-credit
    // checkbox but build their order payload in JS (vivid) were dropping it silently —
    // the diner ticked "pay with store credit" and was charged in full. Themes with a
    // native <form> (pulse, aurora) already carried it; forwarding is harmless there
    // because injection never overwrites a value the payload already set.
    // PARITY-RUN T3: `table_key` is the QR wire key of the scanned table, emitted
    // by the table-verify partial. Themes post the human table NUMBER, so without
    // this the scan gate could only scope a session by number — forwarding the key
    // lets the server check the session against the table that was actually scanned.
    // PARITY-RUN T6: `combos` is the JSON basket the shared combo partial builds.
    // Themes' compiled cart bundles know nothing about combos, so forwarding is
    // the ONLY way a combo reaches checkout on all 40+ of them.
    // K3: `loyalty_redeem` is the points-redemption opt-in the shared loyalty-redeem
    // strip sets; forwarding it lets checkout-fold redemption reach /send-order on
    // every theme whose compiled cart bundle builds its own payload.
    var FIELDS = ['scheduled_at', 'gift_card_code', 'curbside', 'vehicle_make', 'vehicle_color', 'vehicle_plate', 'tender', 'room', 'use_wallet', 'table_key', 'combos', 'tip_amount', 'loyalty_redeem'];

    /* Delivery pin harvest. The diner's chosen delivery coordinates live in one of
       two shapes depending on the theme, and no theme puts them in its order
       payload under a single agreed name (5 themes submit address_lat/lng, one
       latitude/longitude, the rest submit nothing at all — the pin died in the
       DOM). Read them centrally and forward as the canonical delivery_lat/lng,
       which the server normalises onto orders.delivery_lat/lng for dispatch.

       Source order: an explicitly SELECTED saved-address chip wins — `.is-on` is
       the shared active-chip marker on every theme, and its data-lat/lng reflect
       the diner's actual choice (and the pin they may have dropped in their
       profile map, which becomes the address's coords). Otherwise fall back to a
       named coord input, which is what a theme's map / "use my location" / address
       autocomplete fills when no saved address is picked. */
    function coords() {
        var chip = document.querySelector('.is-on[data-lat][data-lng], [data-lat][data-lng].is-on');
        if (chip) {
            var cla = chip.getAttribute('data-lat'), clo = chip.getAttribute('data-lng');
            if (cla && clo) return { lat: cla, lng: clo };
        }
        var pairs = [['delivery_lat', 'delivery_lng'], ['address_lat', 'address_lng'], ['latitude', 'longitude']];
        for (var i = 0; i < pairs.length; i++) {
            var la = document.querySelector('[name="' + pairs[i][0] + '"]');
            var lo = document.querySelector('[name="' + pairs[i][1] + '"]');
            if (la && lo && la.value && lo.value) return { lat: la.value, lng: lo.value };
        }
        return null;
    }

    function extras() {
        var out = {};
        FIELDS.forEach(function (n) {
            var el = document.querySelector('[name="' + n + '"]');
            if (!el) return;
            var v = (el.type === 'checkbox') ? (el.checked ? '1' : '') : (el.value || '');
            if (v !== '') out[n] = v;
        });
        // Delivery pin (server ignores it on non-delivery orders, so forwarding it
        // unconditionally is safe — the type gate lives in submitOrder).
        var c = coords();
        if (c) { out['delivery_lat'] = c.lat; out['delivery_lng'] = c.lng; }
        // P1-G: always present, so every theme's payload is de-duplicable.
        out['client_uuid'] = token();
        return out;
    }

    /* D6: charge-to-room needs the ROOM SERVICE order type, and no storefront theme
       offers one. StoreController@submitOrder only resolves the room, writes
       service_location and evaluates `tender` inside the `ordering-type ==
       'room_service'` branch, so a guest who ticked "Charge to my room" had their
       order submitted as on_table/takeaway and it silently never reached the folio.
       The hotel partial already forwards `room`; the missing piece is the type.

       This is an OVERRIDE, not an addition: every theme already puts its own
       `ordering-type` in the payload, so the additive pass below would skip it.
       Scoped deliberately narrow — only when the guest actively opted in via the
       charge-to-room toggle, which is meaningless under any other order type. A
       guest ordering to their room WITHOUT charging to the folio keeps whatever
       type the theme sent. */
    function overrides(ex) {
        var out = {};
        if (ex.tender === 'charge_to_room') {
            out['ordering-type'] = 'room_service';
            /* The reservation name the guest confirmed in the charge-to-room field is
               the folio authorization (server re-validates it via guestNameMatches).
               Force it as the order `name` so the gate checks what the guest actually
               saw/edited, not whatever generic name box the theme submitted. Only when
               non-empty — never blank out a name the theme already set. */
            var nm = document.querySelector('[name="charge_room_name"]');
            if (nm && String(nm.value).trim() !== '') out['name'] = String(nm.value).trim();
        }
        return out;
    }
    function isSendOrder(u) { return typeof u === 'string' && u.indexOf('/send-order') !== -1; }

    /* P1-G: one checkout intent = one order. A double-tapped "Place order", a
       retry on a flaky connection or a bfcache re-POST used to create a second
       real order (two kitchen tickets, two totals). Every /send-order POST now
       carries a submission token; the server returns the original order for a
       repeat instead of creating another one.

       Lifecycle: minted once per intent and held in sessionStorage so a reload
       mid-checkout keeps it, then ROTATED as soon as a response comes back
       successful — the guest's next order is a genuinely new one. A failed or
       otp_required response keeps the token, because re-submitting after
       entering the code is the same intent. */
    var TOKEN_KEY = 'mb_order_token';
    /* J27-A2: the token is an RFC 9562 UUIDv7 (48-bit ms time + random); the server
       accepts any RFC UUID, so tokens minted before (v4) still replay. */
    function uuid() {
        var b = new Array(16), i;
        try { if (window.crypto && crypto.getRandomValues) b = Array.prototype.slice.call(crypto.getRandomValues(new Uint8Array(16))); } catch (e) {}
        for (i = 0; i < 16; i++) { if (typeof b[i] !== 'number') b[i] = (Math.random() * 256) | 0; }
        var t = Date.now();
        for (i = 5; i >= 0; i--) { b[i] = t % 256; t = Math.floor(t / 256); }
        b[6] = (b[6] & 0x0f) | 0x70;
        b[8] = (b[8] & 0x3f) | 0x80;
        var h = b.map(function (x) { return (x < 16 ? '0' : '') + x.toString(16); }).join('');
        return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
    }
    function token() {
        var t = null;
        try { t = sessionStorage.getItem(TOKEN_KEY); } catch (e) {}
        if (!t) { t = uuid(); try { sessionStorage.setItem(TOKEN_KEY, t); } catch (e) {} }
        return t;
    }
    function rotateToken() { try { sessionStorage.removeItem(TOKEN_KEY); } catch (e) {} }
    /* Rotate only on a real success. `duplicate` responses rotate too: the intent
       is settled, whichever request won. J27-A2: a `conflict` (409 — this token
       already placed a different cart) rotates as well, so the next tap is a new order. */
    function noteResponse(json) {
        try { if (json && (json.success || json.conflict)) rotateToken(); } catch (e) {}
    }
    function inject(body, ex, ov) {
        ov = ov || {};
        if (body instanceof FormData || body instanceof URLSearchParams) {
            Object.keys(ex).forEach(function (k) { if (!body.has(k)) body.append(k, ex[k]); });
            Object.keys(ov).forEach(function (k) { body.set(k, ov[k]); });
            return body;
        }
        if (typeof body === 'string') {
            try {
                var j = JSON.parse(body), ch = false;
                Object.keys(ex).forEach(function (k) { if (j[k] == null) { j[k] = ex[k]; ch = true; } });
                Object.keys(ov).forEach(function (k) { if (j[k] !== ov[k]) { j[k] = ov[k]; ch = true; } });
                return ch ? JSON.stringify(j) : body;
            } catch (e) {
                var p = new URLSearchParams(body);
                Object.keys(ex).forEach(function (k) { if (!p.has(k)) p.append(k, ex[k]); });
                Object.keys(ov).forEach(function (k) { p.set(k, ov[k]); });
                return p.toString();
            }
        }
        return body;
    }

    // fetch
    var of = window.fetch;
    if (of) {
        window.fetch = function (input, init) {
            var watch = false;
            try {
                var u = (typeof input === 'string') ? input : (input && input.url);
                var m = (init && init.method) || (input && input.method) || 'GET';
                watch = String(m).toUpperCase() === 'POST' && isSendOrder(u);
                if (watch) {
                    var ex = extras(), ov = overrides(ex);
                    if ((Object.keys(ex).length || Object.keys(ov).length) && init && 'body' in init) init.body = inject(init.body, ex, ov);
                }
            } catch (e) {}
            var p = of.apply(this, arguments);
            // Rotate the submission token once the order is actually placed.
            try {
                if (watch && p && p.then) {
                    return p.then(function (res) {
                        try { res.clone().json().then(noteResponse, function () {}); } catch (e) {}
                        return res;
                    });
                }
            } catch (e) {}
            return p;
        };
    }

    // XHR (jQuery $.ajax etc.)
    var op = XMLHttpRequest.prototype.open, sd = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function (method, url) {
        this.__mbSend = (String(method).toUpperCase() === 'POST' && isSendOrder(url));
        return op.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function (body) {
        try {
            if (this.__mbSend) {
                var ex = extras(), ov = overrides(ex);
                if (Object.keys(ex).length || Object.keys(ov).length) arguments[0] = inject(body, ex, ov);
                // Same token rotation as the fetch path, for jQuery-style themes.
                this.addEventListener('load', function () {
                    try { noteResponse(JSON.parse(this.responseText)); } catch (e) {}
                });
            }
        } catch (e) {}
        return sd.apply(this, arguments);
    };
})();
</script>
