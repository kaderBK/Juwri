{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Shared telemetry for every <x-spotlight.*> component. Pushed once per page.

  An impression fires when the surface actually scrolls into view, not when it
  renders — a card below the fold that nobody saw is not an impression, and the
  whole point of this table is that we can trust what it says. Clicks and
  dismissals are delegated, so components added by Alpine later still report.

  The endpoint ignores unknown keys/actions and is rate-limited; failures are
  swallowed on purpose — telemetry must never break the page it measures.
--}}
@once
@if (Route::has('spotlight.beacon'))
@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
(function () {
    var URL = @js(route('spotlight.beacon'));
    var TOKEN = @js(csrf_token());

    function send(el, action) {
        var key = el.getAttribute('data-spotlight-key');
        var surface = el.getAttribute('data-spotlight-surface');
        if (!key || !surface) return;
        try {
            fetch(URL, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': TOKEN, 'Accept': 'application/json'},
                body: JSON.stringify({key: key, surface: surface, action: action}),
                keepalive: true,
            }).catch(function () {});
        } catch (e) { /* never break the page we are measuring */ }
    }

    // Impressions: only once each, and only when actually seen.
    function watch() {
        var nodes = document.querySelectorAll('[data-spotlight-key]:not([data-spotlight-seen])');
        if (!nodes.length) return;
        if (!('IntersectionObserver' in window)) {
            nodes.forEach(function (el) { el.setAttribute('data-spotlight-seen', '1'); send(el, 'impression'); });
            return;
        }
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                var el = entry.target;
                if (el.getAttribute('data-spotlight-seen')) return;
                el.setAttribute('data-spotlight-seen', '1');
                send(el, 'impression');
                io.unobserve(el);
            });
        }, {threshold: 0.5});
        nodes.forEach(function (el) { io.observe(el); });
    }

    document.addEventListener('DOMContentLoaded', watch);
    document.addEventListener('spotlight:rescan', watch);

    // Clicks and dismissals, delegated.
    document.addEventListener('click', function (e) {
        var cta = e.target.closest('[data-spotlight-cta]');
        if (cta) {
            var host = cta.closest('[data-spotlight-key]');
            if (host) send(host, 'click');
        }
        var x = e.target.closest('[data-spotlight-dismiss]');
        if (x) {
            var el = x.closest('[data-spotlight-key]');
            if (!el) return;
            send(el, 'dismiss');
            el.remove();   // server-side memory makes this stick across reloads
        }
    });
})();
</script>
@endpush
@endif
@endonce
