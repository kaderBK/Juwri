{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@include('admin.billing.gateways._source', ['href' => 'https://dashboard.stripe.com/apikeys'])
<x-ui.field type="select" name="stripe_payment_mode" :label="___('Payment Mode')" :value="$settings->stripe_payment_mode ?? null"
            :options="['one_time' => ___('One Time'), 'recurring' => ___('Recurring'), 'both' => ___('Both')]" />
<x-ui.field name="stripe_publishable_key" :label="___('Publishable Key').' *'" :value="$settings->stripe_publishable_key ?? null" autocomplete="off" />
<x-ui.field name="stripe_secret_key" :label="___('Secret Key').' *'" :value="$settings->stripe_secret_key ?? null" autocomplete="off" />
<x-ui.field name="stripe_webhook_secret" :label="___('Webhook Secret')" :value="$settings->stripe_webhook_secret ?? null" autocomplete="off" />
@include('admin.billing.gateways._url', ['label' => ___('WebHook Url'), 'url' => url('webhook/'.$gateway->payment_folder),
    'helpHtml' => ___('Select the :EVENTS events for webhook.', ['EVENTS' => '<code>checkout.session.completed</code>, <code>invoice.paid</code>, <code>invoice.upcoming</code>'])])
