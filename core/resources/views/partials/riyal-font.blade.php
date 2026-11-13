{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    /* Saudi Riyal Symbol (U+20C1) — the always-on @font-face, shared across every
     * surface that echoes a currency sign: storefronts, owner dashboard, admin,
     * and the staff POS/KDS screens.
     *
     * Maps ONLY U+20C1 (via unicode-range) to the vendored Saudi Riyal glyph
     * (regular + bold), so it is inert for all other text. The family
     * 'Saudi Riyal' is prepended to each surface's body/price font stack by the
     * including layout, so the glyph is in the effective stack wherever a price
     * is drawn. No system font maps U+20C1 yet (Unicode 17.0, 2025).
     *
     * Self-hosted, same-origin via asset(). (Written "style tag", not the literal
     * markup, so CSP grep tests cannot confuse this prose with markup.)
     */
    $__riyalFaceCss = \Juwri\Modules\Storefront\Services\Theme\FontPairs::riyalFaceCss();
@endphp
@if($__riyalFaceCss !== '')
<style nonce="{{ csp_nonce() }}">{!! $__riyalFaceCss !!}</style>
@endif
