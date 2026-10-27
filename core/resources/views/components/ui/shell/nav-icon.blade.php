{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.shell.nav-icon :glyph="$item['icon']" /> — renders a navigation glyph.

  The navigation model (juwri_nav_model, Lane B) still names icons with the
  retired icon-font keys ("fi-rr-receipt"). This maps each key to a Tabler name
  plus the nearest kit icon, so the shell draws SVG only (J4). The model can move
  to Tabler names later; plain Tabler/kit names pass straight through.
--}}
@props(['glyph' => null, 'size' => 20])
@php
    $__map = [
        'angle-right' => ['chevron-right', 'chevron'], 'apps' => ['apps', 'more'], 'apps-add' => ['category-plus', 'more'],
        'arrows-repeat' => ['repeat', 'refresh'], 'badge' => ['award', 'star'], 'badge-check' => ['rosette-discount-check', 'check'],
        'bed' => ['bed', 'bed'], 'bed-alt' => ['bed', 'bed'], 'bike' => ['bike', 'delivery'], 'bolt' => ['bolt', 'flame'],
        'book' => ['book', 'book-open'], 'book-alt' => ['book-2', 'book-open'], 'books' => ['books', 'book-open'],
        'bookmark' => ['bookmark', 'star'], 'box' => ['box', 'products'], 'box-alt' => ['package', 'products'],
        'broom' => ['brush', 'sparkle'], 'building' => ['building', 'store'], 'calculator' => ['calculator', 'list'],
        'calendar' => ['calendar', 'calendar'], 'calendar-check' => ['calendar-check', 'calendar'],
        'calendar-clock' => ['calendar-time', 'calendar'], 'calendar-lines' => ['calendar-event', 'calendar'],
        'cash-register' => ['cash-register', 'card'], 'chart-histogram' => ['chart-histogram', 'chart'],
        'chart-line-up' => ['chart-line', 'trend-up'], 'chart-pie' => ['chart-pie', 'chart'], 'chart-pie-alt' => ['chart-donut', 'chart'],
        'clock' => ['clock', 'clock'], 'cloud-download' => ['cloud-download', 'download'], 'cocktail' => ['glass-cocktail', 'dine-in'],
        'code-simple' => ['code', 'list'], 'coins' => ['coins', 'wallet'], 'comment-alt' => ['message', 'chat'],
        'comment-check' => ['message-check', 'chat'], 'comment-dots' => ['message-dots', 'chat'],
        'comment-heart' => ['message-heart', 'chat'], 'comments' => ['messages', 'chat'], 'computer' => ['device-desktop', 'more'],
        'concierge-bell' => ['bell-ringing', 'bell'], 'copy-alt' => ['copy', 'list'], 'dashboard' => ['dashboard', 'chart'],
        'devices' => ['devices', 'phone'], 'document' => ['file-text', 'list'], 'download' => ['download', 'download'],
        'duplicate' => ['copy', 'list'], 'exchange' => ['arrows-exchange', 'refresh'], 'eye' => ['eye', 'info'],
        'file-invoice' => ['file-invoice', 'orders'], 'filter' => ['filter', 'filter'], 'gift' => ['gift', 'gift'],
        'globe' => ['world', 'external'], 'globe-alt' => ['world-www', 'external'], 'grid' => ['layout-grid', 'more'],
        'hamburger' => ['burger', 'dine-in'], 'hat-chef' => ['chef-hat', 'kitchen'], 'headset' => ['headset', 'chat'],
        'heart' => ['heart', 'star'], 'images' => ['photo', 'upload'], 'info' => ['info-circle', 'info'], 'key' => ['key', 'settings'],
        'language' => ['language', 'chat'], 'layout-fluid' => ['layout', 'more'], 'link' => ['link', 'share'],
        'list-check' => ['list-check', 'list'], 'lock' => ['lock', 'alert'], 'magic-wand' => ['wand', 'sparkle'],
        'map-marker-home' => ['map-pin', 'pin'], 'marker' => ['map-pin', 'pin'], 'megaphone' => ['speakerphone', 'share'],
        'mobile-notch' => ['device-mobile', 'phone'], 'money-check-edit' => ['receipt-2', 'card'], 'moped' => ['moped', 'delivery'],
        'notebook' => ['notebook', 'book-open'], 'palette' => ['palette', 'pencil'], 'paper-plane' => ['send', 'share'],
        'phone-call' => ['phone-call', 'phone'], 'picture' => ['photo', 'upload'], 'plane-departure' => ['plane-departure', 'external'],
        'play-alt' => ['player-play', 'chevron'], 'plus' => ['plus', 'plus'], 'print' => ['printer', 'print'],
        'pulse' => ['activity', 'trend-up'], 'qrcode' => ['qrcode', 'qr'], 'receipt' => ['receipt', 'orders'],
        'refresh' => ['refresh', 'refresh'], 'room-service' => ['bell-ringing', 'bell'], 'route' => ['route', 'pin'],
        'screen' => ['device-tv', 'more'], 'settings' => ['settings', 'settings'], 'settings-sliders' => ['adjustments', 'filter'],
        'share' => ['share', 'share'], 'shield' => ['shield', 'settings'], 'shield-exclamation' => ['shield-exclamation', 'alert'],
        'shop' => ['building-store', 'store'], 'shuffle' => ['arrows-shuffle', 'refresh'], 'signal-alt' => ['antenna-bars-5', 'chart'],
        'sparkles' => ['sparkles', 'sparkle'], 'star' => ['star', 'star'], 'swatchbook' => ['color-swatch', 'pencil'],
        'tablet' => ['device-tablet', 'phone'], 'thermometer-half' => ['temperature', 'flame'], 'ticket' => ['ticket', 'card'],
        'time-past' => ['history', 'clock'], 'truck-side' => ['truck', 'delivery'], 'users' => ['users', 'users'],
        'utensils' => ['tools-kitchen-2', 'dine-in'], 'wallet' => ['wallet', 'wallet'],
        'whatsapp' => ['brand-whatsapp', 'chat'],
        // Superadmin navigation (WI-10, admin.includes.sidebar).
        'bank' => ['building-bank', 'wallet'], 'bell' => ['bell', 'bell'], 'bell-ring' => ['bell-ringing', 'bell'],
        'box-open' => ['box', 'products'], 'briefcase' => ['briefcase', 'store'], 'browser' => ['browser', 'more'],
        'check-circle' => ['circle-check', 'check'], 'cloud-upload' => ['cloud-upload', 'upload'], 'comment' => ['message', 'chat'],
        'credit-card' => ['credit-card', 'card'], 'cross-circle' => ['circle-x', 'error'], 'envelope' => ['mail', 'chat'],
        'flag' => ['flag', 'star'], 'following' => ['user-check', 'users'], 'house-blank' => ['home', 'home'],
        'inbox' => ['inbox', 'chat'], 'layers' => ['stack-2', 'list'], 'newspaper' => ['news', 'book-open'],
        'plug' => ['plug', 'settings'], 'robot' => ['robot', 'sparkle'], 'rocket-lunch' => ['rocket', 'sparkle'],
        'shield-check' => ['shield', 'settings'], 'shopping-cart' => ['shopping-cart', 'orders'], 'sitemap' => ['sitemap', 'share'],
        'smartphone' => ['device-mobile', 'phone'], 'stats' => ['chart-bar', 'chart'], 'store-alt' => ['building-store', 'store'],
        'tags' => ['tag', 'products'], 'user-add' => ['user-plus', 'users'],
    ];
    $__key = (string) preg_replace('/^(fi\s+)?fi-(rr|br|sr|bs|tr|brands)-/', '', trim((string) $glyph));
    [$__tabler, $__kit] = $__map[$__key] ?? [$__key !== '' ? $__key : 'point', 'dots'];
@endphp
<x-ui.shell.glyph :name="$__tabler" :kit="$__kit" :size="$size" {{ $attributes }} />
