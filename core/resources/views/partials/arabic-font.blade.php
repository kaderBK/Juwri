{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    /* Saudi Riyal rollout — S11: always-on IBM Plex Sans Arabic @font-face for the
     * dashboard / admin / POS surfaces, whose Latin-only body font would otherwise
     * drop Arabic currency signs (د.إ, ر.ق, ﷼) to a system fallback. Scoped by
     * unicode-range to the Arabic blocks so Latin text is untouched. Self-hosted.
     *
     * (Written "style tag", not the literal markup, so CSP grep tests cannot
     * confuse this prose with markup.)
     */
    $__arabicFaceCss = \Juwri\Modules\Storefront\Services\Theme\FontPairs::arabicFaceCss();
@endphp
@if($__arabicFaceCss !== '')
<style nonce="{{ csp_nonce() }}">{!! $__arabicFaceCss !!}</style>
@endif
