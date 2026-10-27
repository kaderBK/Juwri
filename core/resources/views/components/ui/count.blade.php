{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.count :value="3" /> — count badge. Red (default) only for counts that need
  action; tone="accent" or "neutral" for informational counts. Renders nothing at 0
  unless `show-zero` is set. Values above 99 show "99+".
--}}
@props(['value' => 0, 'tone' => 'danger', 'showZero' => false])
@php $n = (int) $value; @endphp
@if ($n > 0 || $showZero)
<span {{ $attributes->class('ui-count') }} @if ($tone !== 'danger') data-tone="{{ $tone }}" @endif>{{ $n > 99 ? '99+' : $n }}</span>
@endif
