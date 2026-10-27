{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout-bare')
@section('title', ___('Payment confirm'))

@section('content')
<div class="ui-pay">
    <p class="ui-overline"><a href="{{ route('home') }}">{{ ___('Home') }}</a> · {{ ___('Payment') }}</p>
    <h1 class="ui-pay__title">{{ ___('Payment details') }}</h1>
    <x-ui.card :title="'Midtrans'">
        @include('owner.billing.gateways._summary')
        <div class="ui-pay__actions">
            <x-ui.button type="button" :block="true" data-ui-call="midtransPayNow">{{ ___('Pay Now') }}</x-ui.button>
            <x-ui.button :href="$cancel_url" variant="ghost" :block="true">{{ ___('Cancel Payment') }}</x-ui.button>
        </div>
    </x-ui.card>
</div>
@endsection

@push('scripts_at_bottom')
    <script src="{{ $url }}" data-client-key="{{ $mt_client_key }}"></script>
    <script nonce="{{ csp_nonce() }}" type="text/javascript">
        // A page-global function: data-ui-call only reaches window properties
        // (the old `let` binding was never on window, so the button did nothing).
        window.midtransPayNow = function () {
            snap.pay(@json($snapToken), {
                onSuccess: function () { window.location = @json($return_url); },
                onPending: function () { window.location = @json($return_url); },
                onError: function () { window.location = @json($cancel_url); },
                onClose: function () { window.location = @json($cancel_url); }
            });
        };
    </script>
@endpush
