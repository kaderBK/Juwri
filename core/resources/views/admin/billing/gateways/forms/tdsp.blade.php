{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@include('admin.billing.gateways._env', ['name' => 'tdsp_sandbox', 'help' => ___('Sandbox uses uat-api.tlync.ly; live uses api.tlync.ly. Tokens differ per environment.')])
<x-ui.field name="tdsp_store_id" :label="___('Store ID').' *'" :value="$settings->tdsp_store_id ?? null" autocomplete="off" />
<x-ui.field name="tdsp_token" :label="___('API Token').' *'" :value="$settings->tdsp_token ?? null" autocomplete="off" />
