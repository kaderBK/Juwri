{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.stat :value="8" :label="___('tickets in the kitchen')" icon="flame" tone="warning" />
    tone  accent (default) | success | warning | danger — colours the icon only
--}}
@props(['label', 'value', 'icon' => null, 'tone' => null])
<div {{ $attributes->class('ui-stat') }} @if ($tone) data-tone="{{ $tone }}" @endif>
@if ($icon)<span class="ui-stat__icon"><x-ui.icon :name="$icon" /></span>@endif
<span class="ui-stat__value">{{ $value }}</span>
<span class="ui-stat__label">{{ $label }}</span>
</div>
