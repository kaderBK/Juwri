{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Shared storefront hero/promo banner slider. Renders nothing when there are no active
     banners. Self-contained (scoped .mbs- styles + vanilla JS — NO framework dependency, so it
     works in every post_template regardless of whether the theme loads Alpine). --}}
@php $mbsBanners = $menuBanners ?? collect(); @endphp
@if($mbsBanners && count($mbsBanners))
<div class="mbs" data-mbs role="region" aria-label="{{ ___('Promotions') }}">
    <div class="mbs__track">
        @foreach($mbsBanners as $b)
            @php $bUrl = $b->image_url ?? media_url('storage/menu/', $b->image, $store->id ?? null); @endphp
            <{{ $b->link_url ? 'a' : 'div' }} class="mbs__slide" @if($b->link_url) href="{{ storefront_localized_link($b->link_url) }}" target="_blank" rel="noopener" @endif>
                @if((string) ($bUrl) !== '')<img class="mbs__img" src="{{ $bUrl }}" alt="{{ $b->title }}" loading="lazy" data-csp-error="closest-hide" data-csp-closest=".mbs__slide">@endif
                @php
                    /* A banner titled with the store's own name repeats the hero text
                       sitting directly above the slider — show the photo alone. */
                    $bCapDupe = trim((string) $b->title) === trim((string) $store->title);
                @endphp
                @if(($b->title || $b->subtitle) && ! $bCapDupe)
                    <div class="mbs__cap">
                        @if($b->title)<strong class="mbs__title">{{ $b->title }}</strong>@endif
                        @if($b->subtitle)<span class="mbs__sub">{{ $b->subtitle }}</span>@endif
                    </div>
                @endif
            </{{ $b->link_url ? 'a' : 'div' }}>
        @endforeach
    </div>

    @if(count($mbsBanners) > 1)
        <button type="button" class="mbs__nav mbs__nav--prev" data-mbs-prev aria-label="{{ ___('Previous') }}">&#8249;</button>
        <button type="button" class="mbs__nav mbs__nav--next" data-mbs-next aria-label="{{ ___('Next') }}">&#8250;</button>
        <div class="mbs__dots" data-mbs-dots>
            @foreach($mbsBanners as $i => $b)
                <button type="button" class="mbs__dot {{ $i === 0 ? 'is-active' : '' }}" data-mbs-go="{{ $i }}" aria-label="{{ ___('Go to slide') }} {{ $i + 1 }}"></button>
            @endforeach
        </div>
    @endif
</div>

<style nonce="{{ csp_nonce() }}">
    .mbs { position: relative; inline-size: 100%; overflow: hidden; border-radius: 16px; background: #0001; margin-block-end: 16px; }
    .mbs__track { display: flex; transition: transform .45s cubic-bezier(.4,0,.2,1); will-change: transform; }
    .mbs__slide { position: relative; inline-size: 100%; flex: 0 0 100%; display: block; text-decoration: none; }
    .mbs__img { inline-size: 100%; aspect-ratio: 16 / 6; object-fit: cover; display: block; }
    .mbs__cap { position: absolute; inset-block-end: 0; inset-inline: 0; padding: 28px 24px 18px;
        background: linear-gradient(to top, rgba(0,0,0,.6), transparent); color: #fff;
        display: flex; flex-direction: column; gap: 2px; }
    .mbs__title { font-size: clamp(1.1rem, 3vw, 1.8rem); font-weight: 700; }
    .mbs__sub { font-size: clamp(.85rem, 2vw, 1rem); opacity: .92; }
    .mbs__nav { position: absolute; inset-block-start: 50%; transform: translateY(-50%);
        inline-size: 38px; block-size: 38px; border: 0; border-radius: 50%; cursor: pointer; z-index: 2;
        background: rgba(0,0,0,.45); color: #fff; font-size: 22px; line-height: 1; display: grid; place-items: center; }
    .mbs__nav:hover { background: rgba(0,0,0,.7); }
    .mbs__nav--prev { inset-inline-start: 10px; }
    .mbs__nav--next { inset-inline-end: 10px; }
    .mbs__dots { position: absolute; inset-block-end: 2px; inset-inline: 0; display: flex; gap: 0; justify-content: center; z-index: 2; }
    .mbs__dot { position: relative; inline-size: 24px; block-size: 24px; padding: 0; border: 0; background: transparent; cursor: pointer; }
    .mbs__dot::before { content: ""; position: absolute; inset: 8px; border-radius: 50%; background: rgba(255,255,255,.5); transition: inset .2s; }
    .mbs__dot.is-active::before { background: #fff; inset-inline: 2px; border-radius: 4px; }
    /* Tap targets (WCAG 2.5.8: at least 24x24 CSS px). The arrows are 44px; each dot button is a
       24px box and the 8px dot (20px pill when active) is drawn by ::before, so the design is unchanged. */
    .mbs__nav { inline-size: 44px; block-size: 44px; font-size: 24px; }
    @media (max-width: 640px) { .mbs__img { aspect-ratio: 16 / 9; } }
</style>

<script nonce="{{ csp_nonce() }}">
(function () {
    function initMbs(root) {
        if (root.__mbsInit) return;
        root.__mbsInit = true;
        var track = root.querySelector('.mbs__track');
        var slides = root.querySelectorAll('.mbs__slide');
        var dots = root.querySelectorAll('[data-mbs-go]');
        var count = slides.length;
        var index = 0, timer = null, tsx = 0;
        if (count === 0) return;
        // In RTL the flex track lays slides out right-to-left, so slide N sits to the LEFT of
        // slide 0. Advancing must translate the track RIGHT (positive), not left — flip the sign.
        var rtl = getComputedStyle(root).direction === 'rtl';

        function render() {
            track.style.transform = 'translateX(' + (rtl ? '' : '-') + (index * 100) + '%)';
            for (var i = 0; i < dots.length; i++) {
                dots[i].classList.toggle('is-active', i === index);
            }
        }
        function go(i) { index = (i % count + count) % count; render(); }
        function next() { go(index + 1); }
        function prev() { go(index - 1); }
        function play() { if (count > 1 && !timer) { timer = setInterval(next, 6000); } }
        function pause() { if (timer) { clearInterval(timer); timer = null; } }

        var nextBtn = root.querySelector('[data-mbs-next]');
        var prevBtn = root.querySelector('[data-mbs-prev]');
        if (nextBtn) nextBtn.addEventListener('click', function (e) { e.preventDefault(); next(); });
        if (prevBtn) prevBtn.addEventListener('click', function (e) { e.preventDefault(); prev(); });
        for (var d = 0; d < dots.length; d++) {
            (function (i) { dots[i].addEventListener('click', function (e) { e.preventDefault(); go(i); }); })(d);
        }
        root.addEventListener('mouseenter', pause);
        root.addEventListener('mouseleave', play);
        root.addEventListener('touchstart', function (e) { tsx = e.changedTouches[0].clientX; pause(); }, { passive: true });
        root.addEventListener('touchend', function (e) {
            var dx = e.changedTouches[0].clientX - tsx;
            if (Math.abs(dx) > 40) { ((dx < 0) !== rtl) ? next() : prev(); }
            play();
        }, { passive: true });

        render();
        play();
    }
    function initAll() {
        var nodes = document.querySelectorAll('[data-mbs]');
        for (var i = 0; i < nodes.length; i++) { initMbs(nodes[i]); }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
</script>
@endif
