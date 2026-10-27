{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.check name="types[]" value="delivery" :label="___('Delivery')" :checked="true" />
  <x-ui.check type="radio" name="pay" value="cash" :label="___('Cash')" />
--}}
@props(['label', 'type' => 'checkbox', 'name' => null, 'value' => '1', 'checked' => false])
<label class="ui-check">
<input type="{{ $type === 'radio' ? 'radio' : 'checkbox' }}" @if ($name) name="{{ $name }}" @endif value="{{ $value }}" @checked($checked) {{ $attributes }}>
<span>{{ $label }}</span>
</label>
