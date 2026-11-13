{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Make the back control on the storefront SUBPAGES actually go back.

  account / profile / track are real pages, not SPA views, so their back chevron
  is a plain <a> pointing at the storefront home. That is the only destination it
  can name in markup — which is why every route through these pages ends at the
  menu: profile → My Orders → back lands on home instead of profile, and the same
  for track → back, on all 17 themes.

  Upgrades the link at click time to history.back() when the previous page is one
  of ours, and otherwise leaves it exactly as it is: a diner who opened /account
  straight from a QR code has no history to return to, and must still get the menu
  rather than being bounced off the site. No JS, no change — the href still works.

  Only the back chevron is claimed, never the "View Menu" buttons that share the
  same href: those name a destination, so they keep taking you there. A link
  counts as back when it says so (`data-back` / `data-mb-back`), when its class
  says so, or when it is icon-only — the shapes the 17 themes actually use.
--}}
@php
    /* Subpages only. On the storefront index a same-href icon-only link is the
       brand logo, and turning that into a back button would be a different bug. */
    $__blHome = (isset($store) && ! request()->routeIs('publicView'))
        ? storefront_localized_link(route('publicView', $store->slug))
        : null;
@endphp
@if($__blHome)
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    "use strict";
    function abs(u) { var a = document.createElement('a'); a.href = u; return a.href; }

    var HOME = abs(@json($__blHome));

    function isBackLink(a) {
        if (a.hasAttribute('data-mb-back') || a.hasAttribute('data-back')) return true;
        if (/(^|[\s-])back([\s-]|$)/i.test(a.getAttribute('class') || '')) return true;

        return !(a.textContent || '').trim(); // icon-only chevron
    }

    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
        if (!a || a.target === '_blank' || a.href !== HOME || !isBackLink(a)) return;

        /* Same-origin previous page only. An empty or foreign referrer means the
           diner arrived here directly (QR, a shared link, a search result) and
           history.back() would take them off the storefront entirely. */
        var ref = document.referrer;
        if (!ref) return;
        var r = abs(ref);
        if (r.indexOf(window.location.origin + '/') !== 0) return;
        if (r === window.location.href) return; // a reload of this same page

        e.preventDefault();
        window.history.back();
    }, true);
})();
</script>
@endif
