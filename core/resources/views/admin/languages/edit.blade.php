{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.panel :title="___('Edit Language')" :action="route('admin.languages.update', $language->id)" method="PUT" files>
    <x-ui.field name="name" :label="___('Name').' *'" :value="$language->name" required />
    <x-ui.field type="select" name="direction" :label="___('Direction').' *'" :value="$language->direction" :options="['ltr' => ___('LTR'), 'rtl' => ___('RTL')]" />
    <input type="hidden" name="active" value="0">
    <x-ui.switch name="active" :checked="(bool) $language->active" :label="___('Active')" />
    <input type="hidden" name="is_default" value="0">
    <x-ui.switch name="is_default" :checked="env('DEFAULT_LANGUAGE') == $language->code" :label="___('Set Default language')" />
</x-ui.panel>
