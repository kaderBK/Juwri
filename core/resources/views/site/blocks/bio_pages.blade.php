{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Site block: bio_pages — the link-in-bio product (presets, blocks,
     insights, AI) with a kit phone mock-up (pure HTML/CSS). --}}
@php
    $sbCaps = [
        [___('42 designer presets'), ___('Pick a look, drop in your logo, and your brand colors flow through every block.')],
        [___('Blocks that sell'), ___('Menu highlights, opening hours, socials, delivery links and a tip jar — reordered by drag.')],
        [___('Click insights'), ___('See what gets tapped, by country and referrer, with CSV export.')],
        [___('AI writes and translates it'), ___('Generate the whole page from your menu, then translate it into every language you serve.')],
    ];
@endphp
<section class="mk-sec sb-block" data-site-block="bio_pages">
    <div class="mk-wrap">
        <div class="mk-split mk-split--rev">
            <div>
                <span class="mk-eyebrow">{{ ___('Bio pages') }}</span>
                <h2 class="mk-h2">{{ ___('Your link-in-bio, but it takes orders.') }}</h2>
                <ul class="mk-checks sb-caps">
                    @foreach ($sbCaps as [$sbTitle, $sbBody])
                        <li><strong>{{ $sbTitle }}</strong><span>{{ $sbBody }}</span></li>
                    @endforeach
                </ul>
                <p class="mk-small sb-foot">{{ ___('Plus a public directory listing with city and cuisine pages — one more road that ends at your menu.') }}</p>
                <div class="mk-actions sb-actions--start">
                    <a href="{{ route('register') }}" class="ui-btn">{{ ___('Claim your bio page') }}</a>
                </div>
            </div>
            <div class="sb-phone" aria-hidden="true">
                <div class="sb-phone__screen">
                    <span class="sb-phone__avatar">NK</span>
                    <b>{{ ___("Nonna's Kitchen") }}</b>
                    <small>{{ ___('Fresh pasta · Downtown') }}</small>
                    <span class="sb-phone__btn sb-phone__btn--primary">{{ ___('Order now') }}</span>
                    <span class="sb-phone__btn">{{ ___('See the menu') }}</span>
                    <span class="sb-phone__btn">{{ ___('Reserve a table') }}</span>
                    <span class="sb-phone__btn">{{ ___('WhatsApp us') }}</span>
                    <small class="sb-phone__tip">{{ ___('Leave a tip') }} ♥</small>
                </div>
            </div>
        </div>
    </div>
</section>
