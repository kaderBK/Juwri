{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Settings'))
@section('page_title', ___('Profile Settings'))
@section('page_sub', ___('Account identity, avatar, email, and password'))

@section('content')
@php
    $plan = $user->plan();
    $restaurantsCount = $user->stores()->count();
    $avatar = $user->image ?: 'default_user.png';
    $billingComplete = !empty($user_options->billing_name)
        && !empty($user_options->billing_address)
        && !empty($user_options->billing_country);
    $billingType = $user_options->billing_details_type ?? 'personal';
    $weeklyDigest = $user_options->weekly_digest_email ?? '0';
    $anomalyAlerts = $user_options->anomaly_alerts_email ?? '0';
@endphp

<div class="ui-scope ui-account" x-data="{ billingType: @js($billingType) }">
    {{-- Hero --}}
    <section class="ui-card ui-account__hero">
        <div class="ui-account__who">
            <img src="{{ asset('storage/profile/'.$avatar) }}" alt="{{ $user->username }}" loading="lazy" class="ui-account__avatar">
            <div>
                <p class="ui-overline">{{ ___('Account') }}</p>
                <h2 class="ui-account__name">{{ $user->username }}</h2>
                <span class="ui-account__email">{{ $user->email }}</span>
            </div>
        </div>
        <dl class="ui-account__facts">
            <div><dt>{{ ___('Plan') }}</dt><dd>{{ $plan->name ?? ___('Free') }}</dd></div>
            <div><dt>{{ ___('Stores') }}</dt><dd>{{ number_format($restaurantsCount) }}</dd></div>
            <div><dt>{{ ___('Email') }}</dt><dd>
                <x-ui.pill :tone="$user->hasVerifiedEmail() ? 'success' : 'warning'">{{ $user->hasVerifiedEmail() ? ___('Verified') : ___('Unverified') }}</x-ui.pill>
            </dd></div>
        </dl>
    </section>

    <div class="ui-account__grid">
        {{-- Profile --}}
        <div>
            <x-ui.card :title="___('Account Setting')">
                <x-slot:actions>
                    <x-ui.pill :tone="$user->hasVerifiedEmail() ? 'success' : 'warning'">
                        {{ $user->hasVerifiedEmail() ? ___('Verified email') : ___('Needs verification') }}
                    </x-ui.pill>
                </x-slot:actions>

                <form method="post" action="{{ route('settings.editProfile') }}" enctype="multipart/form-data">
                    @csrf

                    <div class="ui-cluster">
                        <img id="avatar-preview" src="{{ asset('storage/profile/'.$avatar) }}" alt="{{ $user->username }}" class="ui-account__avatar">
                        <div>
                            <label class="ui-btn ui-btn--secondary ui-btn--sm" for="avatar">
                                <span class="ui-btn__icon"><x-ui.icon name="upload" size="16" /></span>
                                <span>{{ ___('Upload Avatar') }}</span>
                            </label>
                            <input
                                class="ui-visually-hidden"
                                type="file"
                                accept="image/jpg,image/jpeg,image/png"
                                id="avatar"
                                name="avatar"
                                data-ui-call="linenPreviewAvatar" data-ui-arg2="avatar-preview"
                            >
                            <small class="ui-text-2 ui-block">{{ ___('Use 150x150px for better use') }}</small>
                            @error('avatar')
                                <span class="ui-text-danger ui-small">{{ $message }}</span>
                            @enderror
                        </div>
                    </div>

                    <div class="ui-form-grid">
                        <div>
                            <label class="ui-label" for="username">{{ ___('Username') }} *</label>
                            <input class="ui-input" type="text" id="username" name="username" value="{{ old('username', $user->username) }}" required>
                            @error('username')
                                <small class="ui-text-danger">{{ $message }}</small>
                            @enderror
                        </div>

                        <div>
                            <label class="ui-label" for="email">{{ ___('Email address') }} *</label>
                            <input class="ui-input" type="email" id="email" name="email" value="{{ old('email', $user->email) }}" required>
                            @error('email')
                                <small class="ui-text-danger">{{ $message }}</small>
                            @enderror
                        </div>

                        {{-- I2: language for transactional emails / SMS. Blank = platform default. --}}
                        <div>
                            <label class="ui-label" for="preferred_locale">{{ ___('Preferred language') }}</label>
                            <select class="ui-input" id="preferred_locale" name="preferred_locale">
                                <option value="">{{ ___('Use the default language') }}</option>
                                @foreach(\Juwri\Core\Models\Language::where('active', '1')->orderBy('name')->get() as $__lang)
                                    <option value="{{ $__lang->code }}" @selected(old('preferred_locale', $user->preferred_locale) === $__lang->code)>{{ language_display_name($__lang->code, $__lang->name) }}</option>
                                @endforeach
                            </select>
                            <small>{{ ___('The language used for your account emails and SMS.') }}</small>
                            @error('preferred_locale')
                                <small class="ui-text-danger">{{ $message }}</small>
                            @enderror
                        </div>

                        <div class="ui-form-grid__full">
                            <label class="ui-label" for="current_password">{{ ___('Current password') }}</label>
                            <input class="ui-input" type="password" id="current_password" name="current_password" autocomplete="current-password">
                            <small>{{ ___('Required to change your email address or password.') }}</small>
                            @error('current_password')
                                <small class="ui-text-danger ui-block">{{ $message }}</small>
                            @enderror
                        </div>

                        <div>
                            <label class="ui-label" for="new_password">{{ ___('New Password') }}</label>
                            <input class="ui-input" type="password" id="new_password" name="new_password" minlength="6" autocomplete="new-password">
                        </div>

                        <div>
                            <label class="ui-label" for="new_password_confirmation">{{ ___('Confirm New Password') }}</label>
                            <input class="ui-input" type="password" id="new_password_confirmation" name="new_password_confirmation" minlength="6" autocomplete="new-password">
                        </div>
                    </div>

                    @error('new_password')
                        <p class="ui-text-danger ui-small">{{ $message }}</p>
                    @enderror

                    {{-- P6-75: a password change signs out the mobile app everywhere; inside the app, this device may stay. --}}
                    @if (session('owner_app_token_id'))
                        <x-ui.check name="keep_this_device" value="1" :label="___('Keep this device signed in')" />
                    @else
                        <p class="ui-small ui-muted">{{ ___('Changing your password signs out the mobile app on all devices.') }}</p>
                    @endif

                    <div class="sticky-savebar">
                        <x-busy-button type="submit" icon="save" :busy-label="___('Saving…')">
                            {{ ___('Save Changes') }}
                        </x-busy-button>
                    </div>
                </form>
            </x-ui.card>
        </div>

        {{-- Billing --}}
        <div>
            <x-ui.card :title="___('Billing Details')">
                <x-slot:actions>
                    <x-ui.pill :tone="$billingComplete ? 'success' : 'warning'">
                        {{ $billingComplete ? ___('Complete') : ___('Incomplete') }}
                    </x-ui.pill>
                </x-slot:actions>

                <form method="post" action="{{ route('settings.editBilling') }}">
                    @csrf

                    @if($errors->billing->any())
                        <x-ui.banner tone="danger" :title="___('All the fields with * are required')" />
                    @endif

                    <p class="ui-text-2">{{ ___('These details will be used in invoice and payments.') }}</p>

                    <div class="ui-form-grid">
                        <div>
                            <label class="ui-label" for="billing_details_type">{{ ___('Type') }}</label>
                            <select class="ui-input" name="billing_details_type" id="billing_details_type" x-model="billingType" required>
                                <option value="personal">{{ ___('Personal') }}</option>
                                <option value="business">{{ ___('Business') }}</option>
                            </select>
                        </div>

                        <div x-show="billingType === 'business'" x-transition.opacity>
                            <label class="ui-label" for="billing_tax_id">{{ @$settings->invoice_admin_tax_type ?: ___('Tax ID') }}</label>
                            <input class="ui-input" type="text" id="billing_tax_id" name="billing_tax_id" value="{{ old('billing_tax_id', $user_options->billing_tax_id ?? '') }}">
                        </div>

                        <div>
                            <label class="ui-label" for="billing_name">{{ ___('Name') }} *</label>
                            <input class="ui-input" type="text" id="billing_name" name="billing_name" value="{{ old('billing_name', $user_options->billing_name ?? '') }}" required>
                        </div>

                        <div>
                            <label class="ui-label" for="billing_address">{{ ___('Address') }} *</label>
                            <input class="ui-input" type="text" id="billing_address" name="billing_address" value="{{ old('billing_address', $user_options->billing_address ?? '') }}" required>
                        </div>

                        <div>
                            <label class="ui-label" for="billing_city">{{ ___('City') }} *</label>
                            <input class="ui-input" type="text" id="billing_city" name="billing_city" value="{{ old('billing_city', $user_options->billing_city ?? '') }}" required>
                        </div>

                        <div>
                            <label class="ui-label" for="billing_state">{{ ___('State') }} *</label>
                            <input class="ui-input" type="text" id="billing_state" name="billing_state" value="{{ old('billing_state', $user_options->billing_state ?? '') }}" required>
                        </div>

                        <div>
                            <label class="ui-label" for="billing_zipcode">{{ ___('Postal code') }} *</label>
                            <input class="ui-input" type="text" id="billing_zipcode" name="billing_zipcode" value="{{ old('billing_zipcode', $user_options->billing_zipcode ?? '') }}" required>
                        </div>

                        <div>
                            <label class="ui-label" for="billing_country">{{ ___('Country') }} *</label>
                            <select class="ui-input" name="billing_country" id="billing_country" required>
                                <option value="" disabled @selected(empty($user_options->billing_country))>{{ ___('Choose') }}</option>
                                @foreach(countries() as $country)
                                    <option value="{{ $country->code }}" @selected($country->code == old('billing_country', $user_options->billing_country ?? ''))>
                                        {{ country_display_name($country->code, $country->name) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="ui-label" for="weekly_digest_email">{{ ___('Weekly Analytics Email Digest') }}</label>
                            <select class="ui-input" name="weekly_digest_email" id="weekly_digest_email">
                                <option value="0" @selected($weeklyDigest == '0')>{{ ___('Disabled') }}</option>
                                <option value="1" @selected($weeklyDigest == '1')>{{ ___('Enabled') }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="ui-label" for="anomaly_alerts_email">{{ ___('Daily Scan Drop Alerts') }}</label>
                            <select class="ui-input" name="anomaly_alerts_email" id="anomaly_alerts_email">
                                <option value="0" @selected($anomalyAlerts == '0')>{{ ___('Disabled') }}</option>
                                <option value="1" @selected($anomalyAlerts == '1')>{{ ___('Enabled') }}</option>
                            </select>
                            <small class="ui-text-2 ui-block">{{ ___('Emails you when today scans drop 40% or more below your last 7 full days.') }}</small>
                        </div>
                    </div>

                    <div class="sticky-savebar">
                        <x-busy-button type="submit" icon="save" :busy-label="___('Saving…')">
                            {{ ___('Save Changes') }}
                        </x-busy-button>
                    </div>
                </form>
            </x-ui.card>
        </div>
    </div>

    {{-- FIX-2 F2-12: entitlement-aware, not kill-only — the opt-in POST is now
         `feature:ai_assistant`-gated, so an unentitled owner must not see the control. --}}
    @if(Route::has('dashboard.benchmarkOptIn') && \App\Http\Controllers\User\DashboardController::aiAssistantAvailable())
        @php $__benchOptIn = (string) \Juwri\Core\Models\UserOption::getUserOption(auth()->id(), 'peer_benchmark_opt_in') === '1'; @endphp
        <section class="ui-card ui-account__row">
            <div>
                <h3 class="ui-account__row-title">{{ ___('Peer benchmarks') }}</h3>
                <p class="ui-account__row-sub">{{ ___('Contribute anonymized totals to see how you compare with similar stores. Only cohort medians are ever shown — never another store’s data.') }}</p>
            </div>
            <form method="POST" action="{{ route('dashboard.benchmarkOptIn') }}">
                @csrf
                <input type="hidden" name="opt_in" value="{{ $__benchOptIn ? '0' : '1' }}">
                <button type="submit" class="ui-btn ui-btn--sm {{ $__benchOptIn ? 'ui-btn--danger' : 'ui-btn--secondary' }}">
                    {{ $__benchOptIn ? ___('Opt out') : ___('Opt in') }}
                </button>
            </form>
        </section>
    @endif

    @if(Route::has('onboarding.restore'))
        <section class="ui-card ui-account__row">
            <div>
                <h3 class="ui-account__row-title">{{ ___('Setup checklist') }}</h3>
                <p class="ui-account__row-sub">{{ ___('Bring back the getting-started checklist on your dashboard.') }}</p>
            </div>
            <form method="POST" action="{{ route('onboarding.restore') }}">
                @csrf
                <button type="submit" class="ui-btn ui-btn--secondary ui-btn--sm">{{ ___('Restore setup checklist') }}</button>
            </form>
        </section>
    @endif

    {{-- google-suite Lane A — connect/disconnect a social identity to this owner
         account. Gated on the same settings.<provider>_login switch as the login
         page button; hidden entirely when no provider is enabled. --}}
    @php
        $__googleConnect = (string) config('settings.google_login') === '1' && \Illuminate\Support\Facades\Route::has('social.connect');
        $__facebookConnect = (string) config('settings.facebook_login') === '1' && \Illuminate\Support\Facades\Route::has('social.connect');
        $__linkedProvider = $user->oauth_provider ?: null;
    @endphp
    @if($__googleConnect || $__facebookConnect)
        <section class="ui-card ui-account__row">
            <div>
                <h3 class="ui-account__row-title">{{ ___('Connected accounts') }}</h3>
                <p class="ui-account__row-sub">{{ ___('Link a social account to sign in to your dashboard faster.') }}</p>
            </div>
            <div class="ui-account__row-actions">
                @if($__linkedProvider)
                    <x-ui.pill tone="success">{{ ___('Connected via :provider', ['provider' => ucfirst($__linkedProvider)]) }}</x-ui.pill>
                    <form method="POST" action="{{ route('social.disconnect', $__linkedProvider) }}">
                        @csrf
                        <button type="submit" class="ui-btn ui-btn--danger ui-btn--sm">{{ ___('Disconnect') }}</button>
                    </form>
                @else
                    @if($__googleConnect)
                        <a href="{{ route('social.connect', 'google') }}" class="ui-btn ui-btn--secondary ui-btn--sm">{{ ___('Connect Google') }}</a>
                    @endif
                    @if($__facebookConnect)
                        <a href="{{ route('social.connect', 'facebook') }}" class="ui-btn ui-btn--secondary ui-btn--sm">{{ ___('Connect Facebook') }}</a>
                    @endif
                @endif
            </div>
        </section>
    @endif

    {{-- Owner self-service account deletion (Apple 5.1.1(v)). Owners only — never a
         staff, admin or reseller-support session. --}}
    @if(Route::has('user.account.delete.show') && ! auth()->user()->isAdmin() && ! session()->has('staff_session_id') && ! is_array(session('impersonating')))
        <section class="ui-card ui-account__row ui-account__row--danger">
            <div>
                <h3 class="ui-account__row-title">{{ ___('Delete account') }}</h3>
                <p class="ui-account__row-sub">{{ ___('Close your account and permanently delete your stores and data.') }}</p>
            </div>
            <a href="{{ route('user.account.delete.show') }}" class="ui-btn ui-btn--danger ui-btn--sm">{{ ___('Delete account') }}</a>
        </section>
    @endif
</div>
@endsection

@push('scripts')
<script nonce="{{ csp_nonce() }}">
    window.linenPreviewAvatar = function (input, targetId) {
        var target = document.getElementById(targetId);
        if (!input.files || !input.files[0] || !target) {
            return;
        }

        var reader = new FileReader();
        reader.onload = function (event) {
            target.src = event.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    };
</script>
@endpush
