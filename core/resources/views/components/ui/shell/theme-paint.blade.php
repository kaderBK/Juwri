{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.shell.theme-paint /> — sets the theme on <html> before first paint (no flash).
  `theme` cookie = explicit light/dark; no cookie = follow the OS, live. Used by the owner
  shell and the sign-in pages; the toggle lives in assets/owner-ui/layout.js.
--}}
<script nonce="{{ csp_nonce() }}">
(function () {
    var root = document.documentElement;
    function stored() {
        var m = document.cookie.match(/(?:^|; )theme=(light|dark)(?:;|$)/);
        return m ? m[1] : null;
    }
    function paint() {
        var mode = stored();
        var t = mode || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        root.setAttribute('data-theme', t);
        root.setAttribute('data-bs-theme', t);
        root.setAttribute('data-theme-mode', mode || 'system');
    }
    paint();
    try { window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', paint); } catch (e) {}
    window.uiShellPaintTheme = paint;
})();
</script>
