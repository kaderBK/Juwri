{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')
@section('title', ___('WhatsApp Settings'))
@section('page_title', ___('WhatsApp'))
@section('page_sub', ___('Provider, keys, automations and templates for :name', ['name' => $store->title]))
@section('content')

<div class="ui-scope ui-settings ui-wa">

@php
    $providerOptions = \Juwri\Core\Support\Views\PublicSiteViewHelpers::waProviderOptions($providers);
    $flags = [
        'customer_order_whatsapp'  => ___('Send order confirmation on WhatsApp'),
        'customer_status_whatsapp' => ___('Send status updates on WhatsApp'),
        'whatsapp_chatbot_enable'  => ___('AI chatbot auto-reply to inbound messages'),
    ];
    // Effective default when this restaurant hasn't picked a provider.
    $providerDefault = config('settings.whatsapp_default_provider') ?: config('whatsapp.default_provider', 'link');
@endphp

<nav class="ui-wa__tools" aria-label="{{ ___('WhatsApp') }}">
    <x-ui.button :href="route('stores.whatsappInbox', $store->id)" variant="secondary" size="sm" icon="chat">{{ ___('Inbox') }}</x-ui.button>
    <x-ui.button :href="route('stores.whatsappCampaign', $store->id)" variant="secondary" size="sm" icon="share">{{ ___('Campaign') }}</x-ui.button>
    <x-ui.button :href="route('stores.whatsappTemplates', $store->id)" variant="secondary" size="sm" icon="list">{{ ___('Templates') }}</x-ui.button>
</nav>

<form method="post" action="{{ route('stores.whatsappSettings', $store->id) }}" class="ui-wa__form">
    @csrf

    {{-- WA-C3: the ordering on/off switch, moved out of the QuickOrder plugin page.
         IMPORTANT: it is checked from the EFFECTIVE state (whatsapp_ordering_enabled),
         not the raw new option. 1,172 stores are still on the legacy quickorder_enable
         key — rendering this unchecked for them would silently switch their ordering
         off the first time an owner saved any other setting on this page. --}}
    <x-ui.card :title="___('WhatsApp ordering')">
        <x-ui.field type="select" name="whatsapp_ordering_enable"
            :label="___('Send orders to WhatsApp when a customer checks out')"
            :value="whatsapp_ordering_enabled($store->id) ? '1' : '0'"
            :options="['0' => ___('Disabled'), '1' => ___('Enabled')]"
            :help="___('Turn this off to keep taking orders without the WhatsApp hand-off.')" />

        {{-- WA-S1: what happens after checkout. This was hardcoded per theme and
             inconsistent — some popped a new tab, some navigated in place, one did
             nothing at all. The owner decides now. --}}
        @php $__handoff = whatsapp_handoff_settings($store->id); @endphp

        <x-ui.field type="select" name="whatsapp_handoff_mode"
            :label="___('After checkout')"
            :value="$__handoff['mode']"
            :options="[
                'auto'   => ___('Open WhatsApp automatically'),
                'button' => ___('Show a \'Send on WhatsApp\' button'),
                'none'   => ___('Do nothing — the order is placed silently'),
            ]" />

        <x-ui.field type="select" name="whatsapp_handoff_target"
            :label="___('Open in')"
            :value="$__handoff['target']"
            :options="['new' => ___('A new tab'), 'same' => ___('The same tab')]"
            :help="___('On iPhone a new tab can be blocked, so we fall back to the same tab and always show a link.')" />

        <x-ui.field type="number" name="whatsapp_handoff_delay" step="0.5" min="0" max="5"
            :label="___('Delay before opening (seconds)')"
            :value="(string) ($__handoff['delay'] / 1000)"
            :help="___('Gives the customer a moment to see the confirmation first. 0 opens immediately.')" />

        <x-ui.field type="select" name="whatsapp_handoff_route"
            :label="___('Send the order to')"
            :value="$__handoff['route']"
            :options="[
                'branch' => ___('The branch that took the order'),
                'fixed'  => ___('Always the default branch'),
            ]" />
    </x-ui.card>

    <x-ui.card :title="___('Channel')">
        <x-ui.field type="select" name="whatsapp_provider" :label="___('Provider')"
            :value="@$postOptions->whatsapp_provider ?: $providerDefault" :options="$providerOptions"
            :help="___('Defaults to the platform provider (:p) when left unset. wa.me is a free click-to-chat link; Twilio / Meta send real automated messages.', ['p' => $providerDefault])" />
        <x-ui.field type="select" name="whatsapp_number_mode" :label="___('Sending number')"
            :value="@$postOptions->whatsapp_number_mode ?: 'platform'"
            :options="['platform' => ___('Use platform number'), 'own' => ___('Use my own number (BYOK)')]" />
    </x-ui.card>

    <x-ui.card :title="___('Your API keys (BYOK)')">
        <p class="field__hint">{{ ___('Leave blank to keep existing keys or use platform keys. Stored encrypted.') }}</p>

        @foreach (['meta' => 'Meta Cloud API', 'twilio' => 'Twilio'] as $__p => $__label)
            @if ($waSignature[$__p]['has_secret'] || $waSignature[$__p]['routed'])
                <p class="field__hint">
                    {{ $__label }} —
                    @if ($waSignature[$__p]['has_secret'] && $waSignature[$__p]['routed'])
                        <span class="ui-wa__ok">{{ ___('Inbound messages are signature-verified with your key.') }}</span>
                    @elseif (! $waSignature[$__p]['has_secret'])
                        <span class="ui-wa__bad">{{ ___('No signing secret saved — inbound messages on your own number will be rejected. Add your App Secret / Auth Token below.') }}</span>
                    @else
                        <span class="ui-wa__bad">{{ ___('Your sending number is not registered yet — save this page once to register it.') }}</span>
                    @endif
                </p>
            @endif
        @endforeach

        <p class="ui-wa__group">Twilio</p>
        <x-ui.field name="twilio_sid" :label="___('Account SID')" autocomplete="off" />
        <x-ui.field name="twilio_token" type="password" :label="___('Auth Token')" autocomplete="new-password" />
        <x-ui.field name="twilio_from" :label="___('From number')" placeholder="whatsapp:+14155238886" />

        <p class="ui-wa__group">Meta Cloud API</p>
        <x-ui.field name="meta_access_token" type="password" :label="___('Access Token')" autocomplete="new-password" />
        <x-ui.field name="meta_phone_number_id" :label="___('Phone Number ID')" />
        <x-ui.field name="meta_app_secret" type="password" :label="___('App Secret')" autocomplete="new-password" />
        <x-ui.field name="meta_webhook_verify_token" :label="___('Webhook Verify Token')"
            :help="___('Meta webhook URL').': '.url('webhook/whatsapp/meta')" />
    </x-ui.card>

    <x-ui.card :title="___('Automations')">
        <x-plan-gate-notice feature="whatsapp_order_alerts" :owner="$store->user" />
        @foreach ($flags as $flag => $label)
            <x-ui.field type="select" :name="$flag" :label="$label"
                :value="(string) (@$postOptions->$flag ?? '0')" :options="['0' => ___('Disabled'), '1' => ___('Enabled')]" />
        @endforeach
        <x-ui.field name="whatsapp_order_template" :label="___('Order confirmation template name')" :value="@$postOptions->whatsapp_order_template" placeholder="order_confirm" />
        <x-ui.field name="whatsapp_status_template" :label="___('Status update template name')" :value="@$postOptions->whatsapp_status_template" placeholder="order_status"
            :help="___('Template names must match approved WhatsApp templates (required outside the 24h window).')" />
        <x-ui.field type="textarea" name="whatsapp_chatbot_prompt" :label="___('Chatbot system prompt')" :value="@$postOptions->whatsapp_chatbot_prompt" />
    </x-ui.card>

    <x-ui.card :title="___('WhatsApp Numbers (wa.me)')">
        {{-- WA-E1: the ten number fields are gone. A number belongs to the branch
             that answers it, not to the store as a flat list — that is why orders
             could not be routed per location before. Editing them here would also
             write back rows the migration has already retired. --}}
        @php
            $__waNumber = storefront_whatsapp_number($store, null, false);
            $__waBranches = \Juwri\Core\Models\Branch::where('store_id', $store->id)
                ->orderBy('position')->orderBy('id')->get(['id', 'name', 'whatsapp']);
        @endphp

        <p class="field__hint">
            {{ ___('Your WhatsApp numbers live on your branches now, so an order goes to the location that takes it.') }}
        </p>

        @if($__waBranches->isNotEmpty())
            <ul class="ui-list ui-wa__branches">
                @foreach($__waBranches as $__b)
                    <li class="ui-row">
                        <span class="ui-row__icon" data-tone="neutral"><x-ui.icon name="store" size="20" /></span>
                        <span class="ui-row__body">
                            <span class="ui-row__title">{{ $__b->name }}</span>
                            <span class="ui-row__sub @if($__b->whatsapp) ui-ltr @endif">{{ $__b->whatsapp ?: ___('no number of its own') }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif

        <p class="ui-wa__route">
            @if($__waNumber !== '')
                {{ ___('Orders currently go to') }} <strong class="ui-ltr">{{ $__waNumber }}</strong>.
            @else
                <span class="ui-wa__bad">{{ ___('No WhatsApp number is set, so orders cannot be handed over.') }}</span>
            @endif
            <a href="{{ route('branches.index', ['store_id' => $store->id]) }}">{{ ___('Manage branch numbers') }}</a>
        </p>

        <x-ui.field type="textarea" name="whatsapp_message" :label="___('wa.me Order Message')" :value="@$postOptions->whatsapp_message"
            :help="___('Leave empty to use the platform default.')" />

        {{-- WA-C2: the shortcode reference lived only inside the QuickOrder plugin
             page. It belongs beside the field it documents. --}}
        <div class="ui-wa__codes">
            <div class="ui-wa__codes-label">{{ ___('Available shortcodes') }}</div>
            <div class="ui-wa__codes-list">
                @foreach (\App\Services\WhatsApp\OrderMessage::SHORTCODES as $token => $label)
                    <button type="button" class="ui-wa__code" data-token="{{ $token }}"
                            title="{{ ___('Click to copy') }}">
                        <code>{{ $token }}</code>
                        <span>{{ ___($label) }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    </x-ui.card>

    <script nonce="{{ csp_nonce() }}">
        /* Click a shortcode to drop it into the message field at the cursor.
           Bound once: Turbo re-runs this script on every visit. */
        if (!window.__waShortcodes) {
            window.__waShortcodes = true;
            document.addEventListener('click', function (e) {
                var chip = e.target.closest && e.target.closest('.ui-wa__code');
                if (!chip) return;
                var field = document.querySelector('[name="whatsapp_message"]');
                if (!field) return;
                var token = chip.dataset.token,
                    start = field.selectionStart || field.value.length,
                    end = field.selectionEnd || field.value.length;
                field.value = field.value.slice(0, start) + token + field.value.slice(end);
                field.dispatchEvent(new Event('input', { bubbles: true }));
                field.focus();
                field.selectionStart = field.selectionEnd = start + token.length;
                chip.classList.add('is-copied');
                setTimeout(function () { chip.classList.remove('is-copied'); }, 900);
            });
        }
    </script>

    <div class="sticky-savebar" data-bridge-hide="form">
        <x-ui.button type="submit" icon="check">{{ ___('Save') }}</x-ui.button>
    </div>
</form>
</div>

@endsection
