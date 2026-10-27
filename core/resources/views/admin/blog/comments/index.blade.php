{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Blog Comments'))
@section('content')
    <x-ui.data-table :source="route('admin.blog.comments.index').'?blog_id='.urlencode((string) $blog_id)" :delete="route('admin.blog.comments.delete')" :columns="[
        ['label' => ___('User')], ['label' => ___('Blog')], ['label' => ___('Comment')], ['label' => ___('Status')],
        ['label' => ___('Submitted on')], ['label' => '', 'sort' => false], ['check' => true],
    ]" />
@endsection
