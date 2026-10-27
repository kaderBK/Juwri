{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle §4: the campaign hero — a full-bleed image under a dark scrim, the title, a sub line and one white button that scrolls to the
     products. The image is decorative (the title says the same). --}}
@php
    $__s = $vm['store'];
    $__title = ($vm['theme']['texts']['hero_title'] ?? null) ?: ($__s['tagline'] ?? null);
    $__sub = $vm['theme']['texts']['hero_subtitle'] ?? null;
    $__cta = ($vm['theme']['texts']['hero_cta'] ?? null) ?: $kit->s('Shop now');
@endphp
@if (! empty($__s['cover']) && $__title && empty($listing['filtered']))
<section class="c-hero" data-section="hero" aria-labelledby="hero-title"><img class="c-hero__img" src="{{ $__s['cover']['src'] }}" alt="" width="1440" height="440" decoding="async" fetchpriority="high"><div class="c-hero__scrim"></div>
    <div class="c-hero__text"><h2 class="c-hero__title" id="hero-title">{{ $kit->flow($__title) }}</h2>@if ($__sub)<p class="c-hero__sub">{{ $kit->flow($__sub) }}</p>@endif<a class="c-hero__cta" href="#shop">{{ $__cta }}</a></div>
</section>
@endif
