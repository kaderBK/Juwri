{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- 403 on the kit error page (errors/layout). --}}
@extends('errors.layout')
@section('code', '403')
@section('title', ___('Access forbidden'))
@section('message', ___('Access forbidden'))
@section('description', ___('You do not have permission to view this resource.'))
