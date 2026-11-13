{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- GOL E1 (rev/guest-order-linking): post-checkout "create an account to track
     all your orders" prompt on the order track page — where the guest lands and
     lingers (the success screen auto-redirects here after ~3s).

     Shown ONLY to a guest (not a logged-in customer) viewing a real order. The
     inline register form is prefilled from the order and posts to
     customer.register; because the track page is the same session that placed
     the order, a successful register claims that guest order (A3 adopt / claim),
     and the shared banner surfaces the count on the next load. Self-contained
     like mk-rating-prompt: inline styles + nonce'd JS, no per-theme CSS/JS. --}}
@php
    $golShowPrompt = isset($order) && ! auth('customer')->check();
@endphp
@if($golShowPrompt)
<div id="golSignupPrompt" dir="auto"
     style="margin:16px auto 0;max-width:420px;padding:18px;border:1px solid rgba(128,128,128,.28);border-radius:16px;">
    <p style="margin:0 0 4px;font-size:15px;font-weight:800;">{{ ___('Save this order to an account') }}</p>
    <p style="margin:0 0 12px;font-size:13px;opacity:.7;">{{ ___('Create an account to track all your orders in one place.') }}</p>

    <form id="golSignupForm" style="display:flex;flex-direction:column;gap:8px;">
        <input id="golSignupName" type="text" autocomplete="name" placeholder="{{ ___('Your name') }}"
               value="{{ $order->customer_name }}"
               style="padding:11px 12px;border:1px solid rgba(128,128,128,.35);border-radius:10px;font-size:14px;background:transparent;color:inherit;">
        <input id="golSignupEmail" type="email" autocomplete="email" placeholder="{{ ___('Email') }}"
               style="padding:11px 12px;border:1px solid rgba(128,128,128,.35);border-radius:10px;font-size:14px;background:transparent;color:inherit;">
        <input id="golSignupPassword" type="password" autocomplete="new-password" placeholder="{{ ___('Choose a password') }}"
               style="padding:11px 12px;border:1px solid rgba(128,128,128,.35);border-radius:10px;font-size:14px;background:transparent;color:inherit;">
        <p id="golSignupError" role="alert" hidden style="margin:0;font-size:13px;color:#dc2626;"></p>
        <p id="golSignupOk" role="status" hidden style="margin:0;font-size:13px;font-weight:700;color:#16a34a;"></p>
        <button id="golSignupSubmit" type="submit"
                style="padding:12px 16px;border:0;border-radius:10px;background:#111;color:#fff;font-size:14px;font-weight:700;cursor:pointer;">
            {{ ___('Create account') }}
        </button>
    </form>
</div>
<script nonce="{{ csp_nonce() }}">
(function () {
    "use strict";
    var form = document.getElementById('golSignupForm');
    if (!form) { return; }
    var wrap = document.getElementById('golSignupPrompt');
    var errEl = document.getElementById('golSignupError');
    var okEl = document.getElementById('golSignupOk');
    var submit = document.getElementById('golSignupSubmit');
    var cfg = {
        url: @json(route('customer.register', $order->store_id)),
        rid: @json((int) $order->store_id),
        phone: @json((string) ($order->phone_number ?? '')),
        csrf: @json(csrf_token()),
        working: @json(___('Creating…')),
        create: @json(___('Create account'))
    };
    function show(el, msg) { el.textContent = msg; el.hidden = false; }
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        errEl.hidden = true; okEl.hidden = true;
        var name = document.getElementById('golSignupName').value.trim();
        var email = document.getElementById('golSignupEmail').value.trim();
        var pw = document.getElementById('golSignupPassword').value;
        if (!name || !email || !pw) { show(errEl, @json(___('Please fill in every field.'))); return; }
        submit.disabled = true; submit.textContent = cfg.working;
        fetch(cfg.url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ name: name, email: email, phone: cfg.phone, password: pw, password_confirmation: pw, store_id: cfg.rid })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
        .then(function (res) {
            submit.disabled = false; submit.textContent = cfg.create;
            var b = res.body || {};
            if (res.ok && b.success && b.pending_verification) {
                form.hidden = true; show(okEl, b.message || @json(___('Check your inbox to finish setting up your account.')));
            } else if (res.ok && b.success) {
                form.hidden = true;
                var n = parseInt(b.claimed_count || 0, 10);
                show(okEl, n > 0
                    ? @json(___('Account created — your order was saved.'))
                    : @json(___('Account created.')));
            } else {
                show(errEl, b.message || @json(___('Something went wrong. Please try again.')));
            }
        }).catch(function () {
            submit.disabled = false; submit.textContent = cfg.create;
            show(errEl, @json(___('Something went wrong. Please try again.')));
        });
    });
})();
</script>
@endif
