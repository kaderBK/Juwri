{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')
@section('title', ___('WhatsApp Campaign'))
@section('page_title', ___('WhatsApp Campaign'))
@section('page_sub', $store->title)
@section('content')

<div class="ui-scope ui-wa ui-wacamp" x-data="{
        bioUrl: @js($bioUrl ?? null),
        insertBio() {
            if (! this.bioUrl) return;
            const ta = this.$root.querySelector('textarea[name=body]');
            if (! ta) return;
            let start = ta.selectionStart, end = ta.selectionEnd;
            if (start === null || start === undefined) { start = end = ta.value.length; }
            const before = ta.value.slice(0, start);
            const sep = (before.length && ! /\s$/.test(before)) ? ' ' : '';
            const ins = sep + this.bioUrl;
            ta.value = before + ins + ta.value.slice(end);
            const pos = start + ins.length;
            ta.focus();
            ta.setSelectionRange(pos, pos);
            ta.dispatchEvent(new Event('input'));
        }
    }">

<nav class="ui-wa__tools" aria-label="{{ ___('WhatsApp') }}">
    <x-ui.button :href="route('stores.whatsappSettings', $store->id)" variant="secondary" size="sm" icon="settings">{{ ___('Settings') }}</x-ui.button>
    <x-ui.button :href="route('stores.whatsappInbox', $store->id)" variant="secondary" size="sm" icon="chat">{{ ___('Inbox') }}</x-ui.button>
    <x-ui.button :href="route('stores.whatsappTemplates', $store->id)" variant="secondary" size="sm" icon="list">{{ ___('Templates') }}</x-ui.button>
</nav>

<div class="ui-wacamp__grid">
    <aside class="ui-wacamp__side">
        <x-ui.stat :value="number_format($audience)" :label="___('Opted-in audience')" icon="users" :tone="$audience === 0 ? 'warning' : 'success'" />
        <x-ui.banner :tone="$audience === 0 ? 'warning' : 'info'">
            {{ ___('Only customers who opted in and have not sent STOP will receive this. Marketing needs an approved template on Meta/Twilio.') }}
        </x-ui.banner>
    </aside>

    <form method="post" action="{{ route('stores.whatsappCampaign.send', $store->id) }}" class="ui-wacamp__main">
        @csrf
        <x-ui.card :title="___('Marketing broadcast')">
            <div class="ui-wacamp__fields">
                <x-ui.field name="template" :label="___('Approved template name (Meta/Twilio)')" placeholder="promo_offer" dir="ltr" autocomplete="off" />
                <x-ui.field type="textarea" name="body" :label="___('Message')" :placeholder="___('Your message...')" rows="6" required dir="auto" />

                {{-- biolink-tpl lane-d (T2-3): insert this store's bio link at the cursor; shown only when the bio page is live --}}
                @if(!empty($bioUrl))
                    <div class="ui-wacamp__insert">
                        <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" @click="insertBio()">
                            <span class="ui-btn__icon"><x-ui.icon name="external" /></span>{{ ___('Insert bio link') }}
                        </button>
                    </div>
                @endif
            </div>

            <div class="ui-wacamp__send">
                <x-ui.button type="submit" icon="share" :disabled="$audience === 0" data-ui-busy>
                    {{ ___('Send Campaign') }}
                </x-ui.button>
            </div>
        </x-ui.card>
    </form>
</div>
</div>

@endsection
