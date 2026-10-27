{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- read-only lines of a quote (receipt / order summary) --}}
<ul class="c-lines">
@foreach ($quote['lines'] as $__l)
    <li class="c-line">@if (! empty($__l['thumb']))<img class="c-line__thumb" src="{{ $__l['thumb']['src'] }}" alt="" width="64" height="64" decoding="async" loading="lazy">@else<span class="c-line__thumb" aria-hidden="true"></span>@endif
        <div class="c-line__body"><p class="c-line__name">{{ $__l['name'] }}</p><p class="c-line__choices">{{ $kit->flow($__l['choices_label'] ?? '') }}</p>@if (! empty($__l['note']))<p class="c-line__note">{{ $__l['note'] }}</p>@endif<div class="c-line__row"><span class="c-line__qty">{{ $kit->flow($__l['qty'].'×') }}</span></div></div>
        <p class="c-line__price">{{ $kit->money($__l['line_total']) }}</p></li>
@endforeach
</ul>
