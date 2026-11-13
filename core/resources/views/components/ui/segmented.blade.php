{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.segmented> — one-of-few choice as a radio group (works without JS, posts
  like radios). Add data-ui-autosubmit to submit the enclosing form on change.
    name, options [value => label], value (selected), label (visually hidden legend)

    <x-ui.segmented name="period" :value="$period" :label="___('Period')"
        :options="['today' => ___('Today'), 'week' => ___('7 days'), 'month' => ___('30 days')]" data-ui-autosubmit />
--}}
@props(['name', 'options' => [], 'value' => null, 'label' => null])
<fieldset class="ui-seg">
@if ($label)<legend class="ui-visually-hidden">{{ $label }}</legend>@endif
@foreach ($options as $optValue => $optLabel)
<label class="ui-seg__opt">
<input type="radio" class="ui-seg__input" name="{{ $name }}" value="{{ $optValue }}" @checked((string) $value === (string) $optValue) {{ $attributes }}>
<span class="ui-seg__label">{{ $optLabel }}</span>
</label>
@endforeach
</fieldset>
