{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Advertisements'))
@section('header_buttons')
    <x-ui.button size="sm" icon="pencil" :data-url="route('admin.advertisements.edit', $headAd->id)" data-toggle="slidePanel">{{ ___('Head Code') }}</x-ui.button>
@endsection
@section('content')
    <x-ui.data-table :source="route('admin.advertisements.index')" order-dir="asc" :columns="[
        ['label' => ___('Key'), 'sort' => false], ['label' => ___('Provider Name'), 'sort' => false],
        ['label' => ___('Status'), 'sort' => false], ['label' => '', 'sort' => false],
    ]" />
@endsection
