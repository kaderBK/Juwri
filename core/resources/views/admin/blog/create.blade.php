{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Add New Blog'))
@section('content')
    @include('admin.blog._form', ['blog' => null])
@endsection
