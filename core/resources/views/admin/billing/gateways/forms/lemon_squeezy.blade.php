{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@include('admin.billing.gateways._source', ['href' => 'https://app.lemonsqueezy.com/settings/api'])
<x-ui.field type="select" name="lemon_squeezy_test_mode" :label="___('Test Mode')" :value="$settings->lemon_squeezy_test_mode ?? null" :options="['Yes' => ___('Enable'), 'No' => ___('Disable')]"
            :help="___('Use an API key generated while your Lemon Squeezy store is in test mode.')" />
<x-ui.field name="lemon_squeezy_api_key" :label="___('API Key').' *'" :value="$settings->lemon_squeezy_api_key ?? null" autocomplete="off" />
<x-ui.field name="lemon_squeezy_store_id" :label="___('Store ID').' *'" :value="$settings->lemon_squeezy_store_id ?? null" autocomplete="off" />
<x-ui.field name="lemon_squeezy_variant_id" :label="___('Subscription Variant ID').' *'" :value="$settings->lemon_squeezy_variant_id ?? null" autocomplete="off"
            :help="___('A subscription product variant in your store; the plan price overrides its amount at checkout.')" />
<x-ui.field name="lemon_squeezy_variant_id_onetime" :label="___('One-time Variant ID').' *'" :value="$settings->lemon_squeezy_variant_id_onetime ?? null" autocomplete="off"
            :help="___('A single-payment product variant used for lifetime plans and one-time purchases.')" />
<x-ui.field name="lemon_squeezy_webhook_secret" :label="___('Webhook Secret').' *'" :value="$settings->lemon_squeezy_webhook_secret ?? null" autocomplete="off" />
@include('admin.billing.gateways._url', ['label' => ___('Webhook URL'), 'url' => url('webhook/lemon_squeezy'), 'help' => ___('Add this URL as a webhook in your Lemon Squeezy settings.')])
<p class="ui-small ui-text-2">{{ ___('Lemon Squeezy is a merchant of record: it calculates and remits taxes itself, so local tax rules are not applied to its checkouts.') }}</p>
