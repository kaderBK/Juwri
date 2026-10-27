{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  I5-2 — dialog semantics + focus management for the shared `.sheet` bottom-sheet
  pattern (crave and every theme that uses it).

  The sheets are plain `<div class="sheet" hidden>` with no role, no focus trap and
  no focus restore, so a screen-reader user is never told a dialog opened and a
  keyboard user's focus stays behind the sheet. This shared, self-contained ES5
  helper — rendered once from the layout — upgrades every `.sheet` in place:

    • marks it role="dialog" aria-modal="true" (+ aria-labelledby from its heading);
    • on open (the `hidden` attribute is removed) moves focus into the sheet and
      remembers the opener;
    • traps Tab within the sheet;
    • Esc triggers the sheet's OWN close control (.sheet-grab / [data-close]) so the
      theme's open/close state stays in sync — it never force-hides the element;
    • on close restores focus to the opener.

  It only ever ADDS behavior, and stands down entirely when a page has no `.sheet`.
--}}
@if(config('settings.storefront_a11y', true))
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    var sheets = document.querySelectorAll('.sheet');
    if (!sheets.length) return;

    var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
    var openerBySheet = {};
    var uid = 0;

    function labelSheet(sheet) {
        if (sheet.getAttribute('role') !== 'dialog') sheet.setAttribute('role', 'dialog');
        sheet.setAttribute('aria-modal', 'true');
        if (!sheet.getAttribute('aria-label') && !sheet.getAttribute('aria-labelledby')) {
            var h = sheet.querySelector('h1,h2,h3,.sheet-title,[data-sheet-title]');
            if (h) {
                if (!h.id) h.id = 'mbsheet-h-' + (++uid);
                sheet.setAttribute('aria-labelledby', h.id);
            }
        }
    }

    function focusables(sheet) {
        return Array.prototype.filter.call(sheet.querySelectorAll(FOCUSABLE), function (el) {
            return el.offsetParent !== null || el === document.activeElement;
        });
    }

    function onKeydown(e) {
        var sheet = e.currentTarget;
        if (e.key === 'Escape' || e.keyCode === 27) {
            var closer = sheet.querySelector('.sheet-grab,[data-close],.sheet-close,.sheet-x');
            if (closer) { e.preventDefault(); closer.click(); }
            return;
        }
        if (e.key !== 'Tab' && e.keyCode !== 9) return;
        var f = focusables(sheet);
        if (!f.length) { e.preventDefault(); sheet.focus(); return; }
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }

    function open(sheet) {
        var key = sheet.id || (sheet.id = 'mbsheet-' + (++uid));
        openerBySheet[key] = document.activeElement;
        labelSheet(sheet);
        if (!sheet.hasAttribute('tabindex')) sheet.setAttribute('tabindex', '-1');
        sheet.addEventListener('keydown', onKeydown);
        // Defer focus so it lands after the theme's open animation starts.
        setTimeout(function () {
            var f = focusables(sheet);
            (f[0] || sheet).focus();
        }, 60);
    }

    function close(sheet) {
        sheet.removeEventListener('keydown', onKeydown);
        var key = sheet.id;
        var opener = key && openerBySheet[key];
        if (opener && typeof opener.focus === 'function') {
            try { opener.focus(); } catch (e) {}
        }
        if (key) delete openerBySheet[key];
    }

    Array.prototype.forEach.call(sheets, function (sheet) {
        labelSheet(sheet);
        var wasHidden = sheet.hidden;
        new MutationObserver(function () {
            if (sheet.hidden === wasHidden) return;
            wasHidden = sheet.hidden;
            if (sheet.hidden) close(sheet); else open(sheet);
        }).observe(sheet, { attributes: true, attributeFilter: ['hidden'] });
    });
})();
</script>
@endif
