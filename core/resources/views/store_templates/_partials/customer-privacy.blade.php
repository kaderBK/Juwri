{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    P6-91: "Your data" on the account page — download everything this store holds about the signed-in customer
    (JSON, or the orders as CSV) and delete the account in THIS store. Both ask for the password, or a code sent to
    the phone of a customer without one. Included by _partials/account-extras (every theme) and aurora's profile.
    Expects $store and $customer. Optional class hooks as in customer-passkeys ($cpkCard, $cpkTitle, $cpkBtn, $cpkField).
--}}
@php
    $__cpvCustomer = $customer ?? null;
    $__cpvOk = isset($store) && $__cpvCustomer instanceof \App\Models\Customer && (int) $__cpvCustomer->store_id === (int) $store->id;
    $__cpvCard = $cpkCard ?? 'acctx-card';
    $__cpvTitle = $cpkTitle ?? 'acctx-h';
    $__cpvBtn = $cpkBtn ?? 'acctx-btn';
    $__cpvField = $cpkField ?? 'acctx-field';
@endphp
@if($__cpvOk)
@once
<style nonce="{{ csp_nonce() }}">
    .cpv [hidden]{display:none!important}
    .cpv-note{margin:0 0 12px;font-size:13.5px;opacity:.8}
    .cpv-row{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px}
    .cpv-msg{margin:8px 0 0;font-size:13px}
    .cpv-err{margin:8px 0 0;font-size:13px;color:#c0392b}
    .cpv-danger{margin-top:18px;padding-top:14px;border-top:1px solid color-mix(in srgb,currentColor 12%,transparent)}
    .cpv .cpv-ghost{font:inherit;font-size:13px;font-weight:700;background:transparent;color:inherit;cursor:pointer;
        border:1px solid color-mix(in srgb,currentColor 30%,transparent);border-radius:999px;padding:6px 14px}
    .cpv .cpv-delete{font:inherit;font-size:13px;font-weight:700;background:#c0392b;color:#fff;cursor:pointer;border:0;border-radius:999px;padding:8px 16px}
</style>
@endonce
<div class="{{ $__cpvCard }} cpv" id="your-data"
     data-privacy
     data-csrf="{{ csrf_token() }}"
     data-export-url="{{ route('customer.privacy.export', $store->id) }}"
     data-delete-url="{{ route('customer.privacy.delete', $store->id) }}"
     data-code-url="{{ route('customer.privacy.code', $store->id) }}"
     data-done-url="{{ route('publicView', $store->slug) }}"
     data-msg-failed="{{ ___('Something went wrong. Please try again.') }}"
     data-msg-confirm="{{ ___('Delete your account in this store? This cannot be undone.') }}">
    <h3 class="{{ $__cpvTitle }}">{{ ___('Your data') }}</h3>
    <p class="cpv-note">{{ ___('Download a copy of everything this store holds about you, or delete your account. Your accounts with other stores are separate and are not affected.') }}</p>

    @if(! empty($__cpvCustomer->password))
        <label class="{{ $__cpvField }}">
            <span>{{ ___('Current password') }}</span>
            <input type="password" autocomplete="current-password" data-privacy-password>
        </label>
    @elseif(! empty($__cpvCustomer->phone))
        <label class="{{ $__cpvField }}">
            <span>{{ ___('Verification code') }}</span>
            <input type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="10" data-privacy-code>
        </label>
        <button type="button" class="cpv-ghost" data-privacy-send-code>{{ ___('Send me a code') }}</button>
    @endif

    <div class="cpv-row" style="margin-top:12px">
        <button type="button" class="{{ $__cpvBtn }}" data-privacy-export="json">{{ ___('Download my data') }}</button>
        <button type="button" class="cpv-ghost" data-privacy-export="csv">{{ ___('Download my orders (CSV)') }}</button>
    </div>
    <p class="cpv-note">{{ ___('You can download your data 3 times a day.') }}</p>

    <div class="cpv-danger">
        <h4 style="margin:0 0 6px">{{ ___('Delete my account') }}</h4>
        <p class="cpv-note">{{ ___('Your profile, addresses, reviews, loyalty points, store credit and saved items are deleted and you are signed out everywhere. The store keeps its sales and tax records of your past orders, without your name, phone number or address.') }}</p>
        <label class="{{ $__cpvField }}">
            <span>{{ ___('Type :word to confirm.', ['word' => 'DELETE']) }}</span>
            <input type="text" autocomplete="off" data-privacy-confirm>
        </label>
        <button type="button" class="cpv-delete" data-privacy-delete>{{ ___('Delete my account') }}</button>
    </div>
    <p class="cpv-msg" data-privacy-status role="status" hidden></p>
    <p class="cpv-err" data-privacy-error role="alert" hidden></p>
</div>
@once
<script nonce="{{ csp_nonce() }}">
(function () {
    document.querySelectorAll('[data-privacy]').forEach(function (box) {
        var q = function (s) { return box.querySelector(s); };
        var show = function (el, text) { el.textContent = text; el.hidden = !text; };
        var err = q('[data-privacy-error]'), status = q('[data-privacy-status]');
        var body = function (extra) {
            var f = new FormData();
            var pw = q('[data-privacy-password]'), code = q('[data-privacy-code]');
            if (pw) f.append('password', pw.value);
            if (code) f.append('code', code.value);
            Object.keys(extra || {}).forEach(function (k) { f.append(k, extra[k]); });
            return f;
        };
        var post = function (url, data) {
            return fetch(url, { method: 'POST', body: data, credentials: 'same-origin',
                headers: { 'X-CSRF-TOKEN': box.dataset.csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        };
        var fail = function (r) {
            return r.json().then(function (j) { show(err, j.message || box.dataset.msgFailed); }, function () { show(err, box.dataset.msgFailed); });
        };
        var send = q('[data-privacy-send-code]');
        if (send) send.addEventListener('click', function () {
            show(err, '');
            post(box.dataset.codeUrl, new FormData()).then(function (r) { return r.json(); })
                .then(function (j) { show(j.success ? status : err, j.message || ''); }, function () { show(err, box.dataset.msgFailed); });
        });
        box.querySelectorAll('[data-privacy-export]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                show(err, ''); show(status, '');
                var format = btn.getAttribute('data-privacy-export');
                post(box.dataset.exportUrl, body({ format: format })).then(function (r) {
                    if (!r.ok) return fail(r);
                    var name = (r.headers.get('Content-Disposition') || '').match(/filename="([^"]+)"/);
                    return r.blob().then(function (b) {
                        var a = document.createElement('a');
                        a.href = URL.createObjectURL(b);
                        a.download = name ? name[1] : ('my-data.' + format);
                        document.body.appendChild(a); a.click(); a.remove();
                        setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
                    });
                }, function () { show(err, box.dataset.msgFailed); });
            });
        });
        q('[data-privacy-delete]').addEventListener('click', function () {
            show(err, '');
            if (!window.confirm(box.dataset.msgConfirm)) return;
            post(box.dataset.deleteUrl, body({ confirm: q('[data-privacy-confirm]').value.trim() })).then(function (r) {
                if (!r.ok) return fail(r);
                return r.json().then(function (j) { show(status, j.message || ''); setTimeout(function () { window.location.href = box.dataset.doneUrl; }, 1500); });
            }, function () { show(err, box.dataset.msgFailed); });
        });
    });
})();
</script>
@endonce
@endif
