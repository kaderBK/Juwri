{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Confirm Coupon Batch'))
@section('header_buttons')
    <x-ui.button size="sm" variant="secondary" :href="route('admin.coupons.bulk.create')">{{ ___('Back') }}</x-ui.button>
@endsection
@section('content')
    <x-ui.card :title="___('Review Batch Before Generating')">
        <div class="ui-stack">
            <x-ui.row :title="___('Batch Name')" :sub="$data['name'] ?? '—'" />
            <x-ui.row :title="___('Quantity')" :sub="(string) $data['count']" />
            <x-ui.row :title="___('Sample Code Format')" :sub="$sampleCode" />
            <x-ui.row :title="___('Coupon Type')" :sub="$data['settings']['coupon_type'] === 'fixed' ? ___('Fixed') : ___('Percentage')" />
            <x-ui.row :title="___('Discount')" :sub="$data['settings']['coupon_type'] === 'fixed' ? $data['settings']['percentage'].' '.platform_currency() : $data['settings']['percentage'].'%'" />
            <x-ui.row :title="___('Uses Limit')" :sub="$data['settings']['limit'] > 0 ? (string) $data['settings']['limit'] : ___('Unlimited')" />
            <x-ui.row :title="___('Expiry at')" :sub="! empty($data['settings']['expiry_at']) ? $data['settings']['expiry_at'] : ___('Never')" />
            <x-ui.row :title="___('Applies to billing interval')" :sub="ucfirst($data['settings']['applies_interval'])" />
            <x-ui.row :title="___('First period only')" :sub="$data['settings']['first_period_only'] ? ___('Yes') : ___('No')" />

            <form method="POST" action="{{ route('admin.coupons.bulk.store') }}">
                @csrf
                <input type="hidden" name="name" value="{{ $data['name'] ?? '' }}">
                <input type="hidden" name="prefix" value="{{ $data['prefix'] ?? '' }}">
                <input type="hidden" name="count" value="{{ $data['count'] }}">
                <input type="hidden" name="length" value="{{ $data['length'] }}">
                <input type="hidden" name="coupon_type" value="{{ $data['settings']['coupon_type'] }}">
                <input type="hidden" name="discount_percentage" value="{{ $data['settings']['percentage'] }}">
                <input type="hidden" name="uses_limit" value="{{ $data['settings']['limit'] }}">
                @if (! empty($data['settings']['expiry_at']))
                    <input type="hidden" name="expiry_at" value="{{ $data['settings']['expiry_at'] }}">
                @endif
                <input type="hidden" name="applies_interval" value="{{ $data['settings']['applies_interval'] }}">
                @if (! empty($data['settings']['first_period_only']))
                    <input type="hidden" name="first_period_only" value="1">
                @endif
                <div class="ui-cluster">
                    <x-ui.button type="submit">{{ ___('Generate Coupons') }}</x-ui.button>
                    <x-ui.button variant="secondary" :href="route('admin.coupons.bulk.create')">{{ ___('Cancel') }}</x-ui.button>
                </div>
            </form>
        </div>
    </x-ui.card>
@endsection
