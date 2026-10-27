{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- store logo: the logo image, else a 2-letter monogram on the primary colour --}}
@php($__cls = $cls ?? 'c-logo')
{{-- kiosk: the logo is the staff-exit target (a 3 s long press opens the PIN pad, core JS) --}}
@php($__ke = ! empty($kioskExit) || ! empty($vm['context']['kiosk']['on']))
{{-- a placeholder image stands for "no logo" (the kit's fixtures use one): the monogram shows instead --}}
@if (! empty($vm['store']['logo']) && ! str_contains((string) ($vm['store']['logo']['src'] ?? ''), '/img/placeholder-'))
<span class="{{ $__cls }}" @if ($__ke) data-kiosk-exit @endif><img class="c-logo__img" src="{{ $vm['store']['logo']['src'] }}" alt="{{ $vm['store']['logo']['alt'] ?? '' }}" width="76" height="76" decoding="async"></span>
@else
<span class="{{ $__cls }}" aria-hidden="true" @if ($__ke) data-kiosk-exit @endif><span class="c-logo__mark">{{ $kit->monogram($vm['store']['name']) }}</span></span>
@endif
