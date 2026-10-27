{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Site block: hero. Renders the kit home hero (marketing.sections.hero) with
     the block's copy overrides (headline, subline, cta_label, cta_url). The CTA
     link only accepts an http(s) URL or a site-relative path; anything else
     falls back to the sign-up link. --}}
@php
    $sbC = $block['content'] ?? [];
    $sbUrl = trim((string) ($sbC['cta_url'] ?? ''));
    $sbC['cta_url'] = \Juwri\Core\Support\SafeUrl::web($sbUrl)
        ?? (preg_match('#^/(?![/\\\\])#', $sbUrl) ? $sbUrl : '');
@endphp
<div class="sb-block" data-site-block="hero">
    @include('marketing.sections.hero', ['block' => ['content' => $sbC]])
</div>
