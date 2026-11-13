{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    Shared checkout feedback — surfaces facts the /send-order response already
    carries but that NO theme reads, so a diner used to be left in the dark:

      • coupon_declined — a coupon they entered did not make it onto the order
        (expired / invalid / cap hit between apply and checkout). Without this the
        order silently placed at full price.
      • dropped_items — lines that went sold-out or out of their availability
        window at submit time and were left off the order. Without this the diner
        only noticed a missing item on the receipt.

    Both keys ride on a success:true response (the order still places), so this is
    purely additive: a dismissible top banner shown after the theme paints its own
    confirmation. Included once in the layout, like order-result / checkout-otp, so
    every theme gets it at once. No theme reads these keys, so there is no double
    message to guard against.
--}}
<style nonce="{{ csp_nonce() }}">
    .mbfb-note{
        position:fixed;left:0;right:0;top:0;z-index:2147483200;display:none;box-sizing:border-box;
        padding:calc(12px + env(safe-area-inset-top,0px)) 44px 12px 16px;
        background:#b45309;color:#fff;font-family:var(--menu-font, inherit);
        font-size:.93rem;line-height:1.4;box-shadow:0 6px 20px rgba(0,0,0,.18);
    }
    .mbfb-note.is-open{display:block;}
    /* A positive-only notice (charged to room, nothing wrong) reads as a confirmation,
       not a warning — green ground instead of the amber warning ground. */
    .mbfb-note.mbfb-ok{background:#0f7b3f;}
    .mbfb-note p{margin:0 0 4px;font-weight:700;}
    .mbfb-note p:last-child{margin:0;}
    .mbfb-note__x{
        position:absolute;inset-inline-end:10px;top:calc(10px + env(safe-area-inset-top,0px));
        background:transparent;border:0;color:#fff;opacity:.9;font-size:1.3rem;cursor:pointer;line-height:1;
    }
</style>

<div class="mbfb-note" id="mbFbNote" role="alert" aria-live="assertive">
    <div id="mbFbBody"></div>
    <button type="button" class="mbfb-note__x" id="mbFbClose" aria-label="{{ ___('Close') }}">&times;</button>
</div>

<script nonce="{{ csp_nonce() }}">
(function () {
    var note = document.getElementById('mbFbNote');
    var body = document.getElementById('mbFbBody');
    var xBtn = document.getElementById('mbFbClose');
    if (!note || !body) return;

    var I18N = {
        coupon: @json(___('Your coupon could not be applied, so the order was charged at the regular price.')),
        dropped: @json(___('Some items were unavailable and left off your order:')),
        wallet: @json(___('Store credit could not be applied to this order.')),
        chargeOk: @json(___('Charged to your room — settle at reception.')),
        chargeFail: @json(___('Order placed, but we could not add it to your room bill — please pay at reception.')),
    };

    function hide() { note.classList.remove('is-open'); }
    if (xBtn) xBtn.addEventListener('click', hide);

    /* Pin the banner to the theme's centered content column instead of the raw
       viewport. Without this a desktop store (a centered 430–1180px frame) drew
       this fixed banner edge-to-edge across the whole window — the "Charged to
       your room" note floated far outside the storefront frame. On mobile / a
       full-width theme the band spans the viewport, so the guard leaves the
       original full-bleed bar untouched. */
    function alignToFrame() {
        var band = window.mbAnchor && window.mbAnchor.frameBand && window.mbAnchor.frameBand();
        if (band && band.width < window.innerWidth - 40) {
            note.style.left = band.left + 'px';
            note.style.right = 'auto';
            note.style.width = band.width + 'px';
            note.style.borderRadius = '0 0 14px 14px';
        } else {
            note.style.left = '';
            note.style.right = '';
            note.style.width = '';
            note.style.borderRadius = '';
        }
    }
    window.addEventListener('resize', function () { if (note.classList.contains('is-open')) alignToFrame(); });

    function esc(s) {
        return String(s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    // The restaurant checkout (/send-order) and the goods checkout (POST {slug}/checkout, JSON).
    function isSendOrder(u) { return typeof u === 'string' && (u.indexOf('/send-order') !== -1 || /\/checkout(\?|$)/.test(u)); }

    function show(payload) {
        try {
            var j = (typeof payload === 'string') ? JSON.parse(payload) : payload;
            if (!j) return;
            var lines = [];
            var warn = false;
            if (j.coupon_declined) { lines.push('<p>' + esc(I18N.coupon) + '</p>'); warn = true; }
            if (j.dropped_items && j.dropped_items.length) {
                lines.push('<p>' + esc(I18N.dropped) + ' ' + j.dropped_items.map(esc).join('، ') + '</p>');
                warn = true;
            }
            if (j.wallet_ignored) { lines.push('<p>' + esc(I18N.wallet) + '</p>'); warn = true; }
            /* P1-23: a coupon / loyalty reward refused because the order holds medicines in a market that blocks
               promotions — the server's translated reason. */
            if (j.promotions_restricted && j.promotions_message) { lines.push('<p>' + esc(j.promotions_message) + '</p>'); warn = true; }
            /* J27-A2: a 409 — this checkout token already placed a DIFFERENT cart. Themes
               may not show a non-2xx answer, so the translated server message lands here. */
            if (j.conflict && j.message) { lines.push('<p>' + esc(j.message) + '</p>'); warn = true; }
            /* Charge-to-room outcome (C2). The server sets charged_to_room only on a room
               order that opted into the folio tender — absent on every other order, so the
               strict true/false test leaves normal orders untouched. On FAILURE the guest
               must be told the meal is NOT on the room bill (they'd otherwise walk out
               believing it is and the hotel collected nothing); on SUCCESS we confirm it.
               The server's own room_folio_message wins when present; a localized fallback
               covers pre-merge responses that carry only the boolean. */
            if (j.charged_to_room === false) {
                lines.push('<p>' + esc(j.room_folio_message || I18N.chargeFail) + '</p>');
                warn = true;
            } else if (j.charged_to_room === true) {
                lines.push('<p>' + esc(j.room_folio_message || I18N.chargeOk) + '</p>');
            }
            if (!lines.length) return;
            // Let the theme paint its own confirmation first, then layer the notice.
            setTimeout(function () {
                body.innerHTML = lines.join('');
                note.classList.toggle('mbfb-ok', !warn);
                alignToFrame();
                note.classList.add('is-open');
            }, 350);
        } catch (e) {}
    }

    var of = window.fetch;
    if (of) {
        window.fetch = function (input, init) {
            var u = (typeof input === 'string') ? input : (input && input.url);
            var p = of.apply(this, arguments);
            if (isSendOrder(u)) {
                p.then(function (r) { try { r.clone().json().then(show).catch(function () {}); } catch (e) {} return r; }).catch(function () {});
            }
            return p;
        };
    }
    var Xopen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method, url) {
        if (isSendOrder(url)) {
            this.addEventListener('load', function () { show(this.responseText); });
        }
        return Xopen.apply(this, arguments);
    };
})();
</script>
