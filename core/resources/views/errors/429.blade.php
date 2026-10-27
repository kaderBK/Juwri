{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- 429 on the kit error page (errors/layout). --}}
@extends('errors.layout')
@section('code', '429')
@section('title', ___('Slow down a little'))
@section('message', ___('Slow down a little'))
@section('description', ___('Too many requests in a short time. Wait a moment, then try again.'))
