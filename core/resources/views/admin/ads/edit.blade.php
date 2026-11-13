{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.panel :title="$advertisement->provider_name" :action="route('admin.advertisements.update', $advertisement->id)" method="PUT" files>
    <input type="hidden" name="status" value="0">
    <x-ui.switch name="status" :checked="$advertisement->status == '1'" :label="___('Status')" />
    <x-ui.field name="provider_name" :label="___('Provider Name').' *'" :value="$advertisement->provider_name" required />
    <x-ui.field type="textarea" name="code" :label="___('Code').' *'" :value="$advertisement->code" rows="12" class="ui-mono" spellcheck="false" />
</x-ui.panel>
