{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.card> — surface with a hairline border.
    variant  space-separated: hero (dark, one per screen) | inset | flush (no padding) | lg
    title    optional heading; `heading` sets its level (h2 default)
    as       section|div|article|aside   (default section)
  Slots: default body, `actions` (top-end of the header).

    <x-ui.card :title="___('Top sellers today')">
        <x-slot:actions><x-ui.button variant="link" href="…">{{ ___('All products') }}</x-ui.button></x-slot:actions>
        …
    </x-ui.card>
--}}
@props(['variant' => null, 'title' => null, 'heading' => 'h2', 'as' => 'section'])
@php
    $tag = in_array($as, ['section', 'div', 'article', 'aside'], true) ? $as : 'section';
    $h = in_array($heading, ['h2', 'h3', 'h4'], true) ? $heading : 'h2';
    $mods = \Juwri\Core\Support\ViewHelpers::cardModifiers($variant);
@endphp
<{{ $tag }} {{ $attributes->class(array_merge(['ui-card'], $mods)) }}>
@if ($title || isset($actions))
<div class="ui-card__head">
@if ($title)<{{ $h }} class="ui-card__title">{{ $title }}</{{ $h }}>@endif
@isset($actions)<div class="ui-card__actions">{{ $actions }}</div>@endisset
</div>
@endif
{{ $slot }}
</{{ $tag }}>
