{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  J27-A2: client order key for the goods / service checkouts ({slug}/checkout and
  {slug}/book — GoodsCheckoutController / LaundryBookingController). The restaurant
  /send-order gets its key from order-field-forwarder; these themes post their own
  payload (fetch, XHR or a plain <form>), so this hooks all three and adds a
  `client_order_id` (RFC 9562 UUIDv7) the server dedupes on.

  One key per checkout attempt on this page: a double tap or a retry after a lost
  answer resends the same key and gets the SAME order back (never a second one); a
  success or a 409 (the key already placed another cart) starts a new key. Never
  overwrites a key the payload already carries.
--}}
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    if (window.__mbClientOrderKey) return;
    window.__mbClientOrderKey = true;

    function uuid7() {
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
    var key = null;
    function current() { if (!key) key = uuid7(); return key; }
    function note(json) { try { if (json && (json.success || json.conflict)) key = null; } catch (e) {} }

    function isPlace(u) {
        if (typeof u !== 'string') return false;
        var path = u.split('?')[0].split('#')[0].replace(/\/+$/, '');
        return /\/(checkout|book)$/.test(path);
    }
    function inject(body) {
        var k = current();
        if (body instanceof FormData || body instanceof URLSearchParams) {
            if (!body.has('client_order_id')) body.append('client_order_id', k);
            return body;
        }
        if (typeof body === 'string') {
            try {
                var j = JSON.parse(body);
                if (j && typeof j === 'object' && j.client_order_id == null) { j.client_order_id = k; return JSON.stringify(j); }
                return body;
            } catch (e) {
                var p = new URLSearchParams(body);
                if (!p.has('client_order_id')) p.append('client_order_id', k);
                return p.toString();
            }
        }
        if (body == null) return 'client_order_id=' + encodeURIComponent(k);
        return body;
    }

    var of = window.fetch;
    if (of) {
        window.fetch = function (input, init) {
            var watch = false;
            try {
                var u = (typeof input === 'string') ? input : (input && input.url);
                var m = (init && init.method) || (input && input.method) || 'GET';
                watch = String(m).toUpperCase() === 'POST' && isPlace(u);
                if (watch && init) init.body = inject(init.body);
            } catch (e) {}
            var p = of.apply(this, arguments);
            if (watch && p && p.then) {
                p.then(function (r) { try { r.clone().json().then(note, function () {}); } catch (e) {} return r; }, function () {});
            }
            return p;
        };
    }

    var op = XMLHttpRequest.prototype.open, sd = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function (method, url) {
        this.__mbPlace = String(method).toUpperCase() === 'POST' && isPlace(url);
        return op.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function (body) {
        try {
            if (this.__mbPlace) {
                arguments[0] = inject(body);
                this.addEventListener('load', function () { try { note(JSON.parse(this.responseText)); } catch (e) {} });
            }
        } catch (e) {}
        return sd.apply(this, arguments);
    };

    // A plain <form> post: one hidden key per page render, reused on a double submit.
    document.addEventListener('submit', function (ev) {
        try {
            var f = ev.target;
            if (!f || String(f.method).toUpperCase() !== 'POST' || !isPlace(f.action)) return;
            if (f.querySelector('[name="client_order_id"]')) return;
            var i = document.createElement('input');
            i.type = 'hidden'; i.name = 'client_order_id'; i.value = current();
            f.appendChild(i);
        } catch (e) {}
    }, true);
})();
</script>
