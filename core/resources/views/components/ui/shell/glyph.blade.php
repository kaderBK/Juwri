{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.shell.glyph name="calendar-event" kit="calendar" /> — a shell icon.

  Resolution order (J4):
    1. a kit line icon (<x-ui.icon>) when `name` is one of the kit's own names;
    2. the vendored Tabler Icons SVG (assets/vendor/tabler-icons, MIT) for `name`;
    3. the kit icon named by `kit`, so the shell never renders an empty slot while
       the Tabler set is still being vendored.
  Decorative (aria-hidden); the parent control carries the accessible name.
--}}
@props(['name', 'kit' => 'dots', 'size' => 20])
@php
    $__kitNames = ['home', 'orders', 'products', 'sold-out', 'store', 'more', 'search', 'bell', 'plus', 'check', 'x',
        'chevron', 'chevron-down', 'back', 'clock', 'alert', 'info', 'error', 'star', 'flame', 'dine-in', 'delivery',
        'pickup', 'phone', 'chat', 'pin', 'print', 'pause', 'qr', 'share', 'users', 'external', 'filter', 'trend-up',
        'dots', 'chart', 'calendar', 'bed', 'book-open', 'sparkle', 'settings', 'kitchen', 'gift', 'pencil', 'grip',
        'list', 'upload', 'trash', 'wallet', 'card', 'download', 'mic', 'refresh'];
    $__svg = null;
    if (! in_array($name, $__kitNames, true) && preg_match('/^[a-z0-9-]+$/', (string) $name)) {
        foreach (['assets/vendor/tabler-icons/outline/', 'assets/vendor/tabler-icons/'] as $__dir) {
            $__file = public_path($__dir.$name.'.svg');
            if (is_file($__file)) {
                $__raw = (string) file_get_contents($__file);
                // Keep only the drawing; the wrapper below sets size, stroke and a11y.
                if (preg_match('#<svg[^>]*>(.*)</svg>#s', $__raw, $__m)) {
                    $__svg = $__m[1];
                }
                break;
            }
        }
    }
@endphp
@if ($__svg !== null)
<svg {{ $attributes->class('ui-glyph') }} width="{{ (int) $size }}" height="{{ (int) $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">{!! $__svg !!}</svg>
@else
<x-ui.icon :name="in_array($name, $__kitNames, true) ? $name : $kit" :size="$size" {{ $attributes->class('ui-glyph') }} />
@endif
