{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- D1: shared dashboard toast + confirm/prompt modals. Replaces native
     alert()/confirm()/prompt() across the owner dashboard. Exposes global
     window.dashToast(message, {type}), window.dashConfirm(message) =>
     Promise<bool>, and window.dashPrompt(opts) => Promise<string|null>.
     Pure DOM, no framework dep. Owner UI kit only: toasts are .ui-toast, the
     confirm/prompt are native <dialog class="ui-dialog"> (top layer, so they
     also open above another open sheet), buttons/inputs are .ui-btn/.ui-input
     and every colour is a --ui-* token. The element ids are the public hooks. --}}
<div id="dash-toast-host" aria-live="polite" aria-atomic="true"
     style="position:fixed;inset-block-start:16px;inset-inline-end:16px;z-index:1090;display:flex;flex-direction:column;gap:8px;inline-size:min(92vw,360px);pointer-events:none;"></div>

<dialog id="dash-confirm-backdrop" class="ui-dialog ui-dialog--sm" aria-labelledby="dash-confirm-msg">
    <div class="ui-dialog__panel">
        <h2 id="dash-confirm-title" class="ui-sheet__title" style="display:none;margin-block-end:10px;"></h2>
        <p id="dash-confirm-msg" class="ui-text-2" style="margin:0 0 18px;"></p>
        <div class="ui-cluster ui-cluster--end" style="gap:10px;">
            <button type="button" id="dash-confirm-cancel" class="ui-btn ui-btn--secondary ui-btn--sm">{{ ___('Cancel') }}</button>
            <button type="button" id="dash-confirm-ok" class="ui-btn ui-btn--sm">{{ ___('Confirm') }}</button>
        </div>
    </div>
</dialog>

<dialog id="dash-prompt-backdrop" class="ui-dialog ui-dialog--sm" aria-labelledby="dash-prompt-msg">
    <div class="ui-dialog__panel">
        <h2 id="dash-prompt-title" class="ui-sheet__title" style="display:none;margin-block-end:10px;"></h2>
        <p id="dash-prompt-msg" class="ui-text-2" style="margin:0 0 12px;white-space:pre-line;"></p>
        <input id="dash-prompt-input" type="text" class="ui-input ui-input--sm" style="margin:0 0 18px;" autocomplete="off" aria-labelledby="dash-prompt-msg">
        <div class="ui-cluster ui-cluster--end" style="gap:10px;">
            <button type="button" id="dash-prompt-cancel" class="ui-btn ui-btn--secondary ui-btn--sm">{{ ___('Cancel') }}</button>
            <button type="button" id="dash-prompt-ok" class="ui-btn ui-btn--sm">{{ ___('OK') }}</button>
        </div>
    </div>
</dialog>

<script nonce="{{ csp_nonce() }}" data-turbo-eval="false">
(function () {
    if (window.dashToast) { return; }

    var host = document.getElementById('dash-toast-host');
    // N2: honour the viewer's motion preference — reduced-motion gets an instant
    // show/hide with no transition.
    var reduced = function () {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    };
    var TYPES = { success: 1, error: 1, danger: 1, warning: 1, info: 1 };
    window.dashToast = function (message, opts) {
        // Also accept the services.js call shape dashToast(type, message, opts)
        // that most callers use; without this the type word itself was shown.
        if (typeof message === 'string' && TYPES[message] && typeof opts === 'string') {
            var shifted = arguments[2] && typeof arguments[2] === 'object' ? arguments[2] : {};
            shifted = Object.assign({}, shifted, { type: message });
            message = opts;
            opts = shifted;
        }
        opts = opts || {};
        var type = opts.type || 'success';
        // Kit tone per type, shown as the toast's leading edge (--ui-* tokens).
        var colors = {
            success: 'var(--ui-success)', error: 'var(--ui-danger)', danger: 'var(--ui-danger)',
            warning: 'var(--ui-warning)', info: 'var(--ui-accent)'
        };
        // N2: cap the stack at 4 — the oldest collapses so a burst can't blanket
        // the corner.
        while (host.children.length >= 4) { host.removeChild(host.firstChild); }
        var rm = reduced();
        var el = document.createElement('div');
        el.className = 'ui-toast';
        el.setAttribute('data-tone', (type === 'error' || type === 'danger') ? 'danger' : 'success');
        el.setAttribute('role', 'status');
        // Own enter/leave motion below, so the kit's entry animation is off.
        el.style.cssText = 'inline-size:100%;animation:none;border-inline-start:4px solid ' + (colors[type] || colors.info) + ';'
            + (rm ? 'opacity:1;transform:none;' : 'opacity:0;transform:translateY(-6px);transition:opacity .18s ease,transform .18s ease;');
        var msg = document.createElement('span');
        msg.className = 'ui-toast__msg';
        msg.textContent = message == null ? '' : String(message);
        el.appendChild(msg);
        // One optional action link (same contract as the Alpine toast store):
        // {href, actionLabel, spotlightKey} — a spotlight toast reports its click.
        if (opts.href && opts.actionLabel) {
            var act = document.createElement('a');
            act.className = 'ui-toast__action';
            act.href = String(opts.href);
            act.textContent = String(opts.actionLabel);
            if (opts.spotlightKey) {
                act.setAttribute('data-spotlight-key', String(opts.spotlightKey));
                act.setAttribute('data-spotlight-surface', 'toast');
                act.setAttribute('data-spotlight-cta', '');
            }
            el.appendChild(act);
        }
        host.appendChild(el);
        if (!rm) { requestAnimationFrame(function () { el.style.opacity = '1'; el.style.transform = 'translateY(0)'; }); }
        var life = opts.duration || 3200;
        var timer = null;
        var kill = function () {
            if (rm) { if (el.parentNode) el.parentNode.removeChild(el); return; }
            el.style.opacity = '0'; el.style.transform = 'translateY(-6px)';
            setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 220);
        };
        var arm = function (ms) { timer = setTimeout(kill, ms); el.__ends = Date.now() + ms; };
        // N2: pause-on-hover — suspend the dismiss timer, bank the remaining time.
        el.addEventListener('mouseenter', function () { clearTimeout(timer); el.__left = Math.max(0, (el.__ends || 0) - Date.now()); });
        el.addEventListener('mouseleave', function () { arm(el.__left || 1200); });
        arm(life);
    };

    var backdrop = document.getElementById('dash-confirm-backdrop');
    var titleEl = document.getElementById('dash-confirm-title');
    var msgEl = document.getElementById('dash-confirm-msg');
    var okBtn = document.getElementById('dash-confirm-ok');
    var cancelBtn = document.getElementById('dash-confirm-cancel');
    var defaultOkLabel = okBtn.textContent;
    var pending = null;

    // Native <dialog>: showModal() puts it in the top layer (focus is trapped and
    // it sits above any open sheet); a browser without it just gets [open].
    function openDialog(d) {
        if (d.open) { return; }
        if (typeof d.showModal === 'function') { d.showModal(); } else { d.setAttribute('open', ''); }
    }
    function closeDialog(d) {
        if (typeof d.close === 'function' && d.open) { d.close(); } else { d.removeAttribute('open'); }
    }

    function close(result) {
        closeDialog(backdrop);
        if (pending) { pending(result); pending = null; }
    }
    okBtn.addEventListener('click', function () { close(true); });
    cancelBtn.addEventListener('click', function () { close(false); });
    // A click on the dialog box itself (not its panel) is a click on the backdrop.
    backdrop.addEventListener('click', function (e) { if (e.target === backdrop) close(false); });
    // Escape fires the dialog's cancel event: resolve false rather than let it close silently.
    backdrop.addEventListener('cancel', function (e) { e.preventDefault(); close(false); });

    // P1-5: accepts a plain message string (legacy) or an options object:
    // dashConfirm({title, body, confirmLabel, danger}) => Promise<bool>.
    window.dashConfirm = function (message) {
        var opts = (message && typeof message === 'object') ? message : { body: message };
        return new Promise(function (resolve) {
            titleEl.textContent = opts.title == null ? '' : String(opts.title);
            titleEl.style.display = opts.title ? 'block' : 'none';
            msgEl.textContent = opts.body == null ? '' : String(opts.body);
            okBtn.textContent = opts.confirmLabel ? String(opts.confirmLabel) : defaultOkLabel;
            okBtn.className = 'ui-btn ui-btn--sm' + (opts.danger ? ' ui-btn--danger' : '');
            pending = resolve;
            openDialog(backdrop);
            okBtn.focus();
        });
    };

    // window.dashPrompt({title, body, value, placeholder, confirmLabel}) =>
    // Promise<string|null>. Replaces native prompt(): OK resolves the input value
    // (empty string when blank, like prompt), Cancel/Escape/backdrop resolve null.
    var pBackdrop = document.getElementById('dash-prompt-backdrop');
    var pTitle = document.getElementById('dash-prompt-title');
    var pMsg = document.getElementById('dash-prompt-msg');
    var pInput = document.getElementById('dash-prompt-input');
    var pOkBtn = document.getElementById('dash-prompt-ok');
    var pCancelBtn = document.getElementById('dash-prompt-cancel');
    var pDefaultOk = pOkBtn.textContent;
    var pPending = null;

    function pClose(result) {
        closeDialog(pBackdrop);
        if (pPending) { var r = pPending; pPending = null; r(result); }
    }
    pOkBtn.addEventListener('click', function () { pClose(pInput.value); });
    pCancelBtn.addEventListener('click', function () { pClose(null); });
    pBackdrop.addEventListener('click', function (e) { if (e.target === pBackdrop) pClose(null); });
    pBackdrop.addEventListener('cancel', function (e) { e.preventDefault(); pClose(null); });
    pInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); pClose(pInput.value); }
        else if (e.key === 'Escape') { e.preventDefault(); pClose(null); }
    });

    window.dashPrompt = function (opts) {
        opts = opts || {};
        return new Promise(function (resolve) {
            pTitle.textContent = opts.title == null ? '' : String(opts.title);
            pTitle.style.display = opts.title ? 'block' : 'none';
            pMsg.textContent = opts.body == null ? '' : String(opts.body);
            pInput.value = opts.value == null ? '' : String(opts.value);
            pInput.setAttribute('placeholder', opts.placeholder || '');
            pOkBtn.textContent = opts.confirmLabel ? String(opts.confirmLabel) : pDefaultOk;
            pPending = resolve;
            openDialog(pBackdrop);
            pInput.focus();
            pInput.select();
        });
    };
    // brand-alias: deprecated pre-P1-01 names (rename-map); owner/plugin scripts may still call them.
    window.lamarConfirm = window.dashConfirm; window.lamarPrompt = window.dashPrompt; // brand-alias

    // P1-5: declarative confirms — forms/links opt in with data-confirm="message"
    // (+ optional data-confirm-title / data-confirm-label / data-confirm-danger)
    // instead of an inline confirm() submit handler.
    function optsFrom(el) {
        return {
            title: el.getAttribute('data-confirm-title') || null,
            body: el.getAttribute('data-confirm'),
            confirmLabel: el.getAttribute('data-confirm-label') || null,
            danger: el.hasAttribute('data-confirm-danger')
        };
    }
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.getAttribute || !form.getAttribute('data-confirm')) { return; }
        if (form.__dashConfirmed) { form.__dashConfirmed = false; return; }
        e.preventDefault();
        e.stopPropagation();
        window.dashConfirm(optsFrom(form)).then(function (ok) {
            if (!ok) { return; }
            form.__dashConfirmed = true;
            if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
        });
    }, true);
    document.addEventListener('click', function (e) {
        var el = e.target && e.target.closest ? e.target.closest('a[data-confirm]') : null;
        if (!el) { return; }
        e.preventDefault();
        e.stopPropagation();
        window.dashConfirm(optsFrom(el)).then(function (ok) {
            if (ok) { window.location.href = el.href; }
        });
    }, true);
})();
</script>
