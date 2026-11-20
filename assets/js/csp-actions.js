/* SPDX-License-Identifier: AGPL-3.0-only */
/* Copyright (c) JUWRI LTD */
/*
 * P6-115: CSP-safe replacement for inline on*= handlers on non-theme pages
 * (order hub, marketplace, app store, driver + staff PWAs, POS, feedback).
 * The enforced policy has no 'unsafe-inline' for script attributes, so
 * onclick="..." silently does nothing. Markup now names a page function:
 *
 *   data-act="fn"        click -> window.fn(...args)
 *   data-change="fn"     change -> window.fn(...args)
 *   data-args='[1,"x"]'  JSON arguments (optional)
 *   data-ev              prepend the event to the arguments
 *   data-this            prepend the element to the arguments
 *
 * and, for images, a small allow-list (no code in markup is evaluated):
 *   data-csp-error="hide|remove|visibility|replace-ph|fallback" (+ data-csp-fallback="url")
 *   <form data-confirm="msg"> (confirm before submit), data-csp-focus="unlock"
 * Capture listeners are installed as early as possible, and a DOMContentLoaded
 * sweep catches images that failed before the listener existed.
 */
(function () {
    'use strict';

    // fix-xss M1: only these page handlers are reachable from markup. A free-form window lookup let injected
    // markup call eval / Function / fetch / location.assign / open (the dashboard CSP allows 'unsafe-eval').
    // A new data-act / data-change / data-input / data-search handler must be added here
    // (tests/Architecture/CspActionsAllowlistTest fails on a name the markup uses that is missing).
    var ALLOWED = [
        // order hub, staff PWA, POS, kitchen
        'accept', 'reject', 'advance', 'requestBill', 'transferOrder', 'toggle', 'playVoice', 'addItem', 'floorTap',
        'kdsStep', 'toggleTask', 'wAck', 'wOpenAdd', 'wFire', 'wSplit', 'wMerge', 'wAddItem', 'logCheck', 'logIncident',
        // driver PWAs
        'saveToken', 'switchTab', 'claim', 'setStatus', 'requestOtp', 'verifyOtp', 'showStep', 'setShift', 'go', 'logout',
        'acceptOffer', 'rejectOffer', 'openOrderById', 'sendPin', 'sendPhoto', 'markFailed',
        // dispatch
        'reassign', 'unassign', 'redispatch',
        // marketplace, app store, feedback, partner portal
        'facetChanged', 'submitBrowse', 'pickShot', 'pick', 'billingToggle', 'mkCityPicker.open',
        // helpers defined below
        'clickById', 'historyBackOr', 'openFromDataset'
    ];
    var ALLOW = Object.create(null);
    for (var a = 0; a < ALLOWED.length; a++) ALLOW[ALLOWED[a]] = true;

    function call(attr, el, e) {
        var name = el.getAttribute(attr);
        if (!name || !Object.prototype.hasOwnProperty.call(ALLOW, name)) return;
        // dotted names (window.mkCityPicker.open) keep their receiver; own properties only
        var parts = name.split('.'), owner = window, fn = window;
        for (var i = 0; i < parts.length && fn; i++) {
            if (!Object.prototype.hasOwnProperty.call(fn, parts[i])) return;
            owner = fn; fn = fn[parts[i]];
        }
        if (typeof fn !== 'function') return;
        var args = [];
        try { args = el.dataset.args ? JSON.parse(el.dataset.args) : []; } catch (x) { args = []; }
        if (el.hasAttribute('data-this')) args.unshift(el);
        if (el.hasAttribute('data-ev')) args.unshift(e);
        fn.apply(parts.length > 1 ? owner : el, args);
    }

    function delegate(type, attr, capture) {
        document.addEventListener(type, function (e) {
            var t = e.target;
            var el = t && t.closest ? t.closest('[' + attr + ']') : null;
            if (el) call(attr, el, e);
        }, !!capture);
    }

    function imgError(img) {
        var mode = img.getAttribute('data-csp-error');
        if (!mode) return;
        if (mode === 'hide') img.style.display = 'none';
        else if (mode === 'visibility') img.style.visibility = 'hidden';
        else if (mode === 'remove') img.remove();
        else if (mode === 'fallback') {
            var fb = img.getAttribute('data-csp-fallback');
            if (fb && img.getAttribute('src') !== fb) img.setAttribute('src', fb);
        }
        else if (mode === 'replace-ph' && img.parentNode) {
            var d = document.createElement('div');
            d.className = 'ph';
            d.textContent = '\u{1F37D}';
            img.replaceWith(d);
        }
    }

    window.submitBrowse = function () { var f = document.getElementById('browse'); if (f) f.submit(); };
    window.clickById = function (id) { var el = document.getElementById(id); if (el) el.click(); };

    // fix-xss M1: markup-supplied URLs are followed only when they resolve to http(s) (no javascript:/data:).
    function safeUrl(url) {
        try { var u = new URL(String(url), location.href); return (u.protocol === 'https:' || u.protocol === 'http:') ? u.href : null; } catch (x) { return null; }
    }
    window.historyBackOr = function (url) { if (history.length > 1) { history.back(); return; } var u = safeUrl(url); if (u) location.assign(u); };
    window.openFromDataset = function (el, key) { var u = safeUrl(el && el.dataset ? el.dataset[key] : ''); if (u) window.open(u, '_blank', 'noopener'); };

    // <form data-confirm="Are you sure?"> replaces onsubmit="return confirm(...)"
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (f && f.getAttribute && f.hasAttribute('data-confirm') && !window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
    }, true);
    // <input data-csp-focus="unlock"> replaces onfocus="this.removeAttribute('readonly')"
    document.addEventListener('focus', function (e) {
        if (e.target && e.target.getAttribute && e.target.getAttribute('data-csp-focus') === 'unlock') e.target.removeAttribute('readonly');
    }, true);

    delegate('click', 'data-act');
    delegate('change', 'data-change');
    delegate('input', 'data-input');
    delegate('search', 'data-search');

    document.addEventListener('error', function (e) {
        if (e.target && e.target.getAttribute && e.target.hasAttribute('data-csp-error')) imgError(e.target);
    }, true);

    document.addEventListener('DOMContentLoaded', function () {
        var imgs = document.querySelectorAll('img[data-csp-error]');
        for (var i = 0; i < imgs.length; i++) {
            if (imgs[i].complete && imgs[i].getAttribute('src') && imgs[i].naturalWidth === 0) imgError(imgs[i]);
        }
    });
})();
