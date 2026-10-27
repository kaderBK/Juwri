{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.shell.tabbar :tabs="$tabs" /> — phone tab bar (below 768px; never inside
  the native app, which draws its own). Same five destinations as the app
  (OwnerApp::tabsFor): Home, work board, catalogue, Reports, More. "More" opens
  the sidebar drawer ([data-ui-menu]).
--}}
@props(['tabs' => []])
@php
    $__glyphs = [
        'home' => 'home', 'receipt' => 'orders', 'package' => 'products', 'book-open' => 'book-open',
        'calendar' => 'calendar', 'bed' => 'bed', 'chart' => 'chart', 'menu' => 'more',
    ];
    // Compare paths without the /{locale} prefix, trailing slash or query string.
    $__here = \Juwri\Core\Support\ViewHelpers::barePath('/'.ltrim(request()->path(), '/'));
    $__current = null;
    foreach ($tabs as $__t) {
        if (! $__t['path']) {
            continue;
        }
        $__p = \Juwri\Core\Support\ViewHelpers::barePath($__t['path']);
        if ($__t['key'] === 'home' ? $__here === $__p : ($__here === $__p || str_starts_with($__here, $__p.'/'))) {
            $__current = $__t['key'];
            break;
        }
    }
@endphp
<nav class="ui-tabbar" aria-label="{{ ___('Main Menu') }}">
    @foreach ($tabs as $__t)
        @if ($__t['path'])
            <a href="{{ $__t['path'] }}" class="ui-tabbar__item" @if ($__current === $__t['key']) aria-current="page" @endif>
                <x-ui.icon :name="$__glyphs[$__t['icon']] ?? 'more'" size="24" />
                <span>{{ $__t['label'] }}</span>
            </a>
        @else
            <button type="button" class="ui-tabbar__item" data-ui-menu aria-controls="appMenubar">
                <x-ui.icon name="more" size="24" />
                <span>{{ $__t['label'] }}</span>
            </button>
        @endif
    @endforeach
</nav>
