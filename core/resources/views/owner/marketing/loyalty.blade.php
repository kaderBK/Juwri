{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Loyalty program'))
@section('page_title', ___('Loyalty program'))
@section('page_sub', $store->title.' - '.___('Reward repeat visits with stamps or points.'))

@section('content')
{{-- Hero --}}
<div class="ui-card ui-card--inset">
    <div class="ui-cluster ui-cluster--between ui-card__body">
        <div>
            <p class="ui-text-accent ui-small ui-caps">{{ ___('Loyalty') }}</p>
            <h3 class="ui-section-title">{{ ___('Reward your regulars') }}</h3>
            <p class="ui-text-2">{{ ___('Stamp cards or points - diners earn per order, redeem for a reward.') }}</p>
        </div>
        <div class="ui-cluster ui-center">
            <div><span class="ui-muted ui-block">{{ ___('Members') }}</span><strong class="ui-fs-lg">{{ number_format($accounts->count()) }}</strong></div>
            <div><span class="ui-muted ui-block">{{ ___('Active') }}</span><strong class="ui-fs-lg">{{ $program && $program->active ? ___('Yes') : ___('No') }}</strong></div>
            <div><span class="ui-muted ui-block">{{ ___('Type') }}</span><strong class="ui-fs-lg">{{ $program ? ___($program->type) : '—' }}</strong></div>
        </div>
    </div>
</div>

<x-ui.card :title="___('Program rules')">
    <form action="{{ route('loyalty.update', $store->id) }}" method="post">
        @csrf
        <div class="ui-form-grid">
            <div>
                <label class="ui-label">{{ ___('Type') }}</label>
                <select name="type" class="ui-input" required>
                    <option value="stamps" @selected(old('type', $program->type ?? 'stamps') === 'stamps')>{{ ___('Stamps') }}</option>
                    <option value="points" @selected(old('type', $program->type ?? '') === 'points')>{{ ___('Points') }}</option>
                </select>
            </div>
            <div>
                <label class="ui-label">{{ ___('Earn per order') }}</label>
                <input type="number" min="1" max="1000" name="earn_rate" class="ui-input" required value="{{ old('earn_rate', $program->earn_rate ?? 1) }}">
            </div>
            <div>
                <label class="ui-label">{{ ___('Reward threshold') }}</label>
                <input type="number" min="1" max="9999" name="reward_threshold" class="ui-input" required value="{{ old('reward_threshold', $program->reward_threshold ?? 10) }}">
            </div>
            <div>
                <label class="ui-label">{{ ___('Reward discount') }}</label>
                <input type="number" step="0.01" min="0" name="reward_discount" class="ui-input" value="{{ old('reward_discount', $program->reward_discount ?? 0) }}">
            </div>
            <div class="ui-form-grid__full">
                <label class="ui-label">{{ ___('Reward description') }}</label>
                <input type="text" maxlength="200" name="reward_description" class="ui-input" value="{{ old('reward_description', $program->reward_description ?? '') }}" placeholder="{{ ___('Free coffee with 10 stamps') }}">
            </div>
            <div class="ui-form-grid__full">
                <div class="ui-check">
                    <input type="checkbox" id="loyalty-active" name="active" value="1" @checked(old('active', $program->active ?? false))>
                    <label for="loyalty-active">{{ ___('Program is active') }}</label>
                </div>
            </div>

            {{-- Tiers: named thresholds by points/stamps balance (highest reached wins) --}}
            <div class="ui-form-grid__full" x-data="{ tiers: @js(old('tiers', ($program->tierList() ?? []))) }">
                <label class="ui-label">{{ ___('Tiers') }} <span class="ui-muted">{{ ___('(optional — name + minimum balance)') }}</span></label>
                <template x-for="(t, i) in tiers" :key="i">
                    <div class="ui-cluster" style="max-width:520px">
                        <input type="text" class="ui-input ui-input--sm" :name="'tiers['+i+'][name]'" x-model="t.name" placeholder="{{ ___('e.g. Gold') }}">
                        <input type="number" min="0" class="ui-input ui-input--sm" style="max-width:140px" :name="'tiers['+i+'][min]'" x-model.number="t.min" placeholder="{{ ___('min') }}">
                        <button type="button" class="ui-icon-btn ui-menu-item__delete" @click="tiers.splice(i,1)" aria-label="{{ ___('Delete') }}"><x-ui.icon name="trash" /></button>
                    </div>
                </template>
                <button type="button" class="ui-btn ui-btn--sm ui-btn--secondary" @click="tiers.push({name:'',min:0})">＋ {{ ___('Add tier') }}</button>
            </div>
        </div>
        <div class="ui-cluster ui-cluster--end">
            <x-ui.button type="submit" icon="check">{{ ___('Save program') }}</x-ui.button>
        </div>
    </form>
</x-ui.card>

{{-- google-suite Lane C — Google Wallet passes (per-store enable + creds status). --}}
@php
    $__gwCreds = \App\Services\GoogleWallet\GoogleWalletService::enabled();
    $__gwOn = (string) store_options($store->id, 'google_wallet_enabled', '0') === '1';
    // biolink-tpl lane-d (T3-1): the bio-link-on-pass opt-in is only meaningful when
    // the store's bio page is live.
    $__bioLive = \App\Support\BioLinkOutbound::isLive($store);
    $__walletBioOn = (string) store_options($store->id, 'wallet_bio_link', '0') === '1';
@endphp
<x-ui.card :title="___('Google Wallet passes')">
    <x-slot:actions>
        @if($__gwCreds)
            <span class="ui-tag ui-text-success" data-tone="success">{{ ___('Platform configured') }}</span>
        @else
            <span class="ui-text-2 ui-tag" data-tone="neutral">{{ ___('Not configured') }}</span>
        @endif
    </x-slot:actions>

    <p class="ui-muted">
        {{ ___('Let diners save their loyalty card (and gift cards) to Google Wallet. Balances sync automatically after each earn, redeem, or top-up.') }}
    </p>

    @unless($__gwCreds)
        <div class="ui-note ui-small" data-tone="neutral">
            {{ ___('Google Wallet is not configured on the platform yet, so passes stay off for everyone until the platform adds Wallet credentials. You can pre-enable it below; it takes effect once configured.') }}
        </div>
    @endunless

    <div class="ui-note ui-small" data-tone="warning">
        <strong>{{ ___('White-label note:') }}</strong>
        {{ ___('Passes are issued under the platform Google Wallet account, so the platform issuer name shows on every saved pass. Do not enable this on white-label / custom-domain stores until per-reseller issuer accounts are available.') }}
    </div>

    <form action="{{ route('googleWallet.toggle', $store->id) }}" method="post">
        @csrf
        <div class="ui-check">
            <input type="checkbox" id="google-wallet-enabled"
                   name="google_wallet_enabled" value="1" @checked($__gwOn)>
            <label for="google-wallet-enabled">{{ ___('Enable Google Wallet for this store') }}</label>
        </div>
        {{-- biolink-tpl lane-d (T3-1): add the bio page as a link on saved passes — only shown when the bio page is live --}}
        @if($__bioLive)
            <div class="ui-check">
                <input type="checkbox" id="wallet-bio-link"
                       name="wallet_bio_link" value="1" @checked($__walletBioOn)>
                <label for="wallet-bio-link">{{ ___('Add my bio page link to saved passes') }}</label>
            </div>
        @endif
        <div class="ui-cluster ui-cluster--end">
            <x-ui.button type="submit" icon="check">{{ ___('Save') }}</x-ui.button>
        </div>
    </form>
</x-ui.card>

<x-ui.card :title="___('Top members')">
    <x-slot:actions><span class="ui-muted">{{ ___('Top 20 by balance.') }}</span></x-slot:actions>
    @if ($accounts->isEmpty())
        <x-ui.empty :title="___('No loyalty accounts yet. Members appear here after their first earn.')" />
    @else
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead><tr><th>{{ ___('Customer') }}</th><th class="ui-end">{{ ___('Balance') }}</th><th class="ui-end">{{ ___('Earned') }}</th><th class="ui-end">{{ ___('Redeemed') }}</th></tr></thead>
                <tbody>
                    @foreach ($accounts as $a)
                        <tr>
                            <td data-label="{{ ___('Customer') }}">#{{ $a->customer_id }}</td>
                            <td class="ui-end" data-label="{{ ___('Balance') }}">{{ number_format($a->balance) }}</td>
                            <td class="ui-end" data-label="{{ ___('Earned') }}">{{ number_format($a->lifetime_earned ?? 0) }}</td>
                            <td class="ui-end" data-label="{{ ___('Redeemed') }}">{{ number_format($a->lifetime_redeemed ?? 0) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-ui.card>
@endsection
