{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.sheet id="pause-store" :title="___('Pause Al Malqa?')" :description="___('Customers can’t order until you resume.')">
      …optional body…
      <x-slot:footer>
          <x-ui.button variant="secondary" data-ui-close>{{ ___('Cancel') }}</x-ui.button>
          <x-ui.button variant="dark" type="submit" form="pause-form">{{ ___('Pause store') }}</x-ui.button>
      </x-slot:footer>
  </x-ui.sheet>
  Open it with any element carrying data-ui-open="pause-store". Bottom sheet on
  phones, centred dialog on desktop — same content (native <dialog>).
--}}
@props(['id', 'title', 'description' => null])
<dialog id="{{ $id }}" {{ $attributes->class('ui-sheet') }} aria-labelledby="{{ $id }}-title" @if ($description) aria-describedby="{{ $id }}-desc" @endif>
<div class="ui-sheet__handle" aria-hidden="true"></div>
<h2 class="ui-sheet__title" id="{{ $id }}-title">{{ $title }}</h2>
@if ($description)<p class="ui-sheet__desc" id="{{ $id }}-desc">{{ $description }}</p>@endif
@if ($slot->isNotEmpty())<div class="ui-sheet__body">{{ $slot }}</div>@endif
@isset($footer)<div class="ui-sheet__foot">{{ $footer }}</div>@endisset
</dialog>
