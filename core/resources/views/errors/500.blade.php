{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- 500 on the kit error page (errors/layout). --}}
{{-- P6-114 TODO (after P6-112 lands): when App\Support\StatusPage::url() is set, add a "System status" link here (__('site.footer.status')). Not wired yet: P6-112 reworks these pages. --}}
@extends('errors.layout')
@section('code', '500')
@section('title', ___('Something went wrong'))
@section('message', ___('Something went wrong'))
@section('description', ___('We hit an unexpected error. Our team has been notified.'))
