{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Blog Categories'))
@section('header_buttons')
    <x-ui.button size="sm" icon="plus" :data-url="route('admin.blog.category.create')" data-toggle="slidePanel">{{ ___('Add New') }}</x-ui.button>
@endsection
@section('content')
    <x-ui.data-table :source="route('admin.blog.category.index')" :reorder="route('admin.blog.category.reorder')" :delete="route('admin.blog.category.delete')"
        order-dir="asc" :columns="[
            ['label' => '', 'sort' => false], ['label' => ___('Title')], ['label' => ___('Active')], ['label' => ___('Created At')],
            ['label' => '', 'sort' => false], ['check' => true],
        ]" />
@endsection
