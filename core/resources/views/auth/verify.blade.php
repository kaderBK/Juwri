{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('auth.layout')

@section('title', ___('Verify your email') . ' - ' . brand_title())

@section('auth_heading', ___('Check your inbox'))
@section('auth_sub', ___('We sent a verification link to your email. Click it to activate your account.'))

@section('content')

<div class="ui-auth__illus" aria-hidden="true"><x-ui.icon name="check" size="56" /></div>

@if (session('resent') || session('status') === 'verification-link-sent')
    <x-ui.banner tone="success" class="ui-auth__flash">{{ ___('A fresh verification link has been sent to your email.') }}</x-ui.banner>
@endif

<p class="ui-auth__hint">{{ ___('Did not get the email?') }}</p>

@if (Route::has('verification.resend'))
    <form method="POST" action="{{ route('verification.resend') }}">
        @csrf
        <x-ui.button type="submit" variant="secondary" size="lg" block data-ui-busy>{{ ___('Resend verification link') }}</x-ui.button>
    </form>
@endif

@endsection

@section('auth_footer')
    @if (Route::has('logout'))
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="ui-auth__linkbtn">{{ ___('Log out') }}</button>
        </form>
    @endif
@endsection
