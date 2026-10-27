{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.field name="paystack_secret_key" :label="___('Secret Key').' *'" :value="$settings->paystack_secret_key ?? null" autocomplete="off" />
<x-ui.field name="paystack_public_key" :label="___('Public Key').' *'" :value="$settings->paystack_public_key ?? null" autocomplete="off" />
