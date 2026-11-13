{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    Theme Studio B0.7 — shared chrome for every {% widget %} partial, written once
    per page (WidgetTag passes $widgetBase). Not a widget itself: the leading
    underscore keeps it off WidgetTag::names(), so a theme can never request it.

    DOM-independent of any Blade theme: each widget owns a native <dialog>
    (focus trap, Escape, top layer for free), opened by any [data-mbw-open="<name>"]
    trigger and closed by [data-mbw-close] or a backdrop click. Styled only with
    the design tokens (--tc-*, --tr-*, --tf-*) and logical properties (RTL-safe).
--}}
<style nonce="{{ csp_nonce() }}" data-mbw-base>
.mbw-btn{display:inline-flex;align-items:center;justify-content:center;gap:.5em;box-sizing:border-box;min-block-size:44px;padding-block:.55em;padding-inline:1.1em;border:0;border-radius:var(--tr-control,10px);background:var(--tc-primary,#111);color:var(--tc-on-primary,#fff);font:inherit;font-weight:600;line-height:1.2;cursor:pointer;text-decoration:none}
.mbw-btn:hover{background:var(--tc-primary-600,var(--tc-primary,#111))}
.mbw-btn:focus-visible,.mbw-x:focus-visible,.mbw-row:focus-visible{outline:2px solid var(--tc-accent,var(--tc-primary,#2563eb));outline-offset:2px}
.mbw-btn--ghost{background:transparent;color:inherit;border:1px solid rgba(127,127,127,.4)}
.mbw-btn--ghost:hover{background:rgba(127,127,127,.12)}
.mbw-btn[disabled]{opacity:.55;cursor:default}
.mbw-ic{inline-size:1.1em;block-size:1.1em;flex:none;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.mbw-dialog{box-sizing:border-box;inline-size:min(520px,calc(100vw - 24px));block-size:max-content;max-block-size:calc(100dvh - 24px);margin:auto;padding:0;border:0;border-radius:var(--tr-card,16px);background:var(--tc-surface,#fff);color:var(--tc-text,#111);font-family:var(--tf-body,inherit);box-shadow:0 30px 80px -20px rgba(0,0,0,.45);overflow:hidden}
.mbw-dialog::backdrop{background:rgba(0,0,0,.5)}
.mbw-dialog[open]{display:flex;flex-direction:column}
.mbw-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding-block:16px 6px;padding-inline:20px 12px}
.mbw-title{margin:0;font-family:var(--tf-heading,inherit);font-size:1.15rem;line-height:1.3;color:inherit}
.mbw-x{display:inline-grid;place-items:center;inline-size:44px;block-size:44px;flex:none;border:0;border-radius:50%;background:transparent;color:inherit;cursor:pointer}
.mbw-x:hover{background:rgba(127,127,127,.15)}
.mbw-body{flex:1 1 auto;min-block-size:0;padding-block:6px 20px;padding-inline:20px;overflow:auto;overscroll-behavior:contain}
.mbw-lead{margin-block:0 12px;color:var(--tc-text-muted,inherit);font-size:.92rem}
.mbw-form{display:grid;gap:10px}
.mbw-field{display:grid;gap:4px;font-size:.85rem;color:var(--tc-text-muted,inherit)}
.mbw-input{box-sizing:border-box;inline-size:100%;min-block-size:44px;padding-block:10px;padding-inline:12px;border:1px solid rgba(127,127,127,.35);border-radius:var(--tr-control,10px);background:var(--tc-background,transparent);color:var(--tc-text,inherit);font:inherit;font-size:1rem}
.mbw-input:focus{outline:2px solid var(--tc-primary,#111);outline-offset:0}
.mbw-submit{inline-size:100%;margin-block-start:6px}
.mbw-alert{margin-block:0 12px;padding-block:10px;padding-inline:12px;border-radius:var(--tr-control,10px);background:rgba(220,38,38,.12);color:inherit;border-inline-start:3px solid #dc2626}
.mbw-done{display:grid;gap:6px;justify-items:center;text-align:center;padding-block:14px}
.mbw-done b{font-size:1.05rem}
.mbw-done p{margin:0;color:var(--tc-text-muted,inherit)}
.mbw-list{list-style:none;margin:0;padding:0;display:grid;gap:8px}
.mbw-row{display:flex;align-items:center;justify-content:space-between;gap:12px;box-sizing:border-box;min-block-size:48px;padding-block:10px;padding-inline:14px;border:1px solid rgba(127,127,127,.25);border-radius:var(--tr-control,10px);color:inherit;text-decoration:none}
a.mbw-row:hover{background:rgba(127,127,127,.1)}
.mbw-row[aria-current="true"]{border-color:var(--tc-primary,#111);box-shadow:inset 0 0 0 1px var(--tc-primary,#111)}
/* The shared catering form carries inline spacing/brand colours for the Blade themes. */
.mbw-catering .tp-catering{padding:0!important;max-inline-size:none!important;color:inherit}
.mbw-catering .tp-catering input,.mbw-catering .tp-catering textarea{background:var(--tc-background,transparent);color:var(--tc-text,inherit);font:inherit}
.mbw-catering .tp-catering button[type="submit"]{background:var(--tc-primary,#111)!important;color:var(--tc-on-primary,#fff)!important;border-radius:var(--tr-control,10px)!important;min-block-size:44px}
.mbw-dialog [hidden]{display:none!important}
.mbw-muted{color:var(--tc-text-muted,inherit);font-size:.85rem}
</style>
<script nonce="{{ csp_nonce() }}">
(function () {
    "use strict";
    if (window.mbWidgets) { return; }
    function dialogFor(name) { return document.getElementById('mbw-' + name + '-dialog'); }
    function open(d) {
        if (!d) { return; }
        if (typeof d.showModal === 'function') { if (!d.open) { d.showModal(); } }
        else { d.setAttribute('open', ''); }
        try { d.dispatchEvent(new CustomEvent('mbw:open')); } catch (e) {}
    }
    function close(d) {
        if (!d) { return; }
        if (typeof d.close === 'function') { d.close(); } else { d.removeAttribute('open'); }
    }
    window.mbWidgets = {
        open: function (name) { open(dialogFor(name)); },
        close: function (name) { close(dialogFor(name)); }
    };
    document.addEventListener('click', function (e) {
        var t = e.target;
        if (!t || !t.closest) { return; }
        var opener = t.closest('[data-mbw-open]');
        if (opener) { e.preventDefault(); open(dialogFor(opener.getAttribute('data-mbw-open'))); return; }
        var closer = t.closest('[data-mbw-close]');
        if (closer) { close(closer.closest('dialog')); return; }
        // A click landing on the <dialog> box itself (not its content) is the backdrop.
        if (t.tagName === 'DIALOG' && t.classList.contains('mbw-dialog')) { close(t); }
    });
    // Re-open the dialog a form just posted from (success flash or validation errors).
    function autoOpen() {
        var list = document.querySelectorAll('dialog.mbw-dialog[data-mbw-autoopen]');
        for (var i = 0; i < list.length; i++) { open(list[i]); }
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', autoOpen); }
    else { autoOpen(); }
})();
</script>
