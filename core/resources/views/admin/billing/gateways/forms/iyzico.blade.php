{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.field type="select" name="iyzico_sandbox_mode" :label="___('Sandbox Mode').' *'" :value="$settings->iyzico_sandbox_mode ?? null" :options="['test' => ___('Enable'), 'live' => ___('Disable')]" />
<x-ui.field name="iyzico_api_key" :label="___('Iyzico API Key').' *'" :value="$settings->iyzico_api_key ?? null" autocomplete="off" />
<x-ui.field name="iyzico_secret_key" :label="___('Iyzico Secret Key').' *'" :value="$settings->iyzico_secret_key ?? null" autocomplete="off" />
