{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.chip> — filter chip. As a link (href) the active one gets aria-current;
  as a button it is a toggle (aria-pressed). Wrap several in <div class="ui-chips">.
    active, count (small number after the label)

    <x-ui.chip :href="route('…', ['cat' => 'burgers'])" :active="$cat === 'burgers'" :count="5">{{ ___('Burgers') }}</x-ui.chip>
--}}
@props(['href' => null, 'active' => false, 'count' => null, 'type' => 'button'])
@if ($href)
<a href="{{ $href }}" {{ $attributes->class('ui-chip') }} @if ($active) aria-current="true" @endif>
@else
<button type="{{ $type }}" {{ $attributes->class('ui-chip') }} aria-pressed="{{ $active ? 'true' : 'false' }}">
@endif
{{ $slot }}@if ($count !== null)<span class="ui-chip__count">{{ $count }}</span>@endif
@if ($href)
</a>
@else
</button>
@endif
