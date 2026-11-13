{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.field type="select" name="PAYTM_ENVIRONMENT" :label="___('Sandbox Mode')" :value="$settings->PAYTM_ENVIRONMENT ?? null" :options="['TEST' => ___('Enable'), 'PROD' => ___('Disable')]" />
<x-ui.field name="PAYTM_MERCHANT_KEY" :label="___('Paytm Merchant Key')" :value="$settings->PAYTM_MERCHANT_KEY ?? null" autocomplete="off" />
<x-ui.field name="PAYTM_MERCHANT_MID" :label="___('Paytm Merchant ID')" :value="$settings->PAYTM_MERCHANT_MID ?? null" autocomplete="off" />
<x-ui.field name="PAYTM_MERCHANT_WEBSITE" :label="___('Paytm Website Name')" :value="$settings->PAYTM_MERCHANT_WEBSITE ?? null" autocomplete="off" />
