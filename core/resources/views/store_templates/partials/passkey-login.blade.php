{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    P1-19 (customer passkeys): "Sign in with a passkey" in a storefront theme's customer login panel.

    Usage: @include('store_templates.partials.passkey-login', ['store' => $store]) right after the login form's
    social buttons. Renders nothing where this host is not a passkey relying party for this store
    (CustomerRelyingParty) or the customer is already signed in to this store; the button stays hidden until the browser
    supports passkeys. Signs in to THIS store only; the password and phone-code sign-in are unchanged.
    No autofill request on page load (data-conditional="off"): a ceremony starts only on the button.
--}}
@php
    $__cpkPost = $store ?? null;
    $__cpkSigned = auth('customer')->user();
    $__cpkRp = ($__cpkPost && ! ($__cpkSigned && (int) $__cpkSigned->store_id === (int) $__cpkPost->id))
        ? \Juwri\Modules\Customers\Services\Passkeys\CustomerRelyingParty::for(request(), $__cpkPost)
        : null;
@endphp
@if($__cpkRp)
@once
<style nonce="{{ csp_nonce() }}">
    .cpk-login{margin-block:12px 4px}
    .cpk-login [hidden]{display:none!important}
    .cpk-login .cpk-login__btn{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;
        padding:11px 14px;border-radius:12px;border:1px solid color-mix(in srgb,currentColor 24%,transparent);
        background:transparent;color:inherit;font:inherit;font-weight:600;font-size:14px;line-height:1.2;cursor:pointer}
    .cpk-login .cpk-login__btn:hover{background:color-mix(in srgb,currentColor 6%,transparent)}
    .cpk-login .cpk-login__btn svg{flex-shrink:0}
    .cpk-login__error{margin:8px 0 0;font-size:13px;color:#c0392b}
</style>
@endonce
<div class="cpk-login" data-passkey-login data-conditional="off"
     data-options-url="{{ route('customer.passkey.login.options', $__cpkPost->id) }}"
     data-verify-url="{{ route('customer.passkey.login.verify', $__cpkPost->id) }}"
     data-csrf="{{ csrf_token() }}"
     data-msg-failed="{{ ___('This passkey could not be verified. Try again or sign in with your password.') }}">
    <button type="button" class="cpk-login__btn" data-passkey-button hidden>
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="4"/><path d="M2 21v-1a6 6 0 0 1 9.5-4.9"/><circle cx="17.5" cy="13.5" r="2.5"/><path d="M17.5 16v5l1.5-1.2"/></svg>
        <span>{{ ___('Sign in with a passkey') }}</span>
    </button>
    <p class="cpk-login__error" data-passkey-error role="alert" hidden></p>
</div>
@once
<script src="{{ asset('assets/owner-ui/passkeys.js') }}?v={{ is_file(public_path('assets/owner-ui/passkeys.js')) ? filemtime(public_path('assets/owner-ui/passkeys.js')) : '1' }}" nonce="{{ csp_nonce() }}" defer></script>
@endonce
@endif
