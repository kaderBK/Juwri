{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@include('admin.billing.gateways._source', ['href' => 'https://dashboard.razorpay.com/app/keys'])
<x-ui.field name="razorpay_api_key" :label="___('API Key').' *'" :value="$settings->razorpay_api_key ?? null" autocomplete="off" />
<x-ui.field name="razorpay_secret_key" :label="___('Secret Key').' *'" :value="$settings->razorpay_secret_key ?? null" autocomplete="off" />
{{-- WI-06a: signs webhook/razorpay (HMAC-SHA256). Stored encrypted; the page only ever holds the mask,
     and saving the mask back keeps the stored secret. --}}
<div class="ui-field">
    <label class="ui-field__label" for="razorpay_webhook_secret">{{ ___('Webhook Secret') }}</label>
    <div class="ui-field__control">
        <input type="password" id="razorpay_webhook_secret" name="razorpay_webhook_secret" value="{{ $settings->razorpay_webhook_secret ?? '' }}"
               autocomplete="new-password" aria-describedby="razorpay_webhook_secret-help">
    </div>
    <span class="ui-field__help" id="razorpay_webhook_secret-help">{{ ___('The secret you set on the Razorpay webhook. Until it is set, webhook calls are acknowledged and ignored.') }}</span>
</div>
@include('admin.billing.gateways._url', ['label' => ___('Webhook URL'), 'url' => url('webhook/'.$gateway->payment_folder),
    'helpHtml' => ___('Select the :EVENTS events for webhook.', ['EVENTS' => '<code>payment.captured</code>, <code>order.paid</code>'])])
