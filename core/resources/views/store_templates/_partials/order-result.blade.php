{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    F2 — shared order-tracking bar. submitOrder already returns `track_url` on every
    successful order and trackOrder renders a real page (falling back to aurora's
    track view for themes that ship none), but legacy bundles ignore `track_url`,
    so a diner completes an order with no way to track it. This shared partial
    (in the layout, like order-field-forwarder / checkout-otp) watches the
    /send-order response and, when it carries `track_url`, renders a fixed bottom
    "Track your order" bar for every theme at once. Stands down when the theme
    already surfaces a native tracker (DOM detection, never an allow-list).
--}}
<style nonce="{{ csp_nonce() }}">
    .mbtrack-bar{
        position:fixed;left:0;right:0;bottom:0;z-index:2147483100;display:none;
        padding:12px 16px calc(12px + env(safe-area-inset-bottom,0px));
        background:var(--brand, var(--theme-color-1, #2563eb));color:#fff;
        font-family:var(--menu-font, inherit);box-shadow:0 -6px 20px rgba(0,0,0,.18);
        align-items:center;justify-content:center;gap:8px;text-align:center;
    }
    .mbtrack-bar.is-open{display:flex;}
    .mbtrack-bar a{color:#fff;font-weight:800;text-decoration:none;font-size:.98rem;}
    .mbtrack-bar__x{
        position:absolute;inset-inline-end:10px;top:50%;transform:translateY(-50%);
        background:transparent;border:0;color:#fff;opacity:.85;font-size:1.2rem;cursor:pointer;line-height:1;
    }
</style>

<div class="mbtrack-bar" id="mbTrackBar" role="status">
    <a href="#" id="mbTrackLink" rel="noopener">{{ ___('Track your order') }} &rarr;</a>
    <button type="button" class="mbtrack-bar__x" id="mbTrackClose" aria-label="{{ ___('Close') }}">&times;</button>
</div>

<script nonce="{{ csp_nonce() }}">
(function () {
    /* Stand down when the theme already renders a track link (the 8+ themes that
       read track_url), so we never double up. Checked at SHOW time, not here:
       this partial is parsed above the content yield, so at parse time the
       theme's own confirmation markup does not exist yet and this check could
       never match. */
    function themeHasTrackLink() {
        var links = document.querySelectorAll('[href*="/track/"], [data-track-url], .order-track-link');
        for (var i = 0; i < links.length; i++) {
            /* Ignore links that live in a recent-orders / order-lookup HISTORY list.
               feast and velvet inject one for guests, and matching those wrongly
               suppressed this bar right after checkout — leaving the guest with only
               the (possibly off-screen) history block. A fresh confirmation link is
               never inside these containers, so themes with a native tracker are
               unaffected. */
            if (!links[i].closest('#ff-recent-orders, #cvLookupResults, #vRecentOrdersBlock, #vRecentOrders, [data-mb-order-history]')) {
                return true;
            }
        }
        return false;
    }

    var bar  = document.getElementById('mbTrackBar');
    var link = document.getElementById('mbTrackLink');
    var xBtn = document.getElementById('mbTrackClose');
    if (!bar || !link) return;
    if (xBtn) xBtn.addEventListener('click', function () { bar.classList.remove('is-open'); });

    function isSendOrder(u) { return typeof u === 'string' && u.indexOf('/send-order') !== -1; }
    function show(payload) {
        try {
            var j = (typeof payload === 'string') ? JSON.parse(payload) : payload;
            if (!j || !j.track_url) { return; }
            /* Give the theme a beat to paint its own confirmation before we
               decide we are needed. */
            setTimeout(function () {
                if (themeHasTrackLink()) { return; }
                link.setAttribute('href', j.track_url);
                bar.classList.add('is-open');
            }, 300);
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
