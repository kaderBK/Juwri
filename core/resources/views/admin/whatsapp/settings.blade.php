{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('WhatsApp - Settings'))
@section('content')

    {{-- Saved with fetch (JSON {success, message}); the answer is shown as a toast. --}}
    <form method="post" action="{{ route('admin.whatsapp.settings.update') }}" data-ui-ajax class="ui-stack">
        @csrf

        <x-ui.card :title="___('WhatsApp Platform Settings')">
            <div class="ui-form-grid">
                <x-ui.field type="select" name="whatsapp_default_provider" :label="___('Default provider')"
                            :value="config('settings.whatsapp_default_provider', 'link')"
                            :options="\Juwri\Core\Support\ViewHelpers::ucfirstOptions($providers, false)" />
                <x-ui.field type="select" name="whatsapp_billing_mode" :label="___('Billing mode')"
                            :value="config('settings.whatsapp_billing_mode', 'quota_only')"
                            :options="['quota_only' => ___('Quota only'), 'quota_then_wallet' => ___('Quota then wallet'), 'wallet_only' => ___('Wallet only')]" />
                <x-ui.field type="number" name="whatsapp_markup_pct" :label="___('Markup %').' (0.2 = 20%)'" step="0.01"
                            :value="config('settings.whatsapp_markup_pct') ?? config('whatsapp.markup_pct') ?? config('billing.cost_plus_markup', 0)" />
                <x-ui.field type="number" name="whatsapp_markup_flat" :label="___('Markup flat / message')" step="0.001"
                            :value="config('settings.whatsapp_markup_flat', 0)" />
                <x-ui.field type="number" name="whatsapp_monthly_message_limit" :label="___('Monthly limit').' (0 = ∞)'"
                            :value="config('settings.whatsapp_monthly_message_limit', 0)" />
                <div class="ui-stack" style="--ui-gap:var(--ui-space-2)">
                    <x-ui.field type="select" name="whatsapp_verify_signatures" :label="___('Verify webhook signatures')"
                                :value="whatsapp_verify_signatures() ? '1' : '0'"
                                :options="['1' => ___('Yes'), '0' => ___('No')]" />
                    @unless (whatsapp_verify_signatures())
                        <x-ui.banner tone="danger">
                            {{ ___('Signature verification is OFF. Anyone who knows your webhook URL can inject messages for any store. Accepted unverified today: :n', ['n' => $unverifiedToday]) }}
                        </x-ui.banner>
                    @endunless
                </div>
                <div class="ui-form-grid__full">
                    <h3 class="ui-section-title">{{ ___('Inbound webhook health (today)') }}</h3>
                    <dl class="ui-dl">
                        <div><dt>{{ ___('Rejected — bad or missing signature') }}</dt><dd>{{ $rejectedToday }}</dd></div>
                        <div><dt>{{ ___('Accepted without verification') }}</dt><dd>{{ $unverifiedToday }}</dd></div>
                        <div><dt>{{ ___('Messages with no owning store (last 24h)') }}</dt><dd>{{ $unroutedNumbers }}</dd></div>
                    </dl>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card :title="___('Platform provider keys')">
            <div class="ui-stack">
                <p class="ui-text-2">{{ ___('Used for restaurants that have not brought their own keys. Leave blank to keep existing. Stored encrypted.') }}</p>

                <h3 class="ui-section-title">Twilio @isset($platform['twilio'])<x-ui.tag tone="success">{{ ___('configured') }}</x-ui.tag>@endisset</h3>
                <div class="ui-form-grid">
                    <x-ui.field name="twilio_sid" :label="___('Account SID')" autocomplete="off" />
                    <x-ui.field type="password" name="twilio_token" :label="___('Auth Token')" autocomplete="new-password" />
                    <x-ui.field type="tel" name="twilio_from" :label="___('From number')" placeholder="whatsapp:+14155238886" />
                </div>

                <h3 class="ui-section-title">Meta Cloud API @isset($platform['meta'])<x-ui.tag tone="success">{{ ___('configured') }}</x-ui.tag>@endisset</h3>
                <div class="ui-form-grid">
                    <x-ui.field type="password" name="meta_access_token" :label="___('Access Token')" autocomplete="new-password" />
                    <x-ui.field name="meta_phone_number_id" :label="___('Phone Number ID')" />
                    <x-ui.field type="password" name="meta_app_secret" :label="___('App Secret')" autocomplete="new-password" />
                    <x-ui.field name="meta_webhook_verify_token" :label="___('Webhook Verify Token')" />
                </div>
                <p class="ui-small ui-text-2">{{ ___('Meta webhook URL') }}: <code class="ui-mono ui-break">{{ url('webhook/whatsapp/meta') }}</code></p>
            </div>
            <div class="ui-card__foot">
                <x-ui.button type="submit" icon="device-floppy" data-ui-busy>{{ ___('Save Changes') }}</x-ui.button>
            </div>
        </x-ui.card>
    </form>

@endsection
