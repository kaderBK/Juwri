{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout-bare')
@section('title', ___('Payment confirm'))

@section('content')
<div class="ui-pay">
    <p class="ui-overline"><a href="{{ route('home') }}">{{ ___('Home') }}</a> · {{ ___('Payment') }}</p>
    <h1 class="ui-pay__title">{{ ___('Payment details') }}</h1>
    <x-ui.card :title="'Razorpay'">
        @include('owner.billing.gateways._summary')
        <div class="ui-pay__actions">
            <form action="{{ route('ipn', 'razorpay') }}" method="POST" class="ui-pay__gateway">
                @csrf
                <script src="https://checkout.razorpay.com/v1/checkout.js"
                        @foreach ($details as $key => $value)
                            data-{{ $key }}="{{ $value }}" @endforeach>
                </script>
            </form>
            <x-ui.button :href="$cancel_url" variant="ghost" :block="true">{{ ___('Cancel Payment') }}</x-ui.button>
        </div>
    </x-ui.card>
</div>
@endsection

@push('scripts_at_bottom')
    <script nonce="{{ csp_nonce() }}">
        "use strict";
        // Razorpay injects its own submit button; give it the kit look.
        $('.razorpay-payment-button').addClass('ui-btn ui-btn--block');
    </script>
@endpush
