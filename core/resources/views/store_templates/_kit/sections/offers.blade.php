{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php($__off = $vm['features']['offers'] ?? [])
@if ($__off)
<section class="c-offers" aria-label="{{ $kit->s('Offers') }}"><ul class="c-rail c-offers__rail">
@foreach ($__off as $__o)
<li><button class="c-offer" type="button" @if (! empty($__o['code'])) data-kit-apply-offer="{{ $__o['code'] }}" @endif>{{ $kit->icon('tag') }}<span class="c-offer__title">{{ $kit->flow($__o['title']) }}</span>@if (! empty($__o['code'])) <span class="c-offer__code">{{ $kit->ltr($__o['code']) }}</span>@endif</button></li>
@endforeach
</ul></section>
@endif
