{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.field type="select" name="midtrans_sandbox_mode" :label="___('Sandbox Mode')" :value="$settings->midtrans_sandbox_mode ?? null" :options="['test' => ___('Enable'), 'live' => ___('Disable')]" />
<x-ui.field name="midtrans_client_key" :label="___('Midtrans Client Key').' *'" :value="$settings->midtrans_client_key ?? null" autocomplete="off" />
<x-ui.field name="midtrans_server_key" :label="___('Midtrans Server Key').' *'" :value="$settings->midtrans_server_key ?? null" autocomplete="off" />
