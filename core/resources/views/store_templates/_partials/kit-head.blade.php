{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P3-05: the core's design-token, font-pair and riyal-glyph blocks for a kit theme's <head>. Core partials: they read the store
     ($store) and the saved theme settings, which a kit theme's own views must not (THEME-CONTRACT section 4). Nothing renders without a store
     (the conformance fixtures). --}}
@isset($store)
@include('store_templates._partials.theme-tokens')
@include('store_templates._partials.theme-fonts')
@include('store_templates._partials.riyal-font')
@endisset
