{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    $curSign = cur_isolate($postOptions->currency_sign ?? '$');
    $curPos  = (string) currency_pos_effective($postOptions->id ?? null);
    if (!function_exists('moneyFormat')) {
        /* $dp comes from the STORE's currency: this page is not rendered through
           StoreController@publicView, so config('settings.currency_code') is the
           platform's, not the store's. Concatenating the raw float here printed
           artifacts like 1.7999999523162842 verbatim. */
        function moneyFormat($amount, $curSign, $curPos, $dp = 2) {
            $str = number_format((float) $amount, $dp, '.', '');
            return $curPos === '1' ? ($curSign . $str) : ($str . $curSign);
        }
    }

    /* theme-pack-blade: mint/reuse a CustomerToken for F1 (meal plans) + F2 (following) API calls */
    $customerApiToken = null;
    if ($customer) {
        $sessKey = 'au_customer_api_token_'.$customer->id;
        $cached  = session($sessKey);
        if ($cached) {
            $tok = \Juwri\Modules\Customers\Models\CustomerToken::where('token', hash('sha256', $cached))->first();
            if (!$tok || $tok->isExpired()) { $cached = null; }
        }
        if (!$cached) {
            $cached = \Juwri\Modules\Customers\Models\CustomerToken::issue($customer, 'aurora-storefront', 30);
            session([$sessKey => $cached]);
        }
        $customerApiToken = $cached;
    }
    $mealPlansEnabled = \Juwri\Modules\Ordering\Services\MealPlanService::enabled($store->id);
    $isFollowing = ($customer && method_exists($store, 'isFollowedBy')) ? $store->isFollowedBy($customer) : false;
    $isVerifiedStore = method_exists($store, 'isVerified') ? $store->isVerified() : (bool) ($store->is_verified ?? false);
@endphp

@push('style_at_top')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$postOptions->store_template.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$postOptions->store_template.'/css/style.css'))) }}">
    <style nonce="{{ csp_nonce() }}">
        body {
            background-color: var(--au-bg-page, #0A1320);
        }
        .au-account-container {
            max-width: 800px;
            margin: 40px auto;
            padding: 0 20px;
            color: var(--au-text, #ffffff);
            font-family: 'Saudi Riyal', 'Inter', sans-serif;
        }
        .au-acct-card {
            background: var(--au-bg, #111C2A);
            border-radius: 12px;
            padding: 24px;
            border: 1px solid var(--au-border, #1E2D40);
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            color: var(--au-text, #ffffff);
        }
        .au-acct-card-header {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--au-border, #1E2D40);
            color: var(--au-text, #ffffff);
        }
        .au-order-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .au-order-item {
            border-bottom: 1px solid var(--au-border, #1E2D40);
            padding: 16px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .au-order-item:last-child {
            border-bottom: none;
        }
        .au-status-pill {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .au-status-pending { background: #fff3cd; color: #856404; }
        .au-status-completed { background: #d4edda; color: #155724; }
        .au-status-cancelled { background: #f8d7da; color: #721c24; }
        .au-account-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
        }
        .au-account-field label {
            display: block;
            color: var(--au-muted);
            font-size: 12px;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: .08em;
        }
        .au-account-field input,
        .au-account-field textarea {
            width: 100%;
            background: var(--au-input-bg);
            border: 1px solid var(--au-border);
            border-radius: 10px;
            color: var(--au-text);
            padding: 10px 12px;
            font: inherit;
        }
        .au-account-field textarea {
            min-height: 82px;
            resize: vertical;
        }
        .au-account-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            margin-top: 18px;
        }
        .au-account-btn {
            border: 0;
            border-radius: 999px;
            background: var(--au-accent);
            color: #12121a;
            cursor: pointer;
            font-weight: 800;
            padding: 10px 18px;
        }
        .au-account-btn.ghost {
            background: var(--au-glass-2);
            color: var(--au-text);
            border: 1px solid var(--au-border);
        }
        .au-address-list {
            display: grid;
            gap: 12px;
            margin-top: 18px;
        }
        .au-address-item {
            align-items: flex-start;
            background: var(--au-glass);
            border: 1px solid var(--au-border);
            border-radius: 12px;
            display: flex;
            gap: 12px;
            justify-content: space-between;
            padding: 14px;
        }
        .au-default-pill {
            display: inline-flex;
            border-radius: 999px;
            background: rgba(245,181,74,.14);
            color: var(--au-accent);
            font-size: 11px;
            font-weight: 800;
            margin-left: 8px;
            padding: 3px 8px;
            text-transform: uppercase;
        }
        .au-alert {
            border-radius: 12px;
            margin-bottom: 18px;
            padding: 12px 14px;
        }
        .au-alert.success {
            background: rgba(52,211,153,.12);
            border: 1px solid rgba(52,211,153,.25);
            color: #bbf7d0;
        }
        .au-alert.error {
            background: rgba(248,113,113,.12);
            border: 1px solid rgba(248,113,113,.25);
            color: #fecaca;
        }
        .au-order-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 10px;
        }

        @media (max-width: 600px) {
            .au-order-item { flex-direction: column; align-items: flex-start; gap: 10px; }
            .au-address-item { flex-direction: column; }
            .au-order-actions { justify-content: stretch; }
        }
    </style>
@endpush

@section('content')
<div class="aurora">
    <div class="au-ambient"></div>
    <div class="au-app">
        <header class="au-header" style="background: var(--au-glass); padding: 15px 20px;">
            <div class="au-header-inner" style="max-width:800px; margin:0 auto; display:flex; justify-content:space-between; align-items:center;">
                <div class="au-brand">
                    <a data-mb-back href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" style="text-decoration:none; color:var(--au-text); display:flex; align-items:center; gap:10px;">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
                        <h2 style="color:var(--au-text); margin:0;">{{ $store->title }}</h2>
                    </a>
                </div>
                <div class="au-actions">
                    <button type="button" id="au-logout-btn" style="background:var(--au-glass-2); border:none; padding:8px 16px; border-radius:var(--au-r-sm); color:var(--au-text); font-weight:600;">{{ ___('Logout') }}</button>
                </div>
            </div>
        </header>

        <div class="au-account-container">
            <h3 style="margin-bottom:24px; color:var(--au-text);">{{ ___('My Account') }}</h3>

            @if(session('success'))
                <div class="au-alert success">{{ session('success') }}</div>
            @endif

            @if($errors->any())
                <div class="au-alert error">{{ $errors->first() }}</div>
            @endif

            <div id="au-reorder-feedback" class="au-alert error" style="display:none;"></div>

            <a class="au-account-btn ghost" href="{{ route('customer.account', $store->id) }}" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none;margin-bottom:18px;">{{ ___('My Orders') }}</a>

            {{-- Native Share (replaces the retired shared floating pill); gated on the
                 per-store share.fallback_button toggle, same as the 13 in-menu themes.
                 Self-contained nonced script mirrors aurora's invite-copy control above. --}}
            @if(theme_setting($store->id, 'share.fallback_button'))
            <button type="button" class="au-account-btn ghost" id="au-share-menu"
                    data-share-url="{{ storefront_localized_link(route('publicView', $store->slug)) }}"
                    data-copied="{{ ___('Link copied') }}"
                    style="display:inline-flex;align-items:center;gap:8px;margin-bottom:18px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 8a3 3 0 1 0-2.83-4M6 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm12 7a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM8.6 13.5l6.8 3.9M15.4 6.6L8.6 10.5"/></svg>
                <span>{{ ___('Share this menu') }}</span>
            </button>
            <script nonce="{{ csp_nonce() }}">
                (function () {
                    var btn = document.getElementById('au-share-menu');
                    if (!btn) return;
                    var span = btn.querySelector('span');
                    var url = btn.getAttribute('data-share-url');
                    btn.addEventListener('click', function () {
                        if (navigator.share) { navigator.share({ title: document.title, url: url }).catch(function () {}); return; }
                        var done = function () {
                            if (!span) return;
                            var o = span.textContent;
                            span.textContent = btn.getAttribute('data-copied') || o;
                            setTimeout(function () { span.textContent = o; }, 1600);
                        };
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(url).then(done, function () {});
                        }
                    });
                })();
            </script>
            @endif

            <div class="au-acct-card">
                <div class="au-acct-card-header">{{ ___('Profile Details') }}</div>
                <form method="POST" action="{{ route('customer.profile.update', $store->id) }}">
                    @csrf
                    <div class="au-account-grid">
                        <div class="au-account-field">
                            <label for="customer-name">{{ ___('Name') }}</label>
                            <input id="customer-name" type="text" name="name" value="{{ old('name', $customer->name) }}" required>
                        </div>
                        <div class="au-account-field">
                            <label for="customer-email">{{ ___('Email') }}</label>
                            <input id="customer-email" type="email" name="email" value="{{ old('email', $customer->email) }}">
                        </div>
                        <div class="au-account-field">
                            <label for="customer-phone">{{ ___('Phone') }}</label>
                            <input id="customer-phone" type="tel" name="phone" value="{{ old('phone', $customer->phone) }}">
                        </div>
                    </div>
                    <label style="display:flex; gap:10px; align-items:center; margin-top:16px; color:var(--au-muted);">
                        <input type="checkbox" name="notifications_enabled" value="1" @checked(old('notifications_enabled', $customer->notifications_enabled ?? 1))>
                        <span>{{ ___('Order updates & notifications') }}</span>
                    </label>
                    <label style="display:flex; gap:10px; align-items:center; margin-top:12px; color:var(--au-muted);">
                        <input type="checkbox" name="marketing_consent" value="1" @checked(old('marketing_consent', $customer->marketing_consent))>
                        <span>{{ ___('Send me offers and updates from this restaurant') }}</span>
                    </label>
                    <label style="display:flex; gap:10px; align-items:center; margin-top:12px; color:var(--au-muted);">
                        <input type="checkbox" name="sms_consent" value="1" @checked(old('sms_consent', $customer->sms_consent))>
                        <span>{{ ___('WhatsApp & SMS messages') }}</span>
                    </label>
                    <div class="au-account-actions">
                        <button type="submit" class="au-account-btn">{{ ___('Save profile') }}</button>
                    </div>
                </form>
            </div>

            {{-- P1-19: the customer's passkeys for this store, in aurora's own card classes. --}}
            @include('store_templates._partials.customer-passkeys', ['cpkCard' => 'au-acct-card', 'cpkTitle' => 'au-acct-card-header', 'cpkBtn' => 'au-account-btn', 'cpkField' => 'au-account-field'])
            @include('store_templates._partials.customer-privacy', ['cpkCard' => 'au-acct-card', 'cpkTitle' => 'au-acct-card-header', 'cpkBtn' => 'au-account-btn', 'cpkField' => 'au-account-field'])

            {{-- Refer a friend — native aurora port of _partials/account-extras.blade.php
                 (E4). Same data source (Customer::referralCode() → referral_code) and same
                 `?ref=CODE` share link; a friend's first paid order credits you both.
                 Self-gates exactly as account-extras: only shows when a code + publicView
                 route exist. aurora ships a native .au-account-* form, so it can't @include
                 the shared partial without duplicating aurora's layout — this renders the
                 equivalent block in aurora's own card system instead. --}}
            @php
                $auRefCode = method_exists($customer, 'referralCode') ? $customer->referralCode() : ($customer->referral_code ?? null);
                $auShareUrl = ($auRefCode && Route::has('publicView')) ? storefront_localized_link(route('publicView', $store->slug).'?ref='.$auRefCode) : null;
            @endphp
            @if($auShareUrl)
            <div class="au-acct-card">
                <div class="au-acct-card-header">{{ ___('Invite a friend') }}</div>
                <p style="color:var(--au-muted); margin:0 0 16px;">{{ ___('Share your link. When a friend places their first order, you both earn points.') }}</p>
                <div class="au-account-field">
                    <label for="au-invite-link">{{ ___('Your invite link') }}</label>
                    <div style="display:flex; gap:10px; align-items:stretch; flex-wrap:wrap;">
                        <input id="au-invite-link" type="text" readonly value="{{ $auShareUrl }}" aria-label="{{ ___('Your invite link') }}" style="flex:1; min-width:0;">
                        <button type="button" class="au-account-btn ghost" id="au-invite-copy" data-copy-target="au-invite-link" style="flex:none;">{{ ___('Copy') }}</button>
                    </div>
                </div>
            </div>
            <script nonce="{{ csp_nonce() }}">
                (function () {
                    var btn = document.getElementById('au-invite-copy');
                    var input = document.getElementById('au-invite-link');
                    if (!btn || !input) return;
                    var label = btn.textContent;
                    input.addEventListener('focus', function () { input.select(); });
                    btn.addEventListener('click', function () {
                        input.focus();
                        input.select();
                        var done = function () {
                            btn.textContent = @json(___('Copied'));
                            setTimeout(function () { btn.textContent = label; }, 1600);
                        };
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(input.value).then(done, function () {
                                try { document.execCommand('copy'); done(); } catch (e) {}
                            });
                        } else {
                            try { document.execCommand('copy'); done(); } catch (e) {}
                        }
                    });
                })();
            </script>
            @endif

            <div class="au-acct-card">
                <div class="au-acct-card-header">{{ ___('Saved Addresses') }}</div>
                <form method="POST" action="{{ route('customer.addresses.store', $store->id) }}">
                    @csrf
                    <div class="au-account-grid">
                        <div class="au-account-field">
                            <label for="address-label">{{ ___('Label') }}</label>
                            <input id="address-label" type="text" name="label" value="{{ old('label') }}" placeholder="{{ ___('Home') }}">
                        </div>
                        <div class="au-account-field" style="grid-column: 1 / -1;">
                            <label for="address-line">{{ ___('Address') }}</label>
                            <textarea id="address-line" name="line" required placeholder="{{ ___('Street, building, apartment') }}">{{ old('line') }}</textarea>
                        </div>
                        <div class="au-account-field">
                            <label for="address-city">{{ ___('City') }}</label>
                            <input id="address-city" type="text" name="city" value="{{ old('city') }}">
                        </div>
                        <div class="au-account-field">
                            <label for="address-state">{{ ___('State') }}</label>
                            <input id="address-state" type="text" name="state" value="{{ old('state') }}">
                        </div>
                        <div class="au-account-field">
                            <label for="address-zip">{{ ___('Zip') }}</label>
                            <input id="address-zip" type="text" name="zip" value="{{ old('zip') }}">
                        </div>
                        <div class="au-account-field">
                            <label for="address-country">{{ ___('Country') }}</label>
                            <input id="address-country" type="text" name="country" value="{{ old('country') }}">
                        </div>
                    </div>
                    <label style="display:flex; gap:10px; align-items:center; margin-top:16px; color:var(--au-muted);">
                        <input type="checkbox" name="is_default" value="1" checked>
                        <span>{{ ___('Use as my default delivery address') }}</span>
                    </label>
                    <div class="au-account-actions">
                        <button type="submit" class="au-account-btn">{{ ___('Save address') }}</button>
                    </div>
                </form>

                <div class="au-address-list">
                    @forelse($customer->addresses as $address)
                        <div class="au-address-item">
                            <div>
                                <div style="font-weight:700; color:var(--au-text);">
                                    {{ $address->label ?: ___('Saved address') }}
                                    @if($address->is_default)
                                        <span class="au-default-pill">{{ ___('Default') }}</span>
                                    @endif
                                </div>
                                <div style="color:var(--au-muted); margin-top:4px;">
                                    {{ $address->line }}
                                    @if($address->city), {{ $address->city }}@endif
                                    @if($address->state), {{ $address->state }}@endif
                                    @if($address->zip), {{ $address->zip }}@endif
                                    @if($address->country), {{ $address->country }}@endif
                                </div>
                            </div>
                            <form method="POST" action="{{ route('customer.addresses.destroy', [$store->id, $address->id]) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="au-account-btn ghost">{{ ___('Remove') }}</button>
                            </form>
                        </div>
                    @empty
                        <p style="color:var(--au-faint); margin:18px 0 0;">{{ ___('No saved addresses yet.') }}</p>
                    @endforelse
                </div>
            </div>

            {{-- F1 · my meal plans --}}
            @if($mealPlansEnabled)
            <div class="au-acct-card" id="au-mealplans-card">
                <div class="au-acct-card-header">{{ ___('My meal plans') }}</div>
                <div id="au-mealplans-list"><p style="color:var(--au-faint);">{{ ___('Loading…') }}</p></div>
                <div id="au-mealplans-empty" style="display:none; text-align:center; padding:20px 0;">
                    <p style="color:var(--au-faint);">{{ ___('No meal plans yet. Build a cart and tap “Make this a meal plan”.') }}</p>
                    <a href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" style="display:inline-block; margin-top:10px; background:var(--au-accent); color:#000; padding:10px 20px; border-radius:var(--au-r-pill); font-weight:600;" data-tc-text="texts.view_menu">{{ theme_setting($store->id, 'texts.view_menu', ___('View Menu')) }}</a>
                </div>
            </div>
            @endif

            {{-- F2 · following (per-restaurant customer → this brand) --}}
            @if($isFollowing)
            <div class="au-acct-card" id="au-following-card">
                <div class="au-acct-card-header">{{ ___('Following') }}</div>
                <div class="au-address-item">
                    <div>
                        <div style="font-weight:700; color:var(--au-text);">{{ $store->title }}@if($isVerifiedStore) ✓@endif</div>
                        <div style="color:var(--au-muted); margin-top:4px;">{{ ___('You follow this store') }}</div>
                    </div>
                    <button type="button" class="au-account-btn ghost" id="au-unfollow-btn">{{ ___('Unfollow') }}</button>
                </div>
            </div>
            @endif

            {{-- Appearance (aurora is on the shared mbTheme engine). --}}
            <style nonce="{{ csp_nonce() }}">
                .au-appear { display:flex; align-items:center; justify-content:space-between; width:100%; gap:12px; background:transparent; border:0; color:var(--au-text); font:inherit; font-size:15px; font-weight:600; cursor:pointer; padding:0; }
                .au-appear-sw { width:46px; height:27px; border-radius:999px; border:1px solid var(--au-border-hi); position:relative; flex:none; transition:background .15s ease; }
                .au-appear-sw::after { content:""; position:absolute; top:2px; inset-inline-start:2px; width:21px; height:21px; border-radius:50%; background:var(--au-muted); opacity:.7; transition:inset-inline-start .15s ease, opacity .15s ease, background .15s ease; }
                .au-appear[aria-pressed="true"] .au-appear-sw { background:var(--au-accent-20, rgba(255,255,255,.14)); border-color:var(--au-accent); }
                .au-appear[aria-pressed="true"] .au-appear-sw::after { inset-inline-start:21px; opacity:1; background:var(--au-accent); }
            </style>
            <div class="au-acct-card">
                <div class="au-acct-card-header">{{ ___('Appearance') }}</div>
                <button type="button" class="au-appear" data-mb-theme-toggle aria-pressed="false">
                    <span>{{ ___('Dark mode') }}</span>
                    <span class="au-appear-sw" aria-hidden="true"></span>
                </button>
            </div>
            <script nonce="{{ csp_nonce() }}">
                (function () {
                    function go() { if (window.mbTheme) { window.mbTheme.init({ rootSel: '.aurora' }); } else { setTimeout(go, 50); } }
                    if (document.readyState !== 'loading') { go(); } else { document.addEventListener('DOMContentLoaded', go); }
                })();
            </script>

            @php
                $auSession = Route::has('localize');
                $auSupported = $auSession ? null : \LaravelLocalization::getSupportedLocales();
                $auLangs = $languages->filter(fn ($l) => $auSession || isset($auSupported[$l->code]))->values();
            @endphp
            @if($auLangs->count() > 1)
            <div class="au-acct-card">
                <div class="au-acct-card-header">{{ ___('Language') }}</div>
                <div style="display:flex; flex-wrap:wrap; gap:8px;">
                    @foreach($auLangs as $lang)
                        <a href="{{ $auSession ? route('localize', $lang->code) : \LaravelLocalization::getLocalizedURL($lang->code, url()->current()) }}" style="text-decoration:none; padding:8px 14px; border-radius:999px; font-weight:700; font-size:13.5px; border:1px solid var(--au-border); color:var(--au-text);@if(get_lang() === $lang->code) background:var(--au-accent); color:#12121a; border-color:var(--au-accent);@endif">{{ $lang->name }}</a>
                    @endforeach
                </div>
            </div>
            @endif

            @if(! empty($infoPages))
            <div class="au-acct-card">
                <div class="au-acct-card-header">{{ ___('Information') }}</div>
                <div class="au-address-list">
                    @foreach($infoPages as $slug => $label)
                        <a class="au-address-item" style="text-decoration:none; color:var(--au-text); align-items:center;" href="{{ storefront_localized_link(route('store.infoPage', [$store->id, $slug])) }}">
                            <div style="font-weight:700;">{{ $label }}</div>
                            <span style="color:var(--au-muted); font-size:20px; line-height:1;">›</span>
                        </a>
                    @endforeach
                </div>
            </div>
            @endif
        </div>
    </div>
</div>
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    var logoutEndpoint = "{{ route('customer.logout', $store->id) }}";
    var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var reorderRedirectUrl = "{{ storefront_localized_link(route('publicView', $store->slug)) }}";
    var reorderCartKey = "aurora_cart_{{ $store->slug }}";
    var reorderPendingKey = "aurora_reorder_pending_{{ $store->slug }}";
    var reorderNoticeKey = "aurora_reorder_notice_{{ $store->slug }}";
    var reorderFeedback = document.getElementById('au-reorder-feedback');

    var logoutBtn = document.getElementById('au-logout-btn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function() {
            fetch(logoutEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json'
                }
            })
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    window.location.href = "{{ storefront_localized_link(route('publicView', $store->slug)) }}";
                }
            });
        });
    }

    var accountToggle = document.querySelector('#au-account-menu > button');
    var accountMenu = document.querySelector('.au-lang-menu');
    if (accountToggle && accountMenu) {
        accountToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            accountMenu.classList.toggle('show');
        });
        document.addEventListener('click', function() {
            accountMenu.classList.remove('show');
        });
    }

    /* ── theme-pack-blade F1 (meal plans) + F2 (unfollow) ── */
    var TOKEN = @json($customerApiToken);
    var TP_EP = {
        mealPlan: @json(url('customer/meal-plan')),
        follow: @json(url('r/'.$store->slug.'/follow')),
    };
    function tpHeaders(json) {
        var h = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken };
        if (json) h['Content-Type'] = 'application/json';
        if (TOKEN) h['Authorization'] = 'Bearer ' + TOKEN;
        return h;
    }
    function tpEsc(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    var mpList = document.getElementById('au-mealplans-list');
    function tpStatusLabel(s) {
        return ({ active: @json(___('Active')), paused: @json(___('Paused')), cancelled: @json(___('Cancelled')) })[s] || s;
    }
    function tpRenderPlan(p) {
        var actions = '';
        if (p.status === 'active') {
            actions = '<button class="au-account-btn ghost" data-mp="pause" data-id="'+p.id+'">'+@json(___('Pause'))+'</button>' +
                      '<button class="au-account-btn ghost" data-mp="skip" data-id="'+p.id+'">'+@json(___('Skip next'))+'</button>' +
                      '<button class="au-account-btn ghost" data-mp="cancel" data-id="'+p.id+'">'+@json(___('Cancel'))+'</button>';
        } else if (p.status === 'paused') {
            actions = '<button class="au-account-btn ghost" data-mp="resume" data-id="'+p.id+'">'+@json(___('Resume'))+'</button>' +
                      '<button class="au-account-btn ghost" data-mp="cancel" data-id="'+p.id+'">'+@json(___('Cancel'))+'</button>';
        }
        var next = p.next_run ? ('<div style="color:var(--au-muted);font-size:13px;margin-top:4px;">'+@json(___('Next order'))+': <span dir="ltr">'+tpEsc(p.next_run)+'</span></div>') : '';
        return '<div class="au-address-item" style="flex-direction:column;align-items:stretch;" data-plan="'+p.id+'">' +
            '<div style="display:flex;justify-content:space-between;align-items:flex-start;">' +
            '<div><b style="color:var(--au-text);">'+tpEsc(p.name || p.cadence)+'</b>'+next+'</div>' +
            '<span class="au-status-pill au-status-'+(p.status === 'active' ? 'completed' : (p.status === 'paused' ? 'pending' : 'cancelled'))+'">'+tpEsc(tpStatusLabel(p.status))+'</span></div>' +
            (actions ? '<div class="au-order-actions" style="justify-content:flex-start;margin-top:10px;">'+actions+'</div>' : '') + '</div>';
    }
    function tpLoadPlans() {
        if (!mpList) return;
        fetch(TP_EP.mealPlan, { headers: tpHeaders(false) })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var plans = (d && d.data) || [];
                var empty = document.getElementById('au-mealplans-empty');
                if (!plans.length) { mpList.innerHTML = ''; if (empty) empty.style.display = 'block'; return; }
                if (empty) empty.style.display = 'none';
                mpList.innerHTML = plans.map(tpRenderPlan).join('');
            })
            .catch(function () { mpList.innerHTML = '<p style="color:var(--au-faint);">'+@json(___('Could not load plans.'))+'</p>'; });
    }
    if (mpList) {
        tpLoadPlans();
        mpList.addEventListener('click', function (e) {
            var b = e.target.closest('[data-mp]'); if (!b) return;
            var action = b.dataset.mp, id = b.dataset.id;
            if (action === 'cancel' && !window.confirm(@json(___('Cancel this meal plan?')))) return;
            b.disabled = true;
            fetch(TP_EP.mealPlan + '/' + id + '/' + action, { method: 'POST', headers: tpHeaders(true), body: '{}' })
                .then(function (r) { return r.json(); })
                .then(function () { tpLoadPlans(); })
                .catch(function () { b.disabled = false; });
        });
    }

    var unfollowBtn = document.getElementById('au-unfollow-btn');
    if (unfollowBtn) unfollowBtn.addEventListener('click', function () {
        unfollowBtn.disabled = true;
        fetch(TP_EP.follow, { method: 'DELETE', headers: tpHeaders(true), body: '{}' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.following === false) {
                    var card = document.getElementById('au-following-card');
                    if (card) card.style.display = 'none';
                } else { unfollowBtn.disabled = false; }
            })
            .catch(function () { unfollowBtn.disabled = false; });
    });
</script>
@endpush
