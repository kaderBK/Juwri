{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

{{-- ST2 (features-run-4): App Store detail inside the dashboard. Data from
     Juwri\Modules\AppStore\Http\Controllers\AppStoreController@show. Inline install CTA POSTs to
     the EXISTING apps.install route (AppInstallService) — no new commerce. --}}

@section('title', $app->name)
@section('page_title', $app->name)

@section('content')
@php $devName = $app->developer?->name ?: ___('Platform'); $main = $screenshots->first(); @endphp
<div class="ui-scope ui-appshow">
{{-- P0-7: surface top-up dead-end (shortfall above single top-up max) --}}
@if(session('store_error'))
    <x-ui.banner tone="warning">{{ session('store_error') }}</x-ui.banner>
@endif
<a class="ui-appshow__back" href="{{ route('user.store.apps') }}"><x-ui.icon name="back" flip /> {{ ___('All apps') }}</a>

<div class="ui-appshow__grid">
    {{-- Buy box first in the source so phones see the CTA before the gallery;
         on desktop it moves to the side column. --}}
    <aside class="ui-appshow__side">
        <x-ui.card class="ui-appshow__buy">
            <div x-data="{ installing:false, done:false, msg:'', shortfall:0, topup:false }">
            <div class="ui-appshow__id">
                <span class="ui-appshow__icon">
                    @if($app->icon)<img src="{{ $app->icon->url() }}" alt="" width="52" height="52">
                    @else{{ mb_substr($app->name, 0, 1) }}@endif
                </span>
                <div class="ui-appshow__who">
                    <h2 class="ui-appshow__name">{{ $app->name }}</h2>
                    <span class="ui-appshow__dev">{{ ___('by :developer', ['developer' => $devName]) }}
                        @if((int) $app->rating_count > 0)· <span class="ui-appshow__star" aria-hidden="true">★</span> <span class="ui-ltr">{{ number_format($app->rating_avg, 1) }} ({{ $app->rating_count }})</span>@endif
                    </span>
                </div>
            </div>
            <p @class(['ui-appshow__price', 'is-free' => $app->isFreeBilling()])>{{ $app->pricingSummary() }}</p>
            @if($app->tagline)<p class="ui-appshow__tagline">{{ $app->tagline }}</p>@endif

            @if($installed)
                <x-ui.banner tone="success">{{ ___('Installed') }}</x-ui.banner>
                {{-- Polish (P5-2): scope re-consent — the app requests NEW permissions
                     your install has not granted (no silent escalation). --}}
                @if(!empty($pendingScopes ?? []))
                    <x-ui.banner tone="warning">
                        <strong>{{ ___('New permissions requested:') }}</strong>
                        @foreach($pendingScopes as $ps)<code>{{ $ps }}</code> @endforeach
                        <form method="POST" action="{{ route('user.store.apps.reconsent', $app->id) }}" class="ui-appshow__form">@csrf
                            <x-ui.button type="submit" size="sm">{{ ___('Review & accept new permissions') }}</x-ui.button>
                        </form>
                        <small>{{ ___('Until you accept, the app keeps its previously granted access only.') }}</small>
                    </x-ui.banner>
                @endif
                <x-ui.button variant="secondary" block :href="route('apps.page')">{{ ___('Manage in dashboard') }}</x-ui.button>
            @else
                <div x-show="!done">
                    <button type="button" class="ui-btn ui-btn--block" x-show="!topup" :disabled="installing"
                            @click="installing=true; msg='';
                                fetch('{{ route('user.store.install.app', $app->id) }}', {method:'POST', headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}','Accept':'application/json'}})
                                .then(r => r.json().then(d => ({ok:r.ok, d})))
                                .then(({ok, d}) => { installing=false;
                                    if(ok && d.ok){ done=true; }
                                    else if(d.status==='shortfall'){ shortfall=d.shortfall; topup=true; msg=d.message||''; }
                                    else { msg = d.message || '{{ ___('Could not install this app.') }}'; } })
                                .catch(() => { installing=false; msg='{{ ___('Something went wrong. Please try again.') }}'; })">
                        <span x-show="!installing">{{ ___('Install app') }}</span>
                        <span x-show="installing">{{ ___('Installing…') }}</span>
                    </button>
                    <p class="ui-appshow__error" x-show="msg && !topup" x-text="msg"></p>
                    @unless($app->isFreeBilling())
                        <p class="ui-appshow__note" x-show="!topup">{{ ___('Paid app — charges apply per its pricing once installed.') }}</p>
                    @endunless

                    {{-- ST4 shortfall: fund the gap via the existing wallet top-up
                         gateway; the install auto-completes on payment success. --}}
                    <div x-show="topup" x-cloak class="ui-appshow__topup">
                        <p class="ui-appshow__note" x-text="msg"></p>
                        @if($topupEnabled && $topupGateways->isNotEmpty())
                            <form method="POST" action="{{ route('user.store.topup.continue') }}" class="ui-appshow__form">
                                @csrf
                                <input type="hidden" name="purchase" value="app">
                                <input type="hidden" name="id" value="{{ $app->id }}">
                                <label class="ui-label" for="app-topup-method">{{ ___('Pay with') }}</label>
                                <select id="app-topup-method" name="payment_method" class="ui-input" required>
                                    @foreach($topupGateways as $g)<option value="{{ $g->id }}">{{ $g->payment_title ?? $g->payment_folder }}</option>@endforeach
                                </select>
                                <x-ui.button type="submit" block>{{ ___('Top up & install') }}</x-ui.button>
                            </form>
                            <p class="ui-appshow__note">{{ ___('Any credit beyond this purchase stays in your wallet. If you cancel the payment, nothing is charged.') }}</p>
                        @else
                            <p class="ui-appshow__note">{{ ___('Top up your wallet to complete this install.') }}</p>
                        @endif
                    </div>
                </div>
                <div x-show="done" x-cloak class="ui-appshow__done">
                    <x-ui.banner tone="success">{{ ___('Installed') }}</x-ui.banner>
                    <x-ui.button variant="secondary" block :href="route('apps.page')">{{ ___('Manage in dashboard') }}</x-ui.button>
                </div>
            @endif
            </div>
        </x-ui.card>
    </aside>

    <div class="ui-appshow__main">
        <div class="ui-appshow__hero">
            @if($main)<img src="{{ $main->url() }}" alt="{{ $app->name }}">
            @else<span>{{ ___('No preview') }}</span>@endif
        </div>
        @if($screenshots->count() > 1)
            <div class="ui-appshow__thumbs">
                @foreach($screenshots as $shot)
                    <img src="{{ $shot->url() }}" alt="" loading="lazy" width="110" height="70">
                @endforeach
            </div>
        @endif

        <x-ui.card :title="___('About this app')">
            @include('owner.partials.rich-description', ['text' => $app->description ?: $app->tagline])
        </x-ui.card>

        @if($scopes->isNotEmpty())
            <x-ui.card :title="___('Permissions requested')">
                <ul class="ui-appshow__scopes">
                    @foreach($scopes as $s)
                        <li><code>{{ $s['key'] }}</code><span>{{ $s['label'] }}</span></li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif

        @if($publishedReviews->isNotEmpty())
            <x-ui.card :title="___('Reviews')">
                <ul class="ui-appshow__reviews">
                    @foreach($publishedReviews as $rev)
                        <li>
                            <span class="ui-appshow__star" aria-label="{{ (int) $rev->rating }}/5">{{ str_repeat('★', (int) $rev->rating) }}{{ str_repeat('☆', 5 - (int) $rev->rating) }}</span>
                            <span class="ui-appshow__dev">{{ $rev->user?->name }}</span>
                            @if($rev->body)<p>{{ $rev->body }}</p>@endif
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif
    </div>
</div>
</div>
@endsection
