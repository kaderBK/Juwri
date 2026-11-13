{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    F1 — shared checkout-OTP gate (verified phone before order placement).

    Lives in the SHARED layout like the order-field-forwarder / table-verify
    partials, so all ~40 store_templates inherit it without a per-theme edit.
    Only ~8 modern themes ship a native send→verify UI that reacts to the
    server's `otp_required` flag; on the other ~30 (old-2-theme, yellow-theme,
    show-theme, …) an order rejected by the gate showed a raw error string and
    the diner could NEVER place the order. This partial closes that dead-end.

    Renders ONLY when the store has turned the gate on
    (CheckoutOtpController::gateEnabled), so it is completely inert on every
    store that never enabled phone verification.

    Flow: the store's compiled bundle POSTs the order to {store}/send-order. When
    the phone is unverified the server answers 200 with
    {success:false, otp_required:true, phone:…}. This partial watches that
    response (fetch + XHR, same technique as table-verify), opens its own modal,
    sends a code via checkout.otp.send, verifies via checkout.otp.verify, then
    RE-SENDS the captured original order request — which now passes the gate —
    and forwards the diner to the returned track_url. No bundle dependency.

    Stands down when the active theme already ships a native OTP control, so the
    ~8 themes with their own flow are untouched (DOM detection, never an
    allow-list).
--}}
@if(\Juwri\Modules\Ordering\Http\Controllers\CheckoutOtpController::gateEnabled($store->id))
<style nonce="{{ csp_nonce() }}">
    .mbotp-backdrop{
        position:fixed;inset:0;z-index:2147483200;display:none;
        align-items:center;justify-content:center;padding:16px;
        background:rgba(15,23,42,.62);backdrop-filter:blur(2px);
    }
    .mbotp-backdrop.is-open{display:flex;}
    .mbotp{
        width:100%;max-width:380px;border-radius:18px;padding:22px 20px;text-align:start;
        background:var(--theme-bg, #fff);color:var(--theme-text, #111);
        font-family:var(--menu-font, inherit);box-shadow:0 24px 60px rgba(0,0,0,.35);
    }
    .mbotp__ic{
        width:44px;height:44px;border-radius:14px;display:flex;align-items:center;justify-content:center;
        font-size:22px;line-height:1;margin-block-end:12px;
        background:var(--brand, var(--theme-color-1, #2563eb));color:#fff;
    }
    .mbotp__t{font-weight:800;font-size:1.05rem;display:block;}
    .mbotp__h{display:block;margin-block-start:6px;font-size:.86rem;opacity:.8;line-height:1.45;}
    .mbotp__field{
        width:100%;margin-block-start:14px;padding:12px 14px;font:inherit;font-size:1rem;
        color:inherit;background:transparent;border-radius:12px;
        border:1px solid color-mix(in srgb, currentColor 24%, transparent);
    }
    .mbotp__field[readonly]{opacity:.75;}
    .mbotp__code{letter-spacing:.5em;text-align:center;font-weight:800;font-size:1.5rem;}
    .mbotp__btn{
        width:100%;margin-block-start:14px;padding:12px 18px;border:0;border-radius:12px;
        font:inherit;font-weight:700;cursor:pointer;
        background:var(--brand, var(--theme-color-1, #2563eb));color:#fff;
    }
    .mbotp__btn[disabled]{opacity:.6;cursor:progress;}
    .mbotp__link{
        display:block;width:100%;margin-block-start:10px;padding:8px;border:0;background:transparent;
        color:inherit;opacity:.7;font:inherit;font-size:.84rem;cursor:pointer;text-align:center;
    }
    .mbotp__msg{display:block;margin-block-start:10px;font-size:.84rem;min-height:1.1em;}
    .mbotp__msg.is-bad{color:#dc2626;}
    .mbotp__msg.is-good{color:#16a34a;}
    .mbotp [hidden]{display:none !important;}
</style>

<div class="mbotp-backdrop" id="mbOtpBackdrop" role="dialog" aria-modal="true" aria-labelledby="mbOtpTitle">
    <div class="mbotp">
        <span class="mbotp__ic" aria-hidden="true">&#128241;</span>
        <span class="mbotp__t" id="mbOtpTitle">{{ ___('Verify your phone to order') }}</span>
        <span class="mbotp__h">{{ ___('We send a one-time code by SMS to confirm your number before placing the order.') }}</span>

        <span class="mbotp__msg" id="mbOtpMsg" role="status"></span>

        {{-- Step 1: phone (prefilled + read-only so it always matches the order's
             phone) → send SMS. --}}
        <div id="mbOtpSendStep">
            <input type="tel" class="mbotp__field" id="mbOtpPhone" readonly autocomplete="tel"
                   aria-label="{{ ___('Phone number') }}">
            <button type="button" class="mbotp__btn" id="mbOtpSend">{{ ___('Send code') }}</button>
        </div>

        {{-- Step 2: the SMS code → verify → re-submit the order. --}}
        <div id="mbOtpVerifyStep" hidden>
            <input type="text" class="mbotp__field mbotp__code" id="mbOtpCode" inputmode="numeric"
                   autocomplete="one-time-code" maxlength="8" placeholder="······"
                   aria-label="{{ ___('Verification code') }}">
            <button type="button" class="mbotp__btn" id="mbOtpVerify">{{ ___('Verify & place order') }}</button>
        </div>

        <button type="button" class="mbotp__link" id="mbOtpClose">{{ ___('Cancel') }}</button>
    </div>
</div>

<script nonce="{{ csp_nonce() }}">
(function () {
    /* Stand down when the theme ships its own OTP flow (the 8 modern themes),
       so we never double-prompt. DOM detection, never a theme allow-list.

       Two bugs lived in the original one-line check here:
        1. it ran at PARSE time, and this partial sits above the content yield —
           the theme's own markup is not in the DOM yet, so it matched nothing
           on every theme, aurora included;
        2. the selector only described aurora's form. The other seven name a
           gate SHEET, not a form.
       Together they meant crave, matcha, glow, aurora, vivid, feast, velvet and
       dough opened their own sheet AND this modal on the same rejected order.
       Detection now runs when we are about to open, against the full page. */
    var NATIVE_SEL = '[data-otp], #au-otp-form, [id$="-otp-form"], .otp-step,'
        + ' [id*="checkoutotp" i], [id*="CheckoutOtp"], #v-otp-drawer, [data-view="otp"]';
    function themeHasNativeOtp() { return !!document.querySelector(NATIVE_SEL); }

    var box = document.getElementById('mbOtpBackdrop');
    if (!box) return;

    var msg      = document.getElementById('mbOtpMsg');
    var phoneEl  = document.getElementById('mbOtpPhone');
    var codeEl   = document.getElementById('mbOtpCode');
    var sendStep = document.getElementById('mbOtpSendStep');
    var verStep  = document.getElementById('mbOtpVerifyStep');
    var sendBtn  = document.getElementById('mbOtpSend');
    var verBtn   = document.getElementById('mbOtpVerify');
    var closeBtn = document.getElementById('mbOtpClose');

    var SEND_URL   = @json(route('checkout.otp.send'));
    var VERIFY_URL = @json(route('checkout.otp.verify'));
    var STORE_ID   = @json((int) $store->id);
    var CSRF       = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    var L = {
        sending:  @json(___('Sending…')),
        placing:  @json(___('Placing your order…')),
        network:  @json(___('Network error. Please try again.')),
        throttled:@json(___('Too many tries. Please wait a moment and try again.')),
        needcode: @json(___('Enter the code we sent you.')),
        /* The /checkout/otp/* routes carry no locale segment, so their JSON
           messages come back in the store's default language even when the
           diner is browsing /en/… — an English page showed an Arabic
           confirmation. These two outcomes are known, so say them in the page's
           own locale and keep the server string only for the cases we can't
           name (throttle/cooldown detail). */
        sent:     @json(___('We sent you a verification code.')),
        badcode:  @json(___('That code is invalid or has expired.'))
    };

    var pendingReplay = null;   // re-issues the captured /send-order request
    var opened = false;

    function open()  { box.classList.add('is-open'); opened = true; }
    function close() { box.classList.remove('is-open'); }
    function say(text, good) {
        if (!msg) return;
        msg.textContent = text || '';
        msg.classList.toggle('is-bad', !!(text && !good));
        msg.classList.toggle('is-good', !!(text && good));
    }
    function parseJson(t) { try { return JSON.parse(t); } catch (e) { return null; } }

    function post(url, data) {
        var body = new FormData();
        Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': CSRF },
            body: body
        }).then(function (r) {
            return r.json().then(function (j) {
                if (r.status === 429 && j && !('success' in j)) return { success: false, message: L.throttled };
                return j || {};
            }).catch(function () {
                return { success: false, message: r.status === 429 ? L.throttled : L.network };
            });
        });
    }

    /* Watch the /send-order response: an otp_required rejection opens the modal
       with the phone the server normalized (kept read-only so verify + the
       replayed order carry the exact same number). */
    /* The gate rejection is a normal {success:false, message} to the theme, so a
       theme with no native OTP flow paints it in its checkout error box — the
       diner reads "Please verify your phone number to place this order." behind
       our modal, which is exactly what the modal is already doing. Blank the
       nodes whose whole text IS that message (leaf nodes only, so we never wipe
       a container that holds other checkout copy) once we take over. */
    function clearServerNotice(message) {
        if (!message) return;
        var want = String(message).trim();
        if (!want) return;
        var all = document.querySelectorAll('body *');
        for (var i = 0; i < all.length; i++) {
            var el = all[i];
            if (el.children.length || box.contains(el)) continue;
            if ((el.textContent || '').trim() !== want) continue;
            el.textContent = '';
            el.hidden = true;
        }
    }

    function watch(payload) {
        var j = (typeof payload === 'string') ? parseJson(payload) : payload;
        if (j && j.otp_required) {
            if (themeHasNativeOtp()) return;   // the theme is handling this one
            if (phoneEl && j.phone) phoneEl.value = j.phone;
            if (verStep) verStep.hidden = true;
            if (sendStep) sendStep.hidden = false;
            say('');
            open();
            /* The theme writes its error box from the same response, so run
               after its own handler has had the microtask + a repaint. */
            setTimeout(function () { clearServerNotice(j.message); }, 0);
            setTimeout(function () { clearServerNotice(j.message); }, 400);
        }
    }

    function isSendOrder(u) { return typeof u === 'string' && u.indexOf('/send-order') !== -1; }

    /* Capture + watch fetch. */
    var of = window.fetch;
    if (of) {
        window.fetch = function (input, init) {
            var u = (typeof input === 'string') ? input : (input && input.url);
            var m = (init && init.method) || (input && input.method) || 'GET';
            var isOrder = String(m).toUpperCase() === 'POST' && isSendOrder(u);
            var p = of.apply(this, arguments);
            if (isOrder && !(init && init.__mbReplay)) {
                pendingReplay = function () {
                    return of.call(window, input, init).then(function (r) { return r.json(); }).catch(function () { return null; });
                };
                p.then(function (r) {
                    try { r.clone().json().then(watch).catch(function () {}); } catch (e) {}
                    return r;
                }).catch(function () {});
            }
            return p;
        };
    }

    /* Capture + watch XHR (jQuery $.ajax etc.). */
    var Xopen = XMLHttpRequest.prototype.open,
        Xsend = XMLHttpRequest.prototype.send,
        Xhdr  = XMLHttpRequest.prototype.setRequestHeader;
    XMLHttpRequest.prototype.open = function (method, url) {
        this.__mbOrder = (String(method).toUpperCase() === 'POST' && isSendOrder(url));
        this.__mbMethod = method; this.__mbUrl = url; this.__mbHeaders = {};
        return Xopen.apply(this, arguments);
    };
    XMLHttpRequest.prototype.setRequestHeader = function (k, v) {
        if (this.__mbOrder && this.__mbHeaders) this.__mbHeaders[k] = v;
        return Xhdr.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function (body) {
        var self = this;
        if (this.__mbOrder && !this.__mbReplay) {
            pendingReplay = function () {
                return new Promise(function (resolve) {
                    var x = new XMLHttpRequest();
                    x.__mbReplay = true;
                    x.open(self.__mbMethod, self.__mbUrl);
                    Object.keys(self.__mbHeaders).forEach(function (k) {
                        try { x.setRequestHeader(k, self.__mbHeaders[k]); } catch (e) {}
                    });
                    x.addEventListener('load', function () { resolve(parseJson(x.responseText)); });
                    x.addEventListener('error', function () { resolve(null); });
                    x.send(body);
                });
            };
            this.addEventListener('load', function () { watch(self.responseText); });
        }
        return Xsend.apply(this, arguments);
    };

    /* Step 1: send the code. */
    if (sendBtn) sendBtn.addEventListener('click', function () {
        var phone = phoneEl ? phoneEl.value : '';
        if (!phone) return;
        sendBtn.disabled = true;
        say(L.sending, true);
        post(SEND_URL, { phone: phone, store_id: STORE_ID }).then(function (j) {
            say(j.success ? L.sent : (j.message || ''), !!j.success);
            if (j.success) {
                if (sendStep) sendStep.hidden = true;
                if (verStep) verStep.hidden = false;
                if (codeEl) codeEl.focus();
            }
        }).then(function () { sendBtn.disabled = false; });
    });

    /* Step 2: verify, then re-issue the captured order so the gate now passes. */
    if (verBtn) verBtn.addEventListener('click', function () {
        var phone = phoneEl ? phoneEl.value : '';
        var code = codeEl ? codeEl.value : '';
        if (!code) { say(L.needcode, false); return; }
        verBtn.disabled = true;
        say(L.sending, true);
        post(VERIFY_URL, { phone: phone, code: code }).then(function (j) {
            if (!j.success) { say(L.badcode, false); verBtn.disabled = false; return; }
            say(L.placing, true);
            if (!pendingReplay) { window.location.reload(); return; }
            pendingReplay().then(function (res) {
                if (res && res.track_url) { window.location.href = res.track_url; return; }
                /* Order placed (or theme handles it) — reload so the theme's own
                   confirmation/cart state rebuilds against the verified session. */
                window.location.reload();
            }).catch(function () { window.location.reload(); });
        });
    });

    if (closeBtn) closeBtn.addEventListener('click', close);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && opened) close();
    });
})();
</script>
@endif
