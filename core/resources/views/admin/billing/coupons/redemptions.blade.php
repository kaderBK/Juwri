{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Redemptions').': '.$coupon->code)
@section('header_buttons')
    <x-ui.button size="sm" variant="secondary" :href="route('admin.coupons.index')">{{ ___('Back to Coupons') }}</x-ui.button>
@endsection
@section('content')
    <x-ui.card variant="flush">
        @include('partials.coupon-redemptions', ['redemptions' => $redemptions])
        @if ($redemptions->hasPages())
            <div class="ui-card__foot">
                {{ $redemptions->links() }}
            </div>
        @endif
    </x-ui.card>
@endsection
