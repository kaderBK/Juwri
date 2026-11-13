{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $googleOn = ($settings->google_login ?? null) == '1' && \Illuminate\Support\Facades\Route::has('social.login');
    $facebookOn = ($settings->facebook_login ?? null) == '1' && \Illuminate\Support\Facades\Route::has('social.login');
@endphp
@if ($googleOn || $facebookOn)
    <div class="ui-auth__social">
        @if ($googleOn)
            <a href="{{ route('social.login', 'google') }}" class="ui-btn ui-btn--secondary ui-btn--block">
                {{-- Provider marks keep their own colours (brand guidelines). --}}
                <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M20.64 12.2c0-.64-.06-1.25-.16-1.84H12v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92a8.78 8.78 0 0 0 2.68-6.62z"/><path fill="#34A853" d="M12 21c2.43 0 4.47-.81 5.96-2.18l-2.92-2.26c-.8.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.71H3.96v2.33A9 9 0 0 0 12 21z"/><path fill="#FBBC05" d="M6.96 13.71a5.4 5.4 0 0 1 0-3.42V7.96H3.96a9 9 0 0 0 0 8.08l3-2.33z"/><path fill="#EA4335" d="M12 6.58c1.32 0 2.5.45 3.44 1.35l2.58-2.59A9 9 0 0 0 3.96 7.96l3 2.33C7.68 8.16 9.66 6.58 12 6.58z"/></svg>
                {{ ___('Continue with Google') }}
            </a>
        @endif
        @if ($facebookOn)
            <a href="{{ route('social.login', 'facebook') }}" class="ui-btn ui-btn--secondary ui-btn--block">
                <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path fill="#1877F2" d="M24 12a12 12 0 1 0-13.88 11.85v-8.38H7.08V12h3.04V9.36c0-3 1.79-4.67 4.53-4.67 1.31 0 2.68.24 2.68.24v2.95h-1.51c-1.49 0-1.95.93-1.95 1.87V12h3.32l-.53 3.47h-2.79v8.38A12 12 0 0 0 24 12z"/></svg>
                {{ ___('Continue with Facebook') }}
            </a>
        @endif
    </div>
    <p class="ui-auth__or">{{ ___('or') }}</p>
@endif
