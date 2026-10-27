{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<section class="mk-sec">
    <div class="mk-wrap">
        <div class="mk-cta">
            <span class="mk-eyebrow">{{ ___('Get started') }}</span>
            <h2 class="mk-h2">{{ ___('Ready to go live today?') }}</h2>
            <p>{{ ___('Set up your menu, QR codes and online ordering in minutes — free to start, no card required.') }}</p>
            <div class="mk-actions">
                <a href="{{ route('register') }}" class="ui-btn">{{ ___('Start free') }}</a>
                @if (Route::has('pricing') && ! request()->routeIs('pricing'))
                    <a href="{{ route('pricing') }}" class="ui-btn ui-btn--ghost">{{ ___('See pricing') }}</a>
                @endif
            </div>
            <p class="mk-cta__trust">{{ ___('No setup fee · No commission on orders · Cancel anytime') }}</p>
        </div>
    </div>
</section>
