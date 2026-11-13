{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    Platform Google Analytics (admin → Settings → Quick add-ons → Google Analytics).

    Ported from the legacy classic theme's includes/addons.blade.php, which the
    legacy rebuild dropped: the measurement id is still stored and shown in the
    admin form, but nothing injected the gtag tag any more, so no pageviews or
    realtime visitors were recorded after the upgrade.

    Reads config('settings.google_analytics') (SettingsServiceProvider json-decodes
    it to a stdClass on every request) so it resolves identically on the marketing
    site, the dashboard and the storefront — independent of how the per-view
    $settings object is shaped.

    CSP: both tags carry the nonce. A nonce authorises the external loader even
    when script-src is locked to 'self' 'nonce-…', so this stays valid if the
    report-only storefront/dashboard CSP is ever enforced. (Sending hits still
    needs connect-src to include https://www.google-analytics.com — a separate,
    owner-owned CSP change, and moot while CSP is report-only.)
--}}
@php
    $__ga = config('settings.google_analytics');
    $__gaId = is_object($__ga) ? ($__ga->measurement_id ?? null) : (is_array($__ga) ? ($__ga['measurement_id'] ?? null) : null);
    $__gaOn = is_object($__ga) ? ($__ga->status ?? null) : (is_array($__ga) ? ($__ga['status'] ?? null) : null);
@endphp
@if (!empty($__gaOn) && $__gaOn != '0' && !empty($__gaId))
    {{-- PERF: gtag is the biggest 3rd-party (≈179 KB, ~151 ms main-thread). Load
         it lazily — on the first user interaction, or after an idle/3.5 s
         fallback so bounces are still counted — instead of during initial load.
         Keeps analytics working while taking it off the critical path. The
         injected loader carries the nonce so it stays valid under a locked CSP. --}}
    {{-- P1-23: inert until the visitor accepts analytics cookies (partials/cookie-consent starts it). --}}
    <script type="text/plain" data-cookie-consent="analytics" nonce="{{ csp_nonce() }}">
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        (function () {
            var id = @json($__gaId), nonce = @json(csp_nonce()), started = false;
            var evs = ['scroll', 'pointerdown', 'keydown', 'touchstart'];
            function boot() {
                if (started) return; started = true;
                evs.forEach(function (e) { window.removeEventListener(e, boot); });
                gtag('js', new Date());
                gtag('config', id);
                var s = document.createElement('script');
                s.async = true;
                if (nonce) { s.setAttribute('nonce', nonce); s.nonce = nonce; }
                s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(id);
                document.head.appendChild(s);
            }
            evs.forEach(function (e) { window.addEventListener(e, boot, { passive: true, once: true }); });
            if ('requestIdleCallback' in window) requestIdleCallback(boot, { timeout: 3500 });
            else setTimeout(boot, 3500);
        })();
    </script>
@endif
