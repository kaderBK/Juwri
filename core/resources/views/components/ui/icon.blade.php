{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.icon name="orders" /> — owner UI kit line icons (24px grid, 1.9 stroke,
  currentColor). Decorative by default (aria-hidden); give the parent control an
  accessible name. Directional icons (chevron, arrow, bike, external) are
  mirrored in RTL by the .ui-flip-rtl helper when `flip` is set.

  Names the kit does not draw fall back to Tabler Icons (MIT), vendored under
  assets/vendor/tabler-icons/ (only the icons in use; see THIRD-PARTY.md):
  `name="lock"` → outline/lock.svg, `name="star-filled"` → filled/star.svg.
  The SVG body is inlined with the kit's attributes, so it inherits
  currentColor like the kit icons. A name found nowhere renders nothing.
--}}
@props(['name', 'size' => 20, 'flip' => false])
@php
    $paths = [
        'home' => '<path d="M3 10.2 12 3l9 7.2V20a1 1 0 0 1-1 1h-5.5v-6h-5v6H4a1 1 0 0 1-1-1z"/>',
        'orders' => '<path d="M6 2.8h12v18.4l-3-2-3 2-3-2-3 2z"/><path d="M9.2 8h5.6M9.2 11.8h5.6M9.2 15.6h3.2"/>',
        'products' => '<path d="m20.6 13.4-7.2 7.2a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.6"/>',
        'sold-out' => '<path d="m20.6 13.4-7.2 7.2a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><path d="M3.5 3.5l17 17"/>',
        'store' => '<path d="M4 10v10h16V10"/><path d="M2.5 9.5 4.5 4h15l2 5.5a3 3 0 0 1-5.8 1 3 3 0 0 1-3.7 2 3 3 0 0 1-3.7-2 3 3 0 0 1-5.8-1z"/><path d="M10 20v-5h4v5"/>',
        'more' => '<rect x="3.5" y="3.5" width="7" height="7" rx="2"/><rect x="13.5" y="3.5" width="7" height="7" rx="2"/><rect x="3.5" y="13.5" width="7" height="7" rx="2"/><rect x="13.5" y="13.5" width="7" height="7" rx="2"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
        'bell' => '<path d="M6 16v-5a6 6 0 0 1 12 0v5l1.5 2h-15z"/><path d="M10 20.5a2 2 0 0 0 4 0"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'x' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'backspace' => '<path d="M20 5H9l-7 7 7 7h11a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2z"/><path d="m18 9-6 6"/><path d="m12 9 6 6"/>',
        'chevron' => '<path d="m9 6 6 6-6 6"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'back' => '<path d="m15 6-6 6 6 6"/>',
        'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'alert' => '<path d="M12 3.5 2.5 20h19z"/><path d="M12 10v4.5M12 17.2v.1"/>',
        'info' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5M12 8v.1"/>',
        'error' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 8v5M12 16v.1"/>',
        'star' => '<path d="m12 3.5 2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z"/>',
        'flame' => '<path d="M12 21a7 7 0 0 0 7-7c0-4-3-6-4-9-1.5 2-2 3.5-2 5-1-1-2-2-2.5-4C8 8 5 10.5 5 14a7 7 0 0 0 7 7z"/>',
        'dine-in' => '<path d="M7 3v8M5 3v5a2 2 0 0 0 4 0V3M7 11v10"/><path d="M17 21V3c-2 1.5-3 4-3 7v3h3"/>',
        'delivery' => '<circle cx="6" cy="17" r="3"/><circle cx="18" cy="17" r="3"/><path d="M6 17h6l3-7h3M15 10l3 7M10 7h4"/>',
        'pickup' => '<path d="M5 8h14l-1 13H6z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
        'phone' => '<path d="M5 3.5h3.5l2 5-2.5 1.5a11 11 0 0 0 6 6l1.5-2.5 5 2V19a2 2 0 0 1-2 2A17 17 0 0 1 3 5.5a2 2 0 0 1 2-2z"/>',
        'chat' => '<path d="M4 18.5V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H8z"/>',
        'pin' => '<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
        'print' => '<path d="M7 9V3.5h10V9"/><rect x="3.5" y="9" width="17" height="8" rx="2"/><path d="M7 14h10v6.5H7z"/>',
        'pause' => '<circle cx="12" cy="12" r="8.5"/><path d="M10 9v6M14 9v6"/>',
        'qr' => '<rect x="3.5" y="3.5" width="7" height="7" rx="1.2"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.2"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.2"/><path d="M14 14h3v3h-3zM20 14v.1M17 20h3v-3"/>',
        'share' => '<circle cx="18" cy="5.5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="18.5" r="2.5"/><path d="m8.2 10.8 7.6-4.1M8.2 13.2l7.6 4.1"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.8a3.5 3.5 0 0 1 0 6.4M18 14a6.5 6.5 0 0 1 3.5 6"/>',
        'external' => '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
        'filter' => '<path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/>',
        'trend-up' => '<path d="m4 16 6-6 4 4 6-7"/><path d="M15 7h5v5"/>',
        'dots' => '<circle cx="5" cy="12" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="19" cy="12" r="1.2"/>',
        'chart' => '<path d="M4 20V11M10 20V5M16 20v-6M3 20.5h18"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
        'bed' => '<path d="M3 18.5V6M3 14h18v4.5M21 14a3.5 3.5 0 0 0-3.5-3.5H11V14"/><circle cx="7" cy="10.5" r="2"/>',
        'book-open' => '<path d="M12 7c-1.5-1.5-4-2.5-8-2.5V18c4 0 6.5 1 8 2.5 1.5-1.5 4-2.5 8-2.5V4.5c-4 0-6.5 1-8 2.5z"/><path d="M12 7v13.5"/>',
        'sparkle' => '<path d="M12 3c.6 4.2 2.8 6.4 7 7-4.2.6-6.4 2.8-7 7-.6-4.2-2.8-6.4-7-7 4.2-.6 6.4-2.8 7-7z"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'kitchen' => '<path d="M6 13.5a4 4 0 0 1-.6-7.9A5 5 0 0 1 15 4.2a4 4 0 0 1 3 7.3v1.9"/><path d="M6 13.5V20h12v-6.5M6 16.5h12"/>',
        'gift' => '<rect x="3.5" y="8" width="17" height="4.5" rx="1"/><path d="M5 12.5V20h14v-7.5M12 8v12M12 8c-1.5-3.5-5.5-4-5.5-1.5S10 8 12 8zm0 0c1.5-3.5 5.5-4 5.5-1.5S14 8 12 8z"/>',
        'pencil' => '<path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>',
        'grip' => '<circle cx="9" cy="6" r="1.2"/><circle cx="15" cy="6" r="1.2"/><circle cx="9" cy="12" r="1.2"/><circle cx="15" cy="12" r="1.2"/><circle cx="9" cy="18" r="1.2"/><circle cx="15" cy="18" r="1.2"/>',
        'list' => '<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1.2"/><circle cx="4.5" cy="12" r="1.2"/><circle cx="4.5" cy="18" r="1.2"/>',
        'upload' => '<path d="M12 20V9M7 13.5l5-5 5 5M4.5 4.5h15"/>',
        'trash' => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 12.5a1.5 1.5 0 0 0 1.5 1.5h7a1.5 1.5 0 0 0 1.5-1.5L18 7M9 7V4.5A1.5 1.5 0 0 1 10.5 3h3A1.5 1.5 0 0 1 15 4.5V7"/>',
        'wallet' => '<path d="M19 8V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h14a1 1 0 0 0 1-1v-3"/><path d="M21 12v4h-4a2 2 0 0 1 0-4z"/><path d="M3 8h16a1 1 0 0 1 1 1v3"/>',
        'card' => '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 10h19M6 15h4"/>',
        'download' => '<path d="M12 4v11M7 10.5l5 5 5-5M4.5 19.5h15"/>',
        'mic' => '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21"/>',
        'refresh' => '<path d="M20 11a8 8 0 0 0-14.3-4.3L4 8.5M4 4v4.5h4.5M4 13a8 8 0 0 0 14.3 4.3l1.7-1.8M20 20v-4.5h-4.5"/>',
    ];
    $inner = $paths[$name] ?? null;
    $filled = false;
    if ($inner === null && is_string($name) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name) === 1) {
        $filled = str_ends_with($name, '-filled');
        $file = base_path('../assets/vendor/tabler-icons/'.($filled ? 'filled/'.substr($name, 0, -7) : 'outline/'.$name).'.svg');
        // No closure here: a closure literal in a compiled view leaks ~250 bytes on every render (PHP 8.4, see TablerIcons).
        $inner = \Juwri\Core\Support\TablerIcons::body($file);
    }
@endphp
@if ($inner)
@if ($filled)
<svg {{ $attributes->class(['ui-flip-rtl' => $flip]) }} width="{{ (int) $size }}" height="{{ (int) $size }}" viewBox="0 0 24 24" fill="currentColor" stroke="none" aria-hidden="true" focusable="false">{!! $inner !!}</svg>
@else
<svg {{ $attributes->class(['ui-flip-rtl' => $flip]) }} width="{{ (int) $size }}" height="{{ (int) $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">{!! $inner !!}</svg>
@endif
@endif
