{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Site block: cta_banner. Content arrives pre-resolved (flat strings) from
     SitePageRenderer; cta_url passes through SafeUrl before it reaches an href.
     settings.style: solid | gradient. Colours come from the kit accent (brand /
     per-site tokens) — never from block settings. --}}
@php
    $sbC = $block['content'] ?? [];
    $sbUrl = \Juwri\Core\Support\SafeUrl::web($sbC['cta_url'] ?? null);
    $sbGrad = (($block['settings']['style'] ?? 'solid') === 'gradient');
@endphp
<section class="mk-sec sb-block" data-site-block="cta_banner">
    <div class="mk-wrap">
        <div class="mk-cta{{ $sbGrad ? ' sb-cta--gradient' : '' }}">
            @if (($sbC['headline'] ?? '') !== '')
                <h2 class="mk-h2">{{ $sbC['headline'] }}</h2>
            @endif
            @if (($sbC['subline'] ?? '') !== '')
                <p>{{ $sbC['subline'] }}</p>
            @endif
            @if ($sbUrl && ($sbC['cta_label'] ?? '') !== '')
                <div class="mk-actions">
                    <a href="{{ $sbUrl }}" class="ui-btn">{{ $sbC['cta_label'] }}</a>
                </div>
            @endif
        </div>
    </div>
</section>
