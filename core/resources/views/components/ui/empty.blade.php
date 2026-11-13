{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.empty icon="products" :title="___('No products yet')" :body="___('Add your first product or import your menu from a file.')">
      <x-ui.button icon="plus" href="…">{{ ___('Add product') }}</x-ui.button>
  </x-ui.empty>
  Every list needs one. The slot is the single next action.
--}}
@props(['title', 'body' => null, 'icon' => 'products'])
<div {{ $attributes->class('ui-empty') }}>
<span class="ui-empty__icon"><x-ui.icon :name="$icon" size="28" /></span>
<p class="ui-empty__title">{{ $title }}</p>
@if ($body)<p class="ui-empty__body">{{ $body }}</p>@endif
@if ($slot->isNotEmpty())<div class="ui-empty__action">{{ $slot }}</div>@endif
</div>
