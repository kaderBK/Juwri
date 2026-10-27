{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Home-page order type.

  Reported as "you cannot change the order type from the home page", and the
  capability matrix confirmed it of every retained theme INCLUDING the
  reference: all seventeen render their order-type control inside checkout, so
  a diner browsing the menu cannot say "this is for delivery" until they are
  already paying. orbit, velora and crumb once surfaced their own picker on home;
  they now keep it hidden in the DOM and use this bar as the visible one. The script
  still stands down for any theme whose own control is visible on home.

  It does NOT post its own value. The plan was to force `ordering-type` through
  the field forwarder's overrides() path, the way charge-to-room does, but that
  turned out to be both unnecessary and unsafe:

    - unnecessary, because a click on the theme's own (hidden) control is
      honoured on all fourteen themes — their handlers are delegated on
      document, so the control does not have to be on screen. Measured on each
      one against a NON-default value; an earlier probe clicked `delivery`,
      which is already the default nearly everywhere, and reported eight themes
      as unresponsive when every one of them worked;
    - unsafe, because submitOrder validates `address` as required_if delivery and
      `table` as required_if on-table. Overriding the type in the POST body
      while the theme's checkout collected neither would turn a working order
      into a rejected one. Driving the theme's own state means the theme
      renders its own dependent fields and its own payload is already right.

  So this is a remote control for a control the theme already has, and the
  theme stays the single source of truth for what gets submitted.
--}}
@php
    /* Same 17-template scope as the offers strip: the shared layout also serves
       ~40 legacy templates carrying nearly every live store, and this injects
       markup into a page it knows nothing about. */
    $hotTemplates = \Juwri\Modules\Storefront\Services\Theme\KeptTemplates::MODERN;

    $hotTypes = [];
    $hotPost = $store ?? null;

    if (is_object($hotPost) && ! empty($hotPost->id)
        && in_array(app(\App\Services\StorefrontThemeService::class)->resolveSaved($hotPost), $hotTemplates, true)) {
        /* Only what the store actually sells, in the vocabulary submitOrder
           validates (`in:on-table,takeaway,delivery,room_service`).

           room_service is deliberately absent. It only exists when the guest
           scanned a room QR, the themes already default to it in that case, and
           offering a way OUT of it on the home page would let a hotel guest
           silently drop their order off the folio. The whole control stands
           down in that situation — see the script. */
        foreach ([
            ['delivery', ! empty($allow_delivery), ___('Delivery')],
            ['takeaway', ! empty($allow_takeaway), ___('Takeaway')],
            ['on-table', ! empty($allow_on_table), ___('Dine in')],
        ] as [$value, $enabled, $label]) {
            if ($enabled) {
                $hotTypes[] = ['value' => $value, 'label' => $label];
            }
        }
    }

    // One option is not a choice; rendering a single dead chip is worse than
    // rendering nothing.
    if (count($hotTypes) < 2) {
        $hotTypes = [];
    }
@endphp

@if(! empty($hotTypes))
<section class="mbt-bar" id="mbOrderType" hidden aria-label="{{ ___('Order type') }}">
    <div class="mbt-seg" role="group">
        @foreach($hotTypes as $hotType)
            <button class="mbt-chip" type="button" data-mbt="{{ $hotType['value'] }}" aria-pressed="false">{{ $hotType['label'] }}</button>
        @endforeach
    </div>
</section>

<style @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
.mbt-bar{padding:12px 16px 2px;font-family:var(--tf-body,inherit)}
.mbt-bar[hidden]{display:none!important}
/* Same reasoning as the offers strip: no surface of its own beyond the segment
   track, and text colour comes from --mb-ink, computed from whatever the bar
   ends up sitting on. A theme's own text colour is only safe on the surface it
   was chosen for, and this bar moves. */
/* Full-width segmented control (the soiree look as the shared default): the track
   spans the column and the chips share it equally, so the bar reads as a real
   order-type picker instead of a tiny inline pill. Themes that ship their own
   #mbOrderType skin (soiree/scoop/crave/aurora/vivid/velvet) still override this. */
.mbt-seg{display:flex;width:100%;gap:3px;padding:4px;border-radius:999px;background:rgba(127,127,127,.16)}
/* ID-scoped so --mb-ink actually wins. The chips are <button>s, and a host
   theme's own button rule out-specifies a bare `.mbt-chip` (0,1,0): dough's
   `.dough button{color:inherit}` (0,1,1) dragged the inactive chips to the
   theme's inherited text colour — invisible on light themes. `#mbOrderType
   .mbt-chip` (1,1,0) beats any class/element theme rule. The offers strip needs
   no such guard because its text is headings/spans, not buttons. The pressed
   rule is scoped to match so it keeps winning over this base. */
#mbOrderType .mbt-chip{flex:1;display:flex;align-items:center;justify-content:center;
    appearance:none;border:0;background:none;padding:11px 14px;border-radius:999px;cursor:pointer;
    font:inherit;font-size:13.5px;font-weight:700;line-height:1.2;color:var(--mb-ink,inherit);opacity:.72;white-space:nowrap;
    transition:background .18s ease,color .18s ease,opacity .18s ease}
#mbOrderType .mbt-chip[aria-pressed="true"]{opacity:1;background:var(--tc-primary,var(--theme-color-1,#e0480f));color:var(--tc-on-primary,#fff)}
/* 7px of padding on a 13px line draws a ~30px chip — under the 44px tap floor,
   in all 17 templates at once, on the control that picks delivery vs pickup.
   The pill height is the design (a 44px track would tower over every host
   theme), so a transparent ::after carries the hit area instead. The chips sit
   2px apart inside .mbt-seg, so inline growth is capped at half that; the block
   axis is free and takes it to 44. */
.mbt-chip{position:relative}
/* Full-width flex chips already carry a wide hit area; extend only the block axis
   to clear the 44px tap floor. Horizontal inset stays 0 so a chip never overlaps
   the 3px gap into its neighbour's tap zone. */
.mbt-chip::after{content:"";position:absolute;inset:-3px 0}
</style>

<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    var bar = document.getElementById('mbOrderType');
    // mbAnchor comes from _partials/theme-anchor, included just above this in
    // the layout. Guarded rather than assumed: an include-order change would
    // otherwise throw a ReferenceError on every storefront.
    if (!bar || !window.mbAnchor) return;

    /* The theme's own control, in the theme's own vocabulary. picnic and scoop
       speak `pickup`/`dinein` and map them to the server's words at submit
       time, so the alias list is what makes one shared control work on all of
       them. Order matters: the server's own word first. */
    var ALIASES = {
        'delivery': ['delivery'],
        'takeaway': ['takeaway', 'pickup'],
        'on-table': ['on-table', 'on_table', 'dinein', 'dine-in'],
    };
    var ATTRS = ['data-ot', 'data-otype', 'data-order-type', 'data-type'];

    function themeControls() {
        var out = [];
        var nodes = document.querySelectorAll('[' + ATTRS.join('],[') + ']');
        for (var i = 0; i < nodes.length; i++) {
            if (bar.contains(nodes[i])) continue;
            for (var a = 0; a < ATTRS.length; a++) {
                var v = nodes[i].getAttribute(ATTRS[a]);
                if (v) { out.push({ el: nodes[i], value: v }); break; }
            }
        }
        return out;
    }

    function controlFor(value, controls) {
        var names = ALIASES[value] || [value];
        for (var n = 0; n < names.length; n++) {
            for (var i = 0; i < controls.length; i++) {
                if (controls[i].value === names[n]) return controls[i].el;
            }
        }
        return null;
    }

    function visible(el) {
        var r = el.getBoundingClientRect();
        if (r.width <= 0 || r.height <= 0) return false;
        var c = window.getComputedStyle(el);

        return c.display !== 'none' && c.visibility !== 'hidden' && parseFloat(c.opacity) > 0.01;
    }

    /* Whatever a theme calls "selected" — is-on (most), active (aurora, vivid,
       feast, velvet), plus the standard form/ARIA states. */
    function isSelected(el) {
        return /(^|\s)(is-on|active|selected|is-active|current)(\s|$)/.test(String(el.className))
            || el.getAttribute('aria-pressed') === 'true'
            || el.getAttribute('aria-selected') === 'true'
            || (el.tagName === 'INPUT' && el.checked);
    }

    var chips = [].slice.call(bar.querySelectorAll('.mbt-chip'));

    function paint(value) {
        chips.forEach(function (c) {
            c.setAttribute('aria-pressed', c.getAttribute('data-mbt') === value ? 'true' : 'false');
        });
    }

    /* Stand-down is decided during the settle window only, never again.
       The check asks whether the theme shows an order-type control on the page
       — and once a guest OPENS CHECKOUT, every theme does. Re-deciding on each
       sync would delete the bar the first time anyone touched the theme's own
       control, which is precisely the interaction it exists to mirror. */
    var settled = false;

    function sync() {
        var controls = themeControls();

        if (settled) {
            repaint(controls);
            // Layout is fixed after settle, but a light/dark flip still needs the
            // ink refreshed — the chips read from --mb-ink, and a stale value
            // leaves them the colour of the surface they now sit on (invisible).
            mbAnchor.ink(bar);

            return;
        }

        /* Stand down, in three situations where showing this would be wrong:
           - the theme already surfaces its order type on the home page (a visible
             control of its own) — theirs is styled in their own tokens;
           - the guest scanned a room QR, so room service is on the table and
             the hotel flow owns the order type;
           - no control of the theme's was found at all, which would make every
             chip here a dead button. */
        var roomService = controls.some(function (c) { return /room[_-]?service/.test(c.value); });
        var nativeOnHome = controls.some(function (c) { return visible(c.el); });
        if (roomService || nativeOnHome || !controls.length) { bar.remove(); return; }

        // Never offer a type the theme does not actually have a control for.
        var offered = 0;
        chips.forEach(function (chip) {
            var hit = controlFor(chip.getAttribute('data-mbt'), controls);
            chip.hidden = !hit;
            if (hit) offered++;
        });
        if (offered < 2) { bar.remove(); return; }

        repaint(controls);

        bar.hidden = false;
        /* A theme can name its own insertion point with [data-mbt-anchor] and the
           bar lands immediately above THAT node instead of following the generic
           anchor. soiree uses it to keep the order type with the category chips
           rather than near the offers strip. Opt-in: a theme without the marker
           keeps the generic behaviour. */
        var pinned = document.querySelector('[data-mbt-anchor]');
        if (pinned && pinned.parentElement && !pinned.contains(bar)) {
            /* Place directly above the anchor, then KEEP re-asserting it. Other
               injected sections (offers strip, combo rail) settle on their own
               later cadences and reorder our shared parent, shoving the bar off
               the anchor after this one placement. Mirror the offers path: watch
               the parent and re-pin until the settle window closes.

               Mark ourselves as a grid-lead row: both the offers strip and the
               combo rail place via mbAnchor.place(), which climbs ABOVE any
               [data-mb-grid-lead] rows. Without this the combo rail (meal deals)
               re-inserts itself directly above the categories — i.e. between us
               and the anchor — on the JS-populated-category themes. With it, they
               climb above us, leaving the bar flush on top of the categories.
               Scoped to the anchor path so the legacy under-offers path (which
               deliberately sits the bar BELOW the strip) is unaffected. */
            bar.setAttribute('data-mb-grid-lead', '');
            placeAtAnchor(pinned);
            watchAnchor(pinned);
            return;
        }

        /* Sit directly UNDER the offers strip. Both this bar and the strip anchor
           themselves against the top of the menu on their own settle cadences, so
           they compete for that spot: the strip re-runs its own place() and, with
           this bar sitting between it and the grid, keeps re-inserting itself back
           below us. Park at the generic menu anchor until the strip lands, then
           re-assert under it on every sibling reorder until settle, so the LAST
           move of the fight is ours. The idempotency guard in placeUnderOffers()
           (no-op once already under the strip) keeps that from looping. */
        placeUnderOffers() || mbAnchor.place(bar);
        mbAnchor.ink(bar);
        watchOffers();
    }

    /* Move the bar to immediately AFTER the offers strip. Returns false (and does
       nothing) until the strip exists and has been placed. Idempotent: a no-op
       once the bar already follows the strip, so re-asserting never loops. */
    function placeUnderOffers() {
        var strip = document.getElementById('mbOffersStrip');
        if (!strip || strip.hasAttribute('data-mb-pending') || !strip.parentElement || bar.contains(strip)) {
            return false;
        }
        if (bar.previousElementSibling !== strip) {
            strip.parentElement.insertBefore(bar, strip.nextElementSibling);
            mbAnchor.ink(bar);
        }

        return true;
    }

    /* Re-assert placeUnderOffers whenever the bar's siblings reorder — i.e. when
       the strip re-places itself over us — until the settle window closes and the
       strip stops moving. Set up once. */
    function watchOffers() {
        if (bar.__mbtWatchOffers || typeof MutationObserver === 'undefined' || !bar.parentElement) return;
        bar.__mbtWatchOffers = true;
        var mo = new MutationObserver(function () { placeUnderOffers(); });
        mo.observe(bar.parentElement, { childList: true });
        // The strip settles by ~3s; give it a margin, then stop watching.
        setTimeout(function () { placeUnderOffers(); mo.disconnect(); bar.__mbtWatchOffers = false; }, 3600);
    }

    /* Pin the bar immediately ABOVE the theme's [data-mbt-anchor] node (its category
       selector). Idempotent: a no-op once already there, so re-asserting never loops. */
    function placeAtAnchor(pinned) {
        if (!pinned || !pinned.parentElement || pinned.contains(bar)) return;
        if (pinned.previousElementSibling !== bar) {
            mbAnchor.place(bar, pinned);
            mbAnchor.ink(bar);
        }
    }

    /* Re-assert placeAtAnchor whenever the shared parent reorders — i.e. when a
       later-settling section (offers strip, combo rail) inserts itself and pushes
       the bar off the anchor — until the settle window closes. Set up once. */
    function watchAnchor(pinned) {
        placeAtAnchor(pinned);
        if (bar.__mbtWatchAnchor || typeof MutationObserver === 'undefined' || !pinned.parentElement) return;
        bar.__mbtWatchAnchor = true;
        var mo = new MutationObserver(function () { placeAtAnchor(pinned); });
        mo.observe(pinned.parentElement, { childList: true });
        setTimeout(function () { placeAtAnchor(pinned); mo.disconnect(); bar.__mbtWatchAnchor = false; }, 3600);
    }

    // Reflect the theme's current choice rather than asserting our own.
    function repaint(controls) {
        var live = (controls || themeControls()).filter(function (c) { return isSelected(c.el); })[0];
        if (!live) return;
        for (var value in ALIASES) {
            if (ALIASES[value].indexOf(live.value) !== -1) { paint(value); return; }
        }
    }

    bar.addEventListener('click', function (e) {
        var chip = e.target.closest('.mbt-chip');
        if (!chip) return;
        var value = chip.getAttribute('data-mbt');
        var target = controlFor(value, themeControls());
        if (!target) return;
        // The theme owns the state; paint optimistically, then re-read it.
        paint(value);
        target.click();
        setTimeout(sync, 250);
    });

    // A guest who changes their mind inside checkout must not come back to a
    // bar still showing the old choice.
    document.addEventListener('click', function (e) {
        if (!e.target.closest || bar.contains(e.target)) return;
        if (e.target.closest('[' + ATTRS.join('],[') + ']')) setTimeout(sync, 250);
    }, true);

    mbAnchor.onSettled(sync, bar);
    // After the settle window the layout is fixed; only the pressed state moves.
    setTimeout(function () { settled = true; }, 3000);
})();
</script>
@endif
