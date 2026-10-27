{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.field name="qrpay_client_id" :label="___('Client ID').' *'" :value="$settings->qrpay_client_id ?? null" autocomplete="off" />
<x-ui.field name="qrpay_secret_id" :label="___('Secret ID').' *'" :value="$settings->qrpay_secret_id ?? null" autocomplete="off" :help="___('Kept server-side; used only to fetch a short-lived access token.')" />
@include('admin.billing.gateways._url', ['label' => ___('Return / Callback URL'), 'url' => route('ipn', 'qrpay'), 'help' => ___('QRPay returns the buyer here; we re-verify server-side before settling.')])
