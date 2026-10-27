{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- a finished quote as one list (receipt): the server's rows in order, then the total --}}
<dl class="c-quote">
@foreach ($quote['rows'] ?? [] as $__r)
    <div class="c-quote__row{{ ($__r['kind'] ?? '') === 'discount' ? ' c-quote__row--discount' : '' }}"><dt>{{ $__r['label'] }}</dt><dd>@if (! empty($__r['amount_label'])){{ $__r['amount_label'] }}@else{{ $kit->money($__r['amount']) }}@endif</dd></div>
@endforeach
    <div class="c-quote__row c-quote__row--total"><dt>{{ $quote['total_label'] ?? $kit->s('Total') }}</dt><dd>{{ $kit->money($quote['total']) }}</dd></div>
</dl>
