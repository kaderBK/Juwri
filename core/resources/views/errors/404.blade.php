{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- 404 on the kit error page (errors/layout). --}}
@extends('errors.layout')
@section('code', '404')
@section('title', ___('Page not found'))
@section('message', ___('Page not found'))
@section('description', ___('The page you are looking for might have been moved or no longer exists.'))
