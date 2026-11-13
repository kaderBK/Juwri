{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- the server quote: rows in the server's order, then the total. The cart JS fills [data-juwri-rows] and [data-juwri-total];
     $cart (a quote) prefills the same markup for a server-rendered page. Nothing is added or rounded here. --}}
@php($__q = $cart ?? null)
<dl class="c-quote" data-juwri-rows>
    <template data-juwri-row><div class="c-quote__row"><dt data-juwri-row-label></dt><dd data-juwri-row-amount></dd></div></template>
    @foreach ($__q['rows'] ?? [] as $__r)
    <div class="c-quote__row" data-kind="{{ $__r['kind'] ?? '' }}"@if (! empty($__r['informational'])) data-informational @endif><dt data-juwri-row-label>{{ $__r['label'] }}</dt><dd data-juwri-row-amount>{{ $__r['amount_label'] ?? $__r['amount']['formatted'] }}</dd></div>
    @endforeach
</dl>
<dl class="c-quote c-quote--total"><div class="c-quote__row c-quote__row--total"><dt>{{ $__q['total_label'] ?? $kit->s('Total') }}</dt><dd data-juwri-total>{{ $__q['total']['formatted'] ?? '' }}</dd></div></dl>
