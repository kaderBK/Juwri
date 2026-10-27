{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- one state card (empty, closed, minimum, zone, payment failed, offline ...): what happened, then what the shopper can do --}}
@php($__sid = 'state-'.$kit->slug($title))
<article class="c-state c-state--{{ $tone }}" aria-labelledby="{{ $__sid }}"><span class="c-state__icon">{{ $kit->icon($icon) }}</span>
    <div class="c-state__body"><h2 class="c-state__title" id="{{ $__sid }}">{{ $title }}</h2><p class="c-state__text" @if (! empty($live)) role="status" aria-live="polite" @endif>{{ $body }}</p>
        @isset($extraView)@include($extraView['view'], $extraView['data'])@endisset
        @if (! empty($primary) || ! empty($secondary))<div class="c-state__actions">@if (! empty($primary))<a class="c-btn c-btn--primary" href="{{ $vm['endpoints']['home'] }}">{{ $primary }}</a>@endif @if (! empty($secondary))<a class="c-btn c-btn--secondary" href="{{ $vm['endpoints']['home'] }}">{{ $secondary }}</a>@endif</div>@endif
    </div>
</article>
