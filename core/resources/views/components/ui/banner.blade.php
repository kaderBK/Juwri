{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.banner tone="warning">{{ ___('1 product sold out, 2 running low.') }}</x-ui.banner>
    tone  info (default) | success | warning | danger (danger is announced as an alert)
  Say what happened and what to do next.
--}}
@props(['tone' => 'info'])
@php
    $icon = ['warning' => 'alert', 'danger' => 'error', 'success' => 'check'][$tone] ?? 'info';
@endphp
<div {{ $attributes->class('ui-banner') }} @if ($tone !== 'info') data-tone="{{ $tone }}" @endif role="{{ $tone === 'danger' ? 'alert' : 'status' }}">
<span class="ui-banner__icon"><x-ui.icon :name="$icon" size="18" /></span>
<div class="ui-banner__body">{{ $slot }}</div>
</div>
