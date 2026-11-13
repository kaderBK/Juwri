{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('auth.layout')

@section('title', ___('Two-factor authentication'))
@section('auth_heading', ___('Two-factor authentication'))
@section('auth_sub', ___('Enter the code from your authenticator app, or a recovery code.'))

@section('content')

<form method="POST" action="{{ route('2fa.challenge.verify') }}" class="ui-auth__form">
    @csrf
    <x-ui.field name="code" id="code" :label="___('Authentication code')" inputmode="numeric" autocomplete="one-time-code"
                autofocus placeholder="123456" />

    <x-ui.check name="remember_device" value="1" id="remember_device" :label="___('Remember this device for 30 days')" />

    <x-ui.button type="submit" size="lg" block data-ui-busy>{{ ___('Verify') }}</x-ui.button>
</form>

@endsection
