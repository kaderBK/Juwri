{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P1-19b: a staff member's own passkeys (staff guard). A passkey is personal: on a shared POS device each
     staff member adds their own, and the device's account picker shows their name and store. --}}
@extends('auth.layout')

@section('title', ___('My passkeys') . ' - ' . brand_title())
@section('auth_heading', ___('My passkeys'))
@section('auth_sub', ___('Signed in as :name.', ['name' => $staff->name]))

@section('content')

<div class="ui-auth__form">
    <p class="ui-muted">{{ ___('Sign in with your fingerprint, face or device PIN instead of a password. A passkey also counts as two-step sign-in.') }}</p>
    <p class="ui-muted ui-small">{{ ___('On a shared device, use your own phone or security key: anyone who can unlock a shared device can use the passkeys saved on it.') }}</p>

    @if ($passkeys->isEmpty())
        <p>{{ ___('No passkeys yet.') }}</p>
    @else
        <ul class="ui-list" data-passkey-list>
            @foreach ($passkeys as $pk)
                <li class="ui-list__item ui-cluster">
                    <span>
                        <strong>{{ $pk->name }}</strong>
                        <span class="ui-muted ui-small">· {{ $pk->last_used_at ? ___('Last used :date', ['date' => $pk->last_used_at->diffForHumans()]) : ___('Never used') }}</span>
                    </span>
                    <form method="POST" action="{{ route('staff.passkeys.destroy', $pk->id) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="ui-btn ui-btn--danger ui-btn--sm" data-confirm="{{ ___('Remove this passkey? You will no longer be able to sign in with it.') }}">{{ ___('Remove') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($relyingParty !== null)
        <div data-passkey-manage
             data-options-url="{{ route('staff.passkeys.options') }}"
             data-store-url="{{ route('staff.passkeys.store') }}"
             data-csrf="{{ csrf_token() }}"
             data-msg-failed="{{ ___('This passkey could not be added. Please try again.') }}"
             data-msg-unsupported="{{ ___('This browser does not support passkeys.') }}"
             data-msg-name="{{ ___('Give the passkey a name.') }}">
            <x-ui.field name="passkey_name" id="passkey-new-name" :label="___('Passkey name')" :placeholder="___('e.g. My phone')" maxlength="60" data-passkey-name />
            @unless ($confirmed)
                <x-ui.field name="passkey_password" id="passkey-password" type="password" :label="___('Your password')" autocomplete="current-password" data-passkey-password />
            @endunless
            <x-ui.button type="button" size="lg" block data-passkey-add>{{ ___('Add a passkey') }}</x-ui.button>
            <p class="ui-auth__error" data-passkey-error role="alert" hidden></p>
        </div>
        <script src="{{ asset('assets/owner-ui/passkeys.js') }}?v={{ is_file(public_path('assets/owner-ui/passkeys.js')) ? filemtime(public_path('assets/owner-ui/passkeys.js')) : '1' }}" nonce="{{ csp_nonce() }}" defer></script>
    @endif
</div>

@endsection

@section('auth_footer')
    <a href="{{ route('dashboard') }}">{{ ___('Back to dashboard') }}</a>
@endsection
