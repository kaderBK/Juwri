{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<section class="pb-hero" aria-roledescription="carousel" aria-label="{{ ___('Promotions') }}">
    <div class="pb-hero__track" id="pb-hero-track">
        @foreach($slideImages as $i => $img)
            <div class="pb-hero__slide" data-slide="{{ $i }}" aria-hidden="{{ $i === 0 ? 'false' : 'true' }}">
                @if($img)
                    @if((string) ($img) !== '')<img src="{{ $img }}" alt="" loading="{{ $i === 0 ? 'eager' : 'lazy' }}" data-csp-error="parent-class" data-csp-class="is-fallback" data-csp-remove="true">@endif
                @endif
                <div class="pb-hero__fallback pb-hero__fallback--{{ $i % 3 }}"></div>
                <div class="pb-hero__overlay"></div>
                <div class="pb-hero__content">
                    <span class="pb-hero__label">{{ $slideLabels[$i] ?? ___('Special') }}</span>
                    <h2 class="pb-hero__title">{{ $slideTitles[$i] ?? ___('Delicious deal') }}</h2>
                    <span class="pb-hero__cta">{{ $slideCtAs[$i] ?? ___('Order now') }} →</span>
                </div>
                <div class="pb-hero__bar" aria-hidden="true"><span></span></div>
            </div>
        @endforeach
    </div>
    <div class="pb-hero__dots" id="pb-hero-dots">
        @foreach($slideImages as $i => $img)
            <button type="button" data-slide="{{ $i }}" aria-label="{{ ___('Slide') }} {{ $i + 1 }}" aria-current="{{ $i === 0 ? 'true' : 'false' }}"></button>
        @endforeach
    </div>
</section>
