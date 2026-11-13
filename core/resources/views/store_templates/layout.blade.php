{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!DOCTYPE html>
<html lang="{{ get_lang() }}" dir="{{ optional(current_language())->direction ?? 'ltr' }}">
<head>
    <meta charset="utf-8">
    {{-- F7: allow pinch-zoom (WCAG 1.4.4 Resize Text). `maximum-scale=1.0` +
         `user-scalable=0` blocked zoom for diners reading small item text/prices
         on phones — Android still honours it. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    {{-- SEO Growth (empty-menu): a store with no visible items renders an empty
         page Google flags as a Soft 404 — keep it out of the index until it has a
         menu. Links stay followable so a later-filled menu is still discovered. --}}
    <meta name="robots" content="{{ store_has_menu($store->id) ? 'index, follow' : 'noindex, follow' }}">
    @php
        // ISS-C-401: brand-aware title/OG suffix. On a reseller (white-label)
        // host use the reseller brand; on the platform host keep the localized
        // platform brand string. Prevents the platform brand leaking into a
        // reseller storefront's <title>/og:title.
        $__brandSuffix = active_brand_reseller() ? brand_title() : ___('alt_barcode');
    @endphp

@if($settings->userlangsel)
    @if(config('settings.include_language_code'))
    {{-- SEO Growth (locale-scoping): only the languages THIS store genuinely
         publishes (default + real translations). A store that lists a language
         but never translated it renders identical content, so advertising it made
         Google crawl duplicate locale variants. --}}
    {!! seo_hreflang_tags_for_store($store->id) !!}
    @else
    @foreach(store_indexable_langs($store->id) as $__hl)
        <link rel="alternate" href="{{ lang_url($__hl) }}" hreflang="{{ $__hl }}"/>
    @endforeach
    @endif
@endif

    <meta name="author" content="{{ $settings->site_title }}">
    <meta name="title" content="{{ ___('the_qr_menu_for') }} {{ $store->title }} | {{ $__brandSuffix }}">
    {{-- Descriptions may be stored as numeric HTML entities; strip_tags leaves them
         intact and Blade re-escapes the `&`, shipping literal `&#1581;…` to search
         engines and social crawlers. Decode before shortening. --}}
    @php
        // CC run — share cards, platform-wide (serves all 17 modern themes).
        // Subtitle wins the description when the store has one; otherwise the
        // existing description-derived text is used UNCHANGED, so a store with
        // no subtitle renders a byte-identical head to before.
        $__metaSub = trim((string) ($store->sub_title ?? ''));
        $metaDesc = $__metaSub !== ''
            ? text_shorting(html_entity_decode($__metaSub, ENT_QUOTES), 150)
            : text_shorting(html_entity_decode(strip_tags((string) $store->description), ENT_QUOTES), 150);
        // og/twitter image = the real cover when one exists (same F10 guard as
        // the LCP preload below), else the logo — identical URL to before.
        $ogCover = (! empty($store->cover_image) && $store->cover_image !== 'default.png')
            ? media_url('storage/store/cover/', $store->cover_image, $store->id ?? null)
            : null;
        $ogImage = $ogCover ?: media_url('storage/store/logo/', $store->main_image, $store->id ?? null);
    @endphp
    <meta name="description" content="{{ $metaDesc }} | {{ $__brandSuffix }}" />
    <meta name="keywords" content="{{___('meta_keywords')}}">
    <meta property="profile:username" content="{{ $store->slug }}" />
    <link rel="shortcut icon" type="image/x-icon" href="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}">
    {{-- No hardcoded type: logos may be SVG/WEBP/PNG and a wrong declared type
         makes some browsers drop the icon. --}}
    <link rel="icon" href="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}">
    {{-- SEO Growth (locale-scoping): canonical self-references only when the current
         locale is one the store really publishes; a phantom/non-offered locale
         variant consolidates to the store's default URL instead of self. --}}
    @php $__canonical = store_canonical_url($store->id); @endphp
    <link rel="canonical" href="{{ $__canonical }}" />
    <meta property="og:title" content="{{ ___('the_qr_menu_for') }} {{ $store->title }} | {{ $__brandSuffix }}" />
    <meta property="og:url" content="{{ $__canonical }}" />
    <meta property="og:description" content="{{ $metaDesc }} | {{ $__brandSuffix }}" />
    <meta property="og:image:secure_url" content="{{ $ogImage }}" />
    <meta property="og:image" content="{{ $ogImage }}" />
    <meta name="twitter:card" content="{{ $ogCover ? 'summary_large_image' : 'summary' }}" />
    <meta name="twitter:title" content="{{ ___('the_qr_menu_for') }} {{ $store->title }} | {{ $__brandSuffix }}" />
    <meta name="twitter:image" content="{{ $ogImage }}" />
    <meta name="twitter:url" content="{{ $__canonical }}" />
    {{-- F10: preload the real LCP image (the cover/hero), not the logo. Legacy
         themes apply the cover late via JS `data-background-image`, so preloading
         the actual cover with fetchpriority=high is a direct LCP win. Emit no
         image preload when the store has no cover (never preload the default). --}}
    @if(!empty($store->cover_image) && $store->cover_image !== 'default.png')
    <link rel="preload" as="image" fetchpriority="high" href="{{ media_url('storage/store/cover/', $store->cover_image, $store->id ?? null) }}" />
    @endif
    <title>{{ ___('the_qr_menu_for') }} {{ $store->title }} | {{ $__brandSuffix }}</title>
    <link rel="apple-touch-icon" sizes="180x180" href="{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}">

    <meta name="theme-color" content="{{ $store->color }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-touch-fullscreen" content="yes">
    <meta name="HandheldFriendly" content="True">
    {{-- #25: PWA manifest --}}
    <link rel="manifest" href="{{ route('publicView.manifest', $store->slug) }}">
    {{-- P3-03: a kit theme registers its own per-store worker (/sw-store.js, storefront-core partial); the shared
         menu worker below is for every other theme. --}}
    @php $__legacySw = ! \Juwri\Modules\Storefront\Services\Theme\KitThemes::isKit((string) (store_options($store->id)->store_template ?? '')); @endphp
    @if($__legacySw)
    <script nonce="{{ csp_nonce() }}">
        if ('serviceWorker' in navigator) {
            {{-- F14-1: the ?v= (appinfo.version) versions the SW's cache without turning
                 the static SW into a route — a deploy bump changes the SW URL, so
                 activate() purges the old cache automatically. --}}
            {{-- Scope "/" is shared with the owner dashboard's worker (/dashboard-sw.js,
                 PWA-SPEC §3.2). One registration per scope: registering here would
                 replace it in an owner's browser — dropping their order alerts and
                 caching dashboard pages. That worker leaves storefront pages alone and
                 shows order pushes too, so keep it. --}}
            {{-- P1-20 (J28): one worker per store address — "/" on the store's own origin, "/{slug}" on the
                 shared path-form host (so stores never share a worker or its cache). A legacy "/" menu
                 worker left on the shared host is removed. --}}
            var mbSwScope = @json(store_sw_scope($store));
            navigator.serviceWorker.getRegistration('/').then(function (reg) {
                var current = reg && (reg.active || reg.waiting || reg.installing);
                var rootScope = reg && reg.scope === location.origin + '/';
                if (mbSwScope !== '/') {
                    if (rootScope && current && current.scriptURL.indexOf('/sw-menu.js') !== -1) { reg.unregister(); }
                } else if (current && current.scriptURL.indexOf('/dashboard-sw.js') !== -1) { return; }
                return navigator.serviceWorker.register('/sw-menu.js?v={{ config('appinfo.version') }}', { scope: mbSwScope });
            }).catch(function(){});
        }
    </script>
    @endif

    <script nonce="{{ csp_nonce() }}" async>
        var themecolor = @json($store->color);
        var siteurl = @json(route('home'));
    </script>
    {{-- CSP-safe replacement for legacy inline onerror/onload/onchange handlers.
         Capture listeners are installed in <head> so image failures that happen
         while the body is still parsing are handled too. Templates describe only
         a small, allow-listed action through data-csp-* attributes; no code stored
         in markup is evaluated. --}}
    <script nonce="{{ csp_nonce() }}">
        (function () {
            function closest(element, selector) {
                return selector && element.closest ? element.closest(selector) : null;
            }

            function hide(element) {
                if (element) element.style.display = 'none';
            }

            document.addEventListener('error', function (event) {
                var element = event.target;
                if (!element || !element.dataset || !element.dataset.cspError) return;

                switch (element.dataset.cspError) {
                    case 'remove': element.remove(); break;
                    case 'hide': hide(element); break;
                    case 'opacity': element.style.opacity = element.dataset.cspValue || '0'; break;
                    case 'visibility': element.style.visibility = 'hidden'; break;
                    case 'parent-hide': hide(element.parentElement); break;
                    case 'closest-hide': hide(closest(element, element.dataset.cspClosest)); break;
                    case 'fallback':
                        if (element.dataset.cspFallback && element.src !== element.dataset.cspFallback) {
                            element.src = element.dataset.cspFallback;
                        }
                        break;
                    case 'fallback-class':
                        element.classList.add(element.dataset.cspClass);
                        if (element.dataset.cspFallback && element.src !== element.dataset.cspFallback) {
                            element.src = element.dataset.cspFallback;
                        }
                        break;
                    case 'closest-class':
                        var closestNode = closest(element, element.dataset.cspClosest);
                        if (closestNode) closestNode.classList.add(element.dataset.cspClass);
                        if (element.dataset.cspRemove === 'true') element.remove();
                        break;
                    case 'parent-bg':
                        if (element.parentElement) element.parentElement.style.backgroundColor = element.dataset.cspValue || '';
                        break;
                    case 'hide-show':
                        hide(element);
                        var shown = element.parentElement && element.dataset.cspShow ? element.parentElement.querySelector(element.dataset.cspShow) : null;
                        if (shown) shown.style.display = 'flex';
                        break;
                    case 'parent-class':
                        if (element.parentElement) element.parentElement.classList.add(element.dataset.cspClass);
                        var shownInParent = element.parentElement && element.dataset.cspShow ? element.parentElement.querySelector(element.dataset.cspShow) : null;
                        if (shownInParent) shownInParent.style.display = 'flex';
                        if (element.dataset.cspRemove === 'true') element.remove();
                        break;
                    case 'shimmer-error':
                        if (element.parentElement) {
                            element.parentElement.classList.remove(element.dataset.cspShimmer);
                            if (element.dataset.cspClass) element.parentElement.classList.add(element.dataset.cspClass);
                        }
                        if (element.dataset.cspOpacity === 'true') element.style.opacity = '0';
                        if (element.dataset.cspRemove === 'true') element.remove();
                        break;
                    case 'replace-parent-text':
                        if (element.parentElement) element.parentElement.textContent = element.dataset.cspText || '';
                        break;
                    case 'replace-emoji':
                        var emoji = document.createElement('span');
                        emoji.className = 'emoji';
                        emoji.textContent = element.dataset.cspText || '';
                        element.replaceWith(emoji);
                        break;
                    case 'replace-text':
                        element.replaceWith(document.createTextNode(element.dataset.cspText || ''));
                        break;
                    case 'scoop-image-fail':
                        if (typeof window.scoopImgFail === 'function') window.scoopImgFail(element);
                        break;
                    case 'lime-image-fail':
                        if (typeof window.__limeImgFallback === 'function') window.__limeImgFallback(element);
                        break;
                    case 'carousel-fail':
                        if (window.mbCarousel) window.mbCarousel._fail(element);
                        break;
                }
            }, true);

            // Graceful fallback for a missing menu photo. Many /storage/menu/*
            // files were lost (see storage:audit-media), so those <img>s 404 and
            // the browser shows a broken-image icon on the storefront. The legacy
            // themes never opted their menu <img>s into the data-csp-* handler
            // above, so catch any menu photo that failed to load and was not
            // already handled, and swap it to an inline placeholder tile (a data:
            // URI — no extra request, cannot 404). Scoped to /storage/menu/ URLs
            // so it never disturbs a JS-populated (src="") or self-handled image.
            var MB_MENU_PHOTO_PLACEHOLDER = @json('data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 400"><rect width="400" height="400" fill="#dcdae2"/><g fill="none" stroke="#a9a7b4" stroke-width="9" stroke-linejoin="round" stroke-linecap="round"><rect x="118" y="140" width="164" height="120" rx="14"/><circle cx="158" cy="180" r="15"/><path d="M130 246l46-42 34 28 30-26 30 28"/></g></svg>'));
            document.addEventListener('error', function (event) {
                var image = event.target;
                if (!image || image.tagName !== 'IMG' || !image.dataset) return;
                if (image.dataset.cspError || image.dataset.mbPhotoFallback) return;
                if ((image.getAttribute('src') || '').indexOf('/storage/menu/') === -1) return;
                image.dataset.mbPhotoFallback = '1';
                image.classList.add('mb-photo-missing');
                image.src = MB_MENU_PHOTO_PLACEHOLDER;
            }, true);

            document.addEventListener('load', function (event) {
                var element = event.target;
                if (!element || !element.dataset || element.dataset.cspLoad !== 'remove-parent-class') return;
                // data-csp-load-class names the shimmer to drop on load; data-csp-class stays free for the error handler's own class
                if (element.parentElement) element.parentElement.classList.remove(element.dataset.cspLoadClass || element.dataset.cspClass);
            }, true);

            document.addEventListener('focus', function (event) {
                if (event.target && event.target.dataset && event.target.dataset.cspFocus === 'unlock') {
                    event.target.removeAttribute('readonly');
                }
                if (event.target && event.target.dataset && event.target.dataset.cspFocus === 'skip-link') {
                    event.target.style.top = '0';
                }
            }, true);

            document.addEventListener('blur', function (event) {
                if (event.target && event.target.dataset && event.target.dataset.cspBlur === 'skip-link') {
                    event.target.style.top = '-40px';
                }
            }, true);

            document.addEventListener('change', function (event) {
                var element = event.target;
                if (!element || !element.dataset) return;
                if (element.dataset.cspBranchSwitch === 'true') {
                    var url = new URL(window.location.href);
                    if (element.value) url.searchParams.set('branch_id', element.value);
                    else url.searchParams.delete('branch_id');
                    window.location.href = url.toString();
                    return;
                }
                if (!element.dataset.cspToggle) return;
                var target = document.getElementById(element.dataset.cspToggle);
                if (!target) return;
                if (element.dataset.cspToggleMode === 'hidden') {
                    target.hidden = !element.checked;
                } else {
                    target.style.display = element.checked ? (element.dataset.cspDisplay || 'block') : 'none';
                }
            });

            document.addEventListener('click', function (event) {
                var dismiss = event.target.closest && event.target.closest('[data-csp-remove-id]');
                if (dismiss) {
                    var dismissed = document.getElementById(dismiss.dataset.cspRemoveId);
                    if (dismissed) dismissed.remove();
                    return;
                }
                var galleryThumb = event.target.closest && event.target.closest('[data-csp-gallery-main]');
                if (galleryThumb) {
                    var galleryMain = document.querySelector(galleryThumb.dataset.cspGalleryMain);
                    if (galleryMain) galleryMain.src = galleryThumb.src;
                    return;
                }
                var quantity = event.target.closest && event.target.closest('[data-csp-qty-handler]');
                if (quantity) {
                    var handler = window[quantity.dataset.cspQtyHandler];
                    if (typeof handler === 'function') {
                        handler(parseInt(quantity.dataset.cspIndex, 10), parseInt(quantity.dataset.cspDelta, 10));
                    }
                    return;
                }
                var carouselControl = event.target.closest && event.target.closest('[data-csp-carousel-go],[data-csp-carousel-step]');
                if (carouselControl && window.mbCarousel) {
                    if (carouselControl.dataset.cspCarouselGo !== undefined) {
                        window.mbCarousel.go(carouselControl, parseInt(carouselControl.dataset.cspCarouselGo, 10));
                    } else {
                        window.mbCarousel.step(carouselControl, parseInt(carouselControl.dataset.cspCarouselStep, 10));
                    }
                    return;
                }
                var opener = event.target.closest && event.target.closest('[data-csp-open-sheet]');
                if (opener) {
                    var sheet = document.getElementById(opener.dataset.cspOpenSheet);
                    var scrim = document.getElementById(opener.dataset.cspScrim);
                    [sheet, scrim].forEach(function (node) {
                        if (!node) return;
                        node.hidden = false;
                        node.classList.add('is-open');
                    });
                    /* Tag the scrim with the sheet it backs so the close paths below can
                       dismiss the pair. This open handler is GLOBAL — any theme uses
                       data-csp-open-sheet — so the close MUST be global too: a theme's
                       own scrim handler only knows sheets IT opened (picnic tracks its
                       own `openSheet` var), leaving a layout-opened sheet with no way to
                       close on those themes (the "Choose branch" popup could not shut). */
                    if (sheet && scrim) scrim.setAttribute('data-csp-scrim-for', sheet.id);
                    return;
                }

                /* Close: an explicit [data-csp-close-sheet] control, or a click on the
                   managed scrim (the backdrop) itself. */
                var closer = event.target.closest && event.target.closest('[data-csp-close-sheet]');
                var scrimHit = (event.target.hasAttribute && event.target.hasAttribute('data-csp-scrim-for')) ? event.target : null;
                if (closer || scrimHit) {
                    var cSheet = closer
                        ? document.getElementById(closer.dataset.cspCloseSheet)
                        : document.getElementById(scrimHit.getAttribute('data-csp-scrim-for'));
                    var cScrim = closer ? document.getElementById(closer.dataset.cspScrim || '') : scrimHit;
                    cspCloseSheet(cSheet, cScrim);
                    return;
                }
            });

            /* Shared close for a layout-opened sheet+scrim pair. Mirrors the theme
               sheet convention: drop is-open (lets CSS transition out), then hide after
               it settles. Reused by the scrim/close-button path above and Escape. */
            function cspCloseSheet(sheet, scrim) {
                [sheet, scrim].forEach(function (node) { if (node) node.classList.remove('is-open'); });
                if (scrim) scrim.removeAttribute('data-csp-scrim-for');
                window.setTimeout(function () {
                    [sheet, scrim].forEach(function (node) {
                        if (node && !node.classList.contains('is-open')) node.hidden = true;
                    });
                }, 420);
            }

            document.addEventListener('keydown', function (event) {
                if (event.key !== 'Escape' && event.key !== 'Esc') return;
                var scrim = document.querySelector('[data-csp-scrim-for]');
                if (!scrim) return;
                cspCloseSheet(document.getElementById(scrim.getAttribute('data-csp-scrim-for')), scrim);
            });
        }());
    </script>
    <!--Loop for Theme Color codes-->
    <style nonce="{{ csp_nonce() }}">
        :root{@php
        /* sscanf("#%02x%02x%02x") cannot read 3-digit hex: "#111" yielded
           rgba(17,1,,1) — invalid CSS that silently killed EVERY --theme-color-*
           rule depending on it, and var() fallbacks do not rescue an invalid value,
           only a missing one. Expand shorthand first and fall back to black on
           anything unparseable, so the ramp is always valid. */
        $themecolor = (string) ($store->color ?? '');
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i', $themecolor, $m)) {
            $themecolor = '#'.$m[1].$m[1].$m[2].$m[2].$m[3].$m[3];
        }
        [$r, $g, $b] = sscanf($themecolor, "#%02x%02x%02x") ?: [null, null, null];
        if ($r === null || $g === null || $b === null) {
            [$r, $g, $b] = [0, 0, 0];
        }

        $i = 0.01;
        while($i <= 1){
            echo "--theme-color-".str_replace('.','_',$i).": rgba($r,$g,$b,$i);";
            $i += 0.01;
        }
        echo "--theme-color-1: rgba($r,$g,$b,1);";
        @endphp}
    </style>

    {{-- #43: Custom menu font (Google font or uploaded woff2) --}}
    @php
        $menuFont     = @$postOptions->menu_font ?? '';
        $menuFontFile = @$postOptions->menu_font_file ?? '';
    @endphp
    @if($menuFontFile)
    <style nonce="{{ csp_nonce() }}">
        @font-face {
            font-family: 'StoreFont';
            src: url('{{ asset("storage/fonts/" . $menuFontFile) }}') format('woff2');
            font-display: swap;
        }
        :root { --menu-font: 'Saudi Riyal', 'StoreFont', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
    </style>
    @elseif($menuFont)
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family={{ urlencode($menuFont) }}:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style nonce="{{ csp_nonce() }}">
        :root { --menu-font: 'Saudi Riyal', '{{ str_replace('+', ' ', $menuFont) }}', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
    </style>
    @else
    <style nonce="{{ csp_nonce() }}">:root { --menu-font: inherit; }</style>
    @endif

    {{-- Theme customizer overrides (platform P3). Uses the DRAFT for the owner's
         live preview (?preview_theme=1), else the published settings. --}}
    @php
        $__cvPreview = request()->boolean('preview_theme') && auth()->check() && (int) auth()->id() === (int) $store->user_id;
        $__cv = [];
        try {
            $__tpl = store_options($store->id)->store_template ?? '';
            $__ts = $__tpl ? \Juwri\Modules\Storefront\Models\ThemeSetting::where('store_id', $store->id)->where('template', $__tpl)->first() : null;
            if ($__ts) {
                $__cv = is_array($__ts->settings) ? $__ts->settings : [];
                if ($__cvPreview && is_array($__ts->draft)) { $__cv = array_merge($__cv, $__ts->draft); }
            }
        } catch (\Throwable $e) {}
    @endphp
    @if(!empty($__cv))
    {{-- WI-T2: the `fonts.googleapis.com` stylesheet that used to sit here (driven by
         $__cv['fonts.body']) is GONE. It was a render-blocking third-party request on
         every storefront view, and it contradicted this repo's self-hosted-fonts rule —
         the same rule the marketplace theme linter enforces on every submitted theme.
         Self-hosted typography is now `fonts.pair` (config/theme-fonts.php →
         _partials/theme-fonts.blade.php, included below).

         `fonts.body` itself still resolves: it is a legacy free-text family name a
         couple of themes still carry, so the --menu-font declaration below is kept
         until the alias story is settled (GAPS.md WI-T4/T5). It now names the family
         WITHOUT fetching a webfont for it, i.e. it applies when the family is already
         available on the device and otherwise falls through to the system stack. --}}
    <style nonce="{{ csp_nonce() }}">
        :root {
            @if(!empty($__cv['colors.primary']) && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $__cv['colors.primary']))
                @php
                    /* Accept 3-digit shorthand too — this guard used to require exactly
                       six digits, so a perfectly valid "#abc" was ignored outright and
                       the theme-settings primary colour simply never applied. */
                    $__cp = $__cv['colors.primary'];
                    if (preg_match('/^#([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])$/', $__cp, $__cm)) {
                        $__cp = '#'.$__cm[1].$__cm[1].$__cm[2].$__cm[2].$__cm[3].$__cm[3];
                    }
                    list($__cr, $__cg, $__cb) = sscanf($__cp, "#%02x%02x%02x");
                @endphp
                @for($__i = 1; $__i <= 9; $__i++)--theme-color-0_{{ $__i }}: rgba({{ $__cr }},{{ $__cg }},{{ $__cb }},0.{{ $__i }});@endfor
                --theme-color-1: rgba({{ $__cr }},{{ $__cg }},{{ $__cb }},1);
                --brand: {{ $__cv['colors.primary'] }};
            @endif
            @if(!empty($__cv['fonts.body']))--menu-font: 'Saudi Riyal', '{{ str_replace("'", '', $__cv['fonts.body']) }}', -apple-system, sans-serif;@endif
        }
        @if(!empty($__cv['colors.background']) && preg_match('/^#[0-9a-fA-F]{6}$/', $__cv['colors.background']))
        body { background: {{ $__cv['colors.background'] }} !important; }
        @endif
    </style>
    @endif

    {{-- Customizer v2 (WI-T1/WI-T3): the design-token :root block. Emitted AFTER
         the legacy --theme-color-*/--brand block above, which keeps working
         untouched — the two are independent namespaces and nothing regresses. --}}
    @include('store_templates._partials.theme-tokens')

    {{-- I5-4: shared keyboard focus ring (every theme; kept out of theme-tokens
         so that partial stays silent for an uncustomized store). --}}
    @include('store_templates._partials.focus-ring')

    {{-- Customizer v2 (WI-T2): @font-face for the selected self-hosted font pair.
         Silent when no pair is selected, which is every store's default. --}}
    @include('store_templates._partials.theme-fonts')

    {{-- Saudi Riyal (U+20C1): always-on @font-face for the riyal glyph, scoped by
         unicode-range to that one codepoint. 'Saudi Riyal' is prepended to the body
         font stack and --menu-font below so the glyph is used wherever a price is
         drawn, with no effect on any other text. --}}
    @include('store_templates._partials.riyal-font')

    @php
        /* Modern self-contained templates (inline SVG icons + vanilla JS) do not
           need jQuery / popper / bootstrap or the legacy windpage + Tajawal
           assets the ~40 legacy templates rely on. Skipping them drops several
           render-blocking requests plus an external CDN on those themes; the
           legacy path below is left byte-for-byte unchanged. */
        $mbModern = \Juwri\Modules\Storefront\Services\Theme\KeptTemplates::isModern($theme ?? '');
    @endphp
    <link rel="stylesheet" href="{{ asset('assets/global/css/ui-icon.css') }}">

    @unless($mbModern)
    <script src="{{ asset('assets/global/js/jquery.min.js') }}"></script>
    @endunless
    
<!-- satrted -->


    {{-- Material Design Lite used to be pulled from code.getmdl.io here. That host now
         answers 403, so both requests have been failing on every storefront page load,
         in every template, for some time — two guaranteed console errors and two
         wasted round-trips per view.

         Removing them is a no-op for rendering: the 14 legacy templates that use
         `mdl-layout__tab-bar` / `mdl-js-ripple-effect` style those class NAMES in their
         own stylesheets (classic-theme 5 rules, dark-theme 5, arrow-theme 8,
         Ice-cream-theme 5, …) rather than relying on the library's CSS. The only thing
         actually lost is MDL's JS ripple, which has not run since the CDN went dead.
         If the ripple is ever wanted back, vendor MDL under assets/ — do not reintroduce
         a third-party CDN, CSP blocks them. --}}
    @unless($mbModern)
    <link href="https://fonts.googleapis.com/css?family=Tajawal&display=swap" rel="stylesheet">
    @endunless

    
    @if(current_language()->direction == 'rtl')
    <style nonce="{{ csp_nonce() }}">
    .wa-chat-bubble-close-btn {
        left: 20px !important;
        right: unset !important;
    }
    </style>
    @endif
    
    <!-- ended -->

    {{-- Default body face for templates that do not set their own.

         This used to sit BELOW @stack('style_at_top') carrying `!important`, which
         meant it overrode every template's typography at the root — in both language
         branches — and a theme could only get its own face back by re-`!important`-ing
         on descendants. Moving it above the stack and dropping `!important` inverts
         that correctly: a template that declares `body { font-family }` now simply
         wins, and one that declares nothing still gets Roboto (or tajawal in Arabic)
         exactly as before. --}}
    <style nonce="{{ csp_nonce() }}">
    body {
        @if(current_language()->name == 'Arabic')
        font-family: 'Saudi Riyal', tajawal, "Roboto", "Helvetica", "Arial", sans-serif;
        @else
        font-family: 'Saudi Riyal', 'Roboto', sans-serif;
        @endif
    }
    </style>

    @stack('style_at_top')
    
    @if(current_language()->direction == 'rtl')
    <style nonce="{{ csp_nonce() }}">
    
    </style>
    @else
    <style nonce="{{ csp_nonce() }}">
    .user-lang-switcher .dropdown-menu {
        right: 0;
        left: auto;
    }
    </style>
    @endif
    
    @if(@$postOptions->allow_slider_popup == "1")
    <!--link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css"-->
    @unless($mbModern)
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.11.0/umd/popper.min.js" integrity="sha384-b/U6ypiBEHpOf/4+1nzFpr53nxSS+GLCkfwBdFNTxtclqqenISfwAzpKaMNFNmj4" crossorigin="anonymous"></script>
    @endunless
    <link rel="stylesheet" href="{{ asset('assets/global/css/windpage.css?ver=2.9.4') }}">
    @endif    

    @if(@$postOptions->open_close_store == "1" || @$postOptions->open_close_store == "")
    <style nonce="{{ csp_nonce() }}">
    i.stats {
        background: #22e04d;
        font-size: 15px;
        padding: 1px 6px;
        border-radius: 30px;
        color: #fff;
        margin: 0 5px;
        align-self: center;
    }
    </style>
    @else
    <style nonce="{{ csp_nonce() }}">
    i.stats {
        background: #fc0018;
        font-size: 15px;
        padding: 1px 6px;
        border-radius: 30px;
        color: #fff;
        margin: 0 5px;
        align-self: center;
    }
    </style>
    @endif     
 
    <style nonce="{{ csp_nonce() }}">
    .single-page-header .header-image {
        flex-direction: column;
        justify-content: center;
    }
    button.btn.dropdown-toggle.btn-default {
        border-radius: 30px;
    }
    
    @media (max-width: 992px) {
        .header-details {
            width: 100%;
    }
    }
    
    @media (max-width: 575px) {
        .user-lang-wrapper i.ico.ico-bell {
            justify-content: center;
            display: flex;
        }
        button#call-the-waiter-btn {
            border-radius: 26px;
            width: 40px;
        }
    }
    @media (max-width: 400px) {
        .single-page-header.store-header .header-image {
            height: 100px;
            width: 100px;
        }

    }
@media screen and (max-width: 480px){
    .sign-in-form .popup-tabs-nav li:first-child.active {
        border-radius: 0px 0px 0 0 !important;
    }
}
@media screen and (max-width: 474px){
    .menu-content .menu-detail {
        flex-direction: column-reverse !important;
    }
}

    .single-page-header.store-header .header-image img, .single-page-header .header-image {
        border-radius: 70px;
    }
    
    button.btn.d-flex.open-bio.vv2, button.btn.d-flex.open-bio {
        z-index: 99;
    }    
    .popup-box .dropdown-menu.scrollable-menu.open.showlang {
        right: unset;
        left: 29px;
    }    
    .popup-box .bootstrap-select:before {
        background-color: #ffffff05;
    }
    .mfp-wrap.mfp-close-btn-in.mfp-auto-cursor.mfp-ready {
        z-index: 99999999;
    }
    .popup-screen {
        z-index: 99999999;
    }
    #menuCustomize, #viewOrder, #call-waiter-box {
        z-index: 99999999;
    }
    div#view-order-wrapper, .navbar {
        z-index: 99999999;
    }
    .classic-middle.sticky .filter-button {
        font-size: 17px !important;
        padding: 0px 9px !important;
        height: 30px;
        display: flex;
        align-items: center;
    }
    .classic-middle.sticky .filter-gallery.mdl-layout__tab-bar.mdl-js-ripple-effect {
        height: 45px !important;
    }
    .single-page-section {
        margin-bottom: 10px;
    }
    .menu__overlay {
        width: 24px;
    }
    
    
.circle {
  position: relative;
  width: 100px;
  height: 100px;
}
.circle svg {
  fill: none;
  stroke: #8a3ab9;
  stroke-linecap: round;
  stroke-width: 5;
  stroke-dasharray: 1;
  stroke-dashoffset: 0;
  animation: stroke-draw 6s ease-out infinite alternate;
}
.circle img {
  position: absolute;
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%);
  width: 102px;
  border-radius: 50%;
}
@keyframes stroke-draw {
  from {
    stroke: #8a3ab9;
    stroke-dasharray: 1;
  }
  to {
    stroke: #cd486b;
    transform: rotate(180deg);
    stroke-dasharray: 8;
  }
}    
    
    
    .popup-box .circle.profile_image {
        width: 8rem;
        height: 8rem;
    }
    
    .visib{
        visibility: hidden;
        /*max-height: 0px;*/
        /*display: grid !important; */
    }
    .header-detailss.dd a {
        font-size: 0px !important;
    }
    .dd a, .dd a svg {
        width: 45px;
    }
    
    </style>
    
    
    @if(current_language()->name == 'Arabic')
    <style nonce="{{ csp_nonce() }}">
    </style>
    @else
    <style nonce="{{ csp_nonce() }}">
    .langgg .bootstrap-select.btn-group button {
        width: 48%;
        height: 38px;
        min-width: fit-content;
    }    
    .bootstrap-select.btn-group .dropdown-toggle .filter-option {
        text-overflow: unset;
        font-size: 14px;
    }

    .popup-box .user-lang-switcher.second.s2 {
        left: unset;
        right: 33px;
    }
    @media (min-width: 993px) {
        .user-lang-wrapper.d-flex.langgg {
            left: unset;
            right: 173px;
        }        
    } 
    @media (max-width: 459px) {
        .popup-box .user-lang-switcher.second.s2 {
            left: 1px;
        }
    }   
    .langgg button.btn.dropdown-toggle.btn-default {
        left: 13px;
        text-align: center;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .langgg .btn-group .dropdown-toggle .filter-option {
        text-align: center;
    }

    </style>
    @endif    
    
    @if(current_language()->name == 'Urdu')
    <style nonce="{{ csp_nonce() }}">
    .boxed-list-headline h3, button.mdl-layout__tab.filter-button {
        font-weight: normal !important;
    }
    .dd li {
        font-size: 0px !important;
    }
    </style>
    @endif        
    
    {{-- P5-1: validated structured integrations (GA/Pixel/font) — every owner --}}
    @include('store_templates.partials.structured-integrations')

    {{-- P9-4: raw custom-code head sinks (css_code/head_code + raw facebook_pixel/
         google_tag) retired. GA/Pixel/font now render through the validated
         structured-integrations partial above. Columns kept dormant (no data
         loss); see rev/platform-v2 P9-4. --}}

    {{-- F8: Restaurant + Menu schema.org JSON-LD (menu index only). --}}
    @include('store_templates._partials.jsonld-menu')

    {{-- Platform Google Analytics (admin Quick add-ons) — tracks storefront
         visitors under the platform GA id, as the legacy classic theme did.
         The per-store GA above (structured-integrations) is independent. --}}
    @include('partials.analytics')
</head>
<body class="{{ current_language()->direction }}">

<a href="#main-content" class="sr-only sr-only-focusable" style="position:absolute;top:-40px;left:0;z-index:9999;background:#000;color:#fff;padding:8px 16px;border-radius:0 0 8px 0;transition:top .2s;" data-csp-focus="skip-link" data-csp-blur="skip-link">{{ ___('Skip to content') }}</a>

{{-- T2 · S1 — shared splash/onboarding overlay (renders only when
     theme_setting($store->id, 'splash.enabled') is truthy; default OFF). --}}
@include('store_templates._partials.splash')

{{-- Customizer v2 (WI-U3) — live-preview receiver. Renders ONLY for the owner in
     preview mode, so a public storefront carries no message listener at all. --}}
@include('store_templates._partials.theme-preview-bridge')

{{-- T1 · H8 — hotel guest layer: room greeting + charge-to-room tender. Renders
     only when hotel_mode is on AND the visitor arrived via a room QR. --}}
@include('store_templates._partials.hotel-guest')

{{-- WIRING-RUN D4 — checkout field forwarder. It hooks fetch/XHR to carry the newer
     checkout fields (scheduled_at, gift_card_code, curbside/vehicle_*, and the hotel
     tender/room pair) into the send-order POST. It used to be @included per theme,
     which reached only 16 of the 39 templates — so charge-to-room, scheduling and
     gift cards silently dropped on the other 23. It is theme-agnostic, every theme
     extends this layout, and it must run before the theme bundle at the foot of the
     page, so it belongs here exactly once. --}}
@include('store_templates._partials.order-field-forwarder')

{{-- P1-G — shared language switch. Ten themes switched language by writing the
     menu-language cookie and reloading, which cannot work under prefix-mode
     locales: the reload keeps the old URL prefix and RememberLocale puts the UI
     straight back into the language the guest just left. This handles every
     [data-lang-code] control centrally, in the capture phase, so one
     implementation serves all themes. --}}
@include('store_templates._partials.language-switch')

{{-- AL-40: geo hint for AL-20's currency default. Emitted ONLY when the owner has
     provisioned a geo source (config/geoip.php) — dark by default, so the storefront
     HTML is byte-identical for every visitor and every crawler (no per-visitor
     variance, SEO-safe). When provisioned it varies by country by the owner's
     choice; they own the caching implications. --}}
@php($__qcGeo = visitor_country())
@if ($__qcGeo)
    <script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>window.QC_GEO = @json($__qcGeo);</script>
@endif

{{-- AL-20: first-visit currency default (client-only; clicks the theme's own
     switcher, never touches a charge/total/JSON-LD). --}}
@include('store_templates._partials.currency-default')

{{-- MC-34: display-currency estimate notice. aurora and vivid ship their own. --}}
@unless(in_array($postOptions->store_template ?? '', ['aurora', 'vivid'], true))
@include('store_templates._partials.currency-estimate-notice')
@endunless

{{-- AL-10/11: first-visit language suggest banner (client-only, SEO-safe). Sits
     after the switch handler it relays through. --}}
@include('store_templates._partials.locale-suggest')

{{-- Story CTA destination policy. Every theme ships its own story viewer and
     each repeated the same "render whatever cta_url says" logic, which turned a
     cta_url pointing at the store's own home page into a button that opened a
     second tab of the page you were already on. Included unconditionally — the
     shared stories RAIL is an opt-in fallback that renders nothing on themes
     with a native rail, so it cannot host something they depend on. --}}
@include('store_templates._partials.story-cta-policy')

{{-- Shared item-reviews renderer. Reviews exist on only 3 of the 17 retained
     themes; the public endpoint already exists, so this supplies the rendering
     once and each theme opts in with a single call in its product-open path. --}}
@include('store_templates._partials.item-reviews')

{{-- F1 — shared checkout-OTP gate. Renders nothing unless the store turned on
     phone verification (CheckoutOtpController::gateEnabled). Closes the checkout
     dead-end on the ~30 legacy themes whose bundles ignore the server's
     `otp_required` rejection. Placed after the forwarder so it watches the same
     /send-order responses; stands down on themes with a native OTP flow. --}}
@include('store_templates._partials.checkout-otp')

{{-- F2 — shared order-tracking bar. Watches the /send-order response and shows a
     "Track your order" bar when it carries track_url, for every theme that has
     no native tracker. Stands down on themes that already surface one. --}}
@include('store_templates._partials.order-result')

{{-- Shared checkout feedback — surfaces coupon_declined / dropped_items that the
     /send-order response carries but no theme reads (silent full-price charge or
     silently dropped sold-out lines). Additive top banner on a successful order. --}}
@include('store_templates._partials.checkout-feedback')

{{-- D3 — store announcement banner. The fleet bulk op and the per-store option
     editor write store_announcement*, but no theme rendered them. Server-gated on
     store_announcement_active === '1' + non-empty text, so it renders nothing on a
     store with no live announcement. Sibling of the fixed notification banners
     above. --}}
@include('store_templates._partials.store-announcement')

{{-- K1 — post-payment review funnel. Renders nothing unless the store set a
     Google review URL and the feature is allowed; watches the /send-order
     response and shows a star card that smart-routes 4-5★ to Google. --}}
@include('store_templates._partials.review-prompt')

{{-- K4 — checkout tip presets. Renders nothing unless the store turned tips on
     (store_options.tip_enabled); injects a hidden tip_amount the forwarder ships. --}}
@include('store_templates._partials.tip-presets')

{{-- K3 — checkout loyalty redemption. Renders nothing unless feature:loyalty is
     entitled and a returning, logged-in diner has enough points; injects a hidden
     loyalty_redeem the forwarder ships into /send-order. --}}
@include('store_templates._partials.loyalty-redeem')

{{-- google-suite Lane C — "Add to Google Wallet" (loyalty). Renders nothing unless
     Google Wallet is configured + enabled for the store, a customer is logged in,
     and an active loyalty program exists; self-anchors next to the balance block. --}}
@include('store_templates._partials.google-wallet')

{{-- google-suite Lane C follow-up — "Add to Google Wallet" (gift cards). Renders
     nothing unless we are on the customer account page, Wallet is enabled, and the
     signed-in customer purchased a gift card here; self-anchors into the account
     content column and mints each card's save-link from the customer-guarded JWT
     endpoint on click. --}}
@include('store_templates._partials.google-wallet-giftcards')

{{-- PARITY-RUN T3 — verified table QR scan gate. Renders nothing unless the
     store's effective mode needs guest-side proof AND this browser has no
     verified session yet. Placed after the forwarder so the hidden `table_key`
     it emits exists before the theme bundle builds its order payload. --}}
@include('store_templates._partials.table-verify')

{{-- PARITY-RUN T6 — combo meals. Renders nothing unless the store has sellable
     combos and the feature is live for its owner. Shared rather than per-theme:
     40+ compiled theme bundles cannot each grow a configurator, and the
     theme-parity run showed a per-theme copy drifts within one release. Placed
     after the forwarder, which carries the `combos` field it emits into the
     send-order POST. --}}
@include('store_templates._partials.combos')

{{-- F12 — checkout-enhancement inputs (scheduled_at / gift_card_code / curbside)
     for themes that don't render them natively. Each input is gated by its owner
     flag and stands down per-field when the theme already ships it. --}}
@include('store_templates._partials.checkout-enhancements')
{{-- Shared behaviour engines (theme supplies its own styled UI, these own the
     logic): window.mbMapPicker (Leaflet address map, lazy-loaded) +
     window.mbClearDeviceData (wipe local cart/favourites/addresses). --}}
@include('store_templates._partials.map-picker')
@include('store_templates._partials.clear-device-data')
{{-- Desktop mouse access for the hidden-scrollbar horizontal rails (drag-to-pan
     + wheel translation). Fine-pointer devices only; touch untouched. --}}
@include('store_templates._partials.rail-mouse-scroll')

<main id="main-content">
{{-- Placement helper for the injected sections below (window.mbAnchor): finds
     the theme's menu and moves a section next to it. Must come first. --}}
@include('store_templates._partials.theme-anchor')
{{-- Home-page order type. Every retained theme keeps its order-type control
     inside checkout; this drives that same control from the menu page, and
     stands down on the three themes that already surface it on home. --}}
@include('store_templates._partials.home-order-type')
{{-- Shared offers strip — the store's currently-discounted items. Renders only
     on the 17 modern templates, and only when a price schedule is live, so a
     store with no offers grows no empty section. --}}
@include('store_templates._partials.offers-strip')
@include('store_templates._partials.custom-blocks')
{{-- T1 follow-up: upgrade each theme's raw "Later" datetime input to real
     bookable slots from restaurant.scheduleSlots. --}}
@include('store_templates._partials.schedule-slot-picker')

{{-- Opens a specific item from /{slug}?item={menu_id} on every theme. Lets story
     and banner CTAs deep-link to a real dish instead of falling back to the home
     page. Included after offers-strip so the item hooks it resolves already exist. --}}
@include('store_templates._partials.item-deeplink')
{{-- Info-page footer (Terms/Privacy/About/Help) for every visitor. Server-rendered
     here, relocated to the bottom of the menu by mbAnchor; renders nothing when the
     owner has filled no pages, and only on the 17 modern templates. --}}
@include('store_templates._partials.info-links')
@include('store_templates._partials.store-reviews-place')
@yield('content')
</main>

{{-- F14-2 — the shared floating Web Share pill is retired for the 17 modern themes.
     Every one of them now carries a native "Share this menu" control on its profile
     page instead (the in-menu profile view, or the standalone profile page for the
     four themes without one), so the fixed-position fallback no longer belongs in
     the shared layout — it was surfacing on Track Order / account / wallet too,
     which are not the menu. The _partials/share.blade.php file stays for folio and
     pageview, which include it from their own layouts. --}}

{{-- I5-2 — dialog semantics + focus trap for the shared `.sheet` bottom sheets.
     Stands down when the page has no `.sheet`. --}}
@include('store_templates._partials.sheet-a11y')

{{-- Back chevrons on the subpages (account / profile / track) are plain links to
     the menu, so every trip through them ended on home. Returns them to the page
     the diner actually came from, when there is one. --}}
@include('store_templates._partials.back-link')

<script nonce="{{ csp_nonce() }}">
    /* P4-16: nothing in the app reads this global (the themes ship their own item map). It was every menu row, raw, on every
       page (3.7 MB for 2,000 items); now a slim map of the fields a script could want, and empty on a very large catalogue. */
    var TOTAL_MENUS = @json(storefront_menu_stub($total_menus ?? []));
    var CURRENCY_SIGN = @json(@$postOptions->currency_sign);
    var CURRENCY_LEFT = @json(@$postOptions->currency_pos);

    // Language Var
    var LANG_ADD = @json(___('Add'));
    var LANG_PAY_NOW = @json(___('Pay Now'));
    var LANG_SEND_ORDER = @json(___('Send Order'));
</script>


@if(@$postOptions->allow_slider_popup == "1")

    
    <script nonce="{{ csp_nonce() }}" type="text/javascript">
    


        const popupScreen = document.querySelector(".popup-screen");
    
        const popupBox = document.querySelector(".popup-box");
    
        const closeBtn = document.querySelector(".close-btns");
    
        const closeBtn2 = document.querySelector(".start-btns");
    
        const openBtn = document.querySelector(".open-bio");
    
        const neverBtn = document.querySelector(".never-btns");
    
    
        if (popupScreen && closeBtn) {
          closeBtn.addEventListener("click", () => {
    
            popupScreen.classList.remove("active"); //Close the popup screen on click the close button.
    
    
          });
        }
        
        if (popupScreen && closeBtn2) {
          closeBtn2.addEventListener("click", () => {
    
            popupScreen.classList.remove("active"); //Close the popup screen on click the close button.
    
    
          });
        }
        
        
        if (popupScreen && openBtn) {
          openBtn.addEventListener("click", () => {
    
            popupScreen.classList.add("active"); //Open the popup screen on click the open button.
            popupScreen.style.display = "flex"; //Show the popup screen if the cookie is expired.
    
          });
        }
    
        if (popupScreen && neverBtn) {
          neverBtn.addEventListener("click", () => {
    
            popupScreen.classList.remove("active"); //Close the popup screen on click the close button.
    
            //Create a cookie for a day (to expire within a day) on click the close button.
    
            document.cookie = "WebsiteName=testWebsite; max-age=" + 24 * 60 * 60; //1 day = 24 hours = 24*60*60
    
          });
        }
    
        //Use the created cookie to hide or show the popup screen.
    
        const WebsiteCookie = document.cookie.indexOf("WebsiteName=");
    
        if(popupScreen && WebsiteCookie != -1){
    
          popupScreen.style.display = "none"; //Hide the popup screen if the cookie is not expired.
          popupScreen.classList.remove("active"); //Close the popup screen on click the close button.
    
        }
    
        else if (popupScreen) {
    
          popupScreen.style.display = "flex"; //Show the popup screen if the cookie is expired.
          //popupScreen.classList.add("active"); //Open the popup screen on click the open button.
    
        }
        
    </script>
  
@endif 



    @unless($mbModern)
    <script nonce="{{ csp_nonce() }}" type="text/javascript">
        /* on lang change */
        $(window).scroll(function(){
                if($(this).scrollTop() > 800){
                    $('.classic-middle').addClass('sticky')
                } else if($(this).scrollTop() < 709){
                    $('.classic-middle').removeClass('sticky')
                }
        });
    </script>
    @endunless


    
    {{-- P9-4: raw custom-code body sinks (js_code/footer_code) retired — no
         arbitrary script/markup path on the storefront. Columns kept dormant. --}}
    <!--started-->

    
    
    
    {{-- F6: third-party WATI (clare.ai) storefront chat widget. Gated so it can be
         toggled platform-wide from Admin → Settings → General (Option
         `storefront_wati_widget`, no deploy) — now defaults OFF: the self-hosted
         wa.me "Chat with us on WhatsApp" button + post-order hand-off are the
         sanctioned WhatsApp channel, so an admin must opt IN to load the external
         clare.ai bubble. It also cannot survive CSP enforce (script-src 'self' has
         no clare.ai allowlist), so enforce mode fails closed regardless. --}}
    {{-- PCI DSS 6.4.3: never on a payment page (no business need for a third-party chat script next to payment). --}}
    @if(!empty($store->phone)
        && config('settings.storefront_wati_widget', false)
        && !config('csp.enforce', false)
        && \Juwri\Core\Pci\PaymentPages::current(request()) === null)
    <script nonce="{{ csp_nonce() }}">
        var url = 'https://wati-integration-service.clare.ai/ShopifyWidget/shopifyWidget.js?61643';
        var s = document.createElement('script');
        s.type = 'text/javascript';
        s.async = true;
        s.src = url;
        var options = {
      "enabled":true,
      "chatButtonSetting":{
          "backgroundColor":"#4dc247",
          "ctaText":"",
          "borderRadius":"25",
          "marginLeft":"0",
          "marginBottom":"50",
          "marginRight":"50",
          "position":"left"
      },
      "brandSetting":{
          "brandName":"{{$store->title}}",
          "brandSubTitle":"{{ ___('brandsubtitle') }}",
          "brandImg":"{{ media_url('storage/store/logo/', $store->main_image, $store->id ?? null) }}",
          "welcomeText":"{{ ___('welcometext') }}",
          "messageText":"{{ ___('messagetext') }} {{$store->title}}",
          "backgroundColor":"#0a5f54",
          "ctaText":"{{ ___('ctatext') }}",
          "borderRadius":"25",
          "autoShow":false,
          "phoneNumber":"{{$store->phone}}"
      }
    };
        s.onload = function() {
            CreateWhatsappChatWidget(options);
        };
        var x = document.getElementsByTagName('script')[0];
        x.parentNode.insertBefore(s, x);
    </script>
    <style nonce="{{ csp_nonce() }}" type="text/css" id="">
        @media (max-width: 992px){
        .single-page-header .header-details li {
            padding: 1px 10px !important;
        }
        }
        .icon-material-outline-restaurant:before {
            content: unset;
        }

    
    </style>
    @endif       
    
    
    
    


@stack('scripts_at_bottom')

@unless($mbModern)
<script nonce="{{ csp_nonce() }}">
    $(document).ajaxError(function (event, jqxhr) {
        if (jqxhr && jqxhr.status === 429) {
            var message = (jqxhr.responseJSON && (jqxhr.responseJSON.message || jqxhr.responseJSON.error)) || @json(___('Too many requests. Please wait a moment and try again.'));
            alert(message);
        }
    });
</script>
@endunless

{{-- WA-T3: one WhatsApp hand-off for every theme.

     Twelve keeper themes called window.open(url, "_blank") after the async order
     POST. iOS Safari blocks that — the call is no longer inside a user gesture —
     so the order was placed and nothing opened, and the customer reasonably
     concluded it had failed.

     This tries the popup (nice on desktop: the confirmation stays put), detects
     the block, and then does two things a blocker cannot stop: shows a real
     anchor the customer can tap, and navigates the top-level window itself.
     Loaded before the theme bundle so script.js can call it. Nonced: CSP_ENFORCE
     blocks un-nonced inline scripts, and CspEnforceTest ratchets that — without
     the nonce this helper would be silently blocked in production. --}}
<script nonce="{{ csp_nonce() }}">
window.mbWhatsAppHandoff = function (url, opts) {
    if (!url) return false;
    opts = opts || {};

    /* WA-S1: the owner's choice travels with the order response. A theme may pass
       it straight through (mbWhatsAppHandoff(res.whatsapp_url, res.whatsapp_handoff));
       a theme that does not is unaffected, because these all default to today's
       behaviour. */
    var mode   = opts.mode   || 'auto';
    var target = opts.target || 'new';
    var delay  = typeof opts.delay === 'number' ? opts.delay
               : (typeof opts.delay === 'string' && opts.delay !== '' ? parseInt(opts.delay, 10) : 1200);

    if (mode === 'none') return false;

    // "Show a button" — never navigate on the customer's behalf.
    if (mode === 'button') {
        mbWhatsAppFallbackBar(url, opts);
        return false;
    }

    // Owner asked for the same tab: no popup to be blocked in the first place.
    if (target === 'same') {
        mbWhatsAppFallbackBar(url, opts);
        window.setTimeout(function () { window.location.assign(url); }, delay);
        return true;
    }

    var popup = null;
    try { popup = window.open(url, '_blank'); } catch (e) { popup = null; }

    // Blocked (or a wrapper that returns a dead handle) — fall back visibly.
    if (popup && !popup.closed && typeof popup.closed !== 'undefined') {
        return true;
    }

    // Blocked. Show the link and navigate the top-level window, which no blocker
    // can stop.
    mbWhatsAppFallbackBar(url, opts);
    window.setTimeout(function () { window.location.assign(url); }, delay);

    return false;
};

/* The visible, always-tappable fallback. A real anchor: no popup blocker, no
   gesture requirement, nothing to go wrong. */
window.mbWhatsAppFallbackBar = function (url, opts) {
    opts = opts || {};
    if (document.getElementById('mb-wa-fallback')) return;

    var bar = document.createElement('div');
    bar.id = 'mb-wa-fallback';
    bar.setAttribute('role', 'status');
    bar.style.cssText = [
        'position:fixed', 'inset-inline:0', 'inset-block-end:0', 'z-index:2147483000',
        'display:flex', 'align-items:center', 'justify-content:center', 'gap:.6rem',
        'padding:14px 16px', 'background:#25D366', 'color:#0b3d20',
        'font:600 15px/1.3 system-ui,-apple-system,Segoe UI,sans-serif',
        'box-shadow:0 -6px 24px rgba(0,0,0,.18)'
    ].join(';');

    var link = document.createElement('a');
    link.href = url;
    link.rel = 'noopener';
    link.textContent = opts.label || @json(___('Open WhatsApp to send your order'));
    link.style.cssText = 'color:#0b3d20;text-decoration:underline;font-weight:700';

    bar.appendChild(link);
    (document.body || document.documentElement).appendChild(bar);

};
</script>

@include('store_templates.includes.item-carousel-assets')

{{-- M16: the shared storefront money core. MUST load before the theme bundle --
     every theme's tax/discount helper delegates to window.mbMoney. Same shape as
     mbWhatsAppHandoff/mbCarousel above, but a real file so it is cacheable and
     testable. Same-origin, so StorefrontPolicy's script-src 'self' allows it with
     no nonce. Loaded unconditionally (legacy templates included). --}}
<script src="{{ asset('assets/store_templates/_shared/js/mb-money.js') }}?v={{ @filemtime(public_path('assets/store_templates/_shared/js/mb-money.js')) }}"></script>
{{-- Store scope for shared client helpers (currency choice persistence, etc.) so one
     store's localStorage never leaks onto another on the same origin. --}}
<script nonce="{{ csp_nonce() }}">window.MB_STORE = @json((string) ($store->id ?? $store->slug ?? 'default'));</script>
{{-- Open/closed status is recomputed CLIENT-side from the store's hours + timezone so
     the full-page cache never freezes a stale "Open now"/"Closed" into the HTML. The
     payload is time-INDEPENDENT (safe to cache); the script does the per-visitor
     calculation and updates every [data-mb-openchip]. Absent for always-open stores.
     Uses @if-with-assignment (not a @php block, which does not compile in this region). --}}
@if($__mbHours = store_hours_payload($store ?? null))
<script type="application/json" id="mb-hours-data" nonce="{{ csp_nonce() }}">@json($__mbHours)</script>
<script nonce="{{ csp_nonce() }}">(function () {
    var el = document.getElementById('mb-hours-data');
    if (!el) { return; }
    var d;
    try { d = JSON.parse(el.textContent); } catch (e) { return; }
    var DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    var DOW = { Mon: 0, Tue: 1, Wed: 2, Thu: 3, Fri: 4, Sat: 5, Sun: 6 };

    function nowInTz(tz) {
        var parts = new Intl.DateTimeFormat('en-GB', { timeZone: tz || 'UTC', weekday: 'short', hour: '2-digit', minute: '2-digit', hour12: false }).formatToParts(new Date());
        var m = {};
        parts.forEach(function (p) { m[p.type] = p.value; });
        var hh = (m.hour === '24') ? '00' : m.hour; // some engines emit 24 at midnight
        return { idx: DOW[m.weekday], cur: hh + ':' + m.minute };
    }
    function hoursStr(day) {
        // Mirrors storefront_hours_today(): today's window, LTR-isolated, or null.
        if (!day || day.closed || !day.open || !day.close) { return null; }
        return '⁦' + day.open + '–' + day.close + '⁩';
    }
    // Mirrors OpeningHoursService::isOpenAt() + the manual force-closed switch.
    function compute() {
        if (d.manualClosed) { return { open: false, hours: null }; }
        if (!d.week) { return { open: true, hours: null }; }
        var n;
        try { n = nowInTz(d.tz); } catch (e) { return null; }
        if (n.idx == null) { return null; }
        var today = d.week[DAYS[n.idx]] || null;
        var prev = d.week[DAYS[(n.idx + 6) % 7]] || null;
        var todayHours = hoursStr(today);
        if (prev && !prev.closed && prev.open && prev.close && prev.close < prev.open && n.cur <= prev.close) {
            return { open: true, hours: todayHours };
        }
        if (today === null) { return null; }            // unknown → leave server value
        if (today.closed) { return { open: false, hours: null }; }
        if (!today.open || !today.close) { return null; }
        var overnight = today.close < today.open;
        var isOpen = overnight ? (n.cur >= today.open || n.cur <= today.close) : (n.cur >= today.open && n.cur <= today.close);
        return { open: isOpen, hours: todayHours };
    }
    function apply() {
        var st = compute();
        if (!st) { return; }                            // unknown → don't touch
        window.MB_STORE_OPEN = st.open;
        try { document.dispatchEvent(new CustomEvent('mb:store-open', { detail: { open: st.open } })); } catch (e) {}
        var chips = document.querySelectorAll('[data-mb-openchip]');
        for (var i = 0; i < chips.length; i++) {
            var chip = chips[i];
            var t = chip.querySelector('[data-mb-open-text]');
            if (!t) { continue; }
            t.textContent = (st.open ? d.labels.open : d.labels.closed) + (st.hours ? ' · ' + st.hours : '');
            var cc = chip.getAttribute('data-mb-closed-class') || 'closed';
            chip.classList.toggle(cc, !st.open);
        }
    }
    apply();
    setTimeout(apply, 0);                                // catch any late-rendered chip
})();</script>
@endif
<script src="{{ asset('assets/store_templates/_shared/js/mb-currency.js') }}?v={{ @filemtime(public_path('assets/store_templates/_shared/js/mb-currency.js')) }}"></script>
<script src="{{ asset('assets/store_templates/_shared/js/mb-notify.js') }}?v={{ @filemtime(public_path('assets/store_templates/_shared/js/mb-notify.js')) }}"></script>
{{-- P6-301: shared "Order again" pickup (cv_reorder_<slug>), loaded before the theme bundle so every theme's boot can call it. --}}
<script nonce="{{ csp_nonce() }}">window.JUWRI_REORDER_UNAVAILABLE = @json(___('Unavailable'));</script>
<script src="{{ asset('assets/store_templates/_shared/js/reorder-pickup.js') }}?v={{ @filemtime(public_path('assets/store_templates/_shared/js/reorder-pickup.js')) }}"></script>
{{-- Shared light/dark theme engine (Lane B). FOUC guard: stamp the persisted
     explicit choice on <html> before first paint; every togglable theme's dark
     token block also matches html[data-mb-theme="dark"] .<theme>. mbTheme.init()
     (called from each theme's bootstrap) moves the attribute onto the theme
     root and clears this one. --}}
<script nonce="{{ csp_nonce() }}">(function(){try{
  var k='mb_theme_'+String(window.MB_STORE||'default');
  var m=localStorage.getItem(k)||localStorage.getItem('theme');
  if(m==='dark'||m==='light'){document.documentElement.setAttribute('data-mb-theme',m);}
}catch(e){}})();</script>
<script src="{{ asset('assets/store_templates/_shared/js/mb-theme.js') }}?v={{ @filemtime(public_path('assets/store_templates/_shared/js/mb-theme.js')) }}"></script>
{{-- Stories fetch with one retry. Every theme's story rail did
     fetch(url).then(r=>r.json()).catch(()=>rail.hidden=true) with NO retry, so a
     single dropped request (flaky network, or many tabs loading at once) blanked
     the rail for the whole session with no self-heal — reported as "the story
     always disappears" on crumb/soiree/scoop/ember. This resolves to the same
     parsed JSON as the old fetch, but on a network/HTTP failure waits 800ms and
     retries once before giving up (then the theme's existing .catch still hides).
     Loaded before the theme bundle so script.js can call it; themes call it
     defensively (window.mbStoriesGet ? … : plain fetch) so an older cached bundle
     keeps its previous behaviour rather than throwing. Nonced: CSP_ENFORCE. --}}
<script nonce="{{ csp_nonce() }}">
window.mbStoriesGet = function (url, tries) {
    tries = (typeof tries === 'number') ? tries : 1;
    return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { if (!r.ok) throw new Error('stories http ' + r.status); return r.json(); })
        .catch(function (err) {
            if (tries > 0) {
                return new Promise(function (res) { window.setTimeout(res, 800); })
                    .then(function () { return window.mbStoriesGet(url, tries - 1); });
            }
            throw err;
        });
};
</script>
<script src="{{ asset('assets/store_templates/'. $theme .'/js/script.js?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/js/script.js'))) }}"></script>
{{-- Quick add-ons body block (cookie consent + Tawk); dropped in the owner-kit rebuild (WI-16). --}}
@include('partials.quick-addons')
{{-- P1-23: "Cookie settings" for the legacy templates (the modern ones have it in the info footer). --}}
@include('partials.pharmacy-licence')
@include('store_templates._partials.cookie-settings-link', ['cookieLinkSkipModern' => true])
</body>
</html>
