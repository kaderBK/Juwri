{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('AI phone agent'))
@section('page_title', ___('AI phone agent'))
@section('page_sub', ___('Let an AI answer your phone and take orders.'))

@section('page_actions')
    <x-ui.button :href="route('help.show', 'phone-agent')" target="_blank" rel="noopener" variant="secondary" size="sm" icon="book-open">{{ ___('Owner guide') }}</x-ui.button>
    <x-ui.button :href="route('integrations.docs', 'ai-phone-agent')" target="_blank" rel="noopener" variant="secondary" size="sm" icon="book-open">{{ ___('Setup guide') }}</x-ui.button>
    @include('owner.partials.context-store-select', ['showBranch' => false])
@endsection

@section('content')
<div class="ui-scope ui-channels">
@include('owner.phone-agent.partials.minutes-usage', ['minutesUsage' => $minutesUsage])
<x-ui.card :title="___('Connect a number')">
    <form method="post" action="{{ route('stores.phoneagent.save') }}?store_id={{ $rid }}" class="ui-channels__form">
        @csrf
        <div class="ui-channels__fields">
            <div>
                <label class="ui-label" for="pa-provider">{{ ___('Provider') }}</label>
                <select id="pa-provider" name="provider" class="ui-input"><option value="twilio">Twilio</option><option value="telnyx">Telnyx</option></select>
            </div>
            <div><label class="ui-label" for="pa-number">{{ ___('Phone number') }}</label><input id="pa-number" name="number" class="ui-input ui-ltr" placeholder="+1..." inputmode="tel" required></div>
            <div class="ui-channels__wide"><label class="ui-label" for="pa-greeting">{{ ___('Greeting') }}</label><input id="pa-greeting" name="greeting" class="ui-input" placeholder="{{ ___('Hi! How can I help?') }}"></div>
            <div><label class="ui-label" for="pa-handoff">{{ ___('Staff handoff number') }}</label><input id="pa-handoff" name="handoff_number" class="ui-input ui-ltr" inputmode="tel"></div>
            <div><label class="ui-label" for="pa-hours">{{ ___('Active hours') }}</label><input id="pa-hours" name="active_hours" class="ui-input ui-ltr" placeholder="{{ ___('e.g. 09:00-23:00') }}"></div>
            <div><label class="ui-label" for="pa-cap">{{ ___('Monthly minute cap') }}</label><input id="pa-cap" name="monthly_minute_cap" class="ui-input ui-ltr" type="number" min="0" step="1" placeholder="{{ ___('Leave empty for no cap') }}"></div>
            <div><label class="ui-label" for="pa-region">{{ ___('Recording region') }}</label>
                <select id="pa-region" name="recording_region" class="ui-input">
                    <option value="">—</option>
                    <option value="ksa">{{ ___('Saudi Arabia') }}</option>
                    <option value="uae">{{ ___('United Arab Emirates') }}</option>
                    <option value="eu">{{ ___('European Union') }}</option>
                    <option value="other">{{ ___('Other') }}</option>
                </select>
            </div>
            <div><label class="ui-label" for="pa-language">{{ ___('Call language') }}</label>
                <select id="pa-language" name="language" class="ui-input">
                    <option value="">{{ ___('Automatic (store language)') }}</option>
                    <option value="en">{{ ___('English') }}</option>
                    <option value="ar">{{ ___('Arabic') }}</option>
                </select>
                <small class="ui-field__help">{{ ___('The language the phone agent speaks on this line.') }}</small>
            </div>
            <div><label class="ui-label" for="pa-token">{{ ___('Auth token (BYOK)') }}</label><input id="pa-token" name="auth_token" class="ui-input" type="password" autocomplete="off"></div>
        </div>
        <div class="ui-channels__foot">
            <x-ui.switch name="orders_enabled" id="oe" :checked="true" :label="___('Take orders')" />
            <x-ui.switch name="recording_enabled" id="re" :label="___('Record calls')" />
            <x-ui.switch name="link_pay_only" id="lp" :label="___('Payment by link only')" />
            <x-ui.button type="submit" icon="check" data-ui-busy>{{ ___('Save') }}</x-ui.button>
        </div>
    </form>
</x-ui.card>

@if($lines->count())
<section class="ui-card ui-card--flush">
    <table class="ui-table">
        <thead><tr><th>{{ ___('Number') }}</th><th>{{ ___('Provider') }}</th><th>{{ ___('Orders') }}</th><th>{{ ___('Status') }}</th><th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th></tr></thead>
        <tbody>
        @foreach($lines as $l)
            <tr>
                <td data-primary><code class="ui-ltr">{{ $l->number }}</code></td>
                <td data-label="{{ ___('Provider') }}">{{ ucfirst($l->provider) }}</td>
                <td data-label="{{ ___('Orders') }}">{{ $l->orders_enabled ? ___('Yes') : ___('No') }}</td>
                <td data-label="{{ ___('Status') }}"><x-ui.pill :tone="$l->active ? 'success' : 'neutral'">{{ $l->active ? ___('Active') : ___('Disabled') }}</x-ui.pill></td>
                <td class="ui-catalog__row-actions"><form method="post" action="{{ route('stores.phoneagent.toggle', $l->id) }}">@csrf<x-ui.button type="submit" variant="secondary" size="sm">{{ $l->active ? ___('Disable') : ___('Enable') }}</x-ui.button></form></td>
            </tr>
            <tr>
                <td colspan="5">
                    <details class="ui-phone-agent__settings">
                        <summary class="ui-phone-agent__summary">{{ ___('Settings') }}</summary>
                        <form method="post" action="{{ route('stores.phoneagent.update', $l->id) }}?store_id={{ $rid }}">
                            @csrf
                            <div class="ui-channels__fields">
                                <div class="ui-channels__wide"><label class="ui-label" for="pa-greeting-{{ $l->id }}">{{ ___('Greeting') }}</label><input id="pa-greeting-{{ $l->id }}" name="greeting" class="ui-input" value="{{ old('greeting', $l->greeting) }}" maxlength="512"></div>
                                <div><label class="ui-label" for="pa-hours-{{ $l->id }}">{{ ___('Active hours') }}</label><input id="pa-hours-{{ $l->id }}" name="active_hours" class="ui-input ui-ltr" value="{{ old('active_hours', $l->active_hours) }}" placeholder="{{ ___('e.g. 09:00-23:00') }}" maxlength="191"></div>
                                <div><label class="ui-label" for="pa-handoff-{{ $l->id }}">{{ ___('Staff handoff number') }}</label><input id="pa-handoff-{{ $l->id }}" name="handoff_number" class="ui-input ui-ltr" value="{{ old('handoff_number', $l->handoff_number) }}" inputmode="tel" maxlength="32"></div>
                                <div><label class="ui-label" for="pa-cap-{{ $l->id }}">{{ ___('Monthly minute cap') }}</label><input id="pa-cap-{{ $l->id }}" name="monthly_minute_cap" class="ui-input ui-ltr" type="number" min="0" step="1" value="{{ old('monthly_minute_cap', $l->monthly_minute_cap) }}" placeholder="{{ ___('Leave empty for no cap') }}"></div>
                                <div><label class="ui-label" for="pa-region-{{ $l->id }}">{{ ___('Recording region') }}</label>
                                    <select id="pa-region-{{ $l->id }}" name="recording_region" class="ui-input">
                                        <option value="">—</option>
                                        <option value="ksa" @selected(old('recording_region', $l->recording_region) === 'ksa')>{{ ___('Saudi Arabia') }}</option>
                                        <option value="uae" @selected(old('recording_region', $l->recording_region) === 'uae')>{{ ___('United Arab Emirates') }}</option>
                                        <option value="eu" @selected(old('recording_region', $l->recording_region) === 'eu')>{{ ___('European Union') }}</option>
                                        <option value="other" @selected(old('recording_region', $l->recording_region) === 'other')>{{ ___('Other') }}</option>
                                    </select>
                                </div>
                                <div><label class="ui-label" for="pa-language-{{ $l->id }}">{{ ___('Call language') }}</label>
                                    <select id="pa-language-{{ $l->id }}" name="language" class="ui-input">
                                        <option value="">{{ ___('Automatic (store language)') }}</option>
                                        <option value="en" @selected(old('language', $l->language) === 'en')>{{ ___('English') }}</option>
                                        <option value="ar" @selected(old('language', $l->language) === 'ar')>{{ ___('Arabic') }}</option>
                                    </select>
                                    <small class="ui-field__help">{{ ___('The language the phone agent speaks on this line.') }}</small>
                                </div>
                            </div>
                            <div class="ui-channels__foot">
                                <x-ui.switch name="orders_enabled" :id="'pa-orders-'.$l->id" :checked="(bool) old('orders_enabled', $l->orders_enabled)" off="0" :label="___('Take orders')" />
                                <x-ui.switch name="recording_enabled" :id="'pa-rec-'.$l->id" :checked="(bool) old('recording_enabled', $l->recording_enabled)" off="0" :label="___('Record calls')" />
                                <x-ui.switch name="link_pay_only" :id="'pa-linkpay-'.$l->id" :checked="(bool) old('link_pay_only', $l->link_pay_only)" off="0" :label="___('Payment by link only')" />
                                <x-ui.button type="submit" icon="check" data-ui-busy>{{ ___('Save settings') }}</x-ui.button>
                            </div>
                        </form>
                    </details>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</section>
@endif

<x-ui.card :title="___('Recent calls')" variant="flush">
    @if($calls->isEmpty())
        <x-ui.empty icon="phone" :title="___('No calls yet.')" />
    @else
        <table class="ui-table">
            <thead><tr><th>{{ ___('From') }}</th><th>{{ ___('Outcome') }}</th><th>{{ ___('Duration') }}</th><th>{{ ___('Order') }}</th><th>{{ ___('When') }}</th><th><span class="ui-visually-hidden">{{ ___('View') }}</span></th></tr></thead>
            <tbody>
            @foreach($calls as $c)
                <tr>
                    <td data-primary><span class="ui-ltr">{{ $c->from_number ?: '—' }}</span></td>
                    <td data-label="{{ ___('Outcome') }}"><x-ui.tag>{{ ___(ucfirst($c->outcome)) }}</x-ui.tag></td>
                    <td data-label="{{ ___('Duration') }}"><span class="ui-ltr">{{ $c->duration_seconds !== null ? gmdate('i:s', (int) $c->duration_seconds) : '—' }}</span></td>
                    <td data-label="{{ ___('Order') }}"><span class="ui-ltr">{{ $c->order_id ? '#'.$c->order_id : '—' }}</span></td>
                    <td data-label="{{ ___('When') }}"><span class="ui-channels__muted">{{ $c->created_at?->diffForHumans() }}</span></td>
                    <td class="ui-catalog__row-actions"><x-ui.button :href="route('stores.phoneagent.calls.show', $c->id).'?store_id='.$rid" variant="secondary" size="sm">{{ ___('View') }}</x-ui.button></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="ui-channels__foot">
            <x-ui.button variant="link" :href="route('stores.phoneagent.calls.index').'?store_id='.$rid">{{ ___('View all calls') }}</x-ui.button>
        </div>
    @endif
</x-ui.card>
</div>
@endsection
