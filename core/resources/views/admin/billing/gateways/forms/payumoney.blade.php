{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.field type="select" name="payumoney_sandbox_mode" :label="___('Sandbox Mode')" :value="$settings->payumoney_sandbox_mode ?? null" :options="['1' => ___('Enable'), '0' => ___('Disable')]" />
<x-ui.field name="payumoney_merchant_pos_id" :label="___('Payumoney Merchant POS ID').' *'" :value="$settings->payumoney_merchant_pos_id ?? null" autocomplete="off" />
<x-ui.field name="payumoney_signature_key" :label="___('Payumoney Signature Key').' *'" :value="$settings->payumoney_signature_key ?? null" autocomplete="off" />
<x-ui.field name="payumoney_oauth_client_id" :label="___('Payumoney OAuth Client ID').' *'" :value="$settings->payumoney_oauth_client_id ?? null" autocomplete="off" />
<x-ui.field name="payumoney_oauth_client_secret" :label="___('Payumoney OAuth Client Secret').' *'" :value="$settings->payumoney_oauth_client_secret ?? null" autocomplete="off" />
