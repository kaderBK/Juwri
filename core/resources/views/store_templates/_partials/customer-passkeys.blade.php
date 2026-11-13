{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    P1-19 (customer passkeys): the signed-in customer's passkeys for THIS store, on the account page.

    Included by _partials/account-extras (every theme's profile page) and by aurora's own profile page.
    Expects $store and $customer. Optional: $cpkCard (card class, default "acctx-card"), $cpkTitle (heading class,
    default "acctx-h"), $cpkBtn (button class, default "acctx-btn"), $cpkField (field wrapper class, default
    "acctx-field").
    Lists the customer's passkeys with a remove button; adding is offered where this host is a passkey relying
    party for the store. Customers with a password re-enter it to add a passkey (sent with the options request).
    Customers without one need a fresh proof (CustomerSignIn): a sign-in less than 10 minutes old, or a code sent
    to their phone — the code field shows when the sign-in is older (or when the server answers 423 later).
--}}
@php
    $__cpkCard = $cpkCard ?? 'acctx-card';
    $__cpkTitle = $cpkTitle ?? 'acctx-h';
    $__cpkBtn = $cpkBtn ?? 'acctx-btn';
    $__cpkField = $cpkField ?? 'acctx-field';
    $__cpkCustomer = $customer ?? null;
    $__cpkOk = isset($store) && $__cpkCustomer instanceof \App\Models\Customer && (int) $__cpkCustomer->store_id === (int) $store->id;
    $__cpkRp = $__cpkOk ? \Juwri\Modules\Customers\Services\Passkeys\CustomerRelyingParty::for(request(), $store) : null;
    $__cpkList = $__cpkOk ? \Juwri\Modules\Customers\Services\Passkeys\CustomerPasskeys::of($__cpkCustomer)->orderBy('created_at')->get() : collect();
@endphp
@if($__cpkOk && ($__cpkRp !== null || $__cpkList->isNotEmpty()))
@once
<style nonce="{{ csp_nonce() }}">
    .cpk [hidden]{display:none!important}
    .cpk-list{list-style:none;margin:0 0 12px;padding:0}
    .cpk-item{display:flex;gap:12px;align-items:center;justify-content:space-between;padding:10px 0;
        border-bottom:1px solid color-mix(in srgb,currentColor 12%,transparent)}
    .cpk-item__meta{font-size:12.5px;opacity:.7}
    .cpk-item form{margin:0}
    .cpk-note{margin:0 0 12px;font-size:13.5px;opacity:.8}
    .cpk-msg{margin:8px 0 0;font-size:13px}
    .cpk-err{margin:8px 0 0;font-size:13px;color:#c0392b}
    .cpk .cpk-remove{font:inherit;font-size:13px;font-weight:700;background:transparent;color:inherit;cursor:pointer;
        border:1px solid color-mix(in srgb,currentColor 30%,transparent);border-radius:999px;padding:6px 14px}
</style>
@endonce
<div class="{{ $__cpkCard }} cpk" id="passkeys">
    <h3 class="{{ $__cpkTitle }}">{{ ___('Passkeys') }}</h3>
    <p class="cpk-note">{{ ___('Sign in to this store with your fingerprint, face or device PIN instead of a password or a code.') }}</p>
    @if(session('passkey_status'))
        <p class="cpk-msg" role="status">{{ session('passkey_status') }}</p>
    @endif

    @if($__cpkList->isEmpty())
        <p class="cpk-note">{{ ___('No passkeys yet.') }}</p>
    @else
        <ul class="cpk-list">
            @foreach($__cpkList as $__pk)
                <li class="cpk-item">
                    <div>
                        <b>{{ $__pk->name }}</b>@if($__pk->authenticator)<span class="cpk-item__meta"> · {{ $__pk->authenticator }}</span>@endif
                        <div class="cpk-item__meta">
                            {{ ___('Added on :date', ['date' => $__pk->created_at?->translatedFormat('j M Y')]) }}
                            · {{ $__pk->last_used_at ? ___('Last used :date', ['date' => $__pk->last_used_at->diffForHumans()]) : ___('Never used') }}
                            @if($__cpkRp === null || $__pk->rp_id !== $__cpkRp['id'])
                                · {{ ___('Works on :host', ['host' => $__pk->rp_id]) }}
                            @endif
                        </div>
                    </div>
                    <form method="POST" action="{{ route('customer.passkeys.destroy', [$store->id, $__pk->id]) }}"
                          data-passkey-remove="{{ ___('Remove this passkey? You will no longer be able to sign in with it.') }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="cpk-remove">{{ ___('Remove') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif

    @if($__cpkRp !== null)
        <div data-passkey-manage
             data-options-url="{{ route('customer.passkeys.options', $store->id) }}"
             data-store-url="{{ route('customer.passkeys.store', $store->id) }}"
             data-csrf="{{ csrf_token() }}"
             data-msg-failed="{{ ___('This passkey could not be added. Please try again.') }}"
             data-msg-unsupported="{{ ___('This browser does not support passkeys.') }}"
             data-msg-name="{{ ___('Give the passkey a name.') }}">
            <label class="{{ $__cpkField }}">
                <span>{{ ___('Passkey name') }}</span>
                <input type="text" maxlength="60" data-passkey-name placeholder="{{ ___('e.g. My phone') }}">
            </label>
            @if(! empty($__cpkCustomer->password))
                <label class="{{ $__cpkField }}">
                    <span>{{ ___('Current password') }}</span>
                    <input type="password" autocomplete="current-password" data-passkey-password>
                </label>
            @elseif(! empty($__cpkCustomer->phone))
                @php($__cpkFresh = \Juwri\Modules\Customers\Support\CustomerSignIn::isFresh(request(), $__cpkCustomer))
                <div data-passkey-reauth @if($__cpkFresh) hidden @endif>
                    <p class="cpk-note">{{ ___('For your security, enter a code sent to your phone or sign in again to add a passkey.') }}</p>
                    <label class="{{ $__cpkField }}">
                        <span>{{ ___('Verification code') }}</span>
                        <input type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="10" data-passkey-code>
                    </label>
                    <button type="button" class="cpk-remove" data-passkey-send-code data-code-url="{{ route('customer.passkeys.code', $store->id) }}">{{ ___('Send me a code') }}</button>
                    <p class="cpk-msg" data-passkey-code-status role="status" hidden></p>
                </div>
            @endif
            <button type="button" class="{{ $__cpkBtn }}" data-passkey-add>{{ ___('Add a passkey') }}</button>
            <p class="cpk-err" data-passkey-error role="alert" hidden></p>
        </div>
    @endif
</div>
@once
<script src="{{ asset('assets/owner-ui/passkeys.js') }}?v={{ is_file(public_path('assets/owner-ui/passkeys.js')) ? filemtime(public_path('assets/owner-ui/passkeys.js')) : '1' }}" nonce="{{ csp_nonce() }}" defer></script>
@endonce
@endif
