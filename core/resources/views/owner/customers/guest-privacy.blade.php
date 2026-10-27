{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Guest privacy request'))
@section('page_title', ___('Guest privacy request'))
@section('page_sub', ___('Export or anonymise the orders a guest placed without an account. The guest\'s phone is verified first with a one-time code.'))

@section('page_actions')
    <x-ui.button :href="route('customers.index')" variant="ghost" icon="back">{{ ___('Customers') }}</x-ui.button>
@endsection

@section('content')
@if(session('success'))<x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>@endif
@if(session('error'))<x-ui.banner tone="danger">{{ session('error') }}</x-ui.banner>@endif

@if($phone === null)
    <x-ui.card>
        <div class="ui-card__head">{{ ___('Step 1 — verify the guest\'s phone') }}</div>
        <p class="ui-muted">{{ ___('Ask the guest for the phone number used at checkout. We text a one-time code to it, and the guest reads the code to you.') }}</p>

        <form method="post" action="{{ route('customers.guestPrivacy.code') }}" class="ui-form-grid">
            @csrf
            <x-ui.field name="phone" type="tel" :label="___('Phone')" :value="old('phone')" dir="ltr" required />
            <div class="ui-cluster ui-cluster--end">
                <x-ui.button type="submit" icon="phone">{{ ___('Send code') }}</x-ui.button>
            </div>
        </form>

        <hr class="ui-divider">

        <form method="post" action="{{ route('customers.guestPrivacy.verify') }}" class="ui-form-grid">
            @csrf
            <x-ui.field name="code" :label="___('One-time code')" :placeholder="___('6-digit code')" required />
            <div class="ui-cluster ui-cluster--end">
                <x-ui.button type="submit" variant="secondary" icon="check">{{ ___('Verify') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>
@else
    <x-ui.card>
        <div class="ui-card__head ui-cluster ui-cluster--between ui-cluster--top">
            <span>{{ ___('Verified phone') }}</span>
            <x-ui.tag tone="success">{{ $phone }}</x-ui.tag>
        </div>
        <div class="ui-cluster ui-small">
            <span>{{ ___('Guest orders') }}: <strong>{{ number_format((int) $orderCount) }}</strong></span>
        </div>
    </x-ui.card>

    <x-ui.card>
        <div class="ui-card__head">{{ ___('Export data') }}</div>
        <p class="ui-muted">{{ ___('Download a JSON copy of everything linked to this phone: orders, items, amounts, tax records, and any guest fields still on file.') }}</p>
        <form method="post" action="{{ route('customers.guestPrivacy.export') }}">
            @csrf
            <x-ui.button type="submit" variant="secondary" icon="download">{{ ___('Download data (JSON)') }}</x-ui.button>
        </form>
    </x-ui.card>

    @if($blocker)
        <x-ui.banner tone="warning">{{ $blocker }}</x-ui.banner>
    @else
        <x-ui.card>
            <div class="ui-card__head">{{ ___('Anonymise guest orders') }}</div>
            <p class="ui-muted">{{ ___('Amounts, items and tax records are kept for the legal retention period; name, phone, address and notes are removed.') }}</p>
            <form method="post" action="{{ route('customers.guestPrivacy.erase') }}"
                  data-confirm="{{ ___('Anonymise every order linked to this phone? This cannot be undone.') }}" data-confirm-danger>
                @csrf
                <x-ui.field name="confirm" :label="___('Type :word to confirm.', ['word' => $confirmWord])" :placeholder="$confirmWord" required />
                <div class="ui-cluster ui-cluster--end">
                    <x-ui.button type="submit" variant="danger" icon="trash">{{ ___('Anonymise guest orders') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif
@endif
@endsection
