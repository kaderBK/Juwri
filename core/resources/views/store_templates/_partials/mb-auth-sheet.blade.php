{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    Shared, theme-skinnable customer auth sheet (login / phone-OTP / register).

    Wires the REAL customer auth backend (CustomerAuthController) that every food
    theme already uses:
      • POST customer.login        {email, password, store_id}
      • POST customer.register     {name, email, phone?, password, password_confirmation, store_id}
      • POST customer.otp.request  {phone, store_id}
      • POST customer.otp.verify   {phone, code, store_id}
    On success the backend regenerates the session; we reload so the storefront
    re-renders as the signed-in customer (order history, favourites sync, the
    account-claim banner — all key off $customer server-side).

    Rendered ONLY when customer_auth_mode !== 'demo'. Demo stores keep their own
    presentational sheet (a super-admin walkthrough tool — no real accounts).

    Skinning: everything is namespaced .mba-* and themed through --mba-* CSS vars,
    so a theme sets a handful of vars on :root (or its root class) to match. Sane
    light/dark-agnostic defaults are provided so an un-skinned include still looks
    intentional.

    Opening: bind any control with `data-mb-auth-open` (this partial wires them),
    or call window.mbAuthOpen() from a theme's existing sign-in handler.
--}}
@php($__mbaRid = (int) $store->id)
<div class="mba-scrim" id="mbaScrim" hidden>
    <div class="mba-sheet" role="dialog" aria-modal="true" aria-labelledby="mbaTitle" tabindex="-1">
        <button type="button" class="mba-x" id="mbaClose" aria-label="{{ ___('Close') }}">&times;</button>
        <h2 class="mba-title" id="mbaTitle">{{ ___('Sign in') }}</h2>

        <div class="mba-tabs" role="tablist">
            <button type="button" class="mba-tab is-on" data-mba-tab="login" role="tab">{{ ___('Login') }}</button>
            <button type="button" class="mba-tab" data-mba-tab="otp" role="tab">{{ ___('Phone') }}</button>
            <button type="button" class="mba-tab" data-mba-tab="register" role="tab">{{ ___('Register') }}</button>
        </div>

        <p class="mba-msg mba-err" id="mbaErr" role="alert" hidden></p>
        <p class="mba-msg mba-ok" id="mbaOk" role="status" hidden></p>

        {{-- Login --}}
        <form class="mba-form" data-mba-form="login" autocomplete="on">
            <input class="mba-in" type="email" name="email" placeholder="{{ ___('Email') }}" autocomplete="email" required>
            <input class="mba-in" type="password" name="password" placeholder="{{ ___('Password') }}" autocomplete="current-password" required>
            <button type="submit" class="mba-btn">{{ ___('Login') }}</button>
            {{-- Real social sign-in (Google/Apple/Facebook). Self-gating: renders
                 nothing unless a provider is configured for this store. --}}
            @include('store_templates.partials.social-login-buttons', ['store' => $store, 'socialContext' => 'login'])
            @include('store_templates.partials.passkey-login', ['store' => $store])
        </form>

        {{-- Phone OTP --}}
        <form class="mba-form" data-mba-form="otp" hidden autocomplete="on">
            <div data-mba-otp-step="1">
                <p class="mba-sub">{{ ___("We'll text you a one-time code") }}</p>
                <input class="mba-in" type="tel" name="phone" placeholder="{{ ___('Phone number') }}" inputmode="tel" autocomplete="tel">
                <button type="button" class="mba-btn" data-mba-otp-send>{{ ___('Send code') }}</button>
            </div>
            <div data-mba-otp-step="2" hidden>
                <p class="mba-sub">{{ ___('Enter the code we sent you') }}</p>
                <input class="mba-in mba-code" type="text" name="code" placeholder="______" inputmode="numeric" autocomplete="one-time-code" maxlength="6">
                <button type="submit" class="mba-btn">{{ ___('Verify') }}</button>
            </div>
        </form>

        {{-- Register --}}
        <form class="mba-form" data-mba-form="register" hidden autocomplete="on">
            <input class="mba-in" type="text" name="name" placeholder="{{ ___('Your name') }}" autocomplete="name" required>
            <input class="mba-in" type="email" name="email" placeholder="{{ ___('Email') }}" autocomplete="email" required>
            <input class="mba-in" type="tel" name="phone" placeholder="{{ ___('Phone number (optional)') }}" inputmode="tel" autocomplete="tel">
            <input class="mba-in" type="password" name="password" placeholder="{{ ___('Choose a password') }}" autocomplete="new-password" required>
            <button type="submit" class="mba-btn">{{ ___('Create account') }}</button>
            @include('store_templates.partials.social-login-buttons', ['store' => $store, 'socialContext' => 'register'])
        </form>
    </div>
</div>

@include('store_templates._partials.mb-sheet-base')

<script nonce="{{ csp_nonce() }}">
(function () {
    var scrim = document.getElementById('mbaScrim');
    if (!scrim) return;
    var cfg = {
        rid: {{ $__mbaRid }},
        csrf: @json(csrf_token()),
        routes: {
            login:    @json(route('customer.login', $store->id)),
            register: @json(route('customer.register', $store->id)),
            otpReq:   @json(route('customer.otp.request', $store->id)),
            otpVer:   @json(route('customer.otp.verify', $store->id))
        },
        t: {
            working: @json(___('Please wait…')),
            fill:    @json(___('Please fill in every field.')),
            sent:    @json(___('Code sent — check your phone.')),
            ok:      @json(___('Signed in — reloading…')),
            created: @json(___('Account created — reloading…')),
            err:     @json(___('Something went wrong. Please try again.'))
        }
    };
    var sheet = scrim.querySelector('.mba-sheet');
    var errEl = document.getElementById('mbaErr');
    var okEl  = document.getElementById('mbaOk');
    var lastFocus = null;

    function clearMsg(){ errEl.hidden = true; okEl.hidden = true; }
    function showErr(m){ errEl.textContent = m || cfg.t.err; errEl.hidden = false; okEl.hidden = true; }
    function showOk(m){ okEl.textContent = m; okEl.hidden = false; errEl.hidden = true; }

    function open(){
        clearMsg();
        lastFocus = document.activeElement;
        scrim.hidden = false;
        requestAnimationFrame(function(){ scrim.classList.add('is-open'); });
        sheet.focus();
        document.addEventListener('keydown', onKey);
    }
    function close(){
        scrim.classList.remove('is-open');
        document.removeEventListener('keydown', onKey);
        setTimeout(function(){ scrim.hidden = true; }, 200);
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }
    function onKey(e){ if (e.key === 'Escape') close(); }

    // expose + bind openers
    window.mbAuthOpen = open;
    window.mbAuthClose = close;
    document.addEventListener('click', function (e) {
        var o = e.target.closest('[data-mb-auth-open]');
        if (o) { e.preventDefault(); open(); }
    });
    document.getElementById('mbaClose').addEventListener('click', close);
    scrim.addEventListener('click', function (e){ if (e.target === scrim) close(); });

    // tabs
    var tabs = scrim.querySelectorAll('.mba-tab');
    var forms = scrim.querySelectorAll('.mba-form');
    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            clearMsg();
            tabs.forEach(function (x){ x.classList.toggle('is-on', x === t); });
            var want = t.getAttribute('data-mba-tab');
            forms.forEach(function (f){ f.hidden = f.getAttribute('data-mba-form') !== want; });
        });
    });

    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                       'X-CSRF-TOKEN': cfg.csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json().then(function (j){ return { ok: r.ok, b: j || {} }; })
            .catch(function(){ return { ok: r.ok, b: {} }; }); });
    }
    function busy(btn, on){ if (!btn) return; btn.disabled = on; btn.dataset.mbaLabel = btn.dataset.mbaLabel || btn.textContent;
        btn.textContent = on ? cfg.t.working : btn.dataset.mbaLabel; }

    // login
    scrim.querySelector('[data-mba-form="login"]').addEventListener('submit', function (e) {
        e.preventDefault(); clearMsg();
        var f = e.currentTarget, btn = f.querySelector('.mba-btn');
        var email = f.email.value.trim(), pw = f.password.value;
        if (!email || !pw) { showErr(cfg.t.fill); return; }
        busy(btn, true);
        post(cfg.routes.login, { email: email, password: pw, store_id: cfg.rid })
            .then(function (r) { busy(btn, false);
                if (r.ok && r.b.success) { showOk(cfg.t.ok); setTimeout(function(){ location.reload(); }, 500); }
                else showErr(r.b.message); })
            .catch(function () { busy(btn, false); showErr(); });
    });

    // register
    scrim.querySelector('[data-mba-form="register"]').addEventListener('submit', function (e) {
        e.preventDefault(); clearMsg();
        var f = e.currentTarget, btn = f.querySelector('.mba-btn');
        var name = f.name.value.trim(), email = f.email.value.trim(), phone = f.phone.value.trim(), pw = f.password.value;
        if (!name || !email || !pw) { showErr(cfg.t.fill); return; }
        busy(btn, true);
        post(cfg.routes.register, { name: name, email: email, phone: phone, password: pw, password_confirmation: pw, store_id: cfg.rid })
            .then(function (r) { busy(btn, false);
                if (r.ok && r.b.success) { showOk(cfg.t.created); setTimeout(function(){ location.reload(); }, 600); }
                else showErr(r.b.message); })
            .catch(function () { busy(btn, false); showErr(); });
    });

    // otp
    var otpForm = scrim.querySelector('[data-mba-form="otp"]');
    var step1 = otpForm.querySelector('[data-mba-otp-step="1"]');
    var step2 = otpForm.querySelector('[data-mba-otp-step="2"]');
    otpForm.querySelector('[data-mba-otp-send]').addEventListener('click', function (e) {
        clearMsg();
        var btn = e.currentTarget, phone = otpForm.phone.value.trim();
        if (!phone) { showErr(cfg.t.fill); return; }
        busy(btn, true);
        post(cfg.routes.otpReq, { phone: phone, store_id: cfg.rid })
            .then(function (r) { busy(btn, false);
                if (r.ok && r.b.success) { showOk(cfg.t.sent); step1.hidden = true; step2.hidden = false; otpForm.code.focus(); }
                else showErr(r.b.message); })
            .catch(function () { busy(btn, false); showErr(); });
    });
    otpForm.addEventListener('submit', function (e) {
        e.preventDefault(); clearMsg();
        var btn = step2.querySelector('.mba-btn'), phone = otpForm.phone.value.trim(), code = otpForm.code.value.trim();
        if (!code) { showErr(cfg.t.fill); return; }
        busy(btn, true);
        post(cfg.routes.otpVer, { phone: phone, code: code, store_id: cfg.rid })
            .then(function (r) { busy(btn, false);
                if (r.ok && r.b.success) { showOk(cfg.t.ok); setTimeout(function(){ location.reload(); }, 500); }
                else showErr(r.b.message); })
            .catch(function () { busy(btn, false); showErr(); });
    });
})();
</script>
