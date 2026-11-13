{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.layout> — owner dashboard page shell on the owner-ui kit.

    <x-ui.layout body-class="is-admin">…page…</x-ui.layout>

  Draws the sidebar (x-ui.shell.sidebar), top bar (x-ui.shell.topbar), phone tab
  bar (x-ui.shell.tabbar) and account notices around the slot. Pages that still
  extend the section-based layout keep working: `page_title` / `page_sub` /
  `page_actions` / `body_class` sections and the `styles` / `head` / `scripts` /
  `scripts_at_bottom` stacks are read here.

  Inside the native app (OwnerApp::inApp) the web chrome is hidden by CSS
  (shell.css / layout.css) and the tab bar is not rendered at all.
--}}
@props(['bodyClass' => ''])
@php
    $__inApp = \Juwri\Core\Support\OwnerApp::inApp();
    $__lang = function_exists('current_language') ? current_language() : null;
    // Phone tab bar: owners only (staff keep their own menus), never inside the app.
    $__tabs = [];
    if (config('owner_ui.mobile_tabbar') && ! $__inApp && auth()->check()
        && ! (function_exists('is_staff_dashboard') && is_staff_dashboard())) {
        $__tabStore = function_exists('dashboard_current_store') ? dashboard_current_store() : null;
        $__tabs = $__tabStore ? \Juwri\Core\Support\OwnerApp::tabsFor($__tabStore) : [];
    }
    $__bodyClass = trim(implode(' ', array_filter([
        'ui-shell-body', trim($__env->yieldContent('body_class')), $bodyClass, count($__tabs) ? 'ui-has-tabbar' : null,
    ])));
@endphp
<!DOCTYPE html>
<html lang="{{ function_exists('get_lang') ? get_lang() : app()->getLocale() }}"
      dir="{{ $__lang ? $__lang->direction : 'ltr' }}"
      @if ($__inApp) data-owner-app="1" @endif
      @class(['ui-skin' => config('owner_ui.skin')])>
<head>
    <x-ui.shell.head />
</head>
<body class="{{ $__bodyClass }}">
    <a class="ui-skip skip-link" href="#main">{{ ___('Skip to content') }}</a>
    <x-ui.shell.impersonation />

    <div class="ui-shell">
        <x-ui.shell.sidebar />
        <div class="ui-shell__body">
            <x-ui.shell.topbar />
            <main id="main" class="ui-shell__main" tabindex="-1">
                @hasSection('page_title')
                    <header class="ui-page-head">
                        <div>
                            <h1 class="ui-page-head__title">@yield('page_title')</h1>
                            @hasSection('page_sub')
                                <p class="ui-page-head__sub">@yield('page_sub')</p>
                            @endif
                        </div>
                        @hasSection('page_actions')
                            <div class="ui-page-head__actions">@yield('page_actions')</div>
                        @endif
                    </header>
                @endif

                {{-- A feature page that passed its plan gate (EnsureFeatureEnabled shares
                     $pageFeatureKey) gets its "included in your plan" ribbon and open
                     setup steps here, so a gated page added later inherits both. --}}
                @isset($pageFeatureKey)
                    <x-spotlight.ribbon :key="$pageFeatureKey" />
                    <x-spotlight.checklist :key="$pageFeatureKey" />
                @endisset

                <x-ui.shell.notices />

                {{ $slot }}
            </main>
        </div>
    </div>

    @if (count($__tabs))
        <x-ui.shell.tabbar :tabs="$__tabs" />
    @endif

    <x-ui.shell.legacy-scripts />
    <x-ui.shell.scripts />

    @stack('scripts')
    @stack('scripts_at_bottom')
</body>
</html>
