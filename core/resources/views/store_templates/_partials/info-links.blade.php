{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Shared storefront info-page footer — Terms / Privacy / About / Help links for
  EVERY visitor, signed-in or not.

  The owner's info pages already exist as public routes, but the only links to
  them lived on the logged-in profile page, so a guest browsing the menu had no
  way to reach them. This footer surfaces the filled pages on the menu itself.

  Same mechanism as the offers strip: server-rendered at the top of <main>, then
  mbAnchor relocates it in-flow to the BOTTOM of the theme's menu (placeBottom),
  palette-matched via ink(). Renders NOTHING until placement succeeds (so it
  never flashes at the top of the page) and NOTHING at all when the owner has
  filled no pages — an empty footer reads as broken.

  Scoped to the 17 modern templates, exactly like the offers strip: the shared
  layout also serves ~40 legacy templates carrying most live stores, and this
  injects markup into <main>; those themes are left untouched.
--}}
@php
    $mbiPost = $store ?? null;
    $mbiPages = (is_object($mbiPost) && ! empty($mbiPost->id)) ? storefront_info_pages($mbiPost->id) : [];
    $mbiTemplate = (is_object($mbiPost) && ! empty($mbiPost->id)) ? app(\App\Services\StorefrontThemeService::class)->resolveSaved($mbiPost) : '';
    $mbiModern = in_array($mbiTemplate, \Juwri\Modules\Storefront\Services\Theme\KeptTemplates::MODERN, true);
@endphp

{{-- A4 (upstream b80061178): every modern store footer carries a
     "Report this store" link (phishing / abuse), even when the owner filled no pages. --}}
@if($mbiModern && is_object($mbiPost) && ! empty($mbiPost->id))
<footer class="mbi-foot" id="mbInfoFoot" data-mb-pending aria-label="{{ ___('Information') }}">
    <nav class="mbi-links">
        @foreach($mbiPages as $mbiSlug => $mbiLabel)
            <a class="mbi-link" href="{{ storefront_localized_link(route('store.infoPage', [$mbiPost->id, $mbiSlug])) }}">{{ $mbiLabel }}</a>
        @endforeach
        @if(Route::has('contact'))
            <a class="mbi-link mbi-report" href="{{ route('contact', ['report' => $mbiPost->slug]) }}" rel="nofollow noopener" target="_blank">{{ ___('Report this store') }}</a>
        @endif
        {{-- P1-23: re-open the cookie banner to change the visitor's choice. --}}
        <button type="button" class="mbi-link mbi-cookies" data-cookie-settings>{{ __('site.cookie.settings') }}</button>
    </nav>
</footer>

{{-- Scoped styles (`mbi-` prefix). No surface of its own — like the offers strip,
     it cannot know what it will sit on, so it paints nothing and inherits the
     theme's own background; text uses the ink mbAnchor bakes from that surface.
     Generous bottom padding clears the floating dock every modern theme fixes to
     the bottom of the viewport. --}}
<style @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
.mbi-foot{padding:24px 16px calc(104px + env(safe-area-inset-bottom,0px));font-family:var(--tf-body,inherit);text-align:center}
.mbi-foot[data-mb-pending]{display:none}
.mbi-foot[hidden]{display:none!important}
.mbi-links{display:flex;flex-wrap:wrap;gap:8px 10px;justify-content:center;align-items:center}
.mbi-link{
    text-decoration:none;font-size:12.5px;font-weight:600;line-height:1.2;
    color:var(--mb-ink,var(--tc-text,inherit));opacity:.82;
    padding:7px 14px;border-radius:999px;
    border:1px solid color-mix(in srgb, var(--mb-ink, currentColor) 24%, transparent);
    transition:opacity .15s ease;
}
.mbi-link:hover,.mbi-link:focus-visible{opacity:1}
.mbi-report{border-style:dashed;opacity:.6;font-weight:500}
.mbi-cookies{background:none;font-family:inherit;cursor:pointer}
</style>

<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    var foot = document.getElementById('mbInfoFoot');
    // mbAnchor is a sibling partial (theme-anchor), included before this one.
    if (!foot || !window.mbAnchor) return;

    // Placement, ink and the settle cadence are shared with the other injected
    // sections — see _partials/theme-anchor.blade.php. placeBottom drops the
    // footer at the end of the theme's menu; reveal only once it lands (until
    // then it is parked at the top of <main>, which must never paint).
    function sync() {
        if (mbAnchor.placeBottom(foot)) foot.removeAttribute('data-mb-pending');
        mbAnchor.ink(foot);
    }
    mbAnchor.onSettled(sync, foot);
})();
</script>
@endif
