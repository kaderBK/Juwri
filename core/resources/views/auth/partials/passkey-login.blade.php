{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P1-19: "Sign in with a passkey" next to the password form. Shown only where this host is a passkey
     relying party (PasskeyRelyingParty); the button stays hidden until the browser supports passkeys.
     The username field also carries `webauthn` autocomplete, so saved passkeys appear in its autofill.
     P1-19b: `staff => true` points it at the staff passkey sign-in (dashboard / reseller hosts only). --}}
@php
    $__pkRp = \Juwri\Modules\Auth\Support\PasskeyRelyingParty::for(request());
    $__pkStaff = ! empty($staff);
    $__pkShow = $__pkRp !== null && (! $__pkStaff || in_array($__pkRp['kind'], [\Juwri\Core\Http\Hosts\HostMap::APP, 'reseller'], true));
@endphp
@if ($__pkShow)
<div class="ui-auth__form" data-passkey-login
     data-options-url="{{ $__pkStaff ? route('staff.passkey.login.options') : route('passkey.login.options') }}"
     data-verify-url="{{ $__pkStaff ? route('staff.passkey.login.verify') : route('passkey.login.verify') }}"
     data-csrf="{{ csrf_token() }}"
     data-msg-failed="{{ ___('This passkey could not be verified. Try again or sign in with your password.') }}">
    <p class="ui-auth__or">{{ ___('or') }}</p>
    <x-ui.button type="button" variant="secondary" size="lg" block data-passkey-button hidden>{{ ___('Sign in with a passkey') }}</x-ui.button>
    <p class="ui-auth__error" data-passkey-error role="alert" hidden></p>
</div>
<script src="{{ asset('assets/owner-ui/passkeys.js') }}?v={{ is_file(public_path('assets/owner-ui/passkeys.js')) ? filemtime(public_path('assets/owner-ui/passkeys.js')) : '1' }}" nonce="{{ csp_nonce() }}" defer></script>
@endif
