{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Get paid with Paylink'))
@section('page_title', ___('Get paid with Paylink'))
@section('page_sub', ___('Onboard your store to Paylink payments without leaving the dashboard.'))

@section('page_actions')
    <form method="get" action="{{ route('stores.paylink.partner.index') }}">
        <label class="ui-visually-hidden" for="paylink-store">{{ ___('Store') }}</label>
        <select id="paylink-store" name="store_id" class="ui-input ui-input--sm" data-ui-do="submit">
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($rid == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')
@php $status = $onboarding->status ?? null; @endphp
<div class="ui-scope ui-paylink">
    <x-ui.card :title="___('Get paid with Paylink')">
        @if($status === 'active')
            <x-slot:actions><x-ui.pill tone="success">{{ ___('Active') }}</x-ui.pill></x-slot:actions>
        @elseif(in_array($status, ['submitted','pending_nafath']))
            <x-slot:actions><x-ui.pill tone="warning">{{ ___('Pending Nafath verification') }}</x-ui.pill></x-slot:actions>
        @elseif($status === 'rejected')
            <x-slot:actions><x-ui.pill tone="danger">{{ ___('Rejected') }}</x-ui.pill></x-slot:actions>
        @endif

        @if($status === 'active')
            <p class="ui-paylink__text">{{ ___('Paylink is live for this store. Your API keys were provisioned automatically.') }}</p>
            <form method="POST" action="{{ route('stores.paylink.partner.disconnect') }}?store_id={{ $rid }}">
                @csrf
                <x-ui.button type="submit" variant="danger">{{ ___('Disconnect Paylink') }}</x-ui.button>
            </form>
        @elseif(in_array($status, ['submitted','pending_nafath']))
            <x-ui.banner tone="warning">{{ ___('Your registration was submitted. Complete Nafath identity verification with Paylink; we activate automatically once approved.') }}</x-ui.banner>
        @elseif($status === 'rejected')
            <x-ui.banner tone="danger">{{ $onboarding->reject_reason ?: ___('Your Paylink registration was rejected.') }}</x-ui.banner>
        @else
            <p class="ui-paylink__text">{{ ___('Onboard your store to Paylink payments without leaving the dashboard.') }}</p>
        @endif

        @if($status !== 'active' && $status !== 'pending_nafath')
            <form method="POST" action="{{ route('stores.paylink.partner.submit') }}?store_id={{ $rid }}" class="ui-paylink__form">
                @csrf
                <div class="ui-paylink__fields">
                    <div>
                        <label class="ui-label" for="paylink-cr">{{ ___('Commercial Registration (CR) number') }}</label>
                        <input id="paylink-cr" class="ui-input ui-ltr" name="cr_number" inputmode="numeric" required>
                    </div>
                    <div>
                        <label class="ui-label" for="paylink-owner">{{ ___('Owner national ID / Iqama') }}</label>
                        <input id="paylink-owner" class="ui-input ui-ltr" name="owner_id_number" inputmode="numeric" required>
                    </div>
                    <div>
                        <label class="ui-label" for="paylink-iban">{{ ___('IBAN') }}</label>
                        <input id="paylink-iban" class="ui-input ui-ltr" name="iban" autocomplete="off" required>
                    </div>
                    <div>
                        <label class="ui-label" for="paylink-email">{{ ___('Contact email') }}</label>
                        <input id="paylink-email" class="ui-input ui-ltr" type="email" name="email" autocomplete="email">
                    </div>
                </div>
                <div><x-ui.button type="submit" icon="check" data-ui-busy>{{ ___('Submit to Paylink') }}</x-ui.button></div>
            </form>
        @endif
    </x-ui.card>
</div>
@endsection
