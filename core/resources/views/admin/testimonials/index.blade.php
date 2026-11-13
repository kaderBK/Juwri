{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Testimonials'))
@section('header_buttons')
    <x-ui.button size="sm" icon="plus" :data-url="route('admin.testimonials.create')" data-toggle="slidePanel">{{ ___('Add New') }}</x-ui.button>
@endsection
@section('content')
    <x-ui.data-table :source="route('admin.testimonials.index')" :delete="route('admin.testimonials.delete')" :columns="[
        ['label' => ___('Name')], ['label' => ___('Content')], ['label' => '', 'sort' => false], ['check' => true],
    ]" />
@endsection
