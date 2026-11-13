{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Allergies'))
@section('header_buttons')
    <x-ui.button size="sm" icon="plus" :data-url="route('admin.allergies.create')" data-toggle="slidePanel">{{ ___('Add New') }}</x-ui.button>
@endsection
@section('content')
    <x-ui.data-table :source="route('admin.allergies.index')" :reorder="route('admin.allergies.reorder')" :delete="route('admin.allergies.delete')"
        order-dir="asc" :columns="[
            ['label' => '', 'sort' => false], ['label' => ___('Title')], ['label' => ___('Status')],
            ['label' => '', 'sort' => false], ['check' => true],
        ]" />
@endsection
