// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Reseller partner panel runtime (reseller-parity C1; on the owner-ui kit since
 * WI-16r — no Bootstrap: confirm + palette are kit <dialog class="ui-sheet">,
 * toasts go through window.ownerUi.toast, pop-overs are [data-ui-pop]).
 *
 * The reseller portal rides the DashboardPolicy CSP (script-src-attr 'none'), so
 * inline on*= handlers are dead the moment CSP_ENFORCE_DASHBOARD is on. Every
 * handler in the reseller views is expressed as a data-attribute and handled
 * here once, by delegation:
 *
 *   form[data-confirm="…"]            panel confirm dialog before submit (C7; native
 *   a|button[data-confirm="…"]        confirm() only when <dialog> is unsupported)
 *                                     data-confirm-ok="label", data-confirm-danger="false"
 *   form (non-GET)                    submit buttons disabled + spinner while the
 *                                     request is in flight (C7); data-loading="Saving…"
 *                                     swaps the label; data-no-loading opts out
 *   [data-copy="text"] / [data-copy-target="#el"]  copy to clipboard + toast (C7)
 *   input|select[data-autosubmit]     submit the owning form on change
 *   [data-submit-form="formId"]       click submits <form id="formId">
 *   input[data-live-text="#sel"]      mirror value into #sel textContent
 *                                     (falls back to data-live-fallback when empty)
 *   input[data-live-bg="#sel"]        mirror value into #sel background
 *   a[href="#"][role="button"]        default navigation suppressed
 *
 * C2 topbar: [data-rp-bell] notifications dropdown (feed / mark read / read-all)
 * and [data-rp-search] Cmd/Ctrl-K palette (sidebar pages + GET customer search).
 *
 * Values are only ever written via textContent / style (never as markup) so a
 * brand name containing markup or quotes stays inert (the old inline handler put
 * it inside a JS string literal: stored XSS).
 *
 * resellerPanel() is the Alpine root factory for <body x-data>; it must be defined
 * before Alpine boots, so this file loads (non-deferred) ahead of alpine.min.js.
 */
(function () {
    'use strict';

    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }

    function target(sel) {
        if (!sel) { return null; }
        try { return document.querySelector(sel); } catch (e) { return null; }
    }

    var RP = window.RESELLER_PANEL = window.RESELLER_PANEL || {};
    RP.csrf = csrf();

    // C7: translated runtime strings (panel ships <script type="application/json" id="rp-i18n">).
    var I18N = null;
    function t(key, fallback) {
        if (I18N === null) {
            I18N = {};
            var node = document.getElementById('rp-i18n');
            if (node) { try { I18N = JSON.parse(node.textContent || '{}') || {}; } catch (e) { I18N = {}; } }
        }
        return I18N[key] || fallback;
    }
    RP.t = t;

    // ── toasts (plain DOM; Alpine's toast() delegates here) ─────────────────
    function toast(msg, type) {
        if (!msg) { return; }
        if (window.ownerUi && typeof window.ownerUi.toast === 'function') {
            window.ownerUi.toast(String(msg), { tone: type === 'error' ? 'danger' : 'success' });
            return;
        }
        var wrap = document.querySelector('[data-rp-toasts]');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.className = 'rp-toast-wrap';
            wrap.setAttribute('data-rp-toasts', '');
            wrap.setAttribute('role', 'status');
            wrap.setAttribute('aria-live', 'polite');
            document.body.appendChild(wrap);
        }
        var n = document.createElement('div');
        n.className = 'rp-toast' + (type === 'error' ? ' err' : '');
        if (type === 'error') { n.setAttribute('role', 'alert'); }
        n.textContent = String(msg);
        wrap.appendChild(n);
        setTimeout(function () { if (n.parentNode) { n.parentNode.removeChild(n); } }, type === 'error' ? 6000 : 4000);
    }
    RP.toast = toast;

    window.resellerPanel = function () {
        return {
            toast: function (msg, type) { toast(msg, type); },
        };
    };

    // ── clipboard: [data-copy="text"] or [data-copy-target="#input"] ──────────
    function copyToClipboard(text) {
        text = String(text === undefined || text === null ? '' : text);
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            document.body.removeChild(ta);
            if (ok) { resolve(); } else { reject(new Error('copy failed')); }
        });
    }
    RP.copyToClipboard = copyToClipboard;
    window.copyToClipboard = copyToClipboard;

    // ── fetch JSON with normalised errors (builder + future callers) ───────
    // Resolves {ok, status, data}; never rejects on HTTP errors — only on network failure.
    RP.fetchJson = function (url, opts) {
        opts = opts || {};
        var headers = { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' };
        if (opts.body !== undefined && typeof opts.body !== 'string') {
            headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(opts.body);
        }
        return fetch(url, { method: opts.method || 'GET', headers: headers, body: opts.body, credentials: 'same-origin' })
            .then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (data) {
                    return { ok: r.ok, status: r.status, data: data || {} };
                });
            });
    };
    // First human message out of a Laravel JSON error ({message} / {errors:{field:[...]}}).
    RP.errorMessage = function (res) {
        var d = (res && res.data) || {};
        if (d.errors && typeof d.errors === 'object') {
            for (var k in d.errors) {
                if (Object.prototype.hasOwnProperty.call(d.errors, k)) {
                    var v = d.errors[k];
                    if (Array.isArray(v) && v.length) { return String(v[0]); }
                    if (typeof v === 'string') { return v; }
                }
            }
        }
        if (d.message && typeof d.message === 'string') { return d.message; }
        if (res && res.status === 419) { return t('expired', 'Your session expired. Reload the page and try again.'); }
        if (res && res.status === 429) { return t('throttled', 'Too many attempts. Please wait a moment and try again.'); }
        return t('error', 'Something went wrong. Please try again.');
    };
    RP.networkError = function () { return t('network', 'Network error. Check your connection and try again.'); };

    // Disable a button while an async action runs; resolves with the action's result.
    RP.busy = function (btn, fn) {
        if (btn && btn.disabled) { return Promise.resolve(null); }
        if (btn) { setLoading(btn, true); }
        var done = function () { if (btn) { setLoading(btn, false); } };
        return Promise.resolve().then(fn).then(function (v) { done(); return v; }, function (err) { done(); throw err; });
    };

    // ── confirm dialog (replaces native confirm(); falls back to it without <dialog>) ──
    function confirmModal(msg, opts) {
        opts = opts || {};
        var dlg = document.getElementById('rpConfirmModal');
        if (!dlg || typeof dlg.showModal !== 'function') {
            return Promise.resolve(window.confirm(msg));
        }
        return new Promise(function (resolve) {
            var body = dlg.querySelector('[data-rp-confirm-msg]');
            var ok = dlg.querySelector('[data-rp-confirm-ok]');
            var cancel = dlg.querySelector('[data-rp-confirm-cancel]');
            var answered = false;
            body.textContent = String(msg);
            ok.textContent = opts.ok || ok.getAttribute('data-default') || 'OK';
            ok.className = 'ui-btn ' + (opts.danger === false ? 'ui-btn--dark' : 'ui-btn--danger');
            function done(value) {
                if (answered) { return; }
                answered = true;
                ok.removeEventListener('click', onOk);
                cancel.removeEventListener('click', onCancel);
                dlg.removeEventListener('cancel', onCancel);
                dlg.removeEventListener('click', onBackdrop);
                try { dlg.close(); } catch (e) { /* already closed */ }
                resolve(value);
            }
            function onOk() { done(true); }
            function onCancel(e) { if (e) { e.preventDefault(); } done(false); }
            function onBackdrop(e) { if (e.target === dlg) { done(false); } }
            ok.addEventListener('click', onOk);
            cancel.addEventListener('click', onCancel);
            dlg.addEventListener('cancel', onCancel);
            dlg.addEventListener('click', onBackdrop);
            dlg.showModal();
            ok.focus();
        });
    }
    RP.confirm = confirmModal;

    function confirmOpts(node) {
        var o = {};
        if (node.getAttribute('data-confirm-ok')) { o.ok = node.getAttribute('data-confirm-ok'); }
        if (node.getAttribute('data-confirm-danger') === 'false') { o.danger = false; }
        return o;
    }

    // ── submit loading state (C7: every non-GET form; opt out with data-no-loading) ──
    function setLoading(btn, on) {
        if (on) {
            if (btn.getAttribute('data-rp-busy') === '1') { return; }
            btn.setAttribute('data-rp-busy', '1');
            btn.setAttribute('aria-busy', 'true');
            btn.disabled = true;
            var label = btn.getAttribute('data-loading');
            if (label && btn.tagName === 'BUTTON') {
                btn.setAttribute('data-rp-label', btn.textContent);
                btn.textContent = label;
            }
            if (btn.tagName === 'BUTTON') {
                var sp = document.createElement('span');
                sp.className = 'ui-btn__spinner';
                sp.setAttribute('aria-hidden', 'true');
                sp.setAttribute('data-rp-spinner', '');
                btn.insertBefore(sp, btn.firstChild);
            }
        } else {
            if (btn.getAttribute('data-rp-busy') !== '1') { return; }
            btn.removeAttribute('data-rp-busy');
            btn.removeAttribute('aria-busy');
            btn.disabled = false;
            var sp2 = btn.querySelector('[data-rp-spinner]');
            if (sp2) { sp2.parentNode.removeChild(sp2); }
            if (btn.hasAttribute('data-rp-label')) {
                btn.textContent = btn.getAttribute('data-rp-label');
                btn.removeAttribute('data-rp-label');
            }
        }
    }
    RP.setLoading = setLoading;

    function submitButtons(form) {
        var list = Array.prototype.slice.call(form.querySelectorAll('button:not([type]), button[type="submit"], input[type="submit"]'));
        if (form.id) {
            document.querySelectorAll('[form="' + form.id + '"]').forEach(function (b) {
                if (list.indexOf(b) === -1 && (b.type === 'submit')) { list.push(b); }
            });
        }
        return list;
    }

    function wantsLoading(form) {
        if (form.hasAttribute('data-no-loading')) { return false; }
        if (form.hasAttribute('data-loading')) { return true; }
        var target = form.getAttribute('target');
        if (target && target !== '_self') { return false; } // new tab / download: page stays
        return (form.getAttribute('method') || 'get').toLowerCase() !== 'get';
    }

    // Confirm before submitting a form (capture: runs before anything else).
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement)) { return; }
        var msg = form.getAttribute('data-confirm');
        if (!msg) { return; }
        if (form.__rpConfirmed) { form.__rpConfirmed = false; return; }
        e.preventDefault();
        e.stopImmediatePropagation();
        var submitter = e.submitter || null;
        confirmModal(msg, confirmOpts(form)).then(function (ok) {
            if (!ok) { return; }
            form.__rpConfirmed = true;
            if (typeof form.requestSubmit === 'function') {
                try { form.requestSubmit(submitter && submitter.form === form ? submitter : undefined); return; } catch (err) { /* fall through */ }
            }
            form.__rpConfirmed = false;
            form.submit();
        });
    }, true);

    // Disable submit buttons once the submission really goes ahead (bubble: after
    // confirm / Alpine @submit.prevent had their say). Deferred a tick so the
    // submitter's name=value still makes it into the form data set.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement) || e.defaultPrevented || !wantsLoading(form)) { return; }
        var submitter = e.submitter || null;
        setTimeout(function () {
            submitButtons(form).forEach(function (b) {
                if (b === submitter) { setLoading(b, true); } else { b.disabled = true; b.setAttribute('data-rp-busy', '1'); }
            });
            // Safety valve: a download or a server that never answers must not strand the form.
            setTimeout(function () { submitButtons(form).forEach(function (b) { setLoading(b, false); b.disabled = false; b.removeAttribute('data-rp-busy'); }); }, 15000);
        }, 0);
    });

    // Back/forward cache restores the page with buttons still disabled.
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) { return; }
        document.querySelectorAll('[data-rp-busy="1"]').forEach(function (b) { setLoading(b, false); b.disabled = false; b.removeAttribute('data-rp-busy'); });
    });

    document.addEventListener('click', function (e) {
        var el = e.target instanceof Element ? e.target : null;
        if (!el) { return; }

        // Confirm on a link/button (not forms — handled on submit above).
        var c = el.closest('a[data-confirm], button[data-confirm]');
        if (c) {
            if (c.__rpConfirmed) {
                c.__rpConfirmed = false;
            } else {
                e.preventDefault();
                e.stopImmediatePropagation();
                confirmModal(c.getAttribute('data-confirm'), confirmOpts(c)).then(function (ok) {
                    if (!ok) { return; }
                    c.__rpConfirmed = true;
                    c.click();
                });
                return;
            }
        }

        var cp = el.closest('[data-copy], [data-copy-target]');
        if (cp) {
            e.preventDefault();
            var text = cp.getAttribute('data-copy');
            var src = target(cp.getAttribute('data-copy-target'));
            if (src) { text = ('value' in src && src.value !== undefined) ? src.value : src.textContent; }
            copyToClipboard(text).then(function () {
                toast(cp.getAttribute('data-copied') || t('copied', 'Copied'));
            }, function () {
                toast(t('copyFailed', 'Could not copy — select the text and copy it manually.'), 'error');
            });
            return;
        }

        var s = el.closest('[data-submit-form]');
        if (s) {
            e.preventDefault();
            var f = document.getElementById(s.getAttribute('data-submit-form'));
            if (f) {
                if (typeof f.requestSubmit === 'function') { f.requestSubmit(); } else { f.submit(); }
            }
            return;
        }

        var noop = el.closest('a[href="#"][role="button"]');
        if (noop) { e.preventDefault(); }
    });

    document.addEventListener('change', function (e) {
        var el = e.target;
        if (el && el.hasAttribute && el.hasAttribute('data-autosubmit') && el.form) {
            el.form.submit();
        }
    });

    document.addEventListener('input', function (e) {
        var el = e.target;
        if (!el || !el.getAttribute) { return; }
        var t = target(el.getAttribute('data-live-text'));
        if (t) { t.textContent = el.value || el.getAttribute('data-live-fallback') || ''; }
        var b = target(el.getAttribute('data-live-bg'));
        if (b && /^#[0-9a-fA-F]{3,8}$/.test(el.value)) { b.style.background = el.value; }
    });
    // ── helpers ──────────────────────────────────────────────────────────────
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = String(text); }
        return n;
    }

    function getJson(url) {
        return fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); });
    }

    function postJson(url) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        }).then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); });
    }

    // ── notifications bell ──────────────────────────────────────────────────
    function initBell(root) {
        var list = root.querySelector('[data-rp-bell-list]');
        var count = root.querySelector('[data-rp-bell-count]');

        function setCount(n) {
            if (!count) { return; }
            n = parseInt(n, 10) || 0;
            count.textContent = n > 99 ? '99+' : String(n);
            count.hidden = n === 0;
        }

        function render(data) {
            list.textContent = '';
            setCount(data.unread);
            var items = data.items || [];
            if (!items.length) {
                list.appendChild(el('p', 'ui-pop__empty', root.getAttribute('data-empty')));
                return;
            }
            items.forEach(function (it) {
                var a = el('a', 'ui-pop__item rp-note' + (it.read ? '' : ' is-unread'));
                a.href = it.url || '#';
                a.setAttribute('data-rp-note-id', it.id);
                var text = el('span', 'rp-note__text');
                text.appendChild(el('span', 'ui-pop__label', it.title));
                if (it.body) { text.appendChild(el('span', 'rp-note__body', it.body)); }
                a.appendChild(text);
                a.appendChild(el('span', 'ui-pop__meta', it.ago || ''));
                list.appendChild(a);
            });
        }

        function load() {
            getJson(root.getAttribute('data-feed')).then(render).catch(function () {
                list.textContent = '';
                list.appendChild(el('p', 'ui-pop__empty ui-text-danger', root.getAttribute('data-error')));
            });
        }

        // Load the feed whenever the pop-over opens ([data-ui-pop-toggle] click).
        var toggle = root.querySelector('[data-ui-pop-toggle]');
        if (toggle) {
            toggle.addEventListener('click', function () {
                setTimeout(function () {
                    var panel = root.querySelector('[data-ui-pop-panel]');
                    if (panel && !panel.hidden) { load(); }
                }, 0);
            });
        }

        list.addEventListener('click', function (e) {
            var a = e.target instanceof Element ? e.target.closest('[data-rp-note-id]') : null;
            if (!a) { return; }
            e.preventDefault();
            var url = root.getAttribute('data-read-url').replace('__ID__', a.getAttribute('data-rp-note-id'));
            postJson(url).then(function (res) { window.location.href = res.url || a.href; })
                .catch(function () { window.location.href = a.href; });
        });

        var all = root.querySelector('[data-rp-bell-readall]');
        if (all) {
            all.addEventListener('click', function () {
                all.disabled = true;
                postJson(root.getAttribute('data-read-all')).then(load).catch(function () {}).then(function () { all.disabled = false; });
            });
        }
    }

    // ── Cmd-K palette ───────────────────────────────────────────────────────
    function initSearch(modalEl) { // modalEl: <dialog data-rp-search>
        var input = modalEl.querySelector('[data-rp-search-input]');
        var out = modalEl.querySelector('[data-rp-search-results]');
        var endpoint = modalEl.getAttribute('data-endpoint');
        var timer = null;
        var seq = 0;

        function pages() {
            var seen = {};
            var res = [];
            document.querySelectorAll('#appMenubar a.ui-side__link[href]').forEach(function (a) {
                var href = a.getAttribute('href');
                if (!href || href === '#' || href.charAt(0) === '#' || seen[href]) { return; }
                var label = (a.textContent || '').replace(/\s+/g, ' ').trim();
                if (!label) { return; }
                seen[href] = true;
                res.push({ label: label, url: href });
            });
            return res;
        }

        function section(title, rows) {
            if (!rows.length) { return; }
            out.appendChild(el('p', 'rp-search__group', title));
            rows.forEach(function (r) {
                var a = el('a', 'rp-search__item');
                a.href = r.url;
                a.setAttribute('role', 'option');
                a.appendChild(el('span', 'rp-search__label', r.label));
                if (r.sub) { a.appendChild(el('span', 'rp-search__sub', r.sub)); }
                out.appendChild(a);
            });
        }

        function run() {
            var q = (input.value || '').trim().toLowerCase();
            var mine = ++seq;
            var pg = pages().filter(function (p) { return !q || p.label.toLowerCase().indexOf(q) !== -1; }).slice(0, 8);
            out.textContent = '';
            section(modalEl.getAttribute('data-l-pages'), pg);
            if (q.length < 2) {
                if (!pg.length) { out.appendChild(el('p', 'rp-search__empty', modalEl.getAttribute('data-l-empty'))); }
                return;
            }
            getJson(endpoint + '?q=' + encodeURIComponent(q)).then(function (data) {
                if (mine !== seq) { return; }
                var cs = (data.customers || []).map(function (c) { return { label: c.label, sub: c.sub + ' · ' + c.status, url: c.url }; });
                section(modalEl.getAttribute('data-l-customers'), cs);
                if (!pg.length && !cs.length) { out.appendChild(el('p', 'rp-search__empty', modalEl.getAttribute('data-l-empty'))); }
            }).catch(function () {});
        }

        input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 180); });
        input.addEventListener('keydown', function (e) {
            var opts = Array.prototype.slice.call(out.querySelectorAll('[role="option"]'));
            if (!opts.length) { return; }
            var i = opts.indexOf(document.activeElement);
            if (e.key === 'ArrowDown') { e.preventDefault(); opts[0].focus(); }
            if (e.key === 'Enter' && i === -1) { e.preventDefault(); window.location.href = opts[0].href; }
        });
        out.addEventListener('keydown', function (e) {
            var opts = Array.prototype.slice.call(out.querySelectorAll('[role="option"]'));
            var i = opts.indexOf(document.activeElement);
            if (e.key === 'ArrowDown' && i < opts.length - 1) { e.preventDefault(); opts[i + 1].focus(); }
            if (e.key === 'ArrowUp') { e.preventDefault(); if (i <= 0) { input.focus(); } else { opts[i - 1].focus(); } }
        });
        function open() {
            if (modalEl.open) { input.focus(); return; }
            if (typeof modalEl.showModal === 'function') { modalEl.showModal(); } else { modalEl.setAttribute('open', ''); }
            input.value = ''; run(); input.focus();
        }
        modalEl.addEventListener('click', function (e) { if (e.target === modalEl) { modalEl.close(); } });
        document.querySelectorAll('[data-rp-search-open]').forEach(function (b) { b.addEventListener('click', open); });
        document.addEventListener('keydown', function (e) {
            if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); open(); }
        });
    }

    function boot() {
        document.querySelectorAll('[data-rp-bell]').forEach(initBell);
        var m = document.querySelector('[data-rp-search]');
        if (m) { initSearch(m); }
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
