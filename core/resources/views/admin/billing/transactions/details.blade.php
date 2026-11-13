{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.panel :title="___('Transaction Details')">
    @php $discount = 0; @endphp
    <x-ui.list>
        <div class="ui-row"><span class="ui-row__body"><span class="ui-row__title">{{ ___('Title') }}</span></span><span class="ui-row__trail">{{ $title }}</span></div>
        <div class="ui-row"><span class="ui-row__body"><span class="ui-row__title">{{ ___('Price') }}</span></span><strong class="ui-row__trail">{{ price_symbol_format($transaction->base_amount ?? $transaction->amount) }}</strong></div>
        @if ($transaction->coupon)
            @php
                $couponLine = \App\Support\Money\TransactionCouponLine::for($transaction); // fixed: its own currency (was "$")
                $discount = $couponLine['discount'] ?? 0;
            @endphp
            <div class="ui-row">
                <span class="ui-row__body">
                    <span class="ui-row__title">{{ ___('Discount') }}@if (($couponLine['label'] ?? '') !== '') ({{ $couponLine['label'] }})@endif</span>
                    <span class="ui-row__sub">{{ ___('Coupon') }}: <strong>{{ $transaction->coupon->code }}</strong></span>
                </span>
                <strong class="ui-row__trail ui-text-danger">- {{ price_symbol_format($discount) }}</strong>
            </div>
            <div class="ui-row"><span class="ui-row__body"><span class="ui-row__title">{{ ___('Subtotal') }}</span></span><strong class="ui-row__trail">{{ price_symbol_format($transaction->base_amount - $discount) }}</strong></div>
        @endif
        @foreach ($applied_taxes as $tax)
            <div class="ui-row">
                <span class="ui-row__body">
                    <span class="ui-row__title">{{ $tax->name }} <small>({{ $tax->type == 'inclusive' ? ___('Inclusive') : ___('Exclusive') }})</small></span>
                    @if ($tax->description)<span class="ui-row__sub">{{ $tax->description }}</span>@endif
                </span>
                <strong class="ui-row__trail">{{ $tax->type != 'inclusive' ? '+' : '' }} {{ price_symbol_format($tax['value']) }}</strong>
            </div>
        @endforeach
        <div class="ui-row">
            <span class="ui-row__body">
                <span class="ui-row__title"><strong>{{ ___('Total') }}</strong></span>
                @if ($transaction->transaction_gatway)<span class="ui-row__sub">{{ ___('Paid via') }} {{ ucfirst($transaction->transaction_gatway) }}</span>@endif
            </span>
            <strong class="ui-row__trail">{{ price_symbol_format($transaction->amount) }}</strong>
        </div>
    </x-ui.list>
</x-ui.panel>
