{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
/**
 * P6-47: after an owner's first login, prompt them to turn on two-factor sign-in or add a passkey.
 * Shown until a second factor exists or the owner dismisses it (users.preferences.security_nudge_dismissed).
 */
$__nudgeUser = request()->user();
$__showNudge = $__nudgeUser instanceof \Juwri\Core\Models\User
    && ($__nudgeUser->user_type ?? 'user') === 'user'
    && Route::has('2fa.show')
    && \Juwri\Core\Settings\UserSettings::legacy((int) $__nudgeUser->id, 'security_nudge_dismissed') !== '1'
    && ! $__nudgeUser->hasSecondFactor();
@endphp

@if($__showNudge)
    <section class="ui-banner security-nudge" data-tone="warning" id="security-nudge" role="status" aria-labelledby="security-nudge-title">
        <span class="ui-banner__icon"><x-ui.icon name="alert" size="18" /></span>
        <div class="ui-banner__body ui-grow">
            <strong id="security-nudge-title">{{ ___('Secure your account') }}</strong>
            <div class="ui-small">{{ ___('Turn on two-factor sign-in or add a passkey so nobody can get into your account with just your password.') }}</div>
            <div class="ui-cluster">
                <a href="{{ route('2fa.show') }}" class="ui-btn ui-btn--sm">{{ ___('Set up two-factor sign-in') }}</a>
                @if(Route::has('passkeys.add'))
                    <a href="{{ route('passkeys.add') }}" class="ui-btn ui-btn--sm ui-btn--secondary">{{ ___('Add a passkey') }}</a>
                @endif
                @if(Route::has('security-nudge.dismiss'))
                    <form method="POST" action="{{ route('security-nudge.dismiss') }}" class="ui-inline">
                        @csrf
                        <button type="submit" class="ui-btn ui-btn--sm ui-btn--ghost">{{ ___('Not now') }}</button>
                    </form>
                @endif
            </div>
        </div>
    </section>
@endif
