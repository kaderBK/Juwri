{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Superadmin <head> styles on the owner-ui kit (admin.layout).
  Order: tokens → components → shell → screens → skin → layout → admin.
  Accent = platform brand colour.
--}}
@php
    $__accent = $settings->theme_color ?? null;
    $__accent = (is_string($__accent) && preg_match('/^#[0-9A-Fa-f]{6}$/', $__accent)) ? $__accent : '#6366f1';
    $__rgb = implode(', ', array_map('hexdec', str_split(substr($__accent, 1), 2)));
@endphp
<link rel="preload" href="{{ asset('assets/fonts/instrument-sans-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('assets/fonts/fonts.css') }}">
@include('partials.riyal-font')
@include('partials.arabic-font')
@include('partials.brand-tile')

<style nonce="{{ csp_nonce() }}">:root{--theme-color:{{ $__accent }};--theme-color-rgb:{{ $__rgb }};--ui-on-accent:{{ \Juwri\Core\Support\OwnerUi::onAccent($__accent) }};}</style>
<link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/tokens.css') }}">
<link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/components.css') }}">
<link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/shell.css') }}">
<link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/screens.css') }}">
@if (config('owner_ui.skin'))
<link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/skin.css') }}">
@endif
<link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/layout.css') }}">
<link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/admin.css') }}">
<script src="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/owner-ui.js') }}" defer></script>
<script src="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/layout.js') }}" defer></script>
<script src="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/actions.js') }}" defer></script>
<script nonce="{{ csp_nonce() }}">window.ADMIN_I18N = @js([
    'search' => ___('Search'), 'empty' => ___('No records found'), 'loading' => ___('Loading…'),
    'info' => ___('Showing :from–:to of :total'), 'filtered' => ___('(filtered from :total)'),
    'prev' => ___('Previous'), 'next' => ___('Next'), 'selected' => ___(':count selected'),
    'delete' => ___('Delete'), 'confirmDelete' => ___('Delete the selected items? This cannot be undone.'),
    'confirm' => ___('Are you sure?'), 'ok' => ___('Confirm'), 'cancel' => ___('Cancel'),
    'error' => ___('Something went wrong. Please try again.'),
    'expired' => ___('Your session expired. Please reload the page and try again.'),
    'noMatches' => ___('No matches'),
    'rich_bold' => ___('Bold'), 'rich_italic' => ___('Italic'), 'rich_underline' => ___('Underline'),
    'rich_heading' => ___('Heading'), 'rich_subheading' => ___('Subheading'), 'rich_paragraph' => ___('Paragraph'),
    'rich_bullets' => ___('Bulleted list'), 'rich_numbers' => ___('Numbered list'), 'rich_link' => ___('Link'),
    'rich_unlink' => ___('Remove link'), 'rich_clear' => ___('Clear formatting'), 'rich_source' => ___('HTML source'),
    'rich_url' => ___('Link address'), 'copied' => ___('Copied'), 'pickOne' => ___('Select at least one row.'),
]);</script>
<script src="{{ \Juwri\Core\Support\ViewHelpers::assetV('assets/owner-ui/admin.js') }}" defer></script>
<script nonce="{{ csp_nonce() }}">window.ADMIN_URL = @js(url(admin_url()));</script>
@stack('scripts_at_top')
@stack('styles')
