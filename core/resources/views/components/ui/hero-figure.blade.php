{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.hero-figure> — the big number inside a <x-ui.card variant="hero">.
    label, value (pre-formatted), unit (currency code/symbol), delta ('+12.4%'),
    trend up|down, note (comparison text)
  The unit leads in LTR ("SAR 4,862.50") and trails in RTL ("4,862.50 ر.س").

    <x-ui.card variant="hero">
        <x-ui.hero-figure :label="___('Sales today')" value="4,862.50" unit="SAR" delta="+12.4%" :note="___('vs last Thursday, same time')" />
    </x-ui.card>
--}}
@props(['label', 'value', 'unit' => null, 'delta' => null, 'trend' => 'up', 'note' => null])
@php
    $rtl = function_exists('current_language') && current_language() && current_language()->direction === 'rtl';
@endphp
<div {{ $attributes }}>
<div class="ui-hero__head">
<span class="ui-hero__label">{{ $label }}</span>
@if ($delta)
<span class="ui-delta" @if ($trend === 'down') data-trend="down" @endif>
<x-ui.icon name="trend-up" size="14" /><span class="ui-ltr">{{ $delta }}</span>
</span>
@endif
</div>
<div class="ui-hero__value">
@if ($unit && ! $rtl)<span class="ui-hero__unit">{{ $unit }}</span>@endif
<span class="ui-hero__number">{{ $value }}</span>
@if ($unit && $rtl)<span class="ui-hero__unit">{{ $unit }}</span>@endif
</div>
@if ($note)<div class="ui-hero__note">{{ $note }}</div>@endif
</div>
