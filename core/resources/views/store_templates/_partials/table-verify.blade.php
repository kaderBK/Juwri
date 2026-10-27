{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    PARITY-RUN T3 — shared dine-in scan gate (verified table QR).

    Lives in the SHARED layout, exactly like the hotel-guest and order-field
    forwarder partials, so all 17+ store_templates inherit it without a per-theme
    edit (the two-tier-nav parity run proved shared partials reach every theme).

    Renders ONLY when:
      • the store's EFFECTIVE mode needs guest-side proof (table_code | sms_otp);
        `off` and `staff_approve` render nothing at all, AND
      • this browser does not already hold a verified session for the table.

    The overlay is a courtesy, not the guard. The real gate is server-side in
    EnsureTableSessionVerified on the order-submit route — a guest who dismisses
    or blocks this markup simply gets refused at checkout instead.

    No-JS: each step is a real <form method="post"> to a real endpoint that
    answers with a redirect. The script below only upgrades them to inline
    submits and re-opens the sheet when the server refuses an order.
--}}
@php
    $__tvMode = null;
    $__tvTable = null;
    $__tvNeeded = false;
    try {
        if (isset($store) && $store) {
            $__tvMode = \Juwri\Modules\Tables\Services\TableSessionService::mode($store->id);
            if (\Juwri\Modules\Tables\Services\TableSessionService::requiresGuestProof((string) $__tvMode)) {
                /* The table the page controller already resolved through the guarded, query-only key lookup
                   (StoreController::scannedTable + TableKeyGuard), passed as $table; never a second, unguarded lookup
                   here. Rooms are not dine-in tables. */
                $__tvTable = (isset($table) && $table instanceof \Illuminate\Database\Eloquent\Model
                    && $table->getAttribute('area_type') !== 'room'
                    && (int) $table->getAttribute('store_id') === (int) $store->id) ? $table : null;
                $__tvNeeded = ! \Juwri\Modules\Tables\Services\TableSessionService::isVerifiedFor(
                    request(),
                    (int) $store->id,
                    $__tvTable ? (string) $__tvTable->key : null,
                    $__tvTable ? (string) $__tvTable->table_no : null
                );
            }
        }
    } catch (\Throwable $e) {
        $__tvNeeded = false;   // verification must never break a menu render
    }
@endphp
@if($__tvNeeded)
@php
    $__tvRef      = $__tvTable ? (string) $__tvTable->key : '';
    $__tvNo       = $__tvTable ? (string) $__tvTable->table_no : '';
    $__tvIsOtp    = $__tvMode === \Juwri\Modules\Tables\Services\TableSessionService::MODE_SMS_OTP;
    $__tvError    = session('table_verify_error');
    $__tvSuccess  = session('table_verify_success');
    /* Open immediately when the guest scanned a table QR (they are sitting down,
       the code is in front of them); otherwise wait until checkout is refused,
       so a passer-by browsing the menu is never blocked by a wall. */
    $__tvAutoOpen = $__tvRef !== '' || $__tvError !== null;
@endphp

<style nonce="{{ csp_nonce() }}">
    .mb-tv-backdrop{
        position:fixed;inset:0;z-index:2147483000;display:none;
        align-items:center;justify-content:center;padding:16px;
        background:rgba(15,23,42,.62);backdrop-filter:blur(2px);
    }
    .mb-tv-backdrop.is-open{display:flex;}
    .mb-tv{
        width:100%;max-width:380px;border-radius:18px;padding:22px 20px;text-align:start;
        background:var(--theme-bg, #fff);color:var(--theme-text, #111);
        font-family:var(--menu-font, inherit);box-shadow:0 24px 60px rgba(0,0,0,.35);
    }
    .mb-tv__ic{
        width:44px;height:44px;border-radius:14px;display:flex;align-items:center;justify-content:center;
        font-size:22px;line-height:1;margin-block-end:12px;
        background:var(--brand, var(--theme-color-1, #2563eb));color:#fff;
    }
    .mb-tv__t{font-weight:800;font-size:1.05rem;display:block;}
    .mb-tv__h{display:block;margin-block-start:6px;font-size:.86rem;opacity:.8;line-height:1.45;}
    .mb-tv__field{
        width:100%;margin-block-start:14px;padding:12px 14px;font:inherit;font-size:1rem;
        color:inherit;background:transparent;border-radius:12px;
        border:1px solid color-mix(in srgb, currentColor 24%, transparent);
    }
    .mb-tv__code{letter-spacing:.5em;text-align:center;font-weight:800;font-size:1.5rem;}
    .mb-tv__btn{
        width:100%;margin-block-start:14px;padding:12px 18px;border:0;border-radius:12px;
        font:inherit;font-weight:700;cursor:pointer;
        background:var(--brand, var(--theme-color-1, #2563eb));color:#fff;
    }
    .mb-tv__btn[disabled]{opacity:.6;cursor:progress;}
    .mb-tv__link{
        display:block;width:100%;margin-block-start:10px;padding:8px;border:0;background:transparent;
        color:inherit;opacity:.7;font:inherit;font-size:.84rem;cursor:pointer;text-align:center;
    }
    .mb-tv__msg{display:block;margin-block-start:10px;font-size:.84rem;min-height:1.1em;}
    .mb-tv__msg.is-bad{color:#dc2626;}
    .mb-tv__msg.is-good{color:#16a34a;}
    [hidden]{display:none !important;}
</style>

{{-- The QR key rides to /send-order through the shared order-field forwarder, so
     the server can scope the session check to the table that was actually
     scanned rather than trusting the typed number alone. --}}
@if($__tvRef !== '')
<input type="hidden" name="table_key" value="{{ $__tvRef }}">
@endif

<div class="mb-tv-backdrop {{ $__tvAutoOpen ? 'is-open' : '' }}" id="mbTvBackdrop" role="dialog" aria-modal="true" aria-labelledby="mbTvTitle">
    <div class="mb-tv">
        <span class="mb-tv__ic" aria-hidden="true">{!! $__tvIsOtp ? '&#128241;' : '&#128274;' !!}</span>
        <span class="mb-tv__t" id="mbTvTitle">
            {{ $__tvIsOtp ? ___('Verify your phone to order') : ___('Verify your table to order') }}
        </span>
        <span class="mb-tv__h">
            @if($__tvIsOtp)
                {{ ___('We send a one-time code by SMS. This keeps fake orders off your table.') }}
            @else
                {{ ___('Enter the code shown on your table.') }}
            @endif
            @if($__tvNo !== '')
                <strong>{{ ___('Table') }} {{ $__tvNo }}</strong>
            @endif
        </span>

        <span class="mb-tv__msg {{ $__tvError ? 'is-bad' : ($__tvSuccess ? 'is-good' : '') }}" id="mbTvMsg" role="status">{{ $__tvError ?: $__tvSuccess }}</span>

        @if($__tvIsOtp)
            {{-- Step 1: phone → SMS --}}
            <form method="post" action="{{ route('table.verify.otp.send', $store->id) }}" id="mbTvOtpSendForm">
                @csrf
                <input type="hidden" name="table" value="{{ $__tvRef !== '' ? $__tvRef : $__tvNo }}" id="mbTvOtpRef">
                @if($__tvRef === '' && $__tvNo === '')
                    <input type="text" class="mb-tv__field" name="table" inputmode="numeric" required
                           placeholder="{{ ___('Table number') }}" aria-label="{{ ___('Table number') }}">
                @endif
                <input type="tel" class="mb-tv__field" name="phone" id="mbTvPhone" required autocomplete="tel"
                       placeholder="{{ ___('Phone number') }}" aria-label="{{ ___('Phone number') }}">
                <button type="submit" class="mb-tv__btn" id="mbTvOtpSend">{{ ___('Send code') }}</button>
            </form>

            {{-- Step 2: the SMS code --}}
            <form method="post" action="{{ route('table.verify.otp.verify', $store->id) }}" id="mbTvOtpVerifyForm">
                @csrf
                <input type="hidden" name="table" value="{{ $__tvRef !== '' ? $__tvRef : $__tvNo }}">
                <input type="hidden" name="phone" value="" id="mbTvPhoneEcho">
                <input type="text" class="mb-tv__field mb-tv__code" name="code" inputmode="numeric" autocomplete="one-time-code"
                       maxlength="8" required placeholder="······" aria-label="{{ ___('Verification code') }}">
                <button type="submit" class="mb-tv__btn">{{ ___('Verify') }}</button>
            </form>
        @else
            <form method="post" action="{{ route('table.verify.code', $store->id) }}" id="mbTvCodeForm">
                @csrf
                @if($__tvRef !== '' || $__tvNo !== '')
                    <input type="hidden" name="table" value="{{ $__tvRef !== '' ? $__tvRef : $__tvNo }}">
                @else
                    <input type="text" class="mb-tv__field" name="table" inputmode="numeric" required
                           placeholder="{{ ___('Table number') }}" aria-label="{{ ___('Table number') }}">
                @endif
                <input type="text" class="mb-tv__field mb-tv__code" name="code" inputmode="numeric" autocomplete="one-time-code"
                       maxlength="8" required placeholder="····" aria-label="{{ ___('Verification code') }}">
                <button type="submit" class="mb-tv__btn">{{ ___('Verify') }}</button>
            </form>
        @endif

        <button type="button" class="mb-tv__link" id="mbTvClose">{{ ___('Browse the menu first') }}</button>
    </div>
</div>

<script nonce="{{ csp_nonce() }}">
(function () {
    var box = document.getElementById('mbTvBackdrop');
    if (!box) return;
    var msg  = document.getElementById('mbTvMsg');
    var L = {
        sending:  @json(___('Sending…')),
        network:  @json(___('Network error. Please try again.')),
        verified: @json(___('Table verified — enjoy your meal.')),
        /* The HTTP throttles in front of these endpoints answer with the stock
           framework body ({"message":"Too Many Attempts."}), which is English no
           matter the guest's locale. Never show it — a diner staring at a lock
           screen deserves their own language. */
        throttled: @json(___('Too many tries. Please wait a moment and try again.')),
    };

    function open()  { box.classList.add('is-open'); }
    function close() { box.classList.remove('is-open'); }
    function say(text, good) {
        if (!msg) return;
        msg.textContent = text || '';
        msg.classList.toggle('is-bad', text && !good);
        msg.classList.toggle('is-good', !!(text && good));
    }

    var closeBtn = document.getElementById('mbTvClose');
    if (closeBtn) closeBtn.addEventListener('click', close);

    /* Step visibility (JS only — without JS both OTP forms are simply shown and
       both work, because each posts to a real endpoint). */
    var sendForm   = document.getElementById('mbTvOtpSendForm');
    var verifyForm = document.getElementById('mbTvOtpVerifyForm');
    if (sendForm && verifyForm) verifyForm.hidden = true;

    function submitInline(form, onOk) {
        if (!form || !window.fetch || !window.FormData) return;
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = form.querySelector('button[type="submit"]');
            if (btn) btn.disabled = true;
            say(L.sending, true);
            fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(form)
            }).then(function (r) {
                return r.json().then(function (j) {
                    /* Our own 429s (the per-table attempt budget) carry a
                       translated message and a `success` key. The HTTP throttles
                       in front of the route do not — they answer with the stock
                       {"message":"Too Many Attempts."}. Only that second shape
                       gets replaced, so the specific message always wins. */
                    if (r.status === 429 && !('success' in j)) {
                        return { success: false, message: L.throttled };
                    }
                    return j;
                }).catch(function () {
                    return { success: false, message: r.status === 429 ? L.throttled : L.network };
                });
            }).then(function (j) {
                say(j.message || '', !!j.success);
                if (j.success && onOk) onOk(j);
            }).catch(function () {
                say(L.network, false);
            }).then(function () {
                if (btn) btn.disabled = false;
            });
        });
    }

    submitInline(sendForm, function () {
        var phone = document.getElementById('mbTvPhone');
        var echo  = document.getElementById('mbTvPhoneEcho');
        if (phone && echo) echo.value = phone.value;
        if (verifyForm) verifyForm.hidden = false;
        if (sendForm)   sendForm.hidden = true;
    });

    function verified() {
        say(L.verified, true);
        /* The session cookie is set by the response; reload so every theme's own
           checkout state is rebuilt against a now-verified session. */
        window.setTimeout(function () { window.location.reload(); }, 700);
    }
    submitInline(verifyForm, verified);
    submitInline(document.getElementById('mbTvCodeForm'), verified);

    /* Re-open when the server refuses an order for want of verification. The
       refusal rides on the /send-order response as `table_verification_required`
       (HTTP 200 + success:false), the same shape the F7 checkout-OTP gate uses,
       so every theme's own bundle already surfaces the message too. */
    function watch(payload) {
        try {
            var j = (typeof payload === 'string') ? JSON.parse(payload) : payload;
            if (j && j.table_verification_required) {
                open();
                if (j.message) say(j.message, false);
            }
        } catch (e) {}
    }
    function isSendOrder(u) { return typeof u === 'string' && u.indexOf('/send-order') !== -1; }

    var of = window.fetch;
    if (of) {
        window.fetch = function (input, init) {
            var u = (typeof input === 'string') ? input : (input && input.url);
            var p = of.apply(this, arguments);
            if (isSendOrder(u)) {
                p.then(function (r) {
                    try { r.clone().json().then(watch).catch(function () {}); } catch (e) {}
                    return r;
                }).catch(function () {});
            }
            return p;
        };
    }
    var op = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method, url) {
        if (isSendOrder(url)) {
            this.addEventListener('load', function () { watch(this.responseText); });
        }
        return op.apply(this, arguments);
    };
})();
</script>
@endif
