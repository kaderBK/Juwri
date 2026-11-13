{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $__r = $vm['features']['reviews'] ?? null;
    $__rt = $vm['store']['rating'] ?? null;
@endphp
@if ($__r)
<section class="c-card-block c-reviews" aria-labelledby="reviews-title"><h2 class="c-sectionhead__title" id="reviews-title">{{ $kit->s('Reviews') }}</h2>
<p class="c-reviews__summary">{{ $kit->icon('star') }}<span>@if ($__rt){{ $__rt['avg'] }} ({{ number_format($__rt['count']) }})@endif</span></p><a class="c-btn c-btn--secondary" href="#reviews">{{ $kit->s('All reviews') }}</a></section>
@endif
