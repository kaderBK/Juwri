{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('errors.layout')
@section('title', ___('Session expired'))
@section('code', '419')
@section('message', ___('Session expired'))
@section('description', ___('For your security, your session has timed out. Reload the page and try again.'))
