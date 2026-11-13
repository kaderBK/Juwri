{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  AL-20 (auto-locale v1) — first-visit CURRENCY default for the storefront.
  Client-only by contract: the server HTML is identical for every visitor (the
  country→currency map is a static, store-scoped lookup, not the visitor's own
  country), and the actual selection is a programmatic click on the theme's OWN
  [data-cur] switcher button AFTER load — so it never reaches a charge, a stored
  total, or the JSON-LD (which stays in the base currency, server-rendered).

  Turns the visitor's region (server geo QC_GEO if provisioned, else the
  navigator.language region subtag) into a candidate currency the store actually
  offers, and — only if that currency's switcher button is present (i.e. the store
  set a rate for it) and it isn't the base and the visitor hasn't already chosen —
  clicks it. A no-rates store has no non-base [data-cur] buttons, so it is a no-op.
  Themes without a switcher (feast/velvet/picnic) simply have no button to click.
--}}
@php
    $__cdBase = strtoupper((string) store_currency($store->id ?? 0));
    $__cdOffered = [];
    try {
        $__cdOffered = \Juwri\Modules\Storefront\Storefront\DisplayRates::forStore($store->id ?? 0)->codes();
    } catch (\Throwable $e) {
        $__cdOffered = [];
    }
    $__cdMap = $__cdOffered ? currency_country_map($__cdOffered) : [];
    $__cdShow = ! empty($postOptions->enable_multi_currency ?? null) && count($__cdMap) > 0;
@endphp

@if ($__cdShow)
    <script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
    (function () {
        var MAP = @json($__cdMap);          // { COUNTRY: CURRENCY } — offered currencies only
        var BASE = @json($__cdBase);
        var SLUG = @json($store->slug ?? '');

        // Respect a prior explicit currency pick for THIS store (set on switch below).
        var choiceKey = 'qc_cur_choice_' + SLUG;
        try { if (localStorage.getItem(choiceKey)) return; } catch (e) {}

        // Region: server geo first (QC_GEO country, only when the owner provisioned
        // geo — AL-40), else the navigator.language region subtag. IP never read here.
        var country = (window.QC_GEO || '').toString().toUpperCase();
        if (!country) {
            var langs = (navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language || '']);
            for (var i = 0; i < langs.length && !country; i++) {
                var p = (langs[i] || '').split('-');
                if (p[1]) country = p[1].toUpperCase();
            }
        }
        if (!country) return;

        var candidate = MAP[country];
        if (!candidate || candidate === BASE) return; // no offered currency for the region, or it's already base

        function apply() {
            // The rendered switcher button is the source of truth for "offered by
            // THIS store" (derived from the same rates the CFG carries). Absent =
            // no-op (no-rates store, unoffered currency, or a switcher-less theme).
            var btn = document.querySelector('[data-cur="' + (window.CSS && CSS.escape ? CSS.escape(candidate) : candidate) + '"]');
            if (!btn) return;
            try { localStorage.setItem(choiceKey, candidate); } catch (e) {} // it's now this visitor's default
            btn.click();
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', apply);
        } else {
            apply();
        }
    })();
    </script>
@endif
