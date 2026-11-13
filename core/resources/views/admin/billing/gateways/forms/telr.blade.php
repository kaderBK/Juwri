{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.field type="select" name="telr_sandbox_mode" :label="___('Sandbox Mode')" :value="$settings->telr_sandbox_mode ?? null" :options="['test' => ___('Enable'), 'live' => ___('Disable')]" />
<x-ui.field name="telr_store_id" :label="___('Telr Store ID')" :value="$settings->telr_store_id ?? null" autocomplete="off" />
<x-ui.field name="telr_authkey" :label="___('Telr Auth Key')" :value="$settings->telr_authkey ?? null" autocomplete="off" />
