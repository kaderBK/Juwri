{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('auth.layout')

@section('title', ___('Confirm password'))
@section('auth_heading', ___('Confirm your password'))
@section('auth_sub', ___('This is a secure area — please confirm your password before continuing.'))

@section('content')

<form method="POST" action="{{ route('password.confirm.store') }}" class="ui-auth__form">
    @csrf
    <x-ui.field name="password" type="password" id="password" :label="___('Password')" autocomplete="current-password" autofocus />

    <x-ui.button type="submit" size="lg" block data-ui-busy>{{ ___('Confirm') }}</x-ui.button>
</form>

@include('auth.partials.passkey-confirm')

@endsection
