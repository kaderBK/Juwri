{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    FEATURES-5 Track A — shared customer social sign-in buttons.

    Usage: @include('store_templates.partials.social-login-buttons', ['store' => $store])
    Renders nothing when no provider is enabled for this restaurant, so it is safe
    to drop into every theme's login AND register panel. RTL-safe (logical props).

    Optional vars:
      $socialContext  — 'login'|'register' (only affects the divider label)
--}}
@php
    $__socialPost = $store ?? null;
    $__socialProviders = $__socialPost
        ? app(\Juwri\Modules\Auth\Services\Social\SocialCredentialResolver::class)->enabledProviders($__socialPost->id)
        : [];
    $__socialLabels = ['google' => 'Google', 'facebook' => 'Facebook', 'apple' => 'Apple'];
@endphp

@once
@if(!empty($__socialProviders))
<style nonce="{{ csp_nonce() }}">
    .social-login{margin-block:14px 4px}
    .social-login__divider{display:flex;align-items:center;gap:10px;margin:12px 0;color:var(--sl-muted,#8a8a8a);font-size:12px;text-transform:uppercase;letter-spacing:.04em}
    .social-login__divider::before,.social-login__divider::after{content:"";flex:1;height:1px;background:currentColor;opacity:.28}
    .social-login__buttons{display:flex;flex-direction:column;gap:9px}
    .social-login__btn{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;
        padding:11px 14px;border-radius:12px;border:1px solid rgba(0,0,0,.14);
        background:#fff;color:#1c1c1c;font-weight:600;font-size:14px;line-height:1;
        text-decoration:none;cursor:pointer;transition:filter .15s ease,transform .05s ease,box-shadow .15s ease}
    .social-login__btn:hover{filter:brightness(.97);box-shadow:0 2px 10px rgba(0,0,0,.08)}
    .social-login__btn:active{transform:translateY(1px)}
    .social-login__icon{display:inline-flex;flex-shrink:0}
    .social-login__btn--apple{background:#000;color:#fff;border-color:#000}
    .social-login__btn--facebook{color:#1c1c1c}
    @media (prefers-color-scheme: dark){
        .social-login__btn{background:rgba(255,255,255,.06);color:#f2f2f2;border-color:rgba(255,255,255,.18)}
        .social-login__btn--apple{background:#fff;color:#000;border-color:#fff}
    }
</style>
@endif
@endonce

@if(!empty($__socialProviders))
    <div class="social-login" role="group" aria-label="{{ ___('Sign in with a social account') }}">
        <div class="social-login__divider"><span>{{ ___('or continue with') }}</span></div>
        <div class="social-login__buttons">
            @foreach($__socialProviders as $__prov)
                <a class="social-login__btn social-login__btn--{{ $__prov }}"
                   href="{{ route('customer.social.redirect', [$__socialPost->id, $__prov]) }}"
                   rel="nofollow"
                   aria-label="{{ ___('Continue with :provider', ['provider' => $__socialLabels[$__prov] ?? ucfirst($__prov)]) }}">
                    <span class="social-login__icon" aria-hidden="true">
                        @if($__prov === 'google')
                            <svg viewBox="0 0 24 24" width="18" height="18"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.27-4.74 3.27-8.1Z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23Z"/><path fill="#FBBC05" d="M5.84 14.1a6.6 6.6 0 0 1 0-4.2V7.06H2.18a11 11 0 0 0 0 9.88l3.66-2.84Z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84C6.71 7.3 9.14 5.38 12 5.38Z"/></svg>
                        @elseif($__prov === 'facebook')
                            <svg viewBox="0 0 24 24" width="18" height="18"><path fill="#1877F2" d="M24 12a12 12 0 1 0-13.88 11.85v-8.38H7.08V12h3.04V9.36c0-3 1.79-4.67 4.53-4.67 1.31 0 2.68.24 2.68.24v2.95h-1.51c-1.49 0-1.95.92-1.95 1.87V12h3.32l-.53 3.47h-2.79v8.38A12 12 0 0 0 24 12Z"/></svg>
                        @elseif($__prov === 'apple')
                            <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M17.05 12.54c-.03-2.66 2.17-3.94 2.27-4-1.24-1.81-3.16-2.06-3.84-2.09-1.63-.16-3.19.96-4.02.96-.83 0-2.11-.94-3.47-.91-1.79.03-3.44 1.04-4.36 2.64-1.86 3.23-.48 8 1.33 10.62.88 1.28 1.93 2.72 3.31 2.67 1.33-.05 1.83-.86 3.44-.86 1.6 0 2.06.86 3.47.83 1.43-.02 2.34-1.31 3.22-2.6 1.01-1.49 1.43-2.93 1.45-3-.03-.02-2.79-1.07-2.82-4.25Zm-2.64-7.8c.73-.89 1.22-2.12 1.09-3.34-1.05.04-2.32.7-3.08 1.58-.68.78-1.27 2.03-1.11 3.23 1.17.09 2.37-.6 3.1-1.47Z"/></svg>
                        @endif
                    </span>
                    <span class="social-login__label">{{ $__socialLabels[$__prov] ?? ucfirst($__prov) }}</span>
                </a>
            @endforeach
        </div>
    </div>
@endif
