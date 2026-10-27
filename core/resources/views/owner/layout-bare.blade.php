{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Bare owner page (no sidebar / top bar) for payment hand-offs:
  @extends('owner.layout-bare'). Kit tokens + components, the kit action
  script, jQuery for gateway widgets that still need it, and the usual
  `title` section and `styles` / `scripts` / `scripts_at_bottom` stacks.
--}}
@php
    $__lang = \Juwri\Core\Support\Views\PublicSiteViewHelpers::currentLanguageRaw();
    $__accent = $settings->theme_color ?? null;
    $__accent = (is_string($__accent) && preg_match('/^#[0-9A-Fa-f]{6}$/', $__accent)) ? $__accent : '#6366f1';
@endphp
<!DOCTYPE html>
<html lang="{{ function_exists('get_lang') ? get_lang() : app()->getLocale() }}" dir="{{ $__lang ? $__lang->direction : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <x-ui.shell.theme-paint />
    <title>@yield('title', brand_title())</title>
    <link rel="icon" href="{{ brand_favicon() ?: asset('storage/'.($settings->site_favicon ?? 'favicon.png')) }}">
    <style nonce="{{ csp_nonce() }}">:root{--theme-color:{{ $__accent }};--ui-on-accent:{{ \Juwri\Core\Support\OwnerUi::onAccent($__accent) }};}</style>
    <link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/tokens.css') }}">
    <link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/components.css') }}">
    <link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/screens.css') }}">
    <script src="{{ asset('assets/global/js/jquery.min.js') }}"></script>
    <script src="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/actions.js') }}" defer></script>
    @stack('styles')
</head>
<body class="ui-scope ui-bare">
    <main class="ui-bare__main" id="main">
        @yield('content')
    </main>
    @stack('scripts')
    @stack('scripts_at_bottom')
</body>
</html>
