{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('auth.layout')

@section('title', ___('Create account') . ' - ' . brand_title())

@section('auth_heading', ___('Start your free trial'))
@section('auth_sub', ___('No credit card required. Setup takes minutes.'))

@section('content')

@include('auth.partials.social')

<form method="POST" action="{{ route('register') }}" class="ui-auth__form">
    @csrf

    @include('auth.partials.field', [
        'name' => 'username',
        'label' => ___('Username'),
        'placeholder' => 'restaurant_owner',
        'value' => old('username'),
        'error' => $errors->first('username'),
        'required' => true,
        'autocomplete' => 'username',
    ])

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

    @include('auth.partials.field', [
        'name' => 'password',
        'type' => 'password',
        'label' => ___('Password'),
        'placeholder' => ___('At least 6 characters'),
        'error' => $errors->first('password'),
        'required' => true,
        'autocomplete' => 'new-password',
    ])

    @include('auth.partials.field', [
        'name' => 'password_confirmation',
        'type' => 'password',
        'label' => ___('Confirm password'),
        'required' => true,
        'autocomplete' => 'new-password',
    ])

    @if (function_exists('display_captcha'))
        <div>{!! display_captcha() !!}</div>
        @error('g-recaptcha-response')<p class="ui-auth__error">{{ $message }}</p>@enderror
    @endif

    @if (\Juwri\Core\Legal\LegalDocuments::publishedHere())
        {{-- P1-23: Terms / DPA / AUP acceptance (each version recorded, Terms §14.13), business-not-consumer
             confirmation (both required) and the product-news opt-in (never pre-ticked). --}}
        <div class="ui-auth__terms" data-signup-legal>
            <label class="ui-check">
                <input type="checkbox" name="agree_for_term" value="1" id="agree_for_term" required @checked(old('agree_for_term'))
                       @error('agree_for_term') aria-invalid="true" aria-describedby="agree_for_term-error" @enderror>
                <span>{{ \Juwri\Core\Legal\LegalText::inline('site.signup.accept') }}</span>
            </label>
            @error('agree_for_term')<p class="ui-auth__error" id="agree_for_term-error">{{ $message }}</p>@enderror

            <label class="ui-check">
                <input type="checkbox" name="business_confirm" value="1" id="business_confirm" required @checked(old('business_confirm'))
                       @error('business_confirm') aria-invalid="true" aria-describedby="business_confirm-error" @enderror>
                <span>{{ __('site.signup.business') }}</span>
            </label>
            @error('business_confirm')<p class="ui-auth__error" id="business_confirm-error">{{ $message }}</p>@enderror

            <label class="ui-check">
                <input type="checkbox" name="marketing_optin" value="1" id="marketing_optin" @checked(old('marketing_optin'))>
                <span>{{ __('site.signup.marketing_optin') }}</span>
            </label>

            <p class="ui-auth__privacy">{{ \Juwri\Core\Legal\LegalText::inline('site.signup.privacy') }}</p>
        </div>
    @else
        @php
            $allPages = (isset($pages) && $pages) ? $pages : collect();
            $termsPage = $allPages->first(fn ($p) => \Illuminate\Support\Str::contains($p->slug, 'term'));
            $privacyPage = $allPages->first(fn ($p) => \Illuminate\Support\Str::contains($p->slug, 'privacy'));
        @endphp
        <div class="ui-auth__terms">
            <label class="ui-check">
                <input type="checkbox" name="agree_for_term" value="1" id="agree_for_term" @checked(old('agree_for_term'))
                       @error('agree_for_term') aria-invalid="true" aria-describedby="agree_for_term-error" @enderror>
                <span>
                    {{ ___('By signing up you agree to our') }}
                    @if ($termsPage)<a href="{{ route('page', $termsPage->slug) }}">{{ ___('Terms') }}</a>@else {{ ___('Terms') }} @endif
                    {{ ___('and') }}
                    @if ($privacyPage)<a href="{{ route('page', $privacyPage->slug) }}">{{ ___('Privacy Policy') }}</a>@else {{ ___('Privacy Policy') }} @endif.
                </span>
            </label>
            @error('agree_for_term')<p class="ui-auth__error" id="agree_for_term-error">{{ $message }}</p>@enderror
            <label class="ui-check">
                <input type="checkbox" name="marketing_optin" value="1" id="marketing_optin" @checked(old('marketing_optin'))>
                <span>{{ __('site.signup.marketing_optin') }}</span>
            </label>
        </div>
    @endif

    <x-ui.button type="submit" size="lg" block data-ui-busy>{{ ___('Create account') }}</x-ui.button>
</form>

@endsection

@section('auth_footer')
    @if (Route::has('login'))
        {{ ___('Already have an account?') }} <a href="{{ route('login') }}">{{ ___('Log in') }}</a>
    @endif
@endsection
