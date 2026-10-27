{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Shared POS line-icon. Usage: @include('staff.pos.partials.icon', ['name' => 'user'])
     Optional 'size' (px, default 22). Renders a 24-grid stroke=currentColor SVG so it
     inherits the caller's text/accent colour and stays crisp + monochrome L/D. --}}
@php
    $posIcons = [
        'home'     => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9.5"/>',
        'grid'     => '<rect x="3" y="3" width="7" height="7" rx="1.6"/><rect x="14" y="3" width="7" height="7" rx="1.6"/><rect x="3" y="14" width="7" height="7" rx="1.6"/><rect x="14" y="14" width="7" height="7" rx="1.6"/>',
        'orders'   => '<path d="M5 3.5h10l4 4V20a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V4.5a1 1 0 0 1 1-1Z"/><path d="M8 9h6M8 13h8M8 17h5"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 13a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-2.87 1.2V21a2 2 0 1 1-4 0v-.09a1.7 1.7 0 0 0-2.87-1.2l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 13H4.5a2 2 0 1 1 0-4h.09a1.7 1.7 0 0 0 1.2-2.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 11 3.6V3.5a2 2 0 1 1 4 0v.09a1.7 1.7 0 0 0 2.87 1.2l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 20.4 9h.1a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.01.99Z"/>',
        'lock'     => '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/>',
        'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'cart'     => '<circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2 3h3l2.4 12.4a1.5 1.5 0 0 0 1.5 1.2h8.2a1.5 1.5 0 0 0 1.5-1.2L21 7H6"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.4-3.4"/>',
        'alert'    => '<path d="M12 3 2.5 20h19L12 3Z"/><path d="M12 10v4M12 17.4v.01"/>',
        'utensils' => '<path d="M5 3v6a2 2 0 0 0 4 0V3M7 9v12M17 3c-1.7 0-3 2-3 5s1 4 3 4v9"/>',
        'table'    => '<path d="M3 8h18M5 8l1.5-3h11L19 8M6 8v9M18 8v9"/>',
        'receipt'  => '<path d="M6 3h12v18l-2-1.3L14 21l-2-1.3L10 21l-2-1.3L6 21V3Z"/><path d="M9 8h6M9 12h6"/>',
        'check'    => '<circle cx="12" cy="12" r="9"/><path d="m8.4 12 2.4 2.4 4.8-5"/>',
        'backspace'=> '<path d="M8 5h12a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8L2 12 8 5Z"/><path d="m18 9.5-5 5M13 9.5l5 5"/>',
        'back'     => '<path d="M14 6l-6 6 6 6"/><path d="M8 12h12"/>',
        'chart'    => '<path d="M4 20V10M10 20V4M16 20v-7M21 20H3"/>',
    ];
    $s = $size ?? 22;
@endphp
<svg class="pos-ico" viewBox="0 0 24 24" width="{{ $s }}" height="{{ $s }}" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $posIcons[$name] ?? '' !!}</svg>
