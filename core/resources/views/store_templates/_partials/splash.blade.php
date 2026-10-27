{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    T2 · S1 — Shared splash / onboarding layer for store_templates.

    Config-driven via theme_setting($store->id, 'splash.*'). Customizer v2 splits
    the old single flag in two: 'splash.enabled' says whether the store shows a
    splash at all, 'splash.style' picks whose design renders it. This layer is
    the `shared` style; the theme's own intro is `theme` (the default), so the
    two can never stack. Neutral styling inherits each theme's palette through
    the --brand / --theme-color-* CSS vars already emitted by layout.blade.php.

    Keys read:
      splash.enabled              bool   (gate; default true)
      splash.style                enum   theme|shared (this layer = shared)
      splash.image                string (URL/path; falls back to restaurant logo)
      splash.title                string (falls back to restaurant name)
      splash.subtitle             string
      splash.duration             int ms (default 2300, hard cap 5000)
      splash.show_language_picker bool   (renders inline language switcher)

    Session-gate JS: sessionStorage key `mb_splash_<storeId>` — shows once per
    session, auto-dismiss after duration, Skip control, prefers-reduced-motion
    → instant dismiss.
--}}
@php
    $__spEnabled = theme_setting($store->id, 'splash.enabled', true)
        && theme_setting($store->id, 'splash.style', 'theme') === 'shared';
@endphp
@if($__spEnabled)
@php
    $__spImage    = trim((string) theme_setting($store->id, 'splash.image', ''));
    $__spLogo     = media_url('storage/store/logo/', $store->main_image, $store->id ?? null);
    $__spImageSrc = $__spImage !== '' ? $__spImage : $__spLogo;

    $__spTitle    = trim((string) theme_setting($store->id, 'splash.title', ''));
    if ($__spTitle === '') { $__spTitle = (string) $store->title; }

    $__spSubtitle = trim((string) theme_setting($store->id, 'splash.subtitle', ''));

    $__spDuration = (int) theme_setting($store->id, 'splash.duration', 2300);
    if ($__spDuration < 0)    { $__spDuration = 0; }
    if ($__spDuration > 5000) { $__spDuration = 5000; }

    $__spLangPicker = (bool) theme_setting($store->id, 'splash.show_language_picker', false);
    $__spKey        = 'mb_splash_' . (int) $store->id;

    /* Splash v2 — optional look + behaviour overrides. Every one of these
       defaults to "unset", and unset renders exactly what shipped before:
       brand background, white text, once per session, no button. */
    $__spBg        = trim((string) theme_setting($store->id, 'splash.background', ''));
    $__spBgImage   = trim((string) theme_setting($store->id, 'splash.background_image', ''));
    $__spTextColor = trim((string) theme_setting($store->id, 'splash.text_color', ''));
    $__spCta       = trim((string) theme_setting($store->id, 'splash.cta_label', ''));
    $__spFrequency = (string) theme_setting($store->id, 'splash.frequency', 'session');
    if (!in_array($__spFrequency, ['session', 'always', 'daily', 'once'], true)) {
        $__spFrequency = 'session';
    }
    // Colour values reach a style attribute — hex only, same charset the
    // customizer validator enforces; anything else falls back to unset.
    if ($__spBg !== '' && !preg_match('/^#[0-9a-fA-F]{3,8}$/', $__spBg)) { $__spBg = ''; }
    if ($__spTextColor !== '' && !preg_match('/^#[0-9a-fA-F]{3,8}$/', $__spTextColor)) { $__spTextColor = ''; }

    /* Splash v2.1 — entrance animation + logo size/shape. Whitelisted enums;
       anything unexpected snaps back to today's rendering (pop / medium /
       rounded), so the defaults stay pixel-identical. */
    $__spAnim = (string) theme_setting($store->id, 'splash.animation', 'pop');
    if (!in_array($__spAnim, ['pop', 'fade', 'rise', 'none'], true)) { $__spAnim = 'pop'; }

    $__spLogoSize = (string) theme_setting($store->id, 'splash.logo_size', 'm');
    $__spLogoPx   = ['s' => 88, 'm' => 120, 'l' => 160][$__spLogoSize] ?? 120;

    $__spLogoShape  = (string) theme_setting($store->id, 'splash.logo_shape', 'rounded');
    $__spLogoRadius = ['rounded' => '24px', 'round' => '50%', 'square' => '6px'][$__spLogoShape] ?? '24px';
@endphp

<style nonce="{{ csp_nonce() }}">
    .mb-splash{
        position:fixed;inset:0;z-index:2147483000;
        display:flex;flex-direction:column;align-items:center;justify-content:center;
        gap:16px;padding:24px;text-align:center;
        background:var(--theme-color-1, var(--brand, #111));
        color:#fff;opacity:1;transition:opacity .45s ease;
        -webkit-tap-highlight-color:transparent;
    }
    .mb-splash[hidden]{display:none !important;}
    .mb-splash.mb-splash--out{opacity:0;pointer-events:none;}
    .mb-splash__logo{
        width:var(--mb-splash-logo,120px);height:var(--mb-splash-logo,120px);
        max-width:52vw;max-height:52vw;
        object-fit:cover;border-radius:var(--mb-splash-logo-r,24px);
        box-shadow:0 12px 40px rgba(0,0,0,.28);background:#fff;
        animation:mb-splash-pop .5s ease both;
    }
    /* Splash v2.1 — entrance animation variants. 'pop' is the shipped default
       (logo pop only); the others animate the whole content stack. */
    .mb-splash--anim-fade .mb-splash__logo{animation:none;}
    .mb-splash--anim-fade > *{animation:mb-splash-fade .6s ease both;}
    .mb-splash--anim-rise .mb-splash__logo{animation:none;}
    .mb-splash--anim-rise > *{animation:mb-splash-rise .55s ease both;}
    .mb-splash--anim-none .mb-splash__logo{animation:none;}
    .mb-splash__title{
        margin:0;font-family:var(--menu-font, inherit);
        font-size:clamp(1.4rem,6vw,2rem);font-weight:800;line-height:1.15;
    }
    .mb-splash__subtitle{
        margin:0;font-size:clamp(.9rem,3.6vw,1.05rem);opacity:.85;max-width:32ch;
    }
    .mb-splash__langs{
        display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-top:4px;
    }
    .mb-splash__lang{
        display:inline-block;padding:6px 14px;border-radius:999px;
        border:1px solid rgba(255,255,255,.5);
        color:#fff;text-decoration:none;font-size:.85rem;
    }
    .mb-splash__lang.is-active{background:#fff;color:var(--theme-color-1,#111);border-color:#fff;}
    .mb-splash__skip{
        position:absolute;top:calc(env(safe-area-inset-top,0px) + 14px);
        inset-inline-end:16px;
        appearance:none;-webkit-appearance:none;cursor:pointer;
        background:rgba(255,255,255,.14);color:#fff;
        border:1px solid rgba(255,255,255,.4);border-radius:999px;
        padding:6px 16px;font-size:.85rem;font-family:inherit;
    }
    /* Splash v2 */
    .mb-splash__bgimg{
        position:absolute;inset:0;width:100%;height:100%;
        object-fit:cover;z-index:-1;
    }
    .mb-splash__bgimg + .mb-splash__scrim{
        position:absolute;inset:0;z-index:-1;
        background:rgba(0,0,0,.45);
    }
    .mb-splash__cta{
        appearance:none;-webkit-appearance:none;cursor:pointer;
        margin-top:6px;padding:12px 32px;border:0;border-radius:999px;
        background:#fff;color:var(--theme-color-1,#111);
        font-family:inherit;font-size:1rem;font-weight:700;
        box-shadow:0 8px 24px rgba(0,0,0,.22);
    }
    @keyframes mb-splash-pop{from{transform:scale(.82);opacity:0;}to{transform:scale(1);opacity:1;}}
    @keyframes mb-splash-fade{from{opacity:0;}to{opacity:1;}}
    @keyframes mb-splash-rise{from{transform:translateY(18px);opacity:0;}to{transform:translateY(0);opacity:1;}}
    @media (prefers-reduced-motion: reduce){
        .mb-splash{transition:none;}
        .mb-splash__logo,
        .mb-splash--anim-fade > *,
        .mb-splash--anim-rise > *{animation:none;}
    }
</style>

<div class="mb-splash mb-splash--anim-{{ $__spAnim }}" id="mbSplash" role="dialog" aria-modal="true" aria-label="{{ $__spTitle }}"
     style="--mb-splash-logo:{{ $__spLogoPx }}px;--mb-splash-logo-r:{{ $__spLogoRadius }};{{ $__spBg !== '' ? 'background:'.$__spBg.';' : '' }}{{ $__spTextColor !== '' ? 'color:'.$__spTextColor.';' : '' }}">
    @if($__spBgImage !== '')
        @if((string) ($__spBgImage) !== '')<img class="mb-splash__bgimg" src="{{ $__spBgImage }}" alt="" aria-hidden="true" data-csp-error="remove">@endif
        <span class="mb-splash__scrim" aria-hidden="true"></span>
    @endif

    <button type="button" class="mb-splash__skip" id="mbSplashSkip">{{ ___('Skip') }}</button>

    @if($__spImageSrc)
        @if((string) ($__spImageSrc) !== '')<img class="mb-splash__logo" src="{{ $__spImageSrc }}" alt="{{ $__spTitle }}">@endif
    @endif

    <h1 class="mb-splash__title" data-tc-text="splash.title">{{ $__spTitle }}</h1>

    @if($__spSubtitle !== '')
        <p class="mb-splash__subtitle" data-tc-text="splash.subtitle">{{ $__spSubtitle }}</p>
    @endif

    @if($__spLangPicker && !empty($languages) && count($languages) > 1)
        <div class="mb-splash__langs">
            @foreach($languages as $__spLang)
                <a class="mb-splash__lang {{ current_language()->code === $__spLang->code ? 'is-active' : '' }}"
                   href="{{ lang_url($__spLang->code) }}">{{ $__spLang->native ?? $__spLang->name }}</a>
            @endforeach
        </div>
    @endif

    @if($__spCta !== '')
        <button type="button" class="mb-splash__cta" id="mbSplashCta" data-tc-text="splash.cta_label">{{ $__spCta }}</button>
    @endif
</div>

<script nonce="{{ csp_nonce() }}">
(function(){
    var el = document.getElementById('mbSplash');
    if (!el) return;

    var KEY       = @json($__spKey);
    var DURATION  = @json($__spDuration);
    var FREQUENCY = @json($__spFrequency); // always | session | daily | once
    var HAS_CTA   = @json($__spCta !== '');
    var reduce    = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* Splash v2 — frequency gate. 'session' keeps the original sessionStorage
       behaviour byte-for-byte; 'daily' stamps a local date, 'once' a permanent
       flag, 'always' skips the gate entirely. Storage errors (private mode)
       fail open to showing the splash. */
    function today(){ var d = new Date(); return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate(); }
    var seen = false;
    try {
        if (FREQUENCY === 'session')     { seen = window.sessionStorage.getItem(KEY) === '1'; }
        else if (FREQUENCY === 'daily')  { seen = window.localStorage.getItem(KEY) === today(); }
        else if (FREQUENCY === 'once')   { seen = window.localStorage.getItem(KEY) === '1'; }
    } catch (e) {}

    if (seen) { el.parentNode && el.parentNode.removeChild(el); return; }

    try {
        if (FREQUENCY === 'session')     { window.sessionStorage.setItem(KEY, '1'); }
        else if (FREQUENCY === 'daily')  { window.localStorage.setItem(KEY, today()); }
        else if (FREQUENCY === 'once')   { window.localStorage.setItem(KEY, '1'); }
    } catch (e) {}

    function dismiss(){
        if (!el) return;
        if (reduce) {
            el.parentNode && el.parentNode.removeChild(el);
            el = null;
            return;
        }
        el.classList.add('mb-splash--out');
        var node = el;
        el = null;
        window.setTimeout(function(){ node.parentNode && node.parentNode.removeChild(node); }, 500);
    }

    var skip = document.getElementById('mbSplashSkip');
    if (skip) skip.addEventListener('click', dismiss);

    var cta = document.getElementById('mbSplashCta');
    if (cta) cta.addEventListener('click', dismiss);

    /* With a CTA the splash is a landing moment, not an interstitial — it waits
       for the tap instead of auto-dismissing (reduced-motion still dismisses
       instantly; the content is fully reachable behind Skip/CTA either way). */
    if (!HAS_CTA || reduce) {
        window.setTimeout(dismiss, reduce ? 0 : DURATION);
    }
})();
</script>
@endif
