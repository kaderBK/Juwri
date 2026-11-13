{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- F3 · story viewer — full-screen overlay, populated client-side from
     GET {{ url('r/'.$store->id.'/stories') }} (see partials/_home.blade.php for
     the bubble rail that opens this and partials/_script.blade.php for the
     fetch/render/timer logic). Ships hidden + empty in the markup so a no-JS
     visitor never sees a broken shell; lm-boot.js hides the whole bubble rail
     too when the feed comes back empty. Sits at the .lm-app root (like the
     onboarding/sign-in overlays) rather than inside a single .lm-screen,
     since it can be opened from the home screen at any time. --}}
<div class="lm-storyviewer" id="lmStoryViewer" hidden role="dialog" aria-modal="true" aria-label="{{ ___('Stories') }}">
    <div class="lm-sv-bars" id="lmSvBars"></div>
    <div class="lm-sv-top">
        <div class="lm-sv-meta">
            <span class="lm-sv-avatar" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($store->title, 0, 1)) }}</span>
            <span class="lm-sv-name">{{ $store->title }}</span>
        </div>
        <button type="button" class="lm-sv-close" id="lmSvClose" aria-label="{{ ___('Close') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
    </div>
    <div class="lm-sv-stage">
        <img class="lm-sv-media" id="lmSvMedia" alt="" data-csp-error="hide">
        <div class="lm-sv-caption" id="lmSvCaption" hidden></div>
    </div>
    <div class="lm-sv-foot">
        <a class="lm-btn lm-btn-dark lm-btn-block lm-sv-cta" id="lmSvCta" href="#" hidden></a>
    </div>
</div>
