{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@include('admin.billing.gateways._source', ['href' => 'https://secure.2checkout.com/cpanel'])
<x-ui.field type="select" name="2checkout_sandbox_mode" :label="___('Sandbox Mode')" :value="$settings->{'2checkout_sandbox_mode'} ?? null" :options="['sandbox' => ___('Enable'), 'production' => ___('Disable')]" />
<x-ui.field name="checkout_account_number" :label="___('2Checkout Account Number').' *'" :value="$settings->checkout_account_number ?? null" autocomplete="off" />
<x-ui.field name="checkout_public_key" :label="___('Publishable Key').' *'" :value="$settings->checkout_public_key ?? null" autocomplete="off" />
<x-ui.field name="checkout_private_key" :label="___('Private API Key ').' *'" :value="$settings->checkout_private_key ?? null" autocomplete="off" />
