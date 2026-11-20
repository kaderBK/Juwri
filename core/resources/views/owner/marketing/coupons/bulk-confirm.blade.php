{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Confirm Coupon Batch'))
@section('page_title', ___('Confirm Coupon Batch'))
@section('page_sub', ___('Review Batch Before Generating'))

@section('page_actions')
    <x-ui.button :href="route('stores.coupons.bulk.create')" variant="secondary" icon="back">{{ ___('Back') }}</x-ui.button>
@endsection

@section('content')
<div class="ui-scope ui-coupons">
    <x-ui.card :title="___('Review Batch Before Generating')">
        <div class="ui-stack">
            <x-ui.row :title="___('Batch Name')" :sub="$data['name'] ?? '—'" />
            <x-ui.row :title="___('Quantity')" :sub="(string) $data['count']" />
            <x-ui.row :title="___('Sample Code Format')" :sub="$sampleCode" />
            <x-ui.row :title="___('Coupon Type')" :sub="$data['settings']['coupon_type'] === 'fixed' ? ___('Fixed') : ___('Percentage')" />
            <x-ui.row :title="___('Discount')" :sub="$data['settings']['coupon_type'] === 'fixed' ? $data['settings']['percentage'].' '.$currency : $data['settings']['percentage'].'%'" />
            <x-ui.row :title="___('Uses Limit')" :sub="$data['settings']['limit'] > 0 ? (string) $data['settings']['limit'] : ___('Unlimited')" />
            <x-ui.row :title="___('Max per customer')" :sub="! empty($data['settings']['per_customer_limit']) ? (string) $data['settings']['per_customer_limit'] : ___('Unlimited')" />
            <x-ui.row :title="___('Expiry date')" :sub="$data['expiry_date'] ?? ___('Never')" />

            <form method="POST" action="{{ route('stores.coupons.bulk.store') }}">
                @csrf
                <input type="hidden" name="name" value="{{ $data['name'] ?? '' }}">
                <input type="hidden" name="prefix" value="{{ $data['prefix'] ?? '' }}">
                <input type="hidden" name="count" value="{{ $data['count'] }}">
                <input type="hidden" name="length" value="{{ $data['length'] }}">
                <input type="hidden" name="coupon_type" value="{{ $data['settings']['coupon_type'] }}">
                <input type="hidden" name="percentage" value="{{ $data['settings']['percentage'] }}">
                <input type="hidden" name="limit" value="{{ $data['settings']['limit'] }}">
                @if (! empty($data['settings']['per_customer_limit']))
                    <input type="hidden" name="per_customer_limit" value="{{ $data['settings']['per_customer_limit'] }}">
                @endif
                @if (! empty($data['expiry_date']))
                    <input type="hidden" name="expiry_at" value="{{ $data['expiry_date'] }}">
                @endif
                <div class="ui-cluster">
                    <x-ui.button type="submit" icon="check" data-ui-busy>{{ ___('Generate Coupons') }}</x-ui.button>
                    <x-ui.button variant="secondary" :href="route('stores.coupons.bulk.create')">{{ ___('Cancel') }}</x-ui.button>
                </div>
            </form>
        </div>
    </x-ui.card>
</div>
@endsection
