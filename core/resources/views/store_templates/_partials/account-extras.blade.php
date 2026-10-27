{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    Shared account-management block for every storefront theme's account page.

    Renders: edit-profile form, notification / marketing / SMS preferences, saved
    address manager (add + remove), and links to the owner's info pages. All plain
    POST forms hitting existing customer.* routes — no per-theme JS.

    Styling is deliberately "chameleon": transparent surfaces, currentColor-derived
    borders and inherited text, so the block reads correctly on any theme in either
    light or dark without knowing that theme's palette. Themes may set
    `--acctx-accent` on a parent to tint the primary buttons; otherwise buttons use
    a legible outline that works on any background.

    Expects: $store, $customer, and (optionally) $infoPages from the account view.
--}}
@php
    $infoPages = $infoPages ?? [];
    // Theme slug drives the self-hosted Leaflet path for the address map picker.
    $acctxTheme = $theme ?? ($postOptions->store_template ?? '');
@endphp

<style nonce="{{ csp_nonce() }}">
    .acctx, .acctx *, .acctx *::before, .acctx *::after { box-sizing: border-box; }
    .acctx { display: flex; flex-direction: column; gap: 16px; margin: 18px 0; font: inherit; }
    .acctx-card {
        border: 1px solid rgba(128,128,128,.24);
        border: 1px solid color-mix(in srgb, currentColor 20%, transparent);
        border-radius: 16px;
        padding: 18px 16px;
        background: color-mix(in srgb, currentColor 3%, transparent);
    }
    .acctx-h {
        margin: 0 0 14px; font-size: 13px; font-weight: 800;
        text-transform: uppercase; letter-spacing: .06em; opacity: .72;
    }
    .acctx-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .acctx-field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
    .acctx-field.acctx-col2 { grid-column: 1 / -1; }
    .acctx-field > span { font-size: 11.5px; font-weight: 700; opacity: .7; }
    /* Ancestor-prefixed so these always beat theme-wide `.theme input {…}`
       rules (equal specificity would otherwise depend on source order). */
    .acctx .acctx-field input {
        width: 100%; font: inherit; font-size: 15px; color: inherit; height: auto; margin: 0;
        padding: 11px 12px; border-radius: 11px;
        background: rgba(128,128,128,.10);
        background: color-mix(in srgb, currentColor 7%, transparent);
        border: 1px solid rgba(128,128,128,.28);
        border: 1px solid color-mix(in srgb, currentColor 22%, transparent);
        -webkit-appearance: none; appearance: none;
    }
    .acctx .acctx-field input:focus {
        outline: none;
        border-color: currentColor;
        box-shadow: 0 0 0 3px color-mix(in srgb, currentColor 12%, transparent);
    }
    .acctx-checks { display: flex; flex-direction: column; gap: 12px; margin-top: 14px; }
    .acctx-check { display: flex; gap: 11px; align-items: center; font-size: 14px; cursor: pointer; }
    .acctx .acctx-check input { width: 18px; height: 18px; flex: none; accent-color: currentColor; cursor: pointer; margin: 0; }
    /* Ancestor-prefixed so these beat theme-wide `.theme button {…}` resets. */
    .acctx .acctx-btn {
        margin-top: 16px; font: inherit; font-size: 14px; font-weight: 800; cursor: pointer;
        padding: 11px 20px; border-radius: 999px; width: auto; min-height: 0;
        background: var(--acctx-accent, currentColor);
        color: var(--acctx-accent-ink, transparent);
        border: 1.5px solid var(--acctx-accent, currentColor);
        -webkit-appearance: none; appearance: none;
    }
    /* Without a theme accent the filled style would be invisible (bg=text color),
       so the default is a bold outline that is legible on any background. */
    .acctx .acctx-btn:not([style]) { background: transparent; color: inherit; }
    .acctx .acctx-btn.ghost {
        background: transparent; color: inherit;
        border: 1.5px solid color-mix(in srgb, currentColor 34%, transparent);
    }
    .acctx .acctx-btn:active { transform: scale(.97); }
    .acctx-addr {
        display: flex; align-items: flex-start; gap: 12px; justify-content: space-between;
        padding: 12px 0; border-bottom: 1px solid color-mix(in srgb, currentColor 12%, transparent);
    }
    .acctx-addr-txt { min-width: 0; }
    .acctx-addr-txt b { font-size: 14px; font-weight: 800; display: block; }
    .acctx-addr-txt small { font-size: 12.5px; opacity: .68; display: block; margin-top: 3px; }
    .acctx .acctx-x {
        flex: none; font: inherit; font-size: 12.5px; font-weight: 700; cursor: pointer;
        padding: 6px 12px; border-radius: 999px; color: inherit; width: auto; min-height: 0; margin: 0;
        background: transparent;
        border: 1px solid color-mix(in srgb, currentColor 30%, transparent);
    }
    .acctx-empty { opacity: .6; font-size: 13.5px; margin: 4px 0 0; }
    /* Inline address edit (native <details>, no JS). */
    .acctx-edit { margin: -2px 0 6px; }
    .acctx .acctx-editlink {
        display: inline-flex; align-items: center; gap: 5px; cursor: pointer; list-style: none;
        font: inherit; font-size: 12.5px; font-weight: 700; opacity: .68; padding: 2px 0;
    }
    .acctx-editlink::-webkit-details-marker { display: none; }
    .acctx-editlink::after { content: "›"; font-size: 15px; line-height: 1; transition: transform .15s ease; }
    [dir="rtl"] .acctx-editlink::after { transform: scaleX(-1); }
    .acctx-edit[open] .acctx-editlink { opacity: 1; }
    .acctx-edit[open] .acctx-editlink::after { transform: rotate(90deg); }
    [dir="rtl"] .acctx-edit[open] .acctx-editlink::after { transform: rotate(90deg) scaleX(-1); }
    .acctx-edit-form {
        margin: 10px 0 4px; padding-top: 12px;
        border-top: 1px solid color-mix(in srgb, currentColor 12%, transparent);
    }
    .acctx-addr-form { margin-top: 16px; }
    /* Address map picker (self-hosted Leaflet, reuses the cart's geo.reverse). */
    .acctx-geo { margin-top: 16px; }
    .acctx .acctx-useloc {
        display: inline-flex; align-items: center; gap: 8px;
        font: inherit; font-size: 13px; font-weight: 700; cursor: pointer;
        padding: 9px 15px; border-radius: 999px; width: auto; min-height: 0; margin: 0;
        color: inherit; background: color-mix(in srgb, currentColor 9%, transparent);
        border: 1px solid color-mix(in srgb, currentColor 26%, transparent);
        -webkit-appearance: none; appearance: none;
    }
    .acctx .acctx-useloc:active { transform: scale(.97); }
    .acctx .acctx-useloc[disabled] { opacity: .55; cursor: default; }
    .acctx-useloc svg { width: 15px; height: 15px; flex: none; }
    .acctx-map {
        height: 220px; margin-top: 12px; border-radius: 14px; overflow: hidden;
        border: 1px solid color-mix(in srgb, currentColor 20%, transparent);
        background: color-mix(in srgb, currentColor 6%, transparent);
    }
    /* Leaflet paints its own tiles/controls; keep the map font neutral so a theme's
       body font doesn't distort the zoom glyphs, and let it fill the frame. */
    .acctx-map .leaflet-container { height: 100%; width: 100%; font-family: sans-serif; background: transparent; }
    .acctx-maphint { font-size: 11.5px; opacity: .62; margin: 8px 2px 0; }
    .acctx-geoattrib { font-size: 10.5px; opacity: .5; margin: 4px 2px 0; }
    .acctx-links { display: flex; flex-direction: column; }
    /* Ancestor-prefixed so these beat theme-wide `.theme a {…}` rules. */
    .acctx .acctx-link {
        display: flex; align-items: center; justify-content: space-between; gap: 12px;
        padding: 13px 2px; text-decoration: none; color: inherit; font-size: 14.5px; font-weight: 600;
        border-bottom: 1px solid color-mix(in srgb, currentColor 12%, transparent);
    }
    .acctx-links .acctx-link:last-child { border-bottom: 0; }
    .acctx-link svg { width: 18px; height: 18px; opacity: .5; flex: none; }
    [dir="rtl"] .acctx-link svg { transform: scaleX(-1); }
    .acctx-langs { display: flex; flex-wrap: wrap; gap: 8px; }
    .acctx .acctx-lang {
        text-decoration: none; color: inherit; font-size: 13.5px; font-weight: 700;
        padding: 8px 14px; border-radius: 999px;
        border: 1px solid color-mix(in srgb, currentColor 26%, transparent);
    }
    .acctx .acctx-lang.is-current {
        background: color-mix(in srgb, currentColor 14%, transparent);
        border-color: currentColor;
    }
    .acctx .acctx-appearance {
        display: flex; align-items: center; justify-content: space-between; gap: 12px;
        width: 100%; padding: 2px 0; margin: 0; cursor: pointer;
        font: inherit; font-size: 14.5px; font-weight: 600; color: inherit;
        background: transparent; border: 0; -webkit-appearance: none; appearance: none;
    }
    .acctx-switch-visual {
        width: 46px; height: 27px; flex: none; border-radius: 999px; position: relative;
        border: 1px solid color-mix(in srgb, currentColor 30%, transparent);
        transition: background .15s ease;
    }
    .acctx-switch-visual::after {
        content: ""; position: absolute; top: 2px; inset-inline-start: 2px;
        width: 21px; height: 21px; border-radius: 50%; background: currentColor; opacity: .55;
        transition: inset-inline-start .15s ease, opacity .15s ease;
    }
    .acctx-appearance[aria-pressed="true"] .acctx-switch-visual { background: color-mix(in srgb, currentColor 20%, transparent); }
    .acctx-appearance[aria-pressed="true"] .acctx-switch-visual::after { inset-inline-start: 21px; opacity: 1; }
    .acctx-flash { border-radius: 12px; padding: 11px 14px; font-size: 13.5px; font-weight: 600; }
    .acctx-flash.ok { background: color-mix(in srgb, #16a34a 16%, transparent); }
    .acctx-flash.err { background: color-mix(in srgb, #dc2626 16%, transparent); }
    @media (max-width: 420px) { .acctx-grid { grid-template-columns: 1fr; } }
</style>

<div class="acctx">
    @if(session('success'))
        <div class="acctx-flash ok">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="acctx-flash err">{{ $errors->first() }}</div>
    @endif

    {{-- Profile + preferences: one form, one Save (updateProfile reads all of it). --}}
    <form class="acctx-card" method="POST" action="{{ route('customer.profile.update', $store->id) }}">
        @csrf
        <h3 class="acctx-h">{{ ___('Profile') }}</h3>
        <div class="acctx-grid">
            <label class="acctx-field">
                <span>{{ ___('Name') }}</span>
                <input type="text" name="name" value="{{ old('name', $customer->name) }}" required>
            </label>
            <label class="acctx-field">
                <span>{{ ___('Phone') }}</span>
                <input type="tel" name="phone" value="{{ old('phone', $customer->phone) }}">
            </label>
            <label class="acctx-field acctx-col2">
                <span>{{ ___('Email') }}</span>
                <input type="email" name="email" value="{{ old('email', $customer->email) }}">
            </label>
        </div>
        <div class="acctx-checks">
            <label class="acctx-check">
                <input type="checkbox" name="notifications_enabled" value="1" @checked(old('notifications_enabled', $customer->notifications_enabled ?? 1))>
                <span>{{ ___('Order updates & notifications') }}</span>
            </label>
            <label class="acctx-check">
                <input type="checkbox" name="marketing_consent" value="1" @checked(old('marketing_consent', $customer->marketing_consent))>
                <span>{{ ___('Offers & news from this restaurant') }}</span>
            </label>
            <label class="acctx-check">
                <input type="checkbox" name="sms_consent" value="1" @checked(old('sms_consent', $customer->sms_consent))>
                <span>{{ ___('WhatsApp & SMS messages') }}</span>
            </label>
        </div>
        <button type="submit" class="acctx-btn">{{ ___('Save profile') }}</button>
    </form>

    {{-- google-suite Lane A (A5) — connected social accounts. Shows the linked
         provider + a disconnect POST (customer.social.unlink). Renders when the
         store offers social sign-in OR the customer already has a linked identity.
         The controller blocks disconnect when it is the only sign-in method. --}}
    @php
        $acctxSocialProviders = app(\Juwri\Modules\Auth\Services\Social\SocialCredentialResolver::class)->enabledProviders($store->id);
        $acctxLinkedProvider = $customer->oauth_provider ?: null;
    @endphp
    @if(!empty($acctxSocialProviders) || $acctxLinkedProvider)
        <div class="acctx-card">
            <h3 class="acctx-h">{{ ___('Connected accounts') }}</h3>
            @if($acctxLinkedProvider)
                <div class="acctx-addr">
                    <div class="acctx-addr-txt">
                        <b>{{ ___('Connected via :provider', ['provider' => ucfirst($acctxLinkedProvider)]) }}</b>
                        <small>{{ ___('You can sign in with this account.') }}</small>
                    </div>
                    <form method="POST" action="{{ route('customer.social.unlink', $store->id) }}">
                        @csrf
                        <button type="submit" class="acctx-x">{{ ___('Disconnect') }}</button>
                    </form>
                </div>
            @else
                <p class="acctx-empty">{{ ___('Not connected. Use a social button on the login screen to link an account for faster sign-in.') }}</p>
            @endif
        </div>
    @endif

    {{-- E4: refer a friend — share the invite link (`?ref=CODE`); a friend's first
         paid order credits you both. Plus a points-expiry note when the store sets
         one. Both are self-contained, no JS. --}}
    @php
        $acctxRefCode = method_exists($customer, 'referralCode') ? $customer->referralCode() : ($customer->referral_code ?? null);
        $acctxShareUrl = ($acctxRefCode && Route::has('publicView')) ? route('publicView', $store->slug).'?ref='.$acctxRefCode : null;
        $acctxExpiryDays = (int) (store_options($store->id, 'loyalty_points_expiry_days', '0') ?: 0);
    @endphp
    @if($acctxShareUrl)
        <div class="acctx-card">
            <h3 class="acctx-h">{{ ___('Invite a friend') }}</h3>
            <p class="acctx-empty" style="opacity:.82;margin:0 0 12px;">{{ ___('Share your link. When a friend places their first order, you both earn points.') }}</p>
            <label class="acctx-field acctx-col2">
                <span>{{ ___('Your invite link') }}</span>
                <input type="text" readonly value="{{ $acctxShareUrl }}" aria-label="{{ ___('Your invite link') }}">
            </label>
            @if($acctxExpiryDays > 0)
                <p class="acctx-empty" style="margin-top:12px;">{{ ___('Note: loyalty points expire :days days after they are earned.', ['days' => $acctxExpiryDays]) }}</p>
            @endif
        </div>
    @endif

    {{-- Saved addresses: list with edit (inline, native <details> — no JS) +
         remove, plus an add form. --}}
    <div class="acctx-card">
        <h3 class="acctx-h">{{ ___('Saved addresses') }}</h3>
        @forelse($customer->addresses as $address)
            <div class="acctx-addr">
                <div class="acctx-addr-txt">
                    <b>{{ $address->label ?: ___('Address') }}@if($address->is_default) · {{ ___('Default') }}@endif</b>
                    <small>{{ trim(collect([$address->line, $address->city, $address->state, $address->zip, $address->country])->filter()->implode(', ')) }}</small>
                </div>
                <form method="POST" action="{{ route('customer.addresses.destroy', [$store->id, $address->id]) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="acctx-x">{{ ___('Remove') }}</button>
                </form>
            </div>
            {{-- Edit reveals in place; coords are preserved (hidden) so editing the
                 text never drops the delivery pin. Re-pinning is done from the add
                 form's map. --}}
            <details class="acctx-edit">
                <summary class="acctx-editlink">{{ ___('Edit address') }}</summary>
                <form class="acctx-edit-form" method="POST" action="{{ route('customer.addresses.update', [$store->id, $address->id]) }}">
                    @csrf
                    @method('PUT')
                    <div class="acctx-grid">
                        <label class="acctx-field">
                            <span>{{ ___('Label') }}</span>
                            <input type="text" name="label" value="{{ old('label', $address->label) }}" placeholder="{{ ___('Home') }}">
                        </label>
                        <label class="acctx-field">
                            <span>{{ ___('City') }}</span>
                            <input type="text" name="city" value="{{ old('city', $address->city) }}">
                        </label>
                        <label class="acctx-field acctx-col2">
                            <span>{{ ___('Address') }}</span>
                            <input type="text" name="line" required value="{{ old('line', $address->line) }}">
                        </label>
                    </div>
                    <input type="hidden" name="lat" value="{{ $address->lat }}">
                    <input type="hidden" name="lng" value="{{ $address->lng }}">
                    <label class="acctx-check" style="margin-top:12px">
                        <input type="checkbox" name="is_default" value="1" @checked($address->is_default)>
                        <span>{{ ___('Default delivery address') }}</span>
                    </label>
                    <button type="submit" class="acctx-btn ghost">{{ ___('Save changes') }}</button>
                </form>
            </details>
        @empty
            <p class="acctx-empty">{{ ___('No saved addresses yet.') }}</p>
        @endforelse

        <form class="acctx-addr-form" method="POST" action="{{ route('customer.addresses.store', $store->id) }}">
            @csrf
            <div class="acctx-grid">
                <label class="acctx-field">
                    <span>{{ ___('Label') }}</span>
                    <input type="text" name="label" placeholder="{{ ___('Home') }}">
                </label>
                <label class="acctx-field">
                    <span>{{ ___('City') }}</span>
                    <input type="text" name="city">
                </label>
                <label class="acctx-field acctx-col2">
                    <span>{{ ___('Address') }}</span>
                    <input type="text" name="line" required placeholder="{{ ___('Street, building, apartment') }}">
                </label>
            </div>

            @if($acctxTheme)
                {{-- Map picker: "use my location" + tap/drag a pin. Coords ride along
                     as name="lat"/"lng" (storeAddress already validates + saves them). --}}
                <div class="acctx-geo" data-acctx-geo>
                    <button type="button" class="acctx-useloc" data-acctx-useloc>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.6"/></svg>
                        {{ ___('Use my location') }}
                    </button>
                    <input type="hidden" name="lat" value="" data-acctx-lat>
                    <input type="hidden" name="lng" value="" data-acctx-lng>
                    <div class="acctx-map" data-acctx-map></div>
                    <p class="acctx-maphint">{{ ___('Tap the map or drag the pin to set your exact delivery point.') }}</p>
                    <p class="acctx-geoattrib" data-acctx-attrib hidden></p>
                </div>
            @endif

            <label class="acctx-check" style="margin-top:12px">
                <input type="checkbox" name="is_default" value="1" checked>
                <span>{{ ___('Default delivery address') }}</span>
            </label>
            <button type="submit" class="acctx-btn ghost">{{ ___('Add address') }}</button>
        </form>
    </div>

    {{-- Appearance (light/dark) — only the themes on the shared mbTheme engine
         (data-theme on the theme root). mbTheme.init is otherwise called from
         theme script.js, which the account page doesn't load, so wire it here. --}}
    @php
        $acctxDarkRoots = ['matcha' => '.matcha', 'vivid' => '.vd', 'orbit' => '.orbit'];
        $acctxDarkRoot = $acctxDarkRoots[$acctxTheme] ?? null;
    @endphp
    @if($acctxDarkRoot)
        <div class="acctx-card">
            <h3 class="acctx-h">{{ ___('Appearance') }}</h3>
            <button type="button" class="acctx-appearance" data-mb-theme-toggle aria-pressed="false">
                <span>{{ ___('Dark mode') }}</span>
                <span class="acctx-switch-visual" aria-hidden="true"></span>
            </button>
        </div>
        <script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
            (function () {
                var ROOT = @json($acctxDarkRoot);
                function go() {
                    if (window.mbTheme) { window.mbTheme.init({ rootSel: ROOT }); }
                    else { setTimeout(go, 50); }
                }
                if (document.readyState !== 'loading') { go(); }
                else { document.addEventListener('DOMContentLoaded', go); }
            })();
        </script>
    @endif

    {{-- Language switcher — plain links, works without the theme's JS. Handles
         both locale modes: session mode uses the `localize` route, URL-prefix
         mode localizes the current URL. Hidden for single-language stores. --}}
    @php
        $acctxSession = Route::has('localize');
        // URL-prefix mode can only switch to locales LaravelLocalization is
        // configured for; a store's active list may include others (a code not
        // in supportedLocales throws in getLocalizedURL). Session mode routes any.
        $acctxSupported = $acctxSession ? null : \LaravelLocalization::getSupportedLocales();
        $acctxLangs = collect(\Juwri\Core\Support\Views\StoreViewHelpers::supportedLanguages(($languages ?? collect())->all(), $acctxSession, $acctxSupported));
    @endphp
    @if($acctxLangs->count() > 1)
        <div class="acctx-card">
            <h3 class="acctx-h">{{ ___('Language') }}</h3>
            <div class="acctx-langs">
                @foreach($acctxLangs as $lang)
                    <a class="acctx-lang {{ get_lang() === $lang->code ? 'is-current' : '' }}"
                       href="{{ $acctxSession ? route('localize', $lang->code) : \LaravelLocalization::getLocalizedURL($lang->code, url()->current()) }}"
                       @if(get_lang() === $lang->code) aria-current="true" @endif>{{ $lang->name }}</a>
                @endforeach
            </div>
        </div>
    @endif

    {{-- P1-19: the customer's passkeys for this store (renders nothing where passkeys are not offered). --}}
    @include('store_templates._partials.customer-passkeys')

    {{-- P6-91: download my data / delete my account (this store only). --}}
    @include('store_templates._partials.customer-privacy')

    {{-- Owner info pages — only rendered for pages that actually have content. --}}
    @if(! empty($infoPages))
        <div class="acctx-card">
            <h3 class="acctx-h">{{ ___('Information') }}</h3>
            <div class="acctx-links">
                @foreach($infoPages as $slug => $label)
                    <a class="acctx-link" href="{{ storefront_localized_link(route('store.infoPage', [$store->id, $slug])) }}">
                        <span>{{ $label }}</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
                    </a>
                @endforeach
            </div>
        </div>
    @endif
</div>

@if($acctxTheme)
    {{-- Self-hosted Leaflet for the address map picker. Same stack the cart uses:
         Leaflet CSS/JS shipped per theme, OSM tiles, backend geo.reverse. Loaded
         here because account/profile pages don't pull in the theme's script.js. --}}
    <link rel="stylesheet" href="{{ asset('assets/vendor/leaflet/leaflet.css') }}">
    @php
        $acctxGeoCfg = [
            'leafletJs'  => asset('assets/vendor/leaflet/leaflet.js'),
            'leafletImg' => asset('assets/vendor/leaflet/images'),
            'geoReverse' => route('geo.reverse'),
        ];
    @endphp
    <script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
        (function () {
            var wrap = document.querySelector('[data-acctx-geo]');
            if (!wrap) return;
            var mapEl   = wrap.querySelector('[data-acctx-map]');
            var useBtn  = wrap.querySelector('[data-acctx-useloc]');
            var latEl   = wrap.querySelector('[data-acctx-lat]');
            var lngEl   = wrap.querySelector('[data-acctx-lng]');
            var attribEl = wrap.querySelector('[data-acctx-attrib]');
            var form    = wrap.closest('form');
            var lineEl  = form ? form.querySelector('input[name="line"]') : null;

            var CFG = @json($acctxGeoCfg);
            var DEFAULT = [24.7136, 46.6753], DEFAULT_ZOOM = 5;
            var map = null, marker = null, icon = null;

            function loadLeaflet(cb) {
                if (window.L) { cb(); return; }
                var s = document.getElementById('acctx-leaflet-js');
                if (s) { s.addEventListener('load', cb); return; }
                s = document.createElement('script');
                s.id = 'acctx-leaflet-js'; s.src = CFG.leafletJs; s.onload = cb;
                document.head.appendChild(s);
            }
            function setCoords(lat, lng) {
                if (latEl) latEl.value = (lat == null ? '' : lat);
                if (lngEl) lngEl.value = (lng == null ? '' : lng);
            }
            function setAttrib(a) { if (attribEl && a) { attribEl.textContent = a; attribEl.hidden = false; } }
            function placeMarker(lat, lng) {
                if (!map) return;
                if (!marker) {
                    marker = L.marker([lat, lng], { draggable: true, icon: icon }).addTo(map);
                    marker.on('dragend', function () { var p = marker.getLatLng(); pick(p.lat, p.lng, true); });
                } else { marker.setLatLng([lat, lng]); }
            }
            // The pin position is the delivery point (coords always follow it);
            // reverse-geocode only supplies the human-readable label.
            function pick(lat, lng, forceLabel) {
                placeMarker(lat, lng);
                setCoords(lat, lng);
                fetch(CFG.geoReverse + '?lat=' + lat + '&lng=' + lng)
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        setAttrib(d && d.attribution);
                        if (d && d.result && d.result.label && lineEl && (forceLabel || !lineEl.value.trim())) {
                            lineEl.value = d.result.label;
                        }
                    }).catch(function () {});
            }

            loadLeaflet(function () {
                if (typeof L === 'undefined' || !mapEl || map) return;
                icon = L.icon({
                    iconUrl: CFG.leafletImg + '/marker-icon.png',
                    iconRetinaUrl: CFG.leafletImg + '/marker-icon-2x.png',
                    shadowUrl: CFG.leafletImg + '/marker-shadow.png',
                    iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41]
                });
                map = L.map(mapEl, { attributionControl: false }).setView(DEFAULT, DEFAULT_ZOOM);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
                L.control.attribution({ prefix: false }).addAttribution('© OpenStreetMap').addTo(map);
                setTimeout(function () { map.invalidateSize(); }, 120);
                map.on('click', function (e) {
                    map.setView(e.latlng, Math.max(map.getZoom(), 15));
                    pick(e.latlng.lat, e.latlng.lng, false);
                });
            });

            useBtn.addEventListener('click', function () {
                if (!navigator.geolocation) return;
                useBtn.disabled = true;
                navigator.geolocation.getCurrentPosition(function (pos) {
                    useBtn.disabled = false;
                    var lat = pos.coords.latitude, lng = pos.coords.longitude;
                    loadLeaflet(function () {
                        if (map) map.setView([lat, lng], 16);
                        pick(lat, lng, true);
                    });
                }, function () { useBtn.disabled = false; }, { enableHighAccuracy: true, timeout: 8000 });
            });
        })();
    </script>
@endif
