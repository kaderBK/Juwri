{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- 503 on the kit error page (errors/layout). --}}
{{-- P6-114 TODO (after P6-112 lands): when App\Support\StatusPage::url() is set, add a "System status" link here (__('site.footer.status')). Not wired yet: P6-112 reworks these pages. --}}
@extends('errors.layout')
@section('code', '503')
@section('title', ___('Down for maintenance'))
@section('message', ___('Down for maintenance'))
@section('description', ___('We are making the app better. Back online shortly.'))
