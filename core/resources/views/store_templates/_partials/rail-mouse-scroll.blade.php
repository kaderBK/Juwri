{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Desktop mouse access for horizontal rails (specials / HH / banner / category
  chips / sub-chips / stories / combo rail / product gallery — every theme).

  The 17 templates hide scrollbars by design, so a mouse user had NO way to
  scroll any overflow-x rail: no bar to drag, wheel doesn't translate, no drag.
  Touch/trackpad only. This shared layer adds, on fine-pointer devices only:

    1. Drag-to-scroll — press and pan any horizontal rail with the mouse.
       A real drag (>6px) suppresses the card click underneath; a plain click
       still opens the card. Scroll-snap is paused during the drag (it fights
       mid-drag scrollLeft writes) and restored on release.
    2. Wheel translation — a vertical wheel over a rail pans it. Self-verifying:
       the event is swallowed only when the rail actually moved, so a rail at
       either end lets the page keep scrolling normally.

  Fully generic: a delegated listener walks up from the event target to the
  nearest HORIZONTAL-ONLY scroller (scrollWidth overflows, scrollHeight does
  not — so vertical panes like each theme's view-scroll are never hijacked).
  Zero per-theme wiring; JS-injected rails (specials, stories, combos) are
  covered automatically. Touch input is untouched (pointerType gate). RTL-safe:
  drag uses pixel deltas; wheel flips sign under direction:rtl.
--}}
<style @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
body.mb-rail-drag, body.mb-rail-drag *{cursor:grabbing !important;user-select:none !important;-webkit-user-select:none !important}
</style>
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    'use strict';
    if (!window.matchMedia || !matchMedia('(hover: hover) and (pointer: fine)').matches) return;

    var THRESHOLD = 6; // px of travel before a press becomes a drag (and eats the click)

    // Nearest ancestor that scrolls horizontally ONLY. The scrollHeight guard is
    // what keeps vertical panes (view-scroll et al.) out of drag's reach.
    function railOf(node) {
        for (var el = node; el && el !== document.body && el.nodeType === 1; el = el.parentElement) {
            if (el.scrollWidth > el.clientWidth + 1 && el.scrollHeight <= el.clientHeight + 1) {
                var o = getComputedStyle(el).overflowX;
                if (o === 'auto' || o === 'scroll') return el;
            }
        }
        return null;
    }

    /* ---- wheel: vertical wheel over a rail pans it ---- */
    document.addEventListener('wheel', function (e) {
        if (e.ctrlKey) return;                                   // pinch-zoom gesture
        if (Math.abs(e.deltaY) <= Math.abs(e.deltaX)) return;    // already-horizontal input (trackpad)
        var rail = railOf(e.target);
        if (!rail) return;
        var d = e.deltaY;
        // wheel-down should advance INTO the rail; in RTL "forward" is -scrollLeft
        if (getComputedStyle(rail).direction === 'rtl') d = -d;
        var prev = rail.scrollLeft;
        rail.scrollLeft = prev + d;
        if (rail.scrollLeft !== prev) e.preventDefault();        // moved → consume; at end → page scrolls
    }, { passive: false });

    /* ---- drag-to-scroll ---- */
    var rail = null, startX = 0, startLeft = 0, dragging = false, snapSaved = '';

    document.addEventListener('pointerdown', function (e) {
        if (e.pointerType !== 'mouse' || e.button !== 0) return; // touch keeps native behavior
        if (e.target.closest && e.target.closest('input,textarea,select,option,[contenteditable="true"]')) return;
        rail = railOf(e.target);
        if (!rail) return;
        startX = e.clientX;
        startLeft = rail.scrollLeft;
        dragging = false;
    });

    document.addEventListener('pointermove', function (e) {
        if (!rail) return;
        var dx = e.clientX - startX;
        if (!dragging) {
            if (Math.abs(dx) < THRESHOLD) return;
            dragging = true;
            snapSaved = rail.style.scrollSnapType;
            rail.style.scrollSnapType = 'none';
            document.body.classList.add('mb-rail-drag');
        }
        rail.scrollLeft = startLeft - dx;                        // pixel delta → RTL-safe
    });

    function release() {
        if (!rail) return;
        if (dragging) {
            rail.style.scrollSnapType = snapSaved;               // snap re-engages, settles to card
            document.body.classList.remove('mb-rail-drag');
        }
        rail = null;
        // `dragging` stays up for the click that follows pointerup (suppressed
        // below), then clears; a release with no click clears on the timeout.
        setTimeout(function () { dragging = false; }, 0);
    }
    document.addEventListener('pointerup', release);
    document.addEventListener('pointercancel', release);

    // a real drag must not open the card it started on
    document.addEventListener('click', function (e) {
        if (dragging) { e.preventDefault(); e.stopPropagation(); dragging = false; }
    }, true);

    // ...nor start a native link/image drag mid-pan
    document.addEventListener('dragstart', function (e) {
        if (rail || dragging) e.preventDefault();
    });
})();
</script>
