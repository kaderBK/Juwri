{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $__s = $vm['store'];
    $__sub = $vm['theme']['texts']['hero_subtitle'] ?? null;
    $__title = ($vm['theme']['texts']['hero_title'] ?? null) ?: ($__s['tagline'] ?? null);
@endphp
@if (! empty($__s['cover']))
<div class="c-hero" data-section="hero" role="region" aria-label="{{ $__s['name'] }}"><img class="c-hero__img" src="{{ $__s['cover']['src'] }}" alt="" width="1440" height="280" decoding="async" loading="lazy">
@if ($__title)<div class="c-hero__card"><p class="c-hero__title">{{ $kit->flow($__title) }}</p>@if ($__sub)<p class="c-hero__sub">{{ $kit->flow($__sub) }}</p>@endif@include('store_templates._kit.components.meta-row', ['hero' => true])</div>@endif
</div>
@endif
