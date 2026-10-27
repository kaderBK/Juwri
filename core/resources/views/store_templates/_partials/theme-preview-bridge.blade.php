{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    Customizer v2 · WI-U3 — live-preview receiver.

    The owner's editor renders the storefront in an iframe. Without this, every
    tweak cost a full page reload, which is why picking a colour never felt like
    designing. This listens for patches from the editor and applies them to the
    live DOM, so a colour or a heading changes as it is typed.

    It renders ONLY in owner preview mode. A public visitor never receives this
    markup, so there is no message listener on a real storefront at all.

    Safety contract (rev/customizer/RESEARCH.md §1):
      · same-origin only — `event.origin` must equal this page's origin, and the
        message must come from the frame that owns us (`window.parent`);
      · CSS patches accept only custom properties matching the token prefixes
        this platform emits, with a conservative value charset — nothing here can
        introduce a url(), an expression, or a semicolon-smuggled extra rule;
      · text patches write `textContent`, never `innerHTML`, and only into
        elements a THEME opted in by stamping `data-tc-text="<field id>"`;
      · the channel is cosmetic. It persists nothing. The authoritative path is
        still save → validate against the schema → draft, unchanged, and the
        var values it applies were computed server-side by the same Tokens
        service that renders them normally.
--}}
@php
    // The server decides whether this is preview mode; the URL alone is not trusted.
    // P9: $store is already the store model — read its owner id directly, no query.
    $__pbOwnerId = (int) (optional($store)->user_id ?? 0);
    $__pbActive  = request()->boolean('preview_theme')
        && auth()->check()
        && $__pbOwnerId > 0
        && (int) auth()->id() === $__pbOwnerId;
@endphp
@if($__pbActive)
<style nonce="{{ csp_nonce() }}">
    /* WI-V4: in Select mode, section roots get a pointer + a hover outline so the
       owner can see what a click will target. */
    .tc-selecting [data-tc-section]{cursor:pointer;}
    .tc-selecting [data-tc-section]:hover{outline:2px solid var(--tc-primary, #7c5cff);outline-offset:-2px;}
</style>
<script nonce="{{ csp_nonce() }}">
(function () {
    'use strict';

    var ORIGIN = window.location.origin;
    // Token custom properties this platform emits: colour, radius, spacing, font.
    var PROP  = /^--t[crsf]-[a-z0-9-]+$/;
    // Hexes, rgb triples, lengths, and quoted font stacks — no url(), no braces,
    // no semicolons, so a value can never close the declaration and start a rule.
    var VALUE = /^[#a-zA-Z0-9 ,.%()'"\/_-]{1,220}$/;

    function applyVars(vars) {
        if (!vars || typeof vars !== 'object') return;
        var root = document.documentElement;
        Object.keys(vars).forEach(function (name) {
            var value = vars[name];
            if (typeof value !== 'string') value = String(value);
            if (!PROP.test(name) || !VALUE.test(value)) return;
            root.style.setProperty(name, value);
        });
    }

    function applyTexts(texts) {
        if (!texts || typeof texts !== 'object') return;
        Object.keys(texts).forEach(function (id) {
            var value = texts[id];
            if (typeof value !== 'string') return;
            // Themes opt in per node. Nothing is guessed from the DOM.
            // Form fields take the value as their placeholder (the
            // search_placeholder case); everything else as text content —
            // never markup, so a stamped node cannot smuggle HTML.
            document.querySelectorAll('[data-tc-text="' + CSS.escape(id) + '"]').forEach(function (el) {
                if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
                    el.placeholder = value;
                } else {
                    el.textContent = value;
                }
            });
        });
    }

    // WI-V4 — click-to-edit. When the editor turns "Select" on, clicking a
    // section in the preview posts its id back so the editor can jump to the
    // matching group. Sections opt in by carrying `data-tc-section` (the shared
    // section loop stamps it); nothing else in the page is made clickable.
    var selectMode = false;
    function setSelectMode(on) {
        selectMode = !!on;
        document.documentElement.classList.toggle('tc-selecting', selectMode);
    }
    document.addEventListener('click', function (e) {
        if (!selectMode) return;
        var node = e.target.closest('[data-tc-section]');
        if (!node) return;
        e.preventDefault();
        e.stopPropagation();
        try {
            window.parent.postMessage({ type: 'tc-select', section: node.getAttribute('data-tc-section') }, ORIGIN);
        } catch (err) { /* editor only */ }
    }, true);

    window.addEventListener('message', function (event) {
        if (event.origin !== ORIGIN) return;
        if (event.source !== window.parent) return;
        var data = event.data;
        if (!data) return;
        if (data.type === 'tc-patch') { applyVars(data.vars); applyTexts(data.texts); return; }
        if (data.type === 'tc-select-mode') { setSelectMode(data.on); return; }
    });

    // Tell the editor the receiver is live, so it can fall back to reloading the
    // frame on a theme that has not been given the bridge yet.
    try {
        window.parent.postMessage({ type: 'tc-ready', href: window.location.href }, ORIGIN);
    } catch (e) { /* the editor is the only parent that can hear this */ }
}());
</script>
@endif
