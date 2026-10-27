{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P1-19 (J25-13): passkeys on an account's security page (owner settings and reseller portal).
     Pass: $from ('owner' | 'reseller') so a password re-confirmation returns to the right page.
     Lists every passkey of the account; adding is offered only where this host is a relying party. --}}
@php
    $__pkUser = auth()->user();
    $__pkRp = \Juwri\Modules\Auth\Support\PasskeyRelyingParty::for(request());
    $__pkList = $__pkUser instanceof \Juwri\Core\Models\User ? $__pkUser->passkeys()->orderBy('created_at')->get() : collect();
    $__pkImpersonating = session()->has('quick_admin_user_id') || session()->has('impersonating');
@endphp
<section class="ui-card ui-stack" id="passkeys">
    <div class="ui-card__head">
        <h2 class="ui-card__title">{{ ___('Passkeys') }}</h2>
    </div>
    <div class="ui-card__body ui-stack">
        <p class="ui-muted">{{ ___('Sign in with your fingerprint, face or device PIN instead of a password. A passkey also counts as two-step sign-in.') }}</p>

        @if ($__pkList->isEmpty())
            <p>{{ ___('No passkeys yet.') }}</p>
        @else
            <ul class="ui-list" data-passkey-list>
                @foreach ($__pkList as $__pk)
                    <li class="ui-list__item ui-stack">
                        <div>
                            <strong>{{ $__pk->name }}</strong>
                            @if ($__pk->authenticator)<span class="ui-muted"> · {{ $__pk->authenticator }}</span>@endif
                            <div class="ui-muted ui-small">
                                {{ ___('Added on :date', ['date' => $__pk->created_at?->translatedFormat('j M Y')]) }}
                                · {{ $__pk->last_used_at ? ___('Last used :date', ['date' => $__pk->last_used_at->diffForHumans()]) : ___('Never used') }}
                                @if ($__pkRp === null || $__pk->rp_id !== $__pkRp['id'])
                                    · {{ ___('Works on :host', ['host' => $__pk->rp_id]) }}
                                @endif
                            </div>
                        </div>
                        @unless ($__pkImpersonating)
                            <div class="ui-row">
                                <form method="POST" action="{{ route('passkeys.update', $__pk->id) }}" class="ui-input-group" style="max-width:420px">
                                    @csrf
                                    @method('PATCH')
                                    <label class="visually-hidden" for="passkey-name-{{ $__pk->id }}">{{ ___('Passkey name') }}</label>
                                    <input id="passkey-name-{{ $__pk->id }}" name="name" type="text" maxlength="60" class="ui-input" value="{{ $__pk->name }}" required>
                                    <button type="submit" class="ui-btn ui-btn--secondary">{{ ___('Rename') }}</button>
                                </form>
                                <form method="POST" action="{{ route('passkeys.destroy', $__pk->id) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="ui-btn ui-btn--danger" data-confirm="{{ ___('Remove this passkey? You will no longer be able to sign in with it.') }}">{{ ___('Remove') }}</button>
                                </form>
                            </div>
                        @endunless
                    </li>
                @endforeach
            </ul>
        @endif
        @error('name')<div class="ui-text-danger ui-small">{{ $message }}</div>@enderror

        @if ($__pkImpersonating)
            <div class="ui-note" data-tone="warning">{{ ___('Passkeys cannot be changed while you are viewing this account as its owner.') }}</div>
        @elseif ($__pkRp !== null)
            <div data-passkey-manage
                 data-options-url="{{ route('passkeys.options') }}"
                 data-store-url="{{ route('passkeys.store') }}"
                 data-add-url="{{ ($from ?? 'owner') === 'reseller' ? route('passkeys.add', ['from' => 'reseller']) : route('passkeys.add') }}"
                 data-csrf="{{ csrf_token() }}"
                 data-msg-failed="{{ ___('This passkey could not be added. Please try again.') }}"
                 data-msg-unsupported="{{ ___('This browser does not support passkeys.') }}"
                 data-msg-name="{{ ___('Give the passkey a name.') }}">
                <label class="ui-label" for="passkey-new-name">{{ ___('Passkey name') }}</label>
                <div class="ui-input-group" style="max-width:420px">
                    <input id="passkey-new-name" type="text" maxlength="60" class="ui-input" data-passkey-name placeholder="{{ ___('e.g. My laptop') }}">
                    <button type="button" class="ui-btn" data-passkey-add>{{ ___('Add a passkey') }}</button>
                </div>
                <p class="ui-text-danger ui-small" data-passkey-error role="alert" hidden></p>
            </div>
            <script src="{{ asset('assets/owner-ui/passkeys.js') }}?v={{ is_file(public_path('assets/owner-ui/passkeys.js')) ? filemtime(public_path('assets/owner-ui/passkeys.js')) : '1' }}" nonce="{{ csp_nonce() }}" defer></script>
        @endif
    </div>
</section>
