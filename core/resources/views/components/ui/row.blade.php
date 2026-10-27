{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.row> — list row: tinted icon, title, one-line subtitle, trailing element.
    href     makes the whole row a link (a chevron is added automatically)
    icon     <x-ui.icon> name for the tinted square
    tone     accent (default) | warning | danger | success | neutral
  Slot `trail` replaces the chevron (e.g. a pill or a button label).

    <x-ui.row href="…" icon="orders" :title="___('3 new orders waiting')" :sub="___('Oldest placed 4 min ago')" />
--}}
@props(['title', 'sub' => null, 'href' => null, 'icon' => null, 'tone' => 'accent'])
@if ($href)
<a href="{{ $href }}" {{ $attributes->class('ui-row') }}>
@else
<div {{ $attributes->class('ui-row') }}>
@endif
@if ($icon)<span class="ui-row__icon" @if ($tone !== 'accent') data-tone="{{ $tone }}" @endif><x-ui.icon :name="$icon" size="22" /></span>@endif
<span class="ui-row__body">
<span class="ui-row__title">{{ $title }}</span>
@if ($sub)<span class="ui-row__sub">{{ $sub }}</span>@endif
</span>
@isset($trail)
<span class="ui-row__trail">{{ $trail }}</span>
@elseif ($href)
<span class="ui-row__trail"><x-ui.icon name="chevron" size="18" class="ui-row__chevron" /></span>
@endisset
@if ($href)
</a>
@else
</div>
@endif
