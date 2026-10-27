{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Home hero. Optional copy overrides arrive as $block['content'] (site page
     blocks): headline, subline, cta_url, cta_label. --}}
@php
    $mkOv = $block['content'] ?? [];
    $mkHeadline = (string) ($mkOv['headline'] ?? '');
    $mkNouns = [___('your restaurant'), ___('your store'), ___('your café'), ___('your hotel'), ___('any business')];
    $mkWidest = \Juwri\Core\Support\Views\PublicSiteViewHelpers::widestString($mkNouns);
    $mkMark = '@@NOUN@@';
    [$mkPre, $mkPost] = array_pad(explode($mkMark, ___('QR menu & WhatsApp ordering — :noun, live in minutes.', ['noun' => $mkMark]), 2), 2, '');
    $mkChips = [
        ['qr', ___('QR Menu')], ['orders', ___('Online Ordering')], ['chat', ___('WhatsApp Orders')],
        ['kitchen', ___('Kitchen Display')], ['calendar', ___('Reservations')], ['star', ___('Loyalty')],
        ['chart', ___('Analytics')], ['sparkle', ___('AI Menu Studio')], ['delivery', ___('Delivery Apps')],
    ];
@endphp
<section class="mk-hero">
    <div class="mk-wrap">
        <p class="mk-hero__badge">
            <span>{{ ___('QR menus and online ordering') }}</span>
            <strong>{{ ___('Trusted by businesses worldwide') }}</strong>
        </p>
        <h1 class="mk-h1">
            @if ($mkHeadline !== '')
                {{ $mkHeadline }}
            @else
                {{ $mkPre }}<span class="mk-rotator" data-hero-rotator data-words='@json($mkNouns)'><span class="mk-rotator__sizer" aria-hidden="true">{{ $mkWidest }}</span><span class="mk-rotator__word">{{ $mkNouns[0] }}</span></span>{{ $mkPost }}
            @endif
        </h1>
        <p class="mk-lead">
            {{ ($mkOv['subline'] ?? '') !== '' ? $mkOv['subline'] : ___('Start free with QR menu and online ordering; POS and ZATCA on paid plans.') }}
        </p>
        <div class="mk-actions">
            <a href="{{ ($mkOv['cta_url'] ?? '') !== '' ? $mkOv['cta_url'] : route('register') }}" class="ui-btn">{{ ($mkOv['cta_label'] ?? '') !== '' ? $mkOv['cta_label'] : ___('Start free') }}</a>
            <a href="{{ route('publicView', 'crave-demo') }}" target="_blank" rel="noopener" class="ui-btn ui-btn--secondary">{{ ___('See how it works') }}</a>
        </div>
        <p class="mk-note">{{ ___('No card required · No setup fee · Cancel anytime') }}</p>
        @if (Route::has('no-commission'))
            <p class="mk-note"><a href="{{ route('no-commission') }}">{{ ___('0% commission on every order') }}</a></p>
        @endif
        <ul class="mk-hero__chips" aria-label="{{ ___('Features') }}">
            @foreach ($mkChips as [$mkIcon, $mkLabel])
                <li><x-ui.icon :name="$mkIcon" size="16" />{{ $mkLabel }}</li>
            @endforeach
        </ul>
    </div>
</section>
