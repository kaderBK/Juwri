{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.field> — labelled input with help or error text. The label is always
  visible; errors come from the validation bag automatically and say what to do.
    label, name (required)
    type     text|email|tel|number|password|search|url|textarea|select   (default text)
    value    initial value (old() input wins after a failed submit)
    help     helper text under the control
    error    explicit error message (defaults to $errors->first(name))
    prefix   leading text inside the control (e.g. currency)
    options  for type=select: [value => label]; extra <option>s may go in the slot
  Any other attribute (placeholder, required, disabled, inputmode, autocomplete…)
  lands on the control.

    <x-ui.field name="price" type="number" :label="___('Price')" :prefix="store_currency_symbol()" step="0.01" />
--}}
@props(['label', 'name', 'type' => 'text', 'value' => null, 'help' => null, 'error' => null, 'prefix' => null, 'options' => [], 'id' => null, 'rows' => 3])
@php
    $fid = $id ?: 'ui-f-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $name);
    $bag = isset($errors) && $errors instanceof \Illuminate\Support\ViewErrorBag ? $errors : null;
    $message = $error ?? ($bag ? $bag->first($name) : null);
    $current = request()->hasSession() ? old($name, $value) : $value;
    $describedBy = ($message || $help) ? $fid.'-help' : null;
    $isDisabled = $attributes->has('disabled');
@endphp
<div @class(['ui-field', 'ui-field--error' => (bool) $message, 'ui-field--disabled' => $isDisabled])>
<label class="ui-field__label" for="{{ $fid }}">{{ $label }}</label>
<div class="ui-field__control">
@if ($prefix)<span class="ui-field__prefix">{{ $prefix }}</span>@endif
@if ($type === 'textarea')
<textarea id="{{ $fid }}" name="{{ $name }}" rows="{{ (int) $rows }}" @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($message) aria-invalid="true" @endif {{ $attributes }}>{{ $current }}</textarea>
@elseif ($type === 'select')
<select id="{{ $fid }}" name="{{ $name }}" @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($message) aria-invalid="true" @endif {{ $attributes }}>
@foreach ($options as $optValue => $optLabel)
<option value="{{ $optValue }}" @selected((string) $current === (string) $optValue)>{{ $optLabel }}</option>
@endforeach
{{ $slot }}
</select>
<x-ui.icon name="chevron-down" size="16" class="ui-field__chevron" />
@else
<input type="{{ $type }}" id="{{ $fid }}" name="{{ $name }}" @if ($current !== null && $type !== 'password') value="{{ $current }}" @endif @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($message) aria-invalid="true" @endif {{ $attributes }}>
@endif
</div>
@if ($message || $help)
<span class="ui-field__help" id="{{ $fid }}-help">{{ $message ?: $help }}</span>
@endif
</div>
