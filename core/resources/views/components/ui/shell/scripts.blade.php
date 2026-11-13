{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.shell.scripts /> — end-of-body scripts every owner page shares:
  poll guard, notification sounds, phone table cards, native-app bridge,
  client-error beacon and the admin's quick add-ons (cookie notice, chat).
--}}
@php
    
@endphp
{{-- Poll guard: every live poller passes its fetch response through LivePoll.guard(res).
     A 401/419 (session ended under an open tab) stops all polling and reloads once, so the
     auth redirect runs instead of a dead session being retried forever. --}}
<script nonce="{{ csp_nonce() }}" data-turbo-eval="false">
    window.LivePoll = window.LivePoll || {
        stopped: false,
        guard: function (res) {
            if (this.stopped) { return false; }
            if (res && (res.status === 401 || res.status === 419)) {
                this.stopped = true;
                try { window.location.reload(); } catch (e) {}
                return false;
            }
            return true;
        },
    };
</script>

{{-- Notification sounds for the current store (the bell uses them; the sound settings page re-inits). --}}
<script src="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/js/howler.min.js') }}" data-turbo-eval="false"></script>
<script src="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/js/notify-sounds.js') }}" data-turbo-eval="false"></script>
<script nonce="{{ csp_nonce() }}" data-turbo-eval="false">
    window.NotifySounds && NotifySounds.init(@js(function_exists('notification_sounds_config') ? notification_sounds_config(function_exists('dashboard_current_store') ? (dashboard_current_store()?->id) : null) : null));
</script>

{{-- Phones: a data table whose last column holds row actions is shown as one card per row.
     Opt out with .no-cards. Idempotent; runs again after every Turbo visit. --}}
<script nonce="{{ csp_nonce() }}">
    (function () {
        function hasRowActions(table) {
            if (!table.tHead || table.classList.contains('no-cards') || table.classList.contains('rooms-table')) { return false; }
            return !!table.querySelector('tbody tr td:last-child :is(.btn, button, a[href], form, [data-copy-link])');
        }
        function apply() {
            document.querySelectorAll('.table-responsive > table.table').forEach(function (t) {
                if (!t.classList.contains('table-cards') && hasRowActions(t)) { t.classList.add('table-cards'); }
            });
            document.querySelectorAll('table.table-cards').forEach(function (t) {
                var head = t.tHead && t.tHead.rows[0];
                if (!head) { return; }
                var labels = Array.prototype.map.call(head.cells, function (c) { return c.textContent.trim(); });
                Array.prototype.forEach.call(t.tBodies, function (body) {
                    Array.prototype.forEach.call(body.rows, function (row) {
                        Array.prototype.forEach.call(row.cells, function (cell, i) {
                            if (!cell.hasAttribute('data-th')) { cell.setAttribute('data-th', labels[i] || ''); }
                        });
                    });
                });
            });
        }
        window.mbTableCards = apply;
        apply();
        if (!window.__uiTableCards) {
            window.__uiTableCards = true;
            document.addEventListener('DOMContentLoaded', apply);
            document.addEventListener('turbo:load', apply);
        }
    })();
</script>

@if (\Juwri\Core\Support\OwnerApp::inApp())
    {{-- Native app bridge components (vendored Stimulus + hotwire-native-bridge). --}}
    <script type="module" nonce="{{ csp_nonce() }}" src="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-app/bridge.js') }}" data-turbo-eval="false"></script>
@endif

{{-- Client-error beacon: window errors / rejections are reported (deduped, max 12 a page) so
     breakage in the browser shows up in logs. Errors from browser extensions also suggest a
     private window once. Listeners live on window, so they bind once and survive Turbo. --}}
<script nonce="{{ csp_nonce() }}" data-turbo-eval="false">
    (function () {
        if (window.__dashErrBeacon) { return; }
        window.__dashErrBeacon = 1;
        var endpoint = @js(route('dash.clientError'));
        var hint = @js(___('A browser extension may be interfering with this page. If a button seems stuck, open it in a private/incognito window.'));
        var seen = {}, sent = 0, hinted = false;
        var fromExtension = function (s) { return /(chrome|moz|safari-web)-extension:\/\//.test(s || ''); };
        function report(kind, message, source, stack) {
            try {
                if (!message || sent >= 12) { return; }
                var key = (String(message) + '|' + (source || '')).slice(0, 200);
                if (seen[key]) { return; }
                seen[key] = 1; sent++;
                var body = JSON.stringify({
                    message: String(message).slice(0, 300), kind: kind,
                    source: String(source || '').slice(0, 300), stack: String(stack || '').slice(0, 600),
                    path: location.pathname,
                });
                if (navigator.sendBeacon) { navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/json' })); }
                if ((fromExtension(stack) || fromExtension(source)) && !hinted && window.dashToast) {
                    hinted = true;
                    window.dashToast(hint, { type: 'error' });
                }
            } catch (e) { /* reporting must never break the page */ }
        }
        window.addEventListener('error', function (e) {
            report('error', e.message || (e.error && e.error.message), e.filename, e.error && e.error.stack);
        });
        window.addEventListener('unhandledrejection', function (e) {
            var r = e.reason;
            report('rejection', (r && r.message) || String(r), '', r && r.stack);
        });
    })();
</script>

@include('partials.quick-addons')
