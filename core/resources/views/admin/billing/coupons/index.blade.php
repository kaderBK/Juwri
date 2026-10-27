{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Coupons'))
@section('header_buttons')
    <x-ui.button size="sm" icon="plus" :data-url="route('admin.coupons.create')" data-toggle="slidePanel">{{ ___('Add New') }}</x-ui.button>
@endsection
@section('content')
    <x-ui.data-table :source="route('admin.coupons.index')" :delete="route('admin.coupons.delete')" :columns="[
        ['label' => '#'], ['label' => ___('Coupon')], ['label' => ___('Percentage')], ['label' => ___('Used')],
        ['label' => ___('Limit')], ['label' => ___('expiry date')], ['label' => ___('Coupon Type')],
        ['label' => '', 'sort' => false], ['check' => true],
    ]" />
@endsection
