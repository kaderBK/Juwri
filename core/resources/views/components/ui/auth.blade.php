{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.auth> — sign-in / sign-up page frame on the owner-ui kit (WI-16b).

  Two columns from 1024px: a brand panel (logo, one line of promise, three
  selling points) and the form column; phones get the form column only.
  Section-based auth views keep working through templates/…/layouts/auth:
  `title`, `meta_description`, `canonical`, `auth_heading`, `auth_sub` and
  `auth_footer` sections plus the `styles` / `scripts` stacks are read here.
  Brand: reseller white-label on a reseller host, else the platform settings.
--}}
@php
    $__reseller = active_brand_reseller();
    $__brand = brand_title();
    $__lang = function_exists('current_language') ? current_language() : null;
    $__code = function_exists('get_lang') ? get_lang() : app()->getLocale();
    $__rtl = ($__lang && $__lang->direction === 'rtl') || in_array($__code, ['ar', 'ur', 'fa', 'he', 'ps', 'sd', 'dv'], true);
    $__accent = ($__reseller && $__reseller->primary_color) ? $__reseller->primary_color : ($settings->theme_color ?? null);
    $__accent = \Juwri\Core\Support\HexColor::normalize($__accent); // BL-036 R1
    
    $__langs = (isset($languages) ? collect($languages) : collect())->unique('code')->values();
    $__logo = ($__reseller && $__reseller->brand_logo)
        ? $__reseller->brand_logo
        : (! empty($settings->site_logo) ? asset('storage/logo/'.$settings->site_logo) : null);
    $__flashes = [];
    foreach (['success' => 'success', 'info' => 'info', 'warning' => 'warning', 'danger' => 'danger', 'error' => 'danger'] as $__key => $__tone) {
        if (session()->has($__key) && is_string(session($__key))) {
            $__flashes[] = [$__tone, session($__key)];
        }
    }
@endphp
<!DOCTYPE html>
<html lang="{{ $__code }}" dir="{{ $__rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<x-ui.shell.theme-paint />
<title>@yield('title', $__brand)</title>
{{-- A clean search snippet for the indexable pages (login, register). --}}
<meta name="description" content="@yield('meta_description', $__brand)">
<link rel="canonical" href="@yield('canonical', url()->current())">
{!! seo_hreflang_tags() !!}
<link rel="icon" href="{{ brand_favicon() ?: asset('storage/'.($settings->site_favicon ?? 'favicon.png')) }}">
{{-- Instrument Sans + Syne self-hosted (assets/fonts, OFL): no third-party request, CSP/SRI clean. --}}
<link rel="preload" href="{{ asset('assets/fonts/instrument-sans-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="{{ asset('assets/fonts/syne-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('assets/fonts/fonts.css') }}">
{{-- Alexandria (Arabic headings) is self-hosted in assets/fonts/fonts.css (OFL, ALEXANDRIA-OFL.txt): the browser fetches it only when RTL text uses it. --}}
@include('partials.arabic-font')
@include('partials.brand-tile')
@if ($__accent)
<style nonce="{{ csp_nonce() }}">:root{--theme-color:{{ $__accent }};--ui-on-accent:{{ \Juwri\Core\Support\OwnerUi::onAccent($__accent) }};}</style>
@endif
<link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/tokens.css') }}">
<link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/components.css') }}">
<link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/layout.css') }}">
<link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/auth.css') }}">
<script src="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/owner-ui.js') }}" defer></script>
<script src="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/layout.js') }}" defer></script>
@stack('styles')
</head>
<body class="ui-auth-body">
<div class="ui-auth">
    <aside class="ui-auth__brand" aria-label="{{ $__brand }}">
        <a class="ui-auth__logo" href="{{ route('home') }}">
            @if ($__logo)
                <span class="brand-tile brand-tile--sm brand-tile--dark"><img src="{{ $__logo }}" alt="{{ $__brand }}"></span>
            @else
                <span>{{ $__brand }}</span>
            @endif
        </a>
        <div>
            <p class="ui-auth__promise">{{ ___('Run your restaurant from one screen.') }}</p>
            <ul class="ui-auth__points">
                <li><x-ui.icon name="qr" size="20" />{{ ___('QR menus for every table') }}</li>
                <li><x-ui.icon name="orders" size="20" />{{ ___('Online ordering, no commission') }}</li>
                <li><x-ui.icon name="trend-up" size="20" />{{ ___('Live scans, orders, and revenue') }}</li>
            </ul>
        </div>
        <p class="ui-auth__legal">© {{ date('Y') }} {{ $__brand }}</p>
    </aside>

    <div class="ui-auth__side">
        <header class="ui-auth__bar">
            <a class="ui-auth__back" href="{{ route('home') }}">
                <x-ui.icon name="back" size="18" flip />
                <span>{{ ___('Back to home') }}</span>
            </a>
            <div class="ui-auth__tools">
                @if ($__langs->count() > 1)
                    <div class="ui-pop-host" data-ui-pop>
                        <button type="button" class="ui-top__btn ui-top__lang" data-ui-pop-toggle aria-expanded="false" aria-haspopup="true"
                                aria-label="{{ ___('Change language') }}: {{ strtoupper($__code) }}">{{ strtoupper($__code) }}</button>
                        <div class="ui-pop ui-pop--end" data-ui-pop-panel hidden>
                            @foreach ($__langs as $__l)
                                <a class="ui-pop__item" href="{{ function_exists('lang_url') ? lang_url($__l->code) : url('change-lang/'.$__l->code) }}"
                                   @if ($__l->code === $__code) aria-current="true" @endif>
                                    <span class="ui-pop__label">{{ $__l->name }}</span>
                                    @if ($__l->code === $__code)<x-ui.icon name="check" size="16" />@endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
                <button type="button" class="ui-top__btn" data-ui-theme-toggle aria-pressed="false"
                        aria-label="{{ ___('Toggle theme') }}"
                        data-label-dark="{{ ___('Toggle theme') }}" data-label-light="{{ ___('Toggle theme') }}">
                    <x-ui.shell.glyph name="moon" kit="sparkle" class="ui-top__moon" />
                    <x-ui.shell.glyph name="sun" kit="flame" class="ui-top__sun" />
                </button>
            </div>
        </header>

        <main class="ui-auth__main" id="main">
            <div class="ui-auth__card">
                <a class="ui-auth__mark" href="{{ route('home') }}">
                    @if ($__logo)
                        <span class="brand-tile brand-tile--sm"><img src="{{ $__logo }}" alt="{{ $__brand }}"></span>
                    @else
                        <span>{{ $__brand }}</span>
                    @endif
                </a>

                @hasSection('auth_heading')
                    <h1 class="ui-auth__title">@yield('auth_heading')</h1>
                @endif
                @hasSection('auth_sub')
                    <p class="ui-auth__sub">@yield('auth_sub')</p>
                @endif

                @foreach ($__flashes as [$__tone, $__message])
                    <x-ui.banner :tone="$__tone" class="ui-auth__flash">{{ $__message }}</x-ui.banner>
                @endforeach

                {{ $slot }}

                @hasSection('auth_footer')
                    <div class="ui-auth__foot">@yield('auth_footer')</div>
                @endif
            </div>
        </main>

        <p class="ui-auth__legal ui-auth__legal--side">© {{ date('Y') }} {{ $__brand }}</p>
    </div>
</div>
@stack('scripts')
</body>
</html>
