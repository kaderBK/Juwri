{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Blogs'))
@section('header_buttons')
    <x-ui.button size="sm" icon="plus" :href="route('admin.blogs.create')">{{ ___('Add New') }}</x-ui.button>
@endsection
@section('content')
    <x-ui.data-table :source="route('admin.blogs.index')" :delete="route('admin.blogs.delete')" :columns="[
        ['label' => '#'], ['label' => ___('Title')], ['label' => ___('Category'), 'sort' => false],
        ['label' => ___('Comments'), 'sort' => false], ['label' => ___('Status'), 'sort' => false],
        ['label' => ___('Last Modified')], ['label' => '', 'sort' => false], ['check' => true],
    ]" />
@endsection
