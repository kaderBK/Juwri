{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    html-mode builder RUNTIME assets — behaviour primitives + their CSS.

    Included by every public builder-page render (CMS / reseller / biolink). The
    JS scans the sanitised `.mb-doc` for `data-mb-*` hooks (accordion/tabs/carousel/
    reveal/count-up/rotate/parallax) that operators cannot express as script, and
    wires them accessibly + reduced-motion-aware. Both files are served from the
    repo-root docroot ('self'), so they satisfy even the enforcing storefront CSP
    (script-src 'self', style-src 'self'); the script also carries the request nonce
    for defence in depth. Cache-busted by file mtime.
--}}
@php
    $mbRtJs = 'assets/builder/runtime/mb-builder.js';
    $mbRtCss = 'assets/builder/runtime/mb-builder.css';
    $mbRtVer = config('appinfo.version', '1').'.'.(@filemtime(public_path($mbRtJs)) ?: '0');
@endphp
<link rel="stylesheet" href="{{ asset($mbRtCss.'?ver='.$mbRtVer) }}">
<script src="{{ asset($mbRtJs.'?ver='.$mbRtVer) }}" nonce="{{ csp_nonce() }}" defer></script>
