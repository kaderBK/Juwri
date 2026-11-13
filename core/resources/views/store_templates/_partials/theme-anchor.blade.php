{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Shared placement helper for injected storefront sections (`window.mbAnchor`).

  A section rendered from this layout lands at the top of <main>, which is right
  for a theme laid out in document flow and INVISIBLE on the ones that paint an
  app shell of position:fixed panes over the viewport — crave, matcha, orbit and
  crumb each measured a full-size visible box with the theme's own chrome on top
  of it. Anything injected has to find the theme's menu and sit next to it.

  Extracted from the offers strip when the home order-type control became the
  second consumer: the measurements below took four browser rounds to get right
  and are exactly the kind of thing that drifts once it exists in two copies.
--}}
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
window.mbAnchor = (function () {
    /* The MENU grid, not merely a node carrying an item id.
       Anchoring to the first element matching an item id put a section inside a
       hero on three themes — aurora's `.au-featured`, scoop's `.promo` and
       lime's `.daily` all reference a real item and all come first in document
       order. What distinguishes the menu is repetition: it is the container
       holding the MOST item cards. Cards must carry a picture and some height,
       or a row of category chips (same id attribute, no image) wins the count. */
    function menuGrid(exclude) {
        var nodes = document.querySelectorAll('[data-open], [data-open-item], [data-item-id], [data-menu-id], [data-id]');
        var counts = new Map(), best = null, bestN = 0;
        for (var i = 0; i < nodes.length; i++) {
            var n = nodes[i];
            if ((exclude && exclude.contains(n)) || !n.querySelector('img')) continue;
            if (n.getBoundingClientRect().height <= 60) continue;
            var p = n.parentElement;
            if (!p) continue;
            var c = (counts.get(p) || 0) + 1;
            counts.set(p, c);
            if (c > bestN) { bestN = c; best = p; }
        }
        return bestN >= 3 ? best : null;
    }

    function isScrollable(e) {
        var oy = window.getComputedStyle(e).overflowY;

        return oy === 'auto' || oy === 'scroll';
    }

    function scrollHost(el) {
        for (var e = el.parentElement; e && e !== document.body; e = e.parentElement) {
            if (isScrollable(e)) return e;
        }
        return null;
    }

    /* A row's own heading — one short line of text, nothing interactive of its
       own. Deliberately strict and applied to ONE sibling only: an earlier
       version walked back over every heading-ish sibling and hopped a section
       over crumb's hero and top bar to the very top of the page. */
    function isSectionHead(el) {
        if (/^(HEADER|NAV|FORM|MAIN|ASIDE)$/.test(el.tagName)) return false;
        if (el.querySelector('[data-open], [data-open-item], [data-item-id], input, select')) return false;
        if (el.getBoundingClientRect().height > 120) return false;

        return /^H[1-4]$/.test(el.tagName) || !!el.querySelector('h1,h2,h3,h4')
            || /head|title/i.test(String(el.className));
    }

    /**
     * Move `el` next to the theme's menu. Returns true once it is in place.
     * `before` is an optional element to sit above instead (used to keep the
     * order-type control above the offers strip when both are on the page).
     */
    function place(el, before) {
        if (before && before !== el && document.contains(before) && !el.contains(before)) {
            if (el.nextElementSibling === before) return true;
            before.parentElement.insertBefore(el, before);

            return true;
        }

        var grid = menuGrid(el);
        if (!grid || grid.contains(el)) return false;

        var host = scrollHost(grid) || el.parentElement;
        if (!host || host === el || el.contains(host)) return false;

        /* Climb only while the parent is still ABOUT the grid: crumb wraps its
           menu and its hero in one padding div twice the grid's height, and
           climbing into it put the section a screen and a half above the food.
           The scroll container's direct child is too coarse, and the nearest
           <section> is the entire home view on nine themes. */
        var gridH = grid.getBoundingClientRect().height || 1;
        var block = grid;
        for (var hops = 0; hops < 2 && block.parentElement && block.parentElement !== host; hops++) {
            /* A block that already has its own [data-mb-grid-lead] rows glued above it
               (crumb: categories head + tiles + featured head, all siblings of the grid)
               is the menu section — climbing out of it would hop over the lead rows' parent
               and land above the theme's own store header. The 1.6 ratio below cannot tell
               those two apart on its own: it flipped for crumb as soon as the featured grid
               got shorter than 62% of the page body. */
            if (block.previousElementSibling && block.previousElementSibling.hasAttribute('data-mb-grid-lead')) break;
            if (block.parentElement.getBoundingClientRect().height > gridH * 1.6) break;
            block = block.parentElement;
        }
        if (!block.parentElement || block === el || el.contains(block)) return false;

        var target = block;
        var prev = block.previousElementSibling;
        if (prev && prev !== el && isSectionHead(prev)) target = prev;

        /* A theme can glue its own row(s) directly above the grid — e.g. orbit's
           arc category selector, which must touch the items it filters. Climb the
           insertion point above any preceding sibling marked [data-mb-grid-lead]
           so an injected promo/offers section lands ABOVE the categories, not
           wedged between the category selector and the menu. Opt-in: themes
           without the marker are unaffected. */
        while (target.previousElementSibling
            && target.previousElementSibling !== el
            && target.previousElementSibling.hasAttribute('data-mb-grid-lead')) {
            target = target.previousElementSibling;
        }

        if (el.nextElementSibling === target) return true; // already there
        target.parentElement.insertBefore(el, target);

        return true;
    }

    /* Readable ink for whatever the section ended up sitting on.
       Inheriting the theme's text colour is only safe on the surface that
       colour was chosen for, and these sections move: on scoop that meant white
       text (inherited from a dark section) on a bright yellow band. Sample the
       first ancestor that actually paints a background and choose by luminance.
       A theme painting an image or gradient yields no colour to sample, and the
       CSS then falls back to the inherited colour, as before. */
    function ink(el) {
        var bg = null;
        for (var e = el; e && e !== document.documentElement; e = e.parentElement) {
            var c = window.getComputedStyle(e).backgroundColor;
            if (c && c !== 'transparent' && !/rgba\(0, 0, 0, 0\)/.test(c)) { bg = c; break; }
        }
        var m = bg && bg.match(/(\d+)[,\s]+(\d+)[,\s]+(\d+)/);
        if (!m) return;
        var lum = (0.299 * +m[1] + 0.587 * +m[2] + 0.114 * +m[3]) / 255;
        el.style.setProperty('--mb-ink', lum > 0.6 ? '#14181d' : '#f7f8fa');
    }

    /* Theme bundles build their grid and their controls from JS after DOM ready,
       so every consumer needs the same "run now, and again once the theme has
       finished" cadence.

       `el` is the injected section. Given it, the cadence ALSO re-runs whenever
       the storefront flips light↔dark: ink() bakes a text colour from the one
       background it sampled at settle, and a flip after that silently inverts the
       surface — the stale ink then matches what the section now sits on and its
       text ("On sale now" and the offer cards) disappears. Re-running is safe:
       place() and wire() are idempotent, and a diner toggling the theme is rare.

       Themes flip the scheme with different attributes on different nodes —
       mbTheme's `data-theme` on the root, aurora's `data-theme`, velora's
       `data-velora-theme`, dough's `data-dough-theme`, lime's `data-lime-theme`,
       feast's `data-ff-face` — but the toggled node is ALWAYS an ancestor of an
       injected section, so watch attribute changes up the chain rather than
       enumerate every engine. The keyword test below must cover each engine's
       word (note `face`, feast's, which is neither theme/scheme/mode/appearance). A bare
       `class` is deliberately ignored: no theme carries the scheme on one, and
       ancestor class churn (scroll/menu state) would re-fire needlessly. `auto`
       following the OS flips the palette with no DOM mutation to observe, so
       matchMedia covers that path.

       The chain is (re)observed after EVERY run, not just the first: the section
       is server-rendered at the top of the OUTER <main>, and place() then moves it
       INTO the theme root (dough's `#dough`, etc.) — which is where the scheme
       attribute lives. Watching only the pre-placement ancestors would miss it
       entirely, so re-walk once the section has been relocated. `watched` keeps it
       idempotent — each ancestor is observed at most once. */
    function onSettled(fn, el) {
        var watch = (el && typeof MutationObserver !== 'undefined') ? [] : null;
        var queued = false, tail = null;
        function rerun() {
            // Immediate (next frame): reads the background after the flip's style
            // recalc — snappy for themes that swap the palette instantly.
            if (!queued) {
                queued = true;
                var run = function () { queued = false; tick(); };
                if (window.requestAnimationFrame) window.requestAnimationFrame(run);
                else window.setTimeout(run, 16);
            }
            /* Trailing: some themes TRANSITION background-color on the flip (vivid
               fades #vd over ~320ms). A single early sample reads a mid-transition
               colour and bakes the wrong ink — dark ink on a still-fading-to-dark
               surface — and never corrects. Re-sample once the animation settles. */
            if (tail) window.clearTimeout(tail);
            tail = window.setTimeout(function () { tail = null; tick(); }, 450);
        }
        var mo = watch && new MutationObserver(function (recs) {
            for (var i = 0; i < recs.length; i++) {
                if (/theme|scheme|mode|appearance|face/i.test(recs[i].attributeName || '')) { rerun(); return; }
            }
        });
        function watchChain() {
            if (!mo) return;
            for (var n = el.parentElement; n; n = n.parentElement) {
                if (watch.indexOf(n) < 0) { watch.push(n); mo.observe(n, { attributes: true }); }
                if (n === document.documentElement) break;
            }
        }
        function tick() { fn(); watchChain(); }

        tick();
        setTimeout(tick, 900);
        setTimeout(tick, 2500);

        /* The three ticks above assume the theme has built its menu grid by
           2.5s. Large catalogues do not: crave's grid on an 88-item store is
           still empty at 2.5s, so place() kept failing and the section stayed
           hidden (data-mb-pending) until some UNRELATED attribute mutation
           happened to re-fire this — measured at 15.2s, which reads to a diner
           as the offers strip "flashing" in long after the page settled.
           Keep retrying until the section is actually in place. Self-terminating:
           place()/placeBottom() clear data-mb-pending on success, so a section
           that lands on any earlier tick stops the loop immediately. `mo` only
           watches attributes, deliberately — promoting it to childList would
           re-fire on every card the grid renders. */
        (function retry(deadline) {
            if (!el || !el.hasAttribute('data-mb-pending')) return; // placed, or removed
            if (Date.now() > deadline) return;
            window.setTimeout(function () { tick(); retry(deadline); }, 250);
        })(Date.now() + 10000);

        if (mo && window.matchMedia) {
            var mq = window.matchMedia('(prefers-color-scheme: dark)');
            if (mq.addEventListener) mq.addEventListener('change', rerun);
            else if (mq.addListener) mq.addListener(rerun); /* old Safari */
        }
    }

    /**
     * Like place(), but drops `el` at the TOP of the theme's scrollable content
     * (its first child) instead of next to the menu — for first-visit banners that
     * belong above everything. Uses the same menu-grid probe to locate the scroll
     * host, so it works on the app-shell themes (crave/matcha/orbit/crumb) that
     * paint fixed panes: the banner lands inside the pane, not in the viewport
     * margin. Falls back to no-op (banner stays where server-rendered) if no
     * scroll host is found. Idempotent.
     */
    function placeTop(el) {
        var grid = menuGrid(el);
        if (!grid || grid.contains(el)) return false;
        var host = scrollHost(grid);
        if (!host) {
            /* Theme scrolls the WINDOW (no inner scroll container — aurora, vivid…):
               the "top of content" is the top of the theme root. Climb to the highest
               ancestor of the grid whose parent is <main>/<body>, so the banner stays
               INSIDE the theme root and its colour tokens still resolve. */
            host = grid;
            while (host.parentElement && !/^(MAIN|BODY|HTML)$/.test(host.parentElement.tagName)) {
                host = host.parentElement;
            }
        }
        if (!host || host === el || el.contains(host)) return false;
        if (host.firstElementChild === el) return true; // already there
        host.insertBefore(el, host.firstElementChild);

        return true;
    }

    /**
     * Like placeTop(), but drops `el` at the BOTTOM of the theme's scrollable
     * content (its last child) — for a footer that belongs after everything.
     * Uses the same menu-grid probe to find the scroll host, so it lands inside
     * the pane on app-shell themes (crave/matcha/orbit/crumb) rather than in the
     * viewport margin, and at the end of the theme root on window-scroll themes
     * (aurora/vivid…). No-op (stays where server-rendered, i.e. hidden) when no
     * scroll host is found — e.g. the info page itself, which has no menu grid.
     * Idempotent.
     *
     * `before` is an optional element to sit above instead of going last, for the
     * second consumer of the same bottom: the catering form and the info footer
     * both belong at the end of the content, and whichever ran last simply won —
     * putting the legal footer above a whole request form.
     */
    function placeBottom(el, before) {
        var grid = menuGrid(el);
        if (!grid || grid.contains(el)) return false;
        var host = scrollHost(grid);
        if (!host) {
            host = grid;
            while (host.parentElement && !/^(MAIN|BODY|HTML)$/.test(host.parentElement.tagName)) {
                host = host.parentElement;
            }
            /* Document-scroll themes stack their menu as SIBLING sections under a
               <main> (feast renders one <section> per category). Append to that
               <main>/<body> container itself, not INSIDE the first section, so a
               bottom footer lands after ALL of them instead of being wedged after
               the first category with the rest of the menu below it. */
            if (host.parentElement && /^(MAIN|BODY)$/.test(host.parentElement.tagName)) {
                host = host.parentElement;
            }
        }
        if (!host || host === el || el.contains(host)) return false;

        if (before && before !== el && before.parentElement === host && !el.contains(before)) {
            if (el.nextElementSibling === before) return true; // already there
            host.insertBefore(el, before);

            return true;
        }

        if (host.lastElementChild === el) return true; // already there
        host.appendChild(el);

        return true;
    }

    /**
     * Drop `el` inside the theme's CHECKOUT, next to the other amount controls —
     * for strips that change what the diner pays (loyalty redemption today).
     *
     * place()/placeTop()/placeBottom() all anchor to the MENU, which is the wrong
     * half of the storefront for a checkout control: on the themes that paint
     * fixed panes the strip stayed stranded in body flow behind the app shell
     * (measured on crave: rendered, visible, and completely covered), and on the
     * document-flow themes it sat at the very bottom of the page, nowhere near
     * the order it modifies.
     *
     * Probes by IDIOM, not by a list of the 17 themes' element ids: the TIP control
     * is the landmark, because every modern theme ships one, always inside its
     * checkout, always among the amount controls the strip belongs with. Leading
     * with the order button instead needed an id list that was already wrong —
     * aurora calls it `#au-checkout-btn`, and the strip simply never placed there.
     * The button is kept only as a fallback landmark for a store with tips off.
     *
     * Returns true once in place; no-op + false when the page has no checkout at
     * all (the info page, the track page), so the caller can keep retrying cheaply.
     * Idempotent.
     */
    var TOTALS_SEL = '[id*="otals"], [class*="totals"]';
    var TIP_SEL = '[data-tip], [data-tip-pct]:not(.mb-tip-btn), [name="tip_amount"]:not(#mbTipAmount)';
    var CTA_SEL = '#cvPlaceOrder, #placeOrder, #placeBtn, [data-place-order], [id$="submit-order-btn"], [id$="checkout-btn"]';

    function depth(e) {
        var d = 0;
        for (var n = e; n; n = n.parentElement) d++;

        return d;
    }

    /* The tip control that belongs to the CHECKOUT. A theme can offer tips in the
       cart pane as well (dough does, one `.tip-row` per view), and taking the
       first in document order put the strip on the cart screen instead of the one
       that commits the order. Pick the candidate whose nearest shared ancestor
       with the order button is DEEPEST: the checkout's tip shares the checkout
       view with it, the cart's tip only shares the app root. */
    function checkoutTip(el) {
        var tips = document.querySelectorAll(TIP_SEL);
        var cta = document.querySelector(CTA_SEL);
        var best = null, bestDepth = -1;
        for (var i = 0; i < tips.length; i++) {
            if (el.contains(tips[i])) continue;
            if (!cta) return tips[i];
            var anc = tips[i].parentElement;
            while (anc && !anc.contains(cta)) anc = anc.parentElement;
            var d = anc ? depth(anc) : 0;
            if (d > bestDepth) { bestDepth = d; best = tips[i]; }
        }

        return best;
    }

    function placeCheckout(el) {
        var tip = checkoutTip(el);
        if (tip && !el.contains(tip)) {
            /* The tip control's own BLOCK, not the button: every theme wraps its
               tip row in a labelled field, and inserting between the label and
               the buttons would split it. Climb out — but stop BELOW anything
               that scrolls, is the form itself, or already holds the totals.
               Without those guards the climb reached crave's whole `.view-scroll`
               and the strip landed OUTSIDE the scrolling area, as unreachable as
               it was in body flow. */
            var block = tip;
            for (var p = block.parentElement; p && p !== document.body; p = block.parentElement) {
                if (isScrollable(p) || p.tagName === 'FORM' || p.querySelector(TOTALS_SEL)) break;
                block = p;
            }
            if (block !== el && !el.contains(block) && block.parentElement) {
                if (block.nextElementSibling === el) return true; // already there
                block.parentElement.insertBefore(el, block.nextElementSibling);

                return true;
            }
        }

        /* Tips off: fall back to the checkout's own totals. A cart pane carries a
           totals block too, so the order button — which only the checkout has —
           is what identifies the right one. */
        var cta = document.querySelector(CTA_SEL);
        if (!cta || el.contains(cta)) return false;

        for (var n = cta.parentElement; n && n !== document.body; n = n.parentElement) {
            var totals = n.querySelector(TOTALS_SEL);
            if (!totals) continue;
            if (totals === el || el.contains(totals) || !totals.parentElement) return false;
            if (totals.previousElementSibling === el) return true; // already there
            totals.parentElement.insertBefore(el, totals);

            return true;
        }

        /* Last resort — the theme labels its totals in its own idiom (feast's
           .ff-total-row never matches TOTALS_SEL) so neither landmark hit. The order
           button is still the checkout's one certain fixture: drop the controls
           directly ABOVE it, which is where amount-changing controls belong anyway. */
        if (cta.previousElementSibling === el) return true; // already there
        if (cta.parentElement) {
            cta.parentElement.insertBefore(el, cta);

            return true;
        }

        return false;
    }

    /**
     * The horizontal band of the theme's on-screen content column, in viewport
     * pixels: {left, width}. Body-level fixed overlays (the combo FAB, the
     * checkout-feedback banner) are pinned to the VIEWPORT, but every modern theme
     * renders its content as a centered column on desktop (crave's 430px phone
     * frame, velora's 840, crumb's 1178, aurora's centered wrap). Pinned to the
     * viewport those overlays land in the desktop letterbox, far outside the frame.
     * This gives them the column to align to.
     *
     * The scroll host of the menu grid is the frame on the app-shell themes
     * (crave/matcha/velora/crumb — a fixed, centered `.view-scroll`); window-scroll
     * themes (aurora/vivid) have none, so the grid's own rect is the content-left
     * proxy. Cached and only ever REPLACED by a fresh good read: the grid is hidden
     * once a theme swaps to its cart/confirmation view (where the banner shows), and
     * a hidden node measures zero — so we keep the last band measured while it was
     * visible. The band is horizontal only, so it survives scrolling and view swaps;
     * a resize invalidates it (widths change), hence recompute-if-present each call.
     */
    var _band = null;
    function frameBand() {
        var grid = menuGrid(null);
        if (grid) {
            var host = scrollHost(grid);
            var box = (host || grid).getBoundingClientRect();
            if (box.width > 200 && box.width <= window.innerWidth + 2) {
                _band = { left: Math.round(box.left), width: Math.round(box.width) };
            }
        }
        return _band;
    }
    if (typeof window !== 'undefined') {
        window.addEventListener('resize', function () { _band = null; frameBand(); });
    }

    return { menuGrid: menuGrid, place: place, placeTop: placeTop, placeBottom: placeBottom, placeCheckout: placeCheckout, ink: ink, onSettled: onSettled, frameBand: frameBand };
})();
</script>
