{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@include('admin.billing.gateways._env', ['name' => 'paymob_sandbox', 'help' => ___('Uses the Intention API + Unified Checkout on the host of the account region; test keys share the same host.')])
{{-- P6-108b: the account's region host (test keys share it). Blank = from the payment currency, else KSA. --}}
<x-ui.field type="select" name="paymob_region" :label="___('Paymob region')" :value="$settings->paymob_region ?? null" :options="[
    '' => ___('Automatic'), 'eg' => 'Egypt (accept.paymob.com)', 'ksa' => 'Saudi Arabia (ksa.paymob.com)',
    'uae' => 'United Arab Emirates (uae.paymob.com)', 'omn' => 'Oman (oman.paymob.com)',
]" />
<x-ui.field name="paymob_secret_key" :label="___('Secret Key').' *'" :value="$settings->paymob_secret_key ?? null" autocomplete="off" />
<x-ui.field name="paymob_public_key" :label="___('Public Key').' *'" :value="$settings->paymob_public_key ?? null" autocomplete="off" />
<x-ui.field name="paymob_integration_id" :label="___('Integration ID').' *'" :value="$settings->paymob_integration_id ?? null" autocomplete="off" />
<x-ui.field name="paymob_hmac_secret" :label="___('HMAC Secret').' *'" :value="$settings->paymob_hmac_secret ?? null" autocomplete="off" :help="___('Used to verify the HMAC-SHA512 signature on transaction callbacks.')" />
@include('admin.billing.gateways._url', ['label' => ___('Callback / Webhook URL'), 'url' => route('webhook', 'paymob'), 'help' => ___('Set this as the transaction-processed notification URL in your Paymob dashboard.')])
