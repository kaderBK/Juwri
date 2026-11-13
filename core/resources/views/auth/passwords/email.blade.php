{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('auth.layout')

@section('title', ___('Forgot password') . ' - ' . brand_title())

@section('auth_heading', ___('Forgot your password?'))
@section('auth_sub', ___('Enter your email and we will send you a reset link.'))

@section('content')

@if (session('status'))
    <x-ui.banner tone="success" class="ui-auth__flash">{{ session('status') }}</x-ui.banner>
@endif

<form method="POST" action="{{ route('password.email') }}" class="ui-auth__form">
    @csrf

    @include('auth.partials.field', [
        'name' => 'email',
        'type' => 'email',
        'label' => ___('Email'),
        'placeholder' => 'name@store.com',
        'value' => old('email'),
        'error' => $errors->first('email'),
        'required' => true,
        'autocomplete' => 'email',
    ])

    @if (function_exists('display_captcha'))
        <div>{!! display_captcha() !!}</div>
        @error('g-recaptcha-response')<p class="ui-auth__error">{{ $message }}</p>@enderror
    @endif

    <x-ui.button type="submit" size="lg" block data-ui-busy>{{ ___('Send reset link') }}</x-ui.button>
</form>

@endsection

@section('auth_footer')
    @if (Route::has('login'))
        <a href="{{ route('login') }}">&larr; {{ ___('Back to login') }}</a>
    @endif
@endsection
