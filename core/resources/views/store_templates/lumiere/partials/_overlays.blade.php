{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- First-run onboarding + presentational sign-in.

     Progressive enhancement: every overlay ships `hidden` in the markup, so a
     no-JS visitor (or a crawler) lands straight on the real home screen —
     lm-boot.js only reveals these for a first-time GUEST visit (never for an
     already-authenticated $customer), and remembers "seen" in localStorage so
     it never nags a returning visitor. The sign-in step is presentational
     only, per spec: it closes the overlay, it does not call the real phone/
     OTP endpoints (those exist — customer.otp.request/verify — but wiring a
     full OTP flow is a backend-integration decision outside this views-only
     pass). --}}
@php
    $lmObPreview = $items->filter(fn ($m) => $m->image && $m->image !== 'default.png')->take(5)->values();
@endphp
<div class="lm-overlay" id="lmOb1" hidden>
    <div class="lm-ob-card">
    <div class="lm-ob-hero">
        <div class="lm-cluster" aria-hidden="true">
            @for($i = 0; $i < 5; $i++)
                @if($i === 2)
                    <div class="lm-bub">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M12 2c4 5 8 9.5 8 14a8 8 0 01-16 0c0-4.5 4-9 8-14z"/></svg>
                    </div>
                @elseif($lmObPreview->get($i))
                    <div class="lm-bub">@if((string) ($jsItems[$lmObPreview[$i]->id]['img']) !== '')<img src="{{ $jsItems[$lmObPreview[$i]->id]['img'] }}" alt="" data-csp-error="hide">@endif</div>
                @else
                    <div class="lm-bub"></div>
                @endif
            @endfor
        </div>
    </div>
    <div class="lm-ob-body">
        <h2>{{ ___('AI, always here for your skin') }}</h2>
        <p>{{ ___('A companion that reads your skin like an expert — personalized routines, powered by AI.') }}</p>
        <div class="lm-ob-foot">
            <div class="lm-dots-nav"><i class="on"></i><i></i></div>
            <button type="button" class="lm-ob-arrow" data-ov-next="lmOb2" aria-label="{{ ___('Next') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17L17 7M9 7h8v8"/></svg>
            </button>
        </div>
    </div>
    <button type="button" class="lm-ov-skip" data-ov-done>{{ ___('Skip') }}</button>
    </div>
</div>

<div class="lm-overlay" id="lmOb2" hidden>
    <div class="lm-ob-card">
    <div class="lm-ob-hero">
        <div class="lm-cluster" aria-hidden="true">
            @for($i = 0; $i < 5; $i++)
                @if($i === 2)
                    <div class="lm-bub">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M12 2c4 5 8 9.5 8 14a8 8 0 01-16 0c0-4.5 4-9 8-14z"/></svg>
                    </div>
                @elseif($lmObPreview->get(4 - $i))
                    <div class="lm-bub">@if((string) ($jsItems[$lmObPreview[4 - $i]->id]['img']) !== '')<img src="{{ $jsItems[$lmObPreview[4 - $i]->id]['img'] }}" alt="" data-csp-error="hide">@endif</div>
                @else
                    <div class="lm-bub"></div>
                @endif
            @endfor
        </div>
    </div>
    <div class="lm-ob-body">
        <h2>{{ ___('Scan, learn, glow') }}</h2>
        <p>{{ ___('One face scan unlocks tailored product picks and a routine that actually fits you.') }}</p>
        <div class="lm-ob-foot">
            <div class="lm-dots-nav"><i></i><i class="on"></i></div>
            <button type="button" class="lm-ob-arrow" data-ov-next="lmAuth" aria-label="{{ ___('Next') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17L17 7M9 7h8v8"/></svg>
            </button>
        </div>
    </div>
    <button type="button" class="lm-ov-skip" data-ov-done>{{ ___('Skip') }}</button>
    </div>
</div>

@if(($postOptions->customer_auth_mode ?? '') === 'demo')
{{-- Demo stores only: presentational sign-in for super-admin walkthroughs.
     Real stores render the wired sheet (_partials/mb-auth-sheet) instead — the
     lumiere sign-in triggers route to it via openOverlay('lmAuth'). --}}
<div class="lm-overlay lm-auth" id="lmAuth" hidden>
    <div class="lm-ob-card">
    <button type="button" class="lm-ov-skip" data-ov-done>{{ ___('Skip') }}</button>
    <h2>{{ ___('Welcome to :store', ['store' => $store->title]) }}</h2>
    <p class="lm-lead">{{ ___('Enter your phone number to get started.') }}</p>
    <div class="lm-social">
        <button type="button" aria-label="Apple" disabled title="{{ ___('Demo only') }}">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M16 13c0-3 2.4-3.6 2.5-3.7-1.4-2-3.5-2.3-4.2-2.3-1.8-.2-3.5 1-4.4 1-.9 0-2.3-1-3.8-1C4.2 7 2.5 8.4 1.6 10.5c-1.9 3.3-.5 8.2 1.4 10.9.9 1.3 2 2.8 3.4 2.7 1.4-.1 1.9-.9 3.5-.9 1.6 0 2.1.9 3.5.9 1.5 0 2.4-1.3 3.3-2.6.6-.9.9-1.4 1.4-2.4-3.6-1.4-3.5-5.5-1.5-6.1zM13.5 5.2c.8-.9 1.3-2.2 1.1-3.5-1.1.1-2.4.8-3.2 1.7-.7.8-1.3 2.1-1.1 3.3 1.2.1 2.4-.6 3.2-1.5z"/></svg>
        </button>
        <button type="button" aria-label="Google" disabled title="{{ ___('Demo only') }}">
            <svg viewBox="0 0 24 24"><path fill="#4285F4" d="M21.6 12.2c0-.7-.1-1.4-.2-2H12v3.8h5.4a4.6 4.6 0 01-2 3v2.5h3.2c1.9-1.7 3-4.3 3-7.3z"/><path fill="#34A853" d="M12 22c2.7 0 5-.9 6.6-2.4l-3.2-2.5c-.9.6-2 .9-3.4.9-2.6 0-4.8-1.7-5.6-4.1H3.1v2.6A10 10 0 0012 22z"/><path fill="#FBBC05" d="M6.4 13.9a6 6 0 010-3.8V7.5H3.1a10 10 0 000 9z"/><path fill="#EA4335" d="M12 6.1c1.5 0 2.8.5 3.8 1.5l2.8-2.8A10 10 0 003.1 7.5l3.3 2.6C7.2 7.7 9.4 6.1 12 6.1z"/></svg>
        </button>
        <button type="button" aria-label="Facebook" disabled title="{{ ___('Demo only') }}">
            <svg viewBox="0 0 24 24" fill="#1877F2"><path d="M22 12a10 10 0 10-11.6 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.5h-1.2c-1.2 0-1.6.8-1.6 1.5V12h2.7l-.4 2.9h-2.3v7A10 10 0 0022 12z"/></svg>
        </button>
    </div>
    <div class="lm-divider">{{ ___('or continue with') }}</div>
    <div class="lm-field">
        <label for="lmPhoneField">{{ ___('Phone number') }}</label>
        <input type="tel" id="lmPhoneField" placeholder="+963 999 000 111" autocomplete="tel">
    </div>
    <button type="button" class="lm-btn lm-btn-dark lm-btn-block" data-ov-done>{{ ___('Continue') }}</button>
    <p class="lm-authswitch">{{ ___('This is a presentational sign-in — no account is created.') }}</p>
    </div>
</div>
@else
    {{-- Real customer auth (login / phone-OTP / register), skinned to lumiere. --}}
    @include('store_templates._partials.mb-auth-sheet', ['store' => $store])
    @if(auth('customer')->check() && \App\Services\FeatureAccess::storeAllows($store->id, 'customer_wallet'))
        @include('store_templates._partials.mb-wallet-sheet', ['store' => $store])
    @endif
@endif
