{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Site block: features_overview — "From menu to loyalty, one login": five
     core features as kit cards with a link to /features. --}}
@php
    $sbItems = [
        ['qr', ___('QR menus'), ___('Generate a QR code for every table. Diners scan, browse, and order from their own phone — no app to install.')],
        ['orders', ___('Online ordering'), ___('Accept orders straight from the menu. Cash, card, or pay online — no commission on a single order.')],
        ['chart', ___('Live analytics'), ___('Track scans, orders, top items, and conversion in real time from one simple dashboard.')],
        ['star', ___('Loyalty and offers'), ___('Reward repeat diners with points and offers that keep them coming back.')],
        ['world', ___('Multi-language menus'), ___('Translate your entire menu into 11 languages with a single click.')],
    ];
@endphp
<section class="mk-sec sb-block" data-site-block="features_overview">
    <div class="mk-wrap">
        <div class="mk-sec__head">
            <h2 class="mk-h2">{{ ___('From menu to loyalty, one login.') }}</h2>
            <p class="mk-lead">{{ ___('From QR menus to online ordering, analytics, and loyalty — run your whole restaurant from one dashboard.') }}</p>
        </div>
        <div class="mk-grid mk-grid--3">
            @foreach ($sbItems as [$sbIcon, $sbTitle, $sbBody])
                <article class="mk-card">
                    <span class="mk-ico" aria-hidden="true"><x-ui.icon :name="$sbIcon" size="22" /></span>
                    <h3>{{ $sbTitle }}</h3>
                    <p>{{ $sbBody }}</p>
                </article>
            @endforeach
        </div>
        @if (Route::has('features'))
            <div class="mk-actions"><a href="{{ route('features') }}" class="ui-btn ui-btn--secondary">{{ ___('Learn more') }}</a></div>
        @endif
    </div>
</section>
