{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Bio master document. Family layouts (bio/layouts/{family}.blade.php) @extends this
  and fill @section('page'). Contract vars (SPEC §4): $store, $branch, $preset(array),
  $tokens(array), $blocks(array), $bioSettings, $poweredBy(string), $rtl(bool). Optional
  $source (SPEC §11 G5) is echoed onto the root so the click beacon reports it.

  CSP (SPEC §6/§9): the page carries exactly ONE nonced style element (base + family +
  preset CSS + tokens) and ONE nonced script (the click beacon). Google Fonts load via a
  <link> from the hosts added to the bio CSP profile. No external iframe / frame-src (G6).
--}}
@php
    use Illuminate\Support\Facades\File;

    $preset   = is_array($preset ?? null) ? $preset : [];
    $tokens   = is_array($tokens ?? null) ? $tokens : [];
    $blocks   = is_array($blocks ?? null) ? $blocks : [];
    $bioSettings = is_array($bioSettings ?? null) ? $bioSettings : [];
    $rtl      = (bool) ($rtl ?? false);
    $slug     = (string) ($preset['slug'] ?? 'classic_flat');
    $slug     = preg_replace('/[^a-z0-9_]/', '', $slug) ?: 'classic_flat';
    $family   = (string) ($preset['family'] ?? 'list');
    $family   = preg_replace('/[^a-z0-9_]/', '', $family) ?: 'list';

    // rtl_native presets (e.g. majlis_gold) force RTL even under an LTR preview locale.
    if (! empty($preset['rtl_native'])) {
        $rtl = true;
    }

    // ---- assemble the single stylesheet: base -> family -> preset residual -> tokens ----
    $cssParts = [];
    foreach ([
        resource_path('views/bio/css/base.css'),
        // bio-t15 F0: new-block styling (Lane A appends here; loaded after base so it can
        // lean on base tokens, before preset so a preset can still override).
        resource_path('views/bio/css/blocks-ext.css'),
        resource_path('views/bio/css/families/'.$family.'.css'),
        resource_path('views/bio/presets/css/'.$slug.'.css'),
        // bio-t15 F0: appearance overrides (Lane B appends here; last file before tokens so
        // per-page appearance beats the preset's residual CSS).
        resource_path('views/bio/css/appearance.css'),
    ] as $cssFile) {
        if (is_string($cssFile) && File::exists($cssFile)) {
            $cssParts[] = File::get($cssFile);
        }
    }

    $tokenBody = '';
    foreach ($tokens as $k => $v) {
        $k = preg_replace('/[^a-z0-9-]/i', '', (string) $k);
        if ($k === '' || ! str_starts_with($k, '--')) {
            continue;
        }
        $v = str_replace(['<', '>', '{', '}', ';', "\n", "\r"], ' ', (string) $v);
        $v = trim(mb_substr($v, 0, 200));
        if ($v === '') {
            continue;
        }
        $tokenBody .= $k.':'.$v.';';
    }
    $tokenCss = $tokenBody !== '' ? ':root,.bio--'.$slug.'{'.$tokenBody.'}' : '';

    // Strip CSS comments before emitting: slims the payload and guarantees no
    // stray style/script markup token inside a CSS comment can leak into the page.
    $fileCss = preg_replace('#/\*.*?\*/#s', '', implode("\n", $cssParts)) ?? '';
    $bioCss = trim($fileCss."\n".$tokenCss);

    // ---- Google Fonts link built from preset json ----
    $fontFamilies = [];
    foreach (($preset['fonts'] ?? []) as $font) {
        $fam = trim((string) ($font['family'] ?? ''));
        if ($fam === '') {
            continue;
        }
        $weights = \Juwri\Core\Support\Views\PublicSiteViewHelpers::validFontWeights((array) ($font['weights'] ?? [400]));
        $fontFamilies[] = 'family='.str_replace(' ', '+', $fam).':wght@'.implode(';', $weights);
    }
    $fontsHref = $fontFamilies
        ? 'https://fonts.googleapis.com/css2?'.implode('&', $fontFamilies).'&display=swap'
        : '';

    // ---- page meta ----
    $title = $bioSettings['title']
        ?? data_get($store, 'title')
        ?? data_get($store, 'name')
        ?? data_get($store, 'slug')
        ?? 'Menu';
    $themeColor = $tokens['--bg'] ?? '#111111';
    $source = preg_replace('/[^a-z]/', '', (string) ($source ?? 'direct')) ?: 'direct';

    // bio-t15 Lane B B3/B6: appearance passthrough from the controller.
    // $bgImageStyle is a plain `background-image:url(...)` declaration (already
    // sanitized + resolved through media_url() for THIS store by
    // BioAppearance::backgroundImageStyle()) or '' — inlined as a style="" attr
    // on .bio-body, NEVER as a CSS token (SPEC §5: image bg is not a token).
    $bgImageStyle = (string) ($bgImageStyle ?? '');
    $og = is_array($og ?? null) ? $og : [];
    $ogTitle = trim((string) ($og['title'] ?? '')) ?: $title;
    $ogDescription = trim((string) ($og['description'] ?? ''));
    $ogImage = trim((string) ($og['image'] ?? ''));
    $favicon = trim((string) ($favicon ?? ''));

    // Profile header fields are built by the controller (BioPublicController::
    // bioProfile) and passed in as `$profile`, because building them here did NOT
    // reach the header partials: Blade renders the family @section('page') eagerly
    // in the child view's scope, BEFORE this layout's @php runs — so _stack/_profile
    // saw an undefined $profile and always fell back to the "◈" glyph. Consuming it
    // as view data fixes that; the guard keeps a safe empty default for any renderer
    // that does not pass one.
    $profile = is_array($profile ?? null) ? $profile : [];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="robots" content="index,follow">
    <meta name="theme-color" content="{{ $themeColor }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $ogTitle }}</title>
    {{-- bio-t15 Lane B B6: OG/SEO + favicon (SPEC §6). og.image/favicon are
         already-resolved absolute URLs from the controller — escaped here on
         output, never trusted raw. --}}
    <meta property="og:title" content="{{ $ogTitle }}">
    <meta property="og:type" content="profile">
    <meta property="og:url" content="{{ url()->current() }}">
    @if ($ogDescription !== '')
        <meta name="description" content="{{ $ogDescription }}">
        <meta property="og:description" content="{{ $ogDescription }}">
    @endif
    @if ($ogImage !== '')
        <meta property="og:image" content="{{ $ogImage }}">
        <meta name="twitter:image" content="{{ $ogImage }}">
    @endif
    <meta name="twitter:card" content="{{ $ogImage !== '' ? 'summary_large_image' : 'summary' }}">
    @if ($favicon !== '')
        <link rel="icon" href="{{ $favicon }}">
    @endif
    @if ($fontsHref !== '')
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="{{ $fontsHref }}">
    @endif
    <style nonce="{{ csp_nonce() }}">{!! $bioCss !!}</style>
</head>
<body class="bio-body @if ($bgImageStyle !== '') bio-body--img @endif"@if ($bgImageStyle !== '') style="{{ $bgImageStyle }}"@endif>
    @include('bio._sprite')
    <main class="bio bio--{{ $slug }} fam-{{ $family }}" @if ($rtl) dir="rtl" @endif data-bio-preset="{{ $slug }}" data-bio-blocks="{{ count($blocks) }}" data-bio-source="{{ $source ?? 'direct' }}">
        @yield('page')
    </main>
    <script nonce="{{ csp_nonce() }}">
    (function () {
        var root = document.querySelector('.bio');
        if (!root) { return; }
        var src = root.getAttribute('data-bio-source') || 'direct';
        var meta = document.querySelector('meta[name="csrf-token"]');
        var token = meta ? meta.getAttribute('content') : '';
        document.addEventListener('click', function (e) {
            var el = e.target.closest ? e.target.closest('[data-bio-block]') : null;
            if (!el) { return; }
            var id = el.getAttribute('data-bio-block');
            if (!id) { return; }
            try {
                var body = 'source=' + encodeURIComponent(src) + (token ? '&_token=' + encodeURIComponent(token) : '');
                fetch(location.pathname.replace(/\/+$/, '') + '/c/' + encodeURIComponent(id), {
                    method: 'POST',
                    keepalive: true,
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-TOKEN': token,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: body
                }).catch(function () {});
            } catch (_) {}
        }, true);
    })();
    </script>
</body>
</html>
