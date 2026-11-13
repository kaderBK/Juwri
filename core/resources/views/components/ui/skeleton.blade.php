{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.skeleton :rows="3" /> — loading placeholder rows (shimmer stops under
  reduced motion). Announced as busy with an accessible label.
--}}
@props(['rows' => 3, 'label' => null])
<div {{ $attributes->class('ui-skeleton') }} aria-busy="true" aria-label="{{ $label ?? ___('Loading') }}" role="status">
@for ($i = 0; $i < max(1, (int) $rows); $i++)
<div class="ui-skeleton__row" aria-hidden="true">
<span class="ui-skeleton__block"></span>
<span class="ui-skeleton__lines"><span class="ui-skeleton__line"></span><span class="ui-skeleton__line"></span></span>
</div>
@endfor
</div>
