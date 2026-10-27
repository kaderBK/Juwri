{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php($__l = $vm['features']['loyalty'] ?? null)
@if ($__l && ! empty($__l['enabled']))
<section class="c-card-block c-loyalty" aria-label="{{ $kit->s('Join and earn') }}">{{ $kit->icon('sparkles') }}<p class="c-loyalty__text">{{ $kit->s('Join and earn') }}</p>@if (! empty($vm['endpoints']['account']))<a class="c-btn c-btn--secondary" href="{{ $vm['endpoints']['account'] }}">{{ $kit->s('Sign in') }}</a>@endif</section>
@endif
