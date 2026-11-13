{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    Shared "clear saved data on this device" engine (behaviour only — each theme
    renders its OWN styled control/row and its own confirm affordance, then calls
    this to wipe the local cart / favourites / saved addresses and reload).

    Usage from a theme:

        window.mbClearDeviceData({
            keys:    ['theme-cart-key', 'theme-favs-key', 'theme-addr-key'], // this theme's own keys
            slug:    @json($store->slug ?? ''),   // sweeps store-scoped keys (optional)
            storeId: @json($store->id ?? ''),      // clears cv_reorder_<id> (optional)
            confirm: '…',        // if set, window.confirm(this) gates the wipe (optional)
            reload:  true,       // reload after clearing (default true)
            delay:   600,        // ms before reload (optional)
            done:    function(){} // callback after clearing (optional)
        });

    Never touches theme/appearance preference keys (anything matching /theme/i),
    so clearing data does not flip the visitor's light/dark choice.
--}}
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    if (window.mbClearDeviceData) { return; }
    /* Cart/order keys shared across the platform's checkout engines. */
    var SHARED_PREFIXES = ['cv_reorder_', 'pf-cart-', 'pf-favs-', 'ff_recent_orders_'];

    window.mbClearDeviceData = function (cfg) {
        cfg = cfg || {};
        if (cfg.confirm) { try { if (!window.confirm(cfg.confirm)) { return false; } } catch (e) { /* ignore */ } }

        var kill = {};
        (cfg.keys || []).forEach(function (k) { if (k) { kill[k] = 1; } });
        if (cfg.storeId) { kill['cv_reorder_' + cfg.storeId] = 1; }

        try {
            for (var i = window.localStorage.length - 1; i >= 0; i--) {
                var k = window.localStorage.key(i);
                if (!k) { continue; }
                if (/theme|appearance|prefers-color/i.test(k)) { continue; } /* keep light/dark choice */
                var hit = false;
                for (var p = 0; p < SHARED_PREFIXES.length; p++) {
                    if (k.indexOf(SHARED_PREFIXES[p]) === 0) { hit = true; break; }
                }
                if (!hit && cfg.slug && k.indexOf(cfg.slug) >= 0) { hit = true; } /* store-scoped cart/fav/addr */
                if (hit) { kill[k] = 1; }
            }
        } catch (e) { /* best-effort */ }

        try { Object.keys(kill).forEach(function (k) { window.localStorage.removeItem(k); }); } catch (e) { /* best-effort */ }

        if (typeof cfg.done === 'function') { cfg.done(); }
        if (cfg.reload !== false) { setTimeout(function () { window.location.reload(); }, cfg.delay || 600); }
        return true;
    };
})();
</script>
