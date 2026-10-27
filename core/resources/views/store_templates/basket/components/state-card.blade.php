{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- basket: one state card (tag, icon + title, what happened, what the shopper can do) --}}
@php($__sid = 'state-'.$kit->slug($tag))
<article class="c-state c-state--{{ $tone }}" aria-labelledby="{{ $__sid }}"><p class="c-state__tag" aria-hidden="true">{{ $tag }}</p>
    <div class="c-state__head"><span class="c-state__icon">{{ $kit->icon($icon) }}</span><h2 class="c-state__title" id="{{ $__sid }}">{{ $title }}</h2></div>
    <p class="c-state__text" @if (! empty($live)) role="status" aria-live="polite" @endif>{{ $body }}</p>
    @isset($extraView)@include($extraView['view'], $extraView['data'])@endisset
    @if (! empty($actions))<div class="c-state__actions">@foreach ($actions as [$__label, $__kind])<a class="c-btn c-btn--{{ $__kind }}" href="{{ $vm['endpoints']['home'] }}">{{ $__label }}</a>@endforeach</div>@endif
</article>
