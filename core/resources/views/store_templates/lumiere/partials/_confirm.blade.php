{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Confirm — the real order was already created server-side by the time
     this shows (lm-boot.js only reveals it after CHECKOUT_ROUTE returns
     success). It auto-continues to the REAL order-tracking page
     (store_templates/lumiere/track.blade.php, via `data.redirect`) after a
     short pause, with an immediate manual link too — no fabricated status
     timeline lives here (that only exists on the real track page, driven by
     the real order status). --}}
<section class="lm-screen" data-screen="confirm" hidden>
    <div class="lm-confwrap">
        <svg class="lm-checkc" viewBox="0 0 100 100" aria-hidden="true">
            <circle cx="50" cy="50" r="45"/>
            <path d="M30 52l14 14 26-30"/>
        </svg>
        <h2>{{ ___('Order placed') }}</h2>
        <p>{{ ___('Your order is confirmed and on its way.') }}</p>
        <div class="lm-orderno" id="lmOrderNo"></div>

        <div class="lm-conf-actions">
            <a class="lm-btn lm-btn-dark" id="lmTrackOrderLink" href="#">{{ ___('Track your order') }}</a>
            <button type="button" class="lm-btn lm-btn-ghost" data-nav="home">{{ ___('Continue shopping') }}</button>
        </div>
    </div>
</section>
