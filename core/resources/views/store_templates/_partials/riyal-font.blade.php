{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    /* Saudi Riyal Symbol (U+20C1) rollout — S2: the always-on @font-face.
     *
     * Unlike the opt-in font-pair faces (_partials/theme-fonts.blade.php), this
     * partial is emitted on EVERY storefront view, unconditionally. It maps the
     * single codepoint U+20C1 to the vendored Saudi Riyal glyph (regular + bold).
     *
     * `unicode-range:U+20C1` on each face means the browser fetches and applies
     * the face ONLY for that one character — zero visual effect on any other text.
     * The family 'Saudi Riyal' is prepended to the START of the storefront's body
     * font stack and the --menu-font variable (see layout.blade.php) and to every
     * font-pair stack (Juwri\Modules\Storefront\Services\Theme\FontPairs::stacks), so the glyph is in
     * the effective stack wherever a price is drawn. No system font maps U+20C1
     * yet (Unicode 17.0, 2025), so without this it renders as a tofu box.
     *
     * Self-hosted, same-origin via asset() — no CSP change, no CDN.
     *
     * (Written "style tag", not the literal markup, so CspEnforceTest's grep for
     * nonce-less opening style tags cannot confuse this prose with markup.)
     *
     * Delegates to the shared partials.riyal-font emitter so every surface (this
     * storefront one plus dashboard/admin/staff) ships byte-identical @font-face.
     */
@endphp
@include('partials.riyal-font')
