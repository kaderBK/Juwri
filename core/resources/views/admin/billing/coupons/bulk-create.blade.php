{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Bulk Create Coupons'))
@section('header_buttons')
    <x-ui.button size="sm" variant="secondary" :href="route('admin.coupons.index')">{{ ___('Back to Coupons') }}</x-ui.button>
@endsection
@section('content')
    <x-ui.card :title="___('Generate Coupon Batch')">
        @if ($errors->any())
            <x-ui.banner tone="danger">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-ui.banner>
        @endif

        <form method="POST" action="{{ route('admin.coupons.bulk.confirm') }}" class="ui-stack">
            @csrf
            <x-ui.field name="name" :label="___('Batch Name')" :value="old('name')" maxlength="120" :placeholder="___('Optional label for this batch')" />
            <div class="ui-grid-2">
                <x-ui.field name="prefix" :label="___('Code Prefix')" :value="old('prefix')" maxlength="12" class="ui-mono" :placeholder="___('Optional, e.g. SPRING')" />
                <x-ui.field type="number" name="count" :label="___('Quantity').' *'" :value="old('count', 10)" min="1" max="1000" required />
            </div>
            <div class="ui-grid-2">
                <x-ui.field type="number" name="length" :label="___('Random Code Length')" :value="old('length', 8)" min="6" max="12" />
                <x-ui.field type="select" name="coupon_type" :label="___('Coupon Type')" :value="old('coupon_type', 'percentage')"
                    :options="['percentage' => ___('Percentage'), 'fixed' => ___('Fixed')]" />
            </div>
            <div class="ui-grid-2">
                <x-ui.field type="number" name="discount_percentage" :label="___('Discount').' *'" :value="old('discount_percentage', 10)" min="1" step="0.001" required />
                <x-ui.field type="number" name="uses_limit" :label="___('Uses Limit').' *'" :value="old('uses_limit', 1)" min="0" required />
            </div>
            <x-ui.field type="datetime-local" name="expiry_at" :label="___('Expiry at')" :value="old('expiry_at')" />
            @include('admin.billing.coupons._scope', ['coupon' => null])
            <div>
                <x-ui.button type="submit">{{ ___('Review Batch') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>
@endsection
