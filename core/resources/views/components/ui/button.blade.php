{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.button> — owner UI kit button (renders <a> when `href` is given).

    variant  primary|dark|secondary|ghost|danger|success|link   (default primary)
    size     lg (52px) | md (44px) | sm (36px)                   (default md)
    icon     optional <x-ui.icon> name shown before the label
    busy     true → aria-busy + spinner (also set by data-ui-busy on submit)
    block    true → full width
  One primary button per view. Any extra attribute is passed through.

    <x-ui.button icon="plus" :href="route('…')">{{ ___('Add product') }}</x-ui.button>
    <x-ui.button type="submit" variant="dark" data-ui-busy>{{ ___('Save') }}</x-ui.button>
--}}
@props(['variant' => 'primary', 'size' => 'md', 'href' => null, 'type' => 'button', 'icon' => null, 'busy' => false, 'block' => false])
@php
    $classes = [
        'ui-btn',
        'ui-btn--'.$variant => $variant !== 'primary',
        'ui-btn--lg' => $size === 'lg',
        'ui-btn--sm' => $size === 'sm',
        'ui-btn--block' => $block,
    ];
@endphp
@if ($href)
<a href="{{ $href }}" {{ $attributes->class($classes) }} @if ($busy) aria-busy="true" @endif>
@else
<button type="{{ $type }}" {{ $attributes->class($classes) }} @if ($busy) aria-busy="true" @endif>
@endif
<span class="ui-btn__spinner" aria-hidden="true"></span>
@if ($icon)<span class="ui-btn__icon"><x-ui.icon :name="$icon" /></span>@endif
{{ $slot }}
@if ($href)
</a>
@else
</button>
@endif
