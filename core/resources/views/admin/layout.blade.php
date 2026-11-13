{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Superadmin page layout on the owner-ui kit (WI-10): @extends('admin.layout').

  Sections read here:
    title           page heading (also the <title>)
    page_sub        optional line under the heading
    header_buttons  actions at the end of the heading row
    content         the screen
  Stacks: styles, scripts_at_top, scripts, scripts_at_bottom.
  Every superadmin screen is on the kit (WI-10); the old Bootstrap/NexLink
  bridge layout is gone.
--}}
@php
    $__lang = function_exists('current_language') ? current_language() : null;
    $__rtl = $__lang && $__lang->direction === 'rtl';
@endphp
<!DOCTYPE html>
<html lang="{{ function_exists('get_lang') ? get_lang() : app()->getLocale() }}" dir="{{ $__rtl ? 'rtl' : 'ltr' }}"
      @class(['ui-skin' => config('owner_ui.skin')]) data-admin="kit">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <x-ui.shell.theme-paint />
    <title>{{ page_title($__env) }}</title>
    <link rel="icon" href="{{ asset('storage/logo/'.($settings->site_favicon ?? 'favicon.png')) }}">
    @include('admin.includes.styles', ['rtl' => $__rtl])
</head>
<body class="ui-shell-body ui-admin">
    <a class="ui-skip skip-link" href="#main">{{ ___('Skip to content') }}</a>
    <x-demo-frame />

    <div class="ui-shell">
        @include('admin.includes.sidebar')
        <div class="ui-shell__body">
            @include('admin.includes.header')
            <main id="main" class="ui-shell__main" tabindex="-1">
                <header class="ui-page-head">
                    <div>
                        <h1 class="ui-page-head__title">@yield('title')</h1>
                        @hasSection('page_sub')
                            <p class="ui-page-head__sub">@yield('page_sub')</p>
                        @endif
                    </div>
                    @hasSection('header_buttons')
                        <div class="ui-page-head__actions">@yield('header_buttons')</div>
                    @endif
                </header>

                <x-ui.shell.licence-notice />

                @yield('content')
            </main>

            <footer class="ui-admin-foot">
                <span>© {{ date('Y') }} {{ $settings->site_title ?? '' }}</span>
                <span class="ui-tag" data-tone="neutral">v{{ config('appinfo.version') }}</span>
                <x-ui.shell.licensed-to />
                <nav class="ui-admin-foot__links" aria-label="{{ ___('Footer') }}">
                    <a href="{{ route('admin.dashboard') }}">{{ ___('Dashboard') }}</a>
                    <a href="{{ url('/developers') }}" target="_blank" rel="noopener">{{ ___('API docs') }}</a>
                    <a href="{{ url('/') }}" target="_blank" rel="noopener">{{ ___('View site') }}</a>
                </nav>
            </footer>
        </div>
    </div>

    @include('admin.includes.scripts')
</body>
</html>
