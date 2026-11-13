{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P1-19: confirm with a passkey instead of typing the password (accounts with a passkey for this host). --}}
@php($__pkUser = auth()->user())
@if ($__pkUser instanceof \Juwri\Core\Models\User && ($__pkRp = \Juwri\Modules\Auth\Support\PasskeyRelyingParty::for(request())) !== null
     && $__pkUser->passkeys()->where('rp_id', $__pkRp['id'])->exists())
<div class="ui-auth__form" data-passkey-confirm
     data-options-url="{{ route('passkeys.confirm.options') }}"
     data-verify-url="{{ route('passkeys.confirm') }}"
     data-csrf="{{ csrf_token() }}"
     data-msg-failed="{{ ___('This passkey could not be verified. Try again or sign in with your password.') }}">
    <p class="ui-auth__or">{{ ___('or') }}</p>
    <x-ui.button type="button" variant="secondary" size="lg" block data-passkey-button hidden>{{ ___('Confirm with a passkey') }}</x-ui.button>
    <p class="ui-auth__error" data-passkey-error role="alert" hidden></p>
</div>
<script src="{{ asset('assets/owner-ui/passkeys.js') }}?v={{ is_file(public_path('assets/owner-ui/passkeys.js')) ? filemtime(public_path('assets/owner-ui/passkeys.js')) : '1' }}" nonce="{{ csp_nonce() }}" defer></script>
@endif
