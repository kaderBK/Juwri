{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout-bare')
@section('title', ___('Bank Deposit'))
@section('content')
<div class="ui-pay">
    <x-ui.card :title="___('Bank Deposit')">
        <x-ui.banner tone="success">
            {{ ___('Your bank transfer request is saved. Send the amount below to the account shown and quote the reference; we process it as soon as the money arrives.') }}
        </x-ui.banner>
        <dl class="ui-pay__summary">
            <div>
                <dt>{{ ___('Bank Account details') }}</dt>
                <dd>@clean($settings->company_bank_info ?? '', 'richtext')</dd>
            </div>
            @foreach ([
                ___('Reference') => '#'.$transaction->id,
                ___('Description') => $transaction->transaction_description,
                ___('Username') => request()->user()?->username,
            ] as $term => $detail)
                <div>
                    <dt>{{ $term }}</dt>
                    <dd>{{ $detail }}</dd>
                </div>
            @endforeach
            <div class="ui-pay__total">
                <dt>{{ ___('Amount to send') }}</dt>
                <dd class="ui-num">{{ price_code_format($transaction->amount) }}</dd>
            </div>
        </dl>
        @if (Route::has('transactions'))
            <div class="ui-actions">
                <x-ui.button :href="route('transactions')">{{ ___('Transactions') }}</x-ui.button>
            </div>
        @endif
    </x-ui.card>
</div>
@endsection
