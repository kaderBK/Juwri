{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('auth.layout')

@section('title', ___('Staff login') . ' - ' . brand_title())

@section('auth_heading', ___('Staff portal'))
@section('auth_sub', ___('Log in to access your :noun.', ['noun' => store_noun(null, 'poss')]))

@section('content')

@if ($errors->has('auth'))
    <x-ui.banner tone="danger" class="ui-auth__flash">{{ $errors->first('auth') }}</x-ui.banner>
@endif

<form method="POST" action="{{ route('staff.login.attempt') }}" class="ui-auth__form">
    @csrf

    @include('auth.partials.field', [
        'name' => 'email',
        'type' => 'email',
        'label' => ___('Email'),
        'placeholder' => 'staff@store.com',
        'value' => old('email'),
        'error' => $errors->first('email'),
        'required' => true,
        'autocomplete' => 'email webauthn',
    ])

    @include('auth.partials.field', [
        'name' => 'password',
        'type' => 'password',
        'label' => ___('Password'),
        'placeholder' => '••••••••',
        'error' => $errors->first('password'),
        'required' => true,
        'autocomplete' => 'current-password',
    ])

    @if($errors->has('code'))
        @include('auth.partials.field', [
            'name' => 'code',
            'type' => 'text',
            'label' => ___('Two-factor code'),
            'placeholder' => '123456',
            'error' => $errors->first('code'),
            'required' => true,
            'autocomplete' => 'one-time-code',
        ])
    @endif

    <x-ui.check name="remember" value="on" id="remember" :label="___('Remember me')" :checked="(bool) old('remember')" />

    <x-ui.button type="submit" size="lg" block data-ui-busy>{{ ___('Sign in') }}</x-ui.button>
</form>

@include('auth.partials.passkey-login', ['staff' => true])

@endsection

@section('auth_footer')
    @if (Route::has('login'))
        {{ ___('Are you the owner?') }} <a href="{{ route('login') }}">{{ ___('Owner login') }}</a>
    @endif
@endsection
