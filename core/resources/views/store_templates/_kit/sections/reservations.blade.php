{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php($__r = $vm['features']['reservations'] ?? null)
@if ($__r && (! empty($__r['table']) || ! empty($__r['waitlist'])))
<section class="c-card-block c-reserve" data-section="reservations" aria-labelledby="reserve-title"><span class="c-reserve__icon">{{ $kit->icon('calendar') }}</span>
<div class="c-reserve__text"><h2 class="c-reserve__title" id="reserve-title">{{ $kit->s('Book a table') }}</h2><p>{{ $kit->s('Reserve your table and we will have it ready.') }}</p></div>
<div class="c-reserve__actions">@if (! empty($__r['table']))<a class="c-btn c-btn--primary" href="#reserve">{{ $kit->s('Book a table') }}</a>@endif @if (! empty($__r['waitlist']))<a class="c-btn c-btn--secondary" href="#waitlist">{{ $kit->s('Join Waitlist') }}</a>@endif</div></section>
@endif
