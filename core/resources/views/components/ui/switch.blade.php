{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.switch> — a real checkbox with role="switch" (posts like any checkbox).
    name, value ('1'), checked, disabled
    label        visible label (the whole row is clickable); without it pass aria-label
    description  secondary line, wired to aria-describedby
  Add data-ui-autosubmit to submit the enclosing form on change.
    off          when set (e.g. '0'), a hidden input with this value is sent
                 under the same name, so an unticked switch posts it

    <x-ui.switch name="accepting" :checked="$store->accepting" :label="___('Taking orders')"
                 :description="___('Customers can order now')" data-ui-autosubmit />
--}}
@props(['name' => null, 'value' => '1', 'checked' => false, 'disabled' => false, 'label' => null, 'description' => null, 'id' => null, 'off' => null])
@php
    // Deterministic per-request counter (unique within a page; no random ids leaking into rendered HTML/tests).
    $sid = $id ?: 'ui-sw-'.app()->instance('ui.switch.seq', (app()->bound('ui.switch.seq') ? app('ui.switch.seq') : 0) + 1);
@endphp
@if ($name && $off !== null)<input type="hidden" name="{{ $name }}" value="{{ $off }}">@endif
@if ($label)
<label class="ui-switch-row" for="{{ $sid }}">
<span class="ui-switch-row__text">
<span class="ui-switch-row__label">{{ $label }}</span>
@if ($description)<span class="ui-switch-row__desc" id="{{ $sid }}-desc">{{ $description }}</span>@endif
</span>
@endif
<span class="ui-switch">
<input type="checkbox" role="switch" id="{{ $sid }}" class="ui-switch__input" @if ($name) name="{{ $name }}" value="{{ $value }}" @endif @checked($checked) @disabled($disabled) @if ($label && $description) aria-describedby="{{ $sid }}-desc" @endif {{ $attributes }}>
<span class="ui-switch__track" aria-hidden="true"><span class="ui-switch__knob"></span></span>
</span>
@if ($label)
</label>
@endif
