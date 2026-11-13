// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P1-19 (J25-13): passkeys in the browser — sign-in (button + autofill/conditional UI), adding a passkey
 * on the security page, and confirming with a passkey instead of the password. No build step: plain
 * WebAuthn with JSON in and out (the server is laravel/passkeys behind juwri's PasskeyController).
 *
 * Markup hooks (all URLs, the CSRF token and the translated messages come from data attributes):
 *   [data-passkey-login]    data-options-url, data-verify-url, data-csrf, data-msg-failed, data-msg-unsupported
 *   [data-passkey-manage]   data-options-url, data-store-url, data-add-url (owner: password.confirm hop), data-csrf,
 *                           data-msg-failed, data-msg-unsupported, data-msg-name; an optional [data-passkey-password]
 *                           input is sent with the options request (staff re-prove their own password, P1-19b);
 *                           an optional [data-passkey-code] (storefront customers without a password, fresh proof)
 *                           likewise, with [data-passkey-send-code] data-code-url to text the code
 *   [data-passkey-confirm]  data-options-url, data-verify-url, data-csrf, data-msg-failed, data-msg-unsupported
 *   form[data-passkey-remove] asks data-passkey-remove (the question) before it submits
 *
 * Storefront customers (P1-19 customer passkeys) use the same hooks: their sign-in sets data-conditional="off"
 * (no autofill request on every store page view), and a verify answer with `reload` reloads the store page.
 */
(function () {
    'use strict';

    // Loaded once per page even when several partials include it.
    if (window.__juwriPasskeys) { return; }
    window.__juwriPasskeys = true;

    var supported = typeof window.PublicKeyCredential === 'function' && !!navigator.credentials;

    function b64urlToBuffer(value) {
        var s = String(value).replace(/-/g, '+').replace(/_/g, '/');
        while (s.length % 4) { s += '='; }
        var bin = atob(s);
        var bytes = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) { bytes[i] = bin.charCodeAt(i); }
        return bytes.buffer;
    }

    function bufferToB64url(buffer) {
        if (!buffer) { return null; }
        var bytes = new Uint8Array(buffer);
        var s = '';
        for (var i = 0; i < bytes.length; i++) { s += String.fromCharCode(bytes[i]); }
        return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function creationOptions(json) {
        if (typeof PublicKeyCredential.parseCreationOptionsFromJSON === 'function') {
            return PublicKeyCredential.parseCreationOptionsFromJSON(json);
        }
        var o = Object.assign({}, json);
        o.challenge = b64urlToBuffer(o.challenge);
        o.user = Object.assign({}, o.user, { id: b64urlToBuffer(o.user.id) });
        o.excludeCredentials = (o.excludeCredentials || []).map(function (c) {
            return Object.assign({}, c, { id: b64urlToBuffer(c.id) });
        });
        return o;
    }

    function requestOptions(json) {
        if (typeof PublicKeyCredential.parseRequestOptionsFromJSON === 'function') {
            return PublicKeyCredential.parseRequestOptionsFromJSON(json);
        }
        var o = Object.assign({}, json);
        o.challenge = b64urlToBuffer(o.challenge);
        o.allowCredentials = (o.allowCredentials || []).map(function (c) {
            return Object.assign({}, c, { id: b64urlToBuffer(c.id) });
        });
        return o;
    }

    function credentialToJSON(credential) {
        try {
            if (typeof credential.toJSON === 'function') { return credential.toJSON(); }
        } catch (e) { /* fall through to the manual form */ }
        var r = credential.response;
        var out = {
            id: credential.id,
            rawId: bufferToB64url(credential.rawId),
            type: credential.type,
            response: { clientDataJSON: bufferToB64url(r.clientDataJSON) },
            clientExtensionResults: credential.getClientExtensionResults ? credential.getClientExtensionResults() : {}
        };
        if (credential.authenticatorAttachment) { out.authenticatorAttachment = credential.authenticatorAttachment; }
        if (r.attestationObject) {
            out.response.attestationObject = bufferToB64url(r.attestationObject);
            if (typeof r.getTransports === 'function') { out.response.transports = r.getTransports(); }
        } else {
            out.response.authenticatorData = bufferToB64url(r.authenticatorData);
            out.response.signature = bufferToB64url(r.signature);
            if (r.userHandle) { out.response.userHandle = bufferToB64url(r.userHandle); }
        }
        return out;
    }

    async function store(root, url, body) {
        var res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': root.getAttribute('data-csrf') || ''
            },
            body: JSON.stringify(body || {})
        });
        var data = {};
        try { data = await res.json(); } catch (e) { data = {}; }
        return { status: res.status, ok: res.ok, data: data };
    }

    function firstError(data, fallback) {
        if (data && data.errors) {
            var keys = Object.keys(data.errors);
            for (var i = 0; i < keys.length; i++) {
                var v = data.errors[keys[i]];
                if (Array.isArray(v) && v.length) { return v[0]; }
            }
        }
        return (data && data.message) || fallback;
    }

    function showError(root, message) {
        var box = root.querySelector('[data-passkey-error]');
        if (!box) { return; }
        box.textContent = message || '';
        box.hidden = !message;
    }

    function cancelled(err) {
        return err && (err.name === 'AbortError' || err.name === 'NotAllowedError');
    }

    // ── Sign-in ──────────────────────────────────────────────────────────────
    function initLogin(root) {
        var button = root.querySelector('[data-passkey-button]');
        var failed = root.getAttribute('data-msg-failed') || '';
        if (!supported) {
            if (button) { button.hidden = true; }
            return;
        }
        var pending = null;

        async function signIn(conditional) {
            if (pending) { pending.abort(); pending = null; }
            showError(root, '');
            var opts = await post(root, root.getAttribute('data-options-url'), {});
            if (!opts.ok) { if (!conditional) { showError(root, firstError(opts.data, failed)); } return; }
            var request = { publicKey: requestOptions(opts.data.options) };
            if (conditional) {
                pending = new AbortController();
                request.mediation = 'conditional';
                request.signal = pending.signal;
            }
            var credential;
            try {
                credential = await navigator.credentials.get(request);
            } catch (err) {
                if (!cancelled(err) && !conditional) { showError(root, failed); }
                return;
            }
            if (!credential) { return; }
            var remember = document.getElementById('remember');
            var res = await post(root, root.getAttribute('data-verify-url'), {
                credential: credentialToJSON(credential),
                remember: !!(remember && remember.checked)
            });
            if (res.ok && res.data.reload) { window.location.reload(); return; }
            if (res.ok && res.data.redirect) { window.location.assign(res.data.redirect); return; }
            showError(root, firstError(res.data, failed));
            if (res.data && res.data.redirect) { window.setTimeout(function () { window.location.assign(res.data.redirect); }, 2500); }
        }

        if (button) {
            button.hidden = false;
            button.addEventListener('click', function (e) { e.preventDefault(); signIn(false); });
        }
        if (root.getAttribute('data-conditional') !== 'off' && typeof PublicKeyCredential.isConditionalMediationAvailable === 'function') {
            PublicKeyCredential.isConditionalMediationAvailable().then(function (ok) {
                if (ok) { signIn(true); }
            }).catch(function () {});
        }
    }

    // ── Add a passkey (security page) ────────────────────────────────────────
    function initManage(root) {
        var button = root.querySelector('[data-passkey-add]');
        var input = root.querySelector('[data-passkey-name]');
        var failed = root.getAttribute('data-msg-failed') || '';
        if (!button) { return; }
        if (!supported) {
            button.disabled = true;
            showError(root, root.getAttribute('data-msg-unsupported') || '');
            return;
        }
        button.addEventListener('click', async function (e) {
            e.preventDefault();
            showError(root, '');
            var name = input ? input.value.trim() : '';
            if (!name) { showError(root, root.getAttribute('data-msg-name') || failed); if (input) { input.focus(); } return; }
            button.disabled = true;
            try {
                var pw = root.querySelector('[data-passkey-password]');
                var code = root.querySelector('[data-passkey-code]');
                var proof = {};
                if (pw) { proof.password = pw.value; }
                if (code && code.value.trim()) { proof.code = code.value.trim(); }
                var opts = await post(root, root.getAttribute('data-options-url'), proof);
                if (opts.status === 423 && root.getAttribute('data-add-url')) { window.location.assign(root.getAttribute('data-add-url')); return; }
                if (opts.status === 423) {
                    // Customers without a password: a stale sign-in needs a phone code (the field is revealed).
                    var reauth = root.querySelector('[data-passkey-reauth]');
                    if (reauth) { reauth.hidden = false; }
                    showError(root, firstError(opts.data, failed));
                    if (pw) { pw.focus(); } else if (code) { code.focus(); }
                    return;
                }
                if (!opts.ok) { showError(root, firstError(opts.data, failed)); return; }
                var credential;
                try {
                    credential = await navigator.credentials.create({ publicKey: creationOptions(opts.data.options) });
                } catch (err) {
                    if (!cancelled(err)) { showError(root, failed); }
                    return;
                }
                var res = await post(root, root.getAttribute('data-store-url'), { name: name, credential: credentialToJSON(credential) });
                if (res.status === 423) { window.location.assign(root.getAttribute('data-add-url')); return; }
                if (res.ok) { window.location.reload(); return; }
                showError(root, firstError(res.data, failed));
            } finally {
                button.disabled = false;
            }
        });
    }

    // Storefront customers without a password: send a sign-in code to their own phone (fresh proof).
    function initSendCode(button) {
        button.addEventListener('click', async function (e) {
            e.preventDefault();
            var root = button.closest('[data-passkey-manage]');
            if (!root) { return; }
            var status = root.querySelector('[data-passkey-code-status]');
            showError(root, '');
            button.disabled = true;
            try {
                var res = await post(root, button.getAttribute('data-code-url'), {});
                var message = (res.data && res.data.message) || root.getAttribute('data-msg-failed') || '';
                if (res.ok && status) { status.textContent = message; status.hidden = false; } else { showError(root, message); }
                var code = root.querySelector('[data-passkey-code]');
                if (res.ok && code) { code.focus(); }
            } finally {
                button.disabled = false;
            }
        });
    }

    // ── Confirm with a passkey instead of the password ───────────────────────
    function initConfirm(root) {
        var button = root.querySelector('[data-passkey-button]');
        var failed = root.getAttribute('data-msg-failed') || '';
        if (!button) { return; }
        if (!supported) { button.hidden = true; return; }
        button.hidden = false;
        button.addEventListener('click', async function (e) {
            e.preventDefault();
            showError(root, '');
            var opts = await post(root, root.getAttribute('data-options-url'), {});
            if (!opts.ok) { showError(root, firstError(opts.data, failed)); return; }
            var credential;
            try {
                credential = await navigator.credentials.get({ publicKey: requestOptions(opts.data.options) });
            } catch (err) {
                if (!cancelled(err)) { showError(root, failed); }
                return;
            }
            var res = await post(root, root.getAttribute('data-verify-url'), { credential: credentialToJSON(credential) });
            if (res.ok && res.data.redirect) { window.location.assign(res.data.redirect); return; }
            showError(root, firstError(res.data, failed));
        });
    }

    function initRemove(form) {
        form.addEventListener('submit', function (e) {
            if (!window.confirm(form.getAttribute('data-passkey-remove') || '')) { e.preventDefault(); }
        });
    }

    function boot() {
        document.querySelectorAll('form[data-passkey-remove]').forEach(initRemove);
        document.querySelectorAll('[data-passkey-login]').forEach(initLogin);
        document.querySelectorAll('[data-passkey-manage]').forEach(initManage);
        document.querySelectorAll('[data-passkey-confirm]').forEach(initConfirm);
        document.querySelectorAll('[data-passkey-send-code]').forEach(initSendCode);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
