{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Redemptions'))
@section('page_title', ___('Redemptions'))
@section('page_sub', $coupon->code)

@section('page_actions')
    <x-ui.button :href="route('stores.coupons.edit', $coupon)" variant="secondary" icon="back">{{ ___('Back') }}</x-ui.button>
@endsection

@section('content')
<div class="ui-scope">
    <section class="ui-card ui-card--flush">
        @include('partials.coupon-redemptions', ['redemptions' => $redemptions])
        @if ($redemptions->hasPages())
            <div class="ui-card__foot">{{ $redemptions->links() }}</div>
        @endif
    </section>
</div>
@endsection
