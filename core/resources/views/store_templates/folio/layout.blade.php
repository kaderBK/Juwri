{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Folio — display-only flip-book layout. Deliberately does NOT extend
     store_templates/layout.blade.php (which injects the ordering UI) — same
     standalone approach as pageview. --}}
<!DOCTYPE html>
<html lang="{{ get_lang() }}" dir="{{ optional(current_language())->direction ?? 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5, viewport-fit=cover">
    <meta name="robots" content="index,follow">
    <title>{{ $store->title }}</title>
    @if($store->main_image && $store->main_image !== 'default.png')
        <link rel="icon" href="{{ asset('storage/store/logo/'.$store->main_image) }}">
    @endif
    {{-- A5 SEO: themes push og:title / og:image here (index.blade). --}}
    @stack('meta')
    @stack('style')
    {{-- Customizer v2 (WI-T1/WI-T3). Folio deliberately does not extend the
         shared layout, so it includes the token block itself — otherwise it
         would be a theme whose schema offers the roles and whose page never
         gets the vars (same reasoning as pageview). --}}
    @include('store_templates._partials.theme-tokens')
    {{-- Customizer v2 (WI-T2): @font-face for the selected self-hosted font pair.
         Silent when no pair is selected. --}}
    @include('store_templates._partials.theme-fonts')
    {{-- Saudi Riyal (U+20C1): always-on @font-face, scoped by unicode-range. --}}
    @include('store_templates._partials.riyal-font')
</head>
<body class="folio-body">
    {{-- Customizer v2 (WI-U3) — live-preview receiver. Folio does not extend
         the shared layout, so it needs its own include to be editable live. --}}
    @include('store_templates._partials.theme-preview-bridge')
    {{-- Shared splash/onboarding overlay. Self-gates on splash.enabled +
         splash.style === 'shared' (default OFF); Folio ships no native intro, so
         the shared style is the only one. Standalone layout = its own include. --}}
    @include('store_templates._partials.splash')
    {{-- Stories Entry Points ("SE" run). stories_entry_for() owns the resolution
         chain (store customizer → super-admin default → 'ribbon'), and returns
         'none' when the store has no publishable stories — so the mount + bundle
         only ship when an entry will actually render. Called in this theme-owned
         layout (never a shared partial), so the schema-lint never charges the
         'stories.entry' key to other themes. Sits AFTER the splash include. --}}
    @php $seEntry = stories_entry_for($store->id); @endphp
    @if($seEntry !== 'none')
        <link rel="stylesheet"
              href="{{ asset('assets/global/stories-entries/stories-entries.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/global/stories-entries/stories-entries.css'))) }}">
        <div id="seRoot" class="se-root"
             data-se-entry="{{ $seEntry }}"
             data-se-feed="{{ route('stories.public', $store->slug) }}"
             data-se-rtl="{{ optional(current_language())->direction === 'rtl' ? '1' : '0' }}"
             data-se-name="{{ $store->title }}"
             data-se-logo="{{ ($store->main_image && $store->main_image !== 'default.png') ? asset('storage/store/logo/'.$store->main_image) : '' }}"
             data-se-post="{{ (int) $store->id }}"
             data-se-t-stories="{{ ___('Stories') }}"
             data-se-t-open="{{ ___('View stories') }}"
             data-se-t-close="{{ ___('Close') }}"></div>
        <script src="{{ asset('assets/global/stories-entries/stories-entries.js?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/global/stories-entries/stories-entries.js'))) }}"
                nonce="{{ csp_nonce() }}" defer></script>
    @endif
    @yield('content')
    {{-- Shared Web Share button. Default OFF per store (Customize → Share); Folio's
         toolbar has no native share, so this is the one that renders when enabled. --}}
    @include('store_templates._partials.share')
    @stack('script')
{{-- P1-23: "Cookie settings" + the consent banner on every storefront theme. --}}
@include('partials.pharmacy-licence')
@include('store_templates._partials.cookie-settings-link')
</body>
</html>
