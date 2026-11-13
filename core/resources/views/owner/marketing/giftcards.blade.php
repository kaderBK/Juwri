{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Gift cards'))
@section('page_title', ___('Gift cards'))
@section('page_sub', ___('Issued cards, balances and liability.'))

@section('page_actions')
    @include('owner.partials.context-store-select', ['showBranch' => false])
@endsection

@section('content')
<div class="ui-scope ui-gc">
<section class="ui-gc__stats" aria-label="{{ ___('Gift cards') }}">
    <x-ui.stat :label="___('Outstanding liability')" :value="number_format($stats['liability'], 2)" icon="wallet" tone="warning" />
    <x-ui.stat :label="___('Total redeemed')" :value="number_format($stats['redeemed'], 2)" icon="check" tone="success" />
    <x-ui.stat :label="___('Active cards')" :value="$stats['active']" icon="gift" />
</section>

<x-ui.card :title="___('Issue a gift card')">
    <form method="post" action="{{ route('stores.giftcards.issue') }}?store_id={{ $rid }}" class="ui-gc__issue">
        @csrf
        <x-ui.field type="number" name="amount" :label="___('Amount')" step="0.01" min="1" required inputmode="decimal" />
        <x-ui.field type="email" name="recipient_email" :label="___('Recipient email')" dir="ltr" />
        <x-ui.field name="message" :label="___('Message')" maxlength="500" dir="auto" />
        <div class="ui-gc__issue-btn"><x-ui.button type="submit" icon="gift" block data-ui-busy>{{ ___('Issue') }}</x-ui.button></div>
    </form>
</x-ui.card>

{{-- C5: status filter — surface lapsed (expired) cards the B2 sweep flipped. --}}
<nav class="ui-chips" role="group" aria-label="{{ ___('Status') }}">
    @foreach(['' => ___('All'), 'active' => ___('Active'), 'expired' => ___('Expired'), 'disabled' => ___('Disabled')] as $key => $label)
        <a class="ui-chip" @if(($status ?? '') === $key) aria-current="true" @endif
           href="{{ route('stores.giftcards.index') }}?store_id={{ $rid }}{{ $key ? '&status='.$key : '' }}"
           data-status-filter="{{ $key ?: 'all' }}">{{ $label }}</a>
    @endforeach
</nav>

<section class="ui-card ui-card--flush">
    @if ($cards->isEmpty())
        <x-ui.empty icon="gift" :title="___('No gift cards yet.')" />
    @else
    <table class="ui-table">
        <thead><tr>
            <th>{{ ___('Code') }}</th><th class="ui-table__num">{{ ___('Value') }}</th><th class="ui-table__num">{{ ___('Balance') }}</th>
            <th>{{ ___('Status') }}</th><th>{{ ___('Recipient') }}</th><th>{{ ___('Expires') }}</th><th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
        </tr></thead>
        <tbody>
        @foreach($cards as $card)
            <tr>
                <td data-primary><code class="ui-gc__code">{{ $card->code }}</code></td>
                <td class="ui-table__num" data-label="{{ ___('Value') }}"><span class="ui-ltr">{{ number_format((float) $card->initial_value, 2) }}</span></td>
                <td class="ui-table__num" data-label="{{ ___('Balance') }}"><strong class="ui-ltr">{{ number_format($card->balance(), 2) }}</strong></td>
                <td data-label="{{ ___('Status') }}"><x-ui.pill :tone="['active'=>'success','expired'=>'warning','disabled'=>'neutral'][$card->status] ?? 'neutral'" data-status-pill="{{ $card->status }}">{{ ___(ucfirst($card->status)) }}</x-ui.pill></td>
                <td data-label="{{ ___('Recipient') }}"><span class="ui-ltr">{{ $card->recipient_email ?: '—' }}</span></td>
                <td data-label="{{ ___('Expires') }}"><span class="ui-ltr">{{ $card->expires_at?->format('Y-m-d') ?: '—' }}</span></td>
                <td class="ui-gc__actions">
                    @if($card->status === 'active')
                    <div class="ui-gc__actbox">
                    {{-- P6-214: per-row notice when the e-mail was permanently abandoned.
                         Balance is preserved; the owner can resend or correct the address. --}}
                    @if($card->isDeliveryFailed())
                        <div class="ui-gc__notice" data-delivery-notice="{{ $card->id }}">{{ ___('Gift card not delivered - check the address / resend') }} #{{ $card->id }}</div>
                    @endif
                    <form method="post" action="{{ route('stores.giftcards.adjust', $card->id) }}" class="ui-gc__adjust">
                        @csrf
                        {{-- B1: a fresh per-render nonce — a double-clicked Adjust submits the
                             same token twice → the ledger applies it once. --}}
                        <input type="hidden" name="idempotency_token" value="{{ \Illuminate\Support\Str::random(40) }}">
                        <input type="number" step="0.01" name="delta" placeholder="±" class="ui-input ui-input--sm" aria-label="{{ ___('Amount') }}">
                        <button type="submit" class="ui-btn ui-btn--secondary ui-btn--sm">{{ ___('Apply') }}</button>
                    </form>
                    @if($card->recipient_email)
                    <form method="post" action="{{ route('stores.giftcards.resend', $card->id) }}" data-resend-form="{{ $card->id }}">
                        @csrf
                        <button type="submit" class="ui-btn ui-btn--secondary ui-btn--sm" data-resend-btn>{{ ___('Resend') }}</button>
                    </form>
                    @endif
                    <form method="post" action="{{ route('stores.giftcards.void', $card->id) }}" data-confirm="{{ ___('Void this gift card?') }}" data-confirm-danger>
                        @csrf
                        <button type="submit" class="ui-btn ui-btn--danger ui-btn--sm">{{ ___('Void') }}</button>
                    </form>
                    </div>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif
    @if ($cards->hasPages())
        <div class="ui-catalog__pager">{{ $cards->links() }}</div>
    @endif
</section>
</div>
@endsection
