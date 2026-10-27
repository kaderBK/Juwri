{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Auth form field on the kit (<x-ui.field>). Pass: name, label; optional: type, value,
     placeholder, error, required, autocomplete, id. The input id defaults to the field name. --}}
<x-ui.field
    :name="$name"
    :label="$label"
    :type="$type ?? 'text'"
    :value="$value ?? null"
    :error="! empty($error) ? $error : null"
    :id="$id ?? $name"
    :placeholder="$placeholder ?? null"
    :autocomplete="$autocomplete ?? null"
    :required="! empty($required)" />
