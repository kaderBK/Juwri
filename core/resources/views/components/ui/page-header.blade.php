{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.page-header :title="___('Products')" :sub="___('Crave Burger menu · shared by 2 stores')">
      <x-slot:actions>…at most one primary button…</x-slot:actions>
  </x-ui.page-header>
  One title, at most one primary action; secondary actions are outlined.
--}}
@props(['title', 'sub' => null])
<header {{ $attributes->class('ui-page-head') }}>
<div>
<h1 class="ui-page-head__title">{{ $title }}</h1>
@if ($sub)<p class="ui-page-head__sub">{{ $sub }}</p>@endif
</div>
@isset($actions)<div class="ui-page-head__actions">{{ $actions }}</div>@endisset
</header>
