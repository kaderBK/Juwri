{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.field name="CCAVENUE_MERCHANT_KEY" :label="___('CCAvenue Merchant key').' *'" :value="$settings->CCAVENUE_MERCHANT_KEY ?? null" autocomplete="off" />
<x-ui.field name="CCAVENUE_ACCESS_CODE" :label="___('CCAvenue Access Code').' *'" :value="$settings->CCAVENUE_ACCESS_CODE ?? null" autocomplete="off" />
<x-ui.field name="CCAVENUE_WORKING_KEY" :label="___('CCAvenue Working Key').' *'" :value="$settings->CCAVENUE_WORKING_KEY ?? null" autocomplete="off" />
