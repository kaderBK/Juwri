{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- book: the booking summary — the lines, then the rows that sum to the total (Quote, server-formatted) --}}
@php
    $__q = $quote;
    $__rows = collect($__q['rows']);
    // an ordering quote (the laundry's) keeps its total apart from the rows; a booking's carries it as a row (or labels its only subtotal "Total")
    $__printTotal = \Juwri\Core\Support\ViewHelpers::quoteNeedsTotal($__rows, $__q['total_label'] ?? $kit->s('Total'));
@endphp
<ul class="c-bklines">
@foreach ($__q['lines'] as $__l)
    <li class="c-bkline"><div class="c-bkline__text"><p class="c-bkline__name">{{ $__l['name'] }}</p>@if (! empty($__l['choices_label']))<p class="c-bkline__sub">{{ $kit->flow($__l['choices_label']) }}</p>@endif</div><p class="c-bkline__price">{{ $kit->money($__l['line_total']) }}</p></li>
@endforeach
</ul>
<dl class="c-bkq">
@foreach ($__rows as $__r)
    <div class="c-bkq__row c-bkq__row--{{ $__r['kind'] }}"><dt>{{ $__r['label'] }}</dt><dd>@if (! empty($__r['amount_label'])){{ $__r['amount_label'] }}@else{{ $kit->money($__r['amount']) }}@endif</dd></div>
@endforeach
@if ($__printTotal)
    <div class="c-bkq__row c-bkq__row--total"><dt>{{ $__q['total_label'] ?? $kit->s('Total') }}</dt><dd>{{ $kit->money($__q['total']) }}</dd></div>
@endif
</dl>
