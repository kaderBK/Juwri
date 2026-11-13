{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.panel :title="___('Add language')" :action="route('admin.languages.store')">
    <x-ui.field name="name" :label="___('Name').' *'" required autofocus />
    <x-ui.field name="code" :label="___('Code').' (ISO 639 Set 1) *'" required
        help="https://en.wikipedia.org/wiki/List_of_ISO_639-1_codes" />
    <x-ui.field type="select" name="direction" :label="___('Direction').' *'" :options="['ltr' => ___('LTR'), 'rtl' => ___('RTL')]" />
    <input type="hidden" name="is_default" value="0">
    <x-ui.switch name="is_default" :label="___('Set Default language')" />
</x-ui.panel>
