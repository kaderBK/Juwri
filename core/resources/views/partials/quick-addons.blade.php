{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    Quick add-ons BODY block — the P1-23 cookie-consent banner + Tawk.to live chat (consent-gated).

    Ported from the legacy classic includes/addons.blade.php (rendered before
    </body>), which the legacy rebuild dropped along with the Google Analytics tag
    (that one lives in partials/analytics.blade.php, in <head>). Settings are read
    from config('settings.*') so this resolves the same on marketing / dashboard /
    storefront. Inline scripts carry the CSP nonce and use vanilla JS (no jQuery
    dependency, which the public themes don't load) + addEventListener (no inline
    handlers) so it stays valid under an enforced CSP.
--}}
@php
    $__tawk = config('settings.tawk_to');
    $__tawkOn = is_object($__tawk) ? ($__tawk->status ?? null) : (is_array($__tawk) ? ($__tawk['status'] ?? null) : null);
    $__tawkLink = is_object($__tawk) ? ($__tawk->chat_link ?? null) : (is_array($__tawk) ? ($__tawk['chat_link'] ?? null) : null);
@endphp

{{-- P1-23: the consent banner (replaces the old accept-only cookie box; the owner app is excluded inside). --}}
@include('partials.cookie-consent', ['cookieConsentForce' => $cookieConsentForce ?? false])

{{-- PCI DSS 6.4.3: never on a payment page (no business need for a third-party chat script next to payment). --}}
@if (!empty($__tawkOn) && $__tawkOn != '0' && !empty($__tawkLink) && \Juwri\Core\Pci\PaymentPages::current(request()) === null)
    @php $__tawkId = str_replace('https://tawk.to/chat/', '', $__tawkLink); @endphp
    {{-- P1-23: the chat widget sets visitor-tracking cookies → starts only after analytics consent. --}}
    <script type="text/plain" data-cookie-consent="analytics" nonce="{{ csp_nonce() }}">
        var Tawk_API = Tawk_API || {}, Tawk_LoadStart = new Date();
        @auth
            Tawk_API.visitor = { name: @json(auth()->user()->name ?? ''), email: @json(auth()->user()->email ?? '') };
        @endauth
        (function () {
            var s1 = document.createElement('script'), s0 = document.getElementsByTagName('script')[0];
            s1.async = true;
            s1.src = 'https://embed.tawk.to/' + @json($__tawkId);
            s1.charset = 'UTF-8';
            s1.setAttribute('crossorigin', '*');
            s0.parentNode.insertBefore(s1, s0);
        })();
    </script>
@endif
