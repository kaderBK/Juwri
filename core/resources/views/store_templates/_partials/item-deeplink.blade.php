{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Item deep-link — open a specific menu item from the URL, on every theme.

  Reported against several themes as "the stories/banner CTA opens a new page of
  the same home page instead of navigating to the item". The seeded CTAs pointed
  at the store home because no theme exposed a per-item URL to point at: each
  theme opens its item sheet from a JS click, not a route. This gives the whole
  fleet ONE addressable form — /{slug}?item={menu_id} — resolved to the theme's
  own card and clicked open, reusing the exact cross-theme resolver the offers
  strip already relies on (open hook wins over a node that merely carries the id).

  Included once by the layout. Does nothing without ?item=. Items on several
  themes render from JS after load, so it retries briefly rather than firing once.

  Keep Blade echo/directive syntax and any literal script tag out of the comments:
  Blade compiles interpolation inside a script block and inside JS comments alike,
  and CspEnforceTest reads a script tag in a comment as an un-nonced inline script.
--}}
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    var params = new URLSearchParams(window.location.search);
    var id = params.get('item');
    if (!id || !/^[0-9]+$/.test(id)) return;

    var HOOKS = ['[data-open-item="', '[data-open="'];
    var IDENTS = ['[data-item-id="', '[data-menu-id="', '[data-id="'];

    function themeCard() {
        var lists = [HOOKS, IDENTS];
        for (var l = 0; l < lists.length; l++) {
            var sel = lists[l].map(function (p) { return p + id + '"]'; }).join(', ');
            var nodes = document.querySelectorAll(sel);
            for (var i = 0; i < nodes.length; i++) {
                // Skip a match inside the shared offers strip (its cards carry
                // data-mbo-item, not the theme's open hook — but be safe, and
                // don't name the strip's id here so no-offers page assertions hold).
                if (!nodes[i].closest('[data-offer-strip]')) return nodes[i];
            }
        }
        return null;
    }

    var tries = 0;
    function attempt() {
        var target = themeCard();
        if (target) {
            target.scrollIntoView({ block: 'center' });
            (target.querySelector('[data-open-item], [data-open]') || target).click();
            return;
        }
        if (tries++ < 40) { setTimeout(attempt, 150); } // ~6s for themes that build cards from JS
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attempt);
    } else {
        attempt();
    }
})();
</script>
