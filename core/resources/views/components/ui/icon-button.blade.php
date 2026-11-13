{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.icon-button label="Search" icon="search" /> — 44×44 round button.
    label    required accessible name (aria-label)
    variant  outline|filled|ghost   (default outline)
    dot      true → red unread dot
    href     renders <a> instead of <button>
--}}
@props(['label', 'icon' => null, 'variant' => 'outline', 'href' => null, 'type' => 'button', 'dot' => false])
@php
    $classes = ['ui-icon-btn', 'ui-icon-btn--'.$variant => $variant !== 'outline'];
@endphp
@if ($href)
<a href="{{ $href }}" aria-label="{{ $label }}" {{ $attributes->class($classes) }}>
@else
<button type="{{ $type }}" aria-label="{{ $label }}" {{ $attributes->class($classes) }}>
@endif
@if ($icon)<x-ui.icon :name="$icon" />@else{{ $slot }}@endif
@if ($dot)<span class="ui-icon-btn__dot" aria-hidden="true"></span>@endif
@if ($href)
</a>
@else
</button>
@endif
