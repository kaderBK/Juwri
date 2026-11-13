{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- What is being paid for: product, billing interval, total. Expects $transaction. --}}
<dl class="ui-pay__summary">
    <div>
        <dt>{{ ___('Title') }}</dt>
        <dd>{{ $transaction->product_name }}
            @if(!empty(@$transaction->frequency))
                <span class="ui-text-2 ui-capitalize">({{ plan_interval_text($transaction->frequency) }})</span>
            @endif
        </dd>
    </div>
    <div class="ui-pay__total">
        <dt>{{ ___('Total') }}</dt>
        <dd class="ui-num">{{ price_code_format($transaction->amount) }}</dd>
    </div>
</dl>
