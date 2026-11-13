{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout-bare')
@section('title', ___('Payment confirm'))

@section('content')
<div class="ui-pay">
    <p class="ui-overline"><a href="{{ route('home') }}">{{ ___('Home') }}</a> · {{ ___('Payment') }}</p>
    <h1 class="ui-pay__title">{{ ___('Payment details') }}</h1>
    <x-ui.card :title="'Paystack'">
        @include('owner.billing.gateways._summary')
        <div class="ui-pay__actions">
            <form action="{{ route('ipn', 'paystack') }}" method="POST" class="ui-pay__gateway">
                @csrf
                <x-ui.button type="button" :block="true" id="btn-confirm">{{ ___('Pay Now') }}</x-ui.button>
                <script src="//js.paystack.co/v1/inline.js"
                        @foreach($details as $key => $value)
                            data-{{ $key }}="{{ $value }}"
                        @endforeach
                        data-custom-button="btn-confirm"
                >
                </script>
            </form>
            <x-ui.button :href="$cancel_url" variant="ghost" :block="true">{{ ___('Cancel Payment') }}</x-ui.button>
        </div>
    </x-ui.card>
</div>
@endsection
