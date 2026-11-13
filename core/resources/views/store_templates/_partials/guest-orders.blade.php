{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Device-local guest order history — shared across every storefront theme.

  Order history at /my-orders is auth:customer only, so a guest (customer_auth_mode
  = 'guest', or simply not signed in) never sees the order they just placed. This
  keeps a small per-store list on THIS device so the Orders screen can show a
  guest their own recent orders (with a Track link), independent of any account.

  It is a convenience cache, never an authority: it lives only in this browser,
  holds no money math the server didn't return, and is capped. A signed-in diner
  still gets the real, cross-device list from the server; this only fills the gap
  for guests. Per-store key so two stores on one device never mix.

  API (window.mbGuestOrders):
    .list(storeId)           -> array, newest first: {token,trackUrl,total,itemCount,itemIds,at}
    .save(storeId, order)    -> upsert by token (dedupe), unshift, cap 20
    .remove(storeId, token)  -> drop one
--}}
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    var CAP = 20;
    function key(storeId) { return 'mb-guest-orders-' + String(storeId); }
    function readAll(storeId) {
        try { return JSON.parse(window.localStorage.getItem(key(storeId)) || '[]') || []; }
        catch (e) { return []; }
    }
    function writeAll(storeId, arr) {
        try { window.localStorage.setItem(key(storeId), JSON.stringify(arr)); } catch (e) {}
    }
    window.mbGuestOrders = {
        list: function (storeId) {
            return readAll(storeId).filter(function (o) { return o && o.token; });
        },
        save: function (storeId, order) {
            if (!order || !order.token) { return; }
            var all = readAll(storeId).filter(function (o) { return o && o.token && o.token !== order.token; });
            all.unshift({
                token: order.token,
                trackUrl: order.trackUrl || null,
                total: order.total || '',
                itemCount: order.itemCount || 0,
                itemIds: (order.itemIds || []).slice(0, 50),
                at: order.at || Date.now()
            });
            writeAll(storeId, all.slice(0, CAP));
        },
        remove: function (storeId, token) {
            writeAll(storeId, readAll(storeId).filter(function (o) { return o && o.token !== token; }));
        }
    };
})();
</script>
