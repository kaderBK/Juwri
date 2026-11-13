{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.panel :title="___('Upload Plugin')" :action="route('admin.plugins.store')" files>
    <x-ui.field type="file" name="plugin_zip" id="plugin_zip" :label="___('Plugin Zip File').' *'" accept=".zip" required />
</x-ui.panel>
