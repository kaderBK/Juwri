{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('auth.layout')

@section('title', ___('Reset password') . ' - ' . brand_title())

@section('auth_heading', ___('Set a new password'))
@section('auth_sub', ___('Choose something you will remember.'))

@section('content')

<form method="POST" action="{{ route('password.update') }}" class="ui-auth__form">
    @csrf
    <input type="hidden" name="token" value="{{ $token ?? request()->route('token') }}">

    @include('auth.partials.field', [
        'name' => 'email',
        'type' => 'email',
        'label' => ___('Email'),
        'value' => $email ?? old('email'),
        'error' => $errors->first('email'),
        'required' => true,
        'autocomplete' => 'email',
    ])

    @include('auth.partials.field', [
        'name' => 'password',
        'type' => 'password',
        'label' => ___('New password'),
        'placeholder' => ___('At least 6 characters'),
        'error' => $errors->first('password'),
        'required' => true,
        'autocomplete' => 'new-password',
    ])

    @include('auth.partials.field', [
        'name' => 'password_confirmation',
        'type' => 'password',
        'label' => ___('Confirm new password'),
        'required' => true,
        'autocomplete' => 'new-password',
    ])

    <x-ui.button type="submit" size="lg" block data-ui-busy>{{ ___('Reset password') }}</x-ui.button>
</form>

@endsection

@section('auth_footer')
    @if (Route::has('login'))
        <a href="{{ route('login') }}">&larr; {{ ___('Back to login') }}</a>
    @endif
@endsection
