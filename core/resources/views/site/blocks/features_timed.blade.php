{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Site block: features_timed — the zero-commission block: checklist, sign-up
     and pricing links, and a kit panel standing in for the product shots. --}}
<section class="mk-sec sb-block" data-site-block="features_timed">
    <div class="mk-wrap">
        <div class="mk-split">
            <div>
                <span class="mk-eyebrow">{{ ___('QR menus · ordering · POS — zero commission') }}</span>
                <h2 class="mk-h2">{{ ___('More orders. Zero commission.') }}</h2>
                <p class="mk-muted">{{ ___('Digital menus, online ordering and a full POS — live the same afternoon. Delivery apps take 30%. We take none.') }}</p>
                <ul class="mk-checks">
                    <li>{{ ___('Keep 100% of every order — flat subscription, no cut') }}</li>
                    <li>{{ ___('Photograph a paper menu, AI builds the digital one') }}</li>
                    <li>{{ ___('QR & WhatsApp ordering straight from the table') }}</li>
                    <li>{{ ___('POS register, kitchen screens and delivery included') }}</li>
                    <li>{{ ___('Free to start — paid plans only when you grow') }}</li>
                </ul>
                <div class="mk-actions sb-actions--start">
                    <a href="{{ route('register') }}" class="ui-btn">{{ ___('Start free') }}</a>
                    @if (Route::has('pricing'))
                        <a href="{{ route('pricing') }}" class="ui-btn ui-btn--secondary">{{ ___('See pricing') }}</a>
                    @endif
                </div>
                <ul class="mk-chips">
                    <li>✓ {{ ___('No card required') }}</li>
                    <li>✓ {{ ___('Live in minutes') }}</li>
                    <li>✓ {{ ___('Cancel anytime') }}</li>
                </ul>
            </div>
            <div class="mk-panel" aria-hidden="true">
                <div class="mk-panel__head"><x-ui.icon name="orders" /> {{ ___('The POS register with a live cart') }}</div>
                <div class="mk-panel__row">
                    <span>🔔 {{ ___('New order — table 7') }}</span>
                    <span class="mk-panel__note">{{ ___('2× Classic Deluxe · just now') }}</span>
                </div>
                <div class="mk-panel__row">
                    <span>▲ {{ ___('+38% repeat orders') }}</span>
                    <span class="mk-panel__note">{{ ___('with loyalty switched on') }}</span>
                </div>
                <div class="mk-panel__row">
                    <span>{{ ___('Commission per order') }}</span>
                    <x-ui.pill tone="success">0%</x-ui.pill>
                </div>
                <div class="mk-panel__foot">{{ ___('A storefront menu on a phone') }}</div>
            </div>
        </div>
    </div>
</section>
