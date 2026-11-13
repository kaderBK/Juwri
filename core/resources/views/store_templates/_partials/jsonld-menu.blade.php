{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- F8 — Restaurant + Menu schema.org JSON-LD for the storefront menu page.
     Mirrors the marketplace partial but uses the STOREFRONT canonical URL. Only
     emitted on the menu index (guarded on $total_menus, which publicView sets),
     so it stays off the track/account/payment renders that share this layout.
     `application/ld+json` is data, not executable script — CSP script-src does
     not apply, so no nonce is needed. --}}
@if(isset($total_menus) && !empty($total_menus))
@php
    try {
        /* B1: pass the active branch (geo repoint → real Branch coords) and the
           store's active menu languages (inLanguage). Both are optional and
           guarded — this partial also renders where they are not in scope. */
        $__jsonLd = \Juwri\Modules\Storefront\Services\Seo\StoreJsonLdBuilder::forStorefront(
            $store,
            $activeBranch ?? null,
            $menu_languages ?? []
        );
        /* F8b — owner-gated (per-store option `menu_seo_jsonld`, DEFAULT OFF): add
           dish names as schema.org MenuItems for richer AEO/SEO. Built from the
           already-rendered $total_menus (never a fresh active=1 query), so it can
           never leak an item the plan menu-limit / branch / availability gate hid. */
        if (! empty($__jsonLd)
            && store_options($store->id, 'menu_seo_jsonld')
            && isset($total_menus) && ! empty($total_menus)
            && ($__menuNode = \Juwri\Modules\Storefront\Services\Seo\StoreJsonLdBuilder::menuFromMenus($total_menus, $store))) {
            $__jsonLd['hasMenu'] = $__menuNode;
        }
    } catch (\Throwable $e) {
        $__jsonLd = [];   // structured data must never break a menu render
    }
@endphp
@if(!empty($__jsonLd))
<script type="application/ld+json" nonce="{{ csp_nonce() }}">{!! json_ld($__jsonLd) !!}</script>
@endif
@endif
