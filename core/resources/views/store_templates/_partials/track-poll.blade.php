{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- T2: ONE track poller shared by all 17 themes. Replaces both the per-theme
     5s status setInterval AND the prep-eta partial's own 15s poll — the JSON
     returns status + prep_eta + token_number in a single response, so one
     request feeds everything. This owns the whole lifecycle:
       - 5s while the tab is visible, stretched to 30s when hidden (a backgrounded
         tab must still catch "ready" for the T5 notification, so it SLOWS, never
         fully stops),
       - immediate refetch the moment the tab returns to the foreground,
       - STOP entirely once the order is terminal (completed/cancelled/delivered)
         after one final update — a finished order must not poll forever (the very
         reason throttle:public-poll exists),
       - live prep-eta line + live board token, so a late board assignment or a
         fresh estimate appears without a manual reload.
     Each theme registers its own updateUI via window.mbTrackPoll(cb). The poll is
     armed once, after DOMContentLoaded, so every theme has registered first. --}}
<script nonce="{{ csp_nonce() }}">
(function () {
    "use strict";
    if (window.mbTrackPoll) { return; }
    var TERMINAL = { completed: 1, cancelled: 1, delivered: 1 };
    var cbs = [];
    var timer = null;
    var stopped = false;
    var started = false;
    var url = window.location.href;

    function paintPrepEta(res) {
        var line = document.getElementById('prepEtaLine');
        var text = document.getElementById('prepEtaText');
        if (!line || !text) { return; }
        if (res && res.prep_eta && res.prep_eta.line) {
            text.textContent = res.prep_eta.line;   // textContent, never innerHTML
            line.hidden = false;
        } else {
            line.hidden = true;
        }
    }
    function paintToken(res) {
        // Late board assignment: the number is server-rendered once, then kept live.
        var n = document.getElementById('orderTokenNumber');
        if (n && res && res.token_number) { n.textContent = res.token_number; }
    }
    function stop() { stopped = true; if (timer) { clearInterval(timer); timer = null; } }
    function arm(ms) {
        if (timer) { clearInterval(timer); timer = null; }
        if (stopped) { return; }
        timer = setInterval(fetchOnce, ms);
    }
    function handle(res) {
        if (!res) { return; }
        paintPrepEta(res);
        paintToken(res);
        for (var i = 0; i < cbs.length; i++) { try { cbs[i](res); } catch (e) {} }
        // One final update already applied above — now stop for terminal orders.
        if (res.status && TERMINAL[res.status]) { stop(); }
    }
    function fetchOnce() {
        return fetch(url, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); }).then(handle).catch(function () {});
    }

    document.addEventListener('visibilitychange', function () {
        if (stopped) { return; }
        if (document.hidden) {
            arm(30000);                 // slow down, do not stop — still catch "ready"
        } else {
            fetchOnce();                // catch up immediately on return
            arm(5000);
        }
    });

    window.mbTrackPoll = function (cb) { if (typeof cb === 'function') { cbs.push(cb); } };

    function start() {
        if (started) { return; }
        started = true;
        arm(document.hidden ? 30000 : 5000);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
</script>

{{-- NOTIF-CENTER guest source: remember THIS order on the device so the storefront
     notification bell can show it later (rev/notif-center-spec.md). Logged-in diners
     get a server inbox; guests get this store-scoped localStorage list, written from
     the one page every order funnels through. The scope + key match the menu page's
     window.MB_STORE / mb-notify.js reader, so no per-theme wiring is needed. The live
     status label is kept fresh via the shared poll above (window.mbTrackPoll).
     A9: GUESTS ONLY — a logged-in diner's orders live in the server inbox; writing
     them here would leak their orders to the next guest on a shared device. --}}
@if (! auth('customer')->check())
@php
    // Localized status → label, resolved server-side (ready wording depends on type).
    $__roLabels = [
        'unpaid'           => ___('Awaiting Payment'),
        'new'              => ___('Received'),
        'pending'          => ___('Received'),
        'pending_approval' => ___('Awaiting approval'),
        'preparing'        => ___('Preparing'),
        'ready'            => $order->type === 'delivery' ? ___('On the Way') : ___('Ready'),
        'out_for_delivery' => ___('On the Way'),
        'delivered'        => ___('Delivered'),
        'completed'        => ___('Completed'),
        'call_bill'        => ___('Bill requested'),
        'cancelled'        => ___('Cancelled'),
    ];
    $__roScope = (string) ($store->id ?? $store->slug ?? 'default');
    $__roTotal = store_price_symbol($order->price ?? $order->total, $store->id);
    $__roAt    = optional($order->created_at)->toIso8601String();
@endphp
<script nonce="{{ csp_nonce() }}">
(function () {
    "use strict";
    var SCOPE  = @json($__roScope);
    var KEY    = 'mb_recent_orders_' + SCOPE;
    var URL    = @json(url()->current());
    var ID     = @json((string) $order->id);
    var TITLE  = @json(___('Order')) + ' #' + ID;
    var TOTAL  = @json($__roTotal);
    var AT     = @json($__roAt);
    var LABELS = @json($__roLabels);
    if (!URL || !ID) { return; }

    function label(s) {
        return LABELS[s] || (s ? String(s).charAt(0).toUpperCase() + String(s).slice(1).replace(/_/g, ' ') : '');
    }
    function readList() { try { return JSON.parse(localStorage.getItem(KEY) || '[]') || []; } catch (e) { return []; } }
    function writeList(l) { try { localStorage.setItem(KEY, JSON.stringify(l.slice(0, 10))); } catch (e) {} }

    var last = null;
    function upsert(status) {
        if (status === last) { return; }
        // A8: a LIVE status change stamps the event time (client clock is fine)
        // so the bell shows "Ready · 2m", not the order's placement age. The
        // first call (page load) keeps the stored/created_at time.
        var changed = last !== null;
        last = status;
        var list = readList();
        var at = AT;
        for (var i = 0; i < list.length; i++) { if (list[i] && list[i].url === URL) { at = list[i].at || AT; break; } }
        if (changed) { at = new Date().toISOString(); }
        list = list.filter(function (o) { return o && o.url !== URL; });
        list.unshift({ url: URL, title: TITLE, body: label(status) + (TOTAL ? ' · ' + TOTAL : ''), at: at, status: status });
        writeList(list);
    }

    upsert(@json((string) $order->status));
    if (typeof window.mbTrackPoll === 'function') {
        window.mbTrackPoll(function (res) { if (res && res.status) { upsert(res.status); } });
    }
})();
</script>
@endif
