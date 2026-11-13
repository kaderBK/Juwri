{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('auth.layout')

@section('title', ___('Log in') . ' - ' . brand_title())
{{-- Reuses the translated sub-heading so the search snippet is in the page's language. --}}
@section('meta_description', ___('Log in to manage your restaurants.') . ' — ' . brand_title())

@section('auth_heading', ___('Welcome back'))
@section('auth_sub', ___('Log in to manage your restaurants.'))

@section('content')

@include('auth.partials.social')

<form method="POST" action="{{ route('login') }}" class="ui-auth__form">
    @csrf

    @include('auth.partials.field', [
        'name' => 'username',
        'type' => 'text',
        'label' => ___('Username or email'),
        'placeholder' => 'you@store.com',
        'value' => old('username'),
        'error' => $errors->first('username'),
        'required' => true,
        'autocomplete' => 'username webauthn',
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

    <div class="ui-auth__row">
        <x-ui.check name="remember" value="on" id="remember" :label="___('Remember me')" :checked="(bool) old('remember')" />
        @if (Route::has('password.request'))
            <a href="{{ route('password.request') }}" class="ui-auth__link">{{ ___('Forgot password?') }}</a>
        @endif
    </div>

    @if (function_exists('display_captcha'))
        <div>{!! display_captcha() !!}</div>
        @error('g-recaptcha-response')<p class="ui-auth__error">{{ $message }}</p>@enderror
    @endif

    <x-ui.button type="submit" size="lg" block data-ui-busy>{{ ___('Sign in') }}</x-ui.button>
</form>

@include('auth.partials.passkey-login')

@endsection

@section('auth_footer')
    @if (Route::has('register'))
        {{ ___('New here?') }} <a href="{{ route('register') }}">{{ ___('Create an account') }}</a>
    @endif
@endsection
