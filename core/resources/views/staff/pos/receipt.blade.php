{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    // P6-272: refund receipt sub-mode passes a single adjustment; the regular
    // receipt passes the order's full refund list (may be empty). The same
    // sheet chrome (sign, decimals, $money closure) is shared.
    $adjustment = $adjustment ?? null;
    $refunds = $refunds ?? collect();

    // Printed receipt (thermal / monospace font): fall back to the ISO code for a
    // sign that is not WinAnsi-drawable — the Saudi Riyal U+20C1 glyph has no
    // thermal/monospace font and would print as a tofu box. currency_sign_safe →
    // "SAR", keeping "$"/"€"/etc. as-is.
    $sign = currency_sign_safe($options->currency_sign ?? '', (string) ($options->currency_code ?? ''));
    $sign = $sign !== '' ? $sign : '$';
    // Locale-aware position (RTL/Arabic → sign-first). Use the effective helper with
    // the store id, since the POS receipt route doesn't prime config('settings.*').
    $before = currency_pos_effective($store->id) === 1;
    // I4: honour the store's own currency decimals (Gulf currencies are 3-decimal).
    // The POS receipt route does not run publicView's per-store currency override,
    // so resolve from the store's StoreOption code rather than the platform currency.
    $decimals = currency_decimals($options->currency_code ?? null);
    $money = function ($v) use ($sign, $before, $decimals) {
        $n = number_format((float) $v, $decimals, '.', '');
        return $before ? $sign . $n : $n . $sign;
    };
    $subtotal = 0;
    foreach (($order->items ?? collect()) as $it) {
        if (!$it->menu) continue;
        // P4-01: the prices the order CHARGED (the scheduled unit_price and modifier price snapshots, as
        // Order::computeMoney / the e-invoice) — before, today's menu prices, so an old receipt stopped adding up.
        $price = $it->unit_price ?? (($it->variation && $it->variant) ? $it->variant->price : $it->menu->price);
        $line = $price * $it->quantity;
        foreach ($it->itemExtras as $ex) { if ($ex->extra) $line += ($ex->price ?? $ex->extra->price) * $it->quantity; }
        // T6-4: snapshotted choice-group option prices.
        foreach ((array) $it->options as $g) { foreach ((array) ($g['selected'] ?? []) as $o) { $line += ($o['price'] ?? 0) * $it->quantity; } }
        $subtotal += $line;
    }
    // D7: render tax from the frozen snapshot when the order carries one; a legacy
    // (null) order falls back to a live recompute inside TaxSnapshot::lines().
    $taxLines = \Juwri\Modules\Ordering\Services\Tax\TaxSnapshot::lines($order);
    $tax = array_sum(array_map(fn ($l) => (float) ($l['amount'] ?? 0), $taxLines));
    // P6-272: sum of refund amounts (major units, order currency).
    $refundTotal = 0.0;
    foreach ($refunds as $r) { $refundTotal += (float) $r->amount; }
    $netPaid = (float) $order->total - $refundTotal;
@endphp
<x-ui.print :title="$adjustment ? (___('pos_receipt_refund_receipt').' #'.$adjustment->id) : (___('receipt').' #'.$order->id)" paper="receipt" autoprint>
    <div class="ui-print__center ui-print__strong ui-print__big">{{ $store->title }}</div>
    @if ($store->address)<div class="ui-print__center">{{ $store->address }}</div>@endif
    @if ($store->phone)<div class="ui-print__center ui-print__ltr">{{ $store->phone }}</div>@endif
    <hr class="ui-print__rule">
    {{-- P6-272: mark a refill print under the store header so it cannot be mistaken for the original. --}}
    @if (!empty($isCopy))<div class="ui-print__center ui-print__strong ui-print__big">{{ ___('pos_receipt_copy') }}</div>@endif
    @if (!empty($station))<div class="ui-print__center ui-print__strong">{{ $station }}</div>@endif
    <div>{{ ___('Order') }} #{{ $order->id }}</div>
    {{-- PARITY-RUN T4: name the whole merged party, not just the bill's table. --}}
    @php $__party = \Juwri\Modules\Tables\Services\TableMergeService::partyLabelForOrder($order); @endphp
    <div>{{ ___('Type') }}: {{ order_type_label($order->type) }}@if ($__party) · {{ ___('Table') }} {{ $__party }}@elseif ($order->table_number) · {{ ___('Table') }} {{ $order->table_number }}@endif</div>
    <div>{{ optional($order->created_at)->format('Y-m-d H:i') }}</div>
    @if ($adjustment)
        {{-- P6-272: refund receipt — no line items; the adjustment IS the receipt. --}}
        <hr class="ui-print__rule">
        <table class="ui-print__table">
            <tr class="is-total ui-print__strong">
                <td>{{ ___('pos_receipt_refund') }} #{{ $adjustment->id }}</td>
                <td class="is-num">−{{ $money((float) $adjustment->amount) }}</td>
            </tr>
        </table>
        @if (!empty($adjustment->reason))
            <hr class="ui-print__rule">
            <div>{{ ___('reason') }}: {{ $adjustment->reason }}</div>
        @endif
        <div>{{ optional($adjustment->created_at)->format('Y-m-d H:i') }}</div>
    @else
        <hr class="ui-print__rule">
        <table class="ui-print__table">
            @foreach ($order->items as $it)
                @php $price = $it->unit_price ?? (($it->variation && $it->variant) ? $it->variant->price : optional($it->menu)->price); @endphp
                <tr>
                    <td>{{ $it->quantity }}× {{ optional($it->menu)->getRawOriginal('name') ?? '—' }}</td>
                    <td class="is-num">{{ $money($price * $it->quantity) }}</td>
                </tr>
                @foreach ((array) $it->options as $g)
                    @foreach ((array) ($g['selected'] ?? []) as $o)
                        <tr><td class="ui-print__indent">» {{ $o['option'] ?? '' }}</td><td class="is-num">@if (($o['price'] ?? 0) > 0){{ $money(($o['price'] ?? 0) * $it->quantity) }}@endif</td></tr>
                    @endforeach
                @endforeach
                @foreach ($it->itemExtras as $ex)
                    @if ($ex->extra)<tr><td class="ui-print__indent">+ {{ $ex->extra->getRawOriginal('title') }}</td><td class="is-num">{{ $money(($ex->price ?? $ex->extra->price) * $it->quantity) }}</td></tr>@endif
                @endforeach
                @if ($it->customerNote() !== '')<tr><td class="ui-print__indent" colspan="2">★ {{ $it->customerNote() }}</td></tr>@endif
            @endforeach
        </table>
        <hr class="ui-print__rule">
        <table class="ui-print__table">
            <tr><td>{{ ___('Subtotal') }}</td><td class="is-num">{{ $money($subtotal) }}</td></tr>
            {{-- J32: pre-VAT levies, each its own line --}}
            @foreach (\Juwri\Modules\Ordering\Services\Tax\TaxEngine::levyLines($order) as $lv)<tr><td>{{ $lv['name'] }}</td><td class="is-num">{{ $money($lv['amount'] / (10 ** $lv['decimals'])) }}</td></tr>@endforeach
            @foreach ($taxLines as $tl)<tr><td>{{ $tl['label'] ?? ___('Tax') }}@if (($tl['rate'] ?? 0) > 0) ({{ rtrim(rtrim(number_format((float) $tl['rate'], 2, '.', ''), '0'), '.') }}%)@endif</td><td class="is-num">{{ $money($tl['amount'] ?? 0) }}</td></tr>@endforeach
            @if (($order->service_charge_amount ?? 0) > 0)<tr><td>{{ ___('Service') }}</td><td class="is-num">{{ $money($order->service_charge_amount) }}</td></tr>@endif
            @if (($order->tip_amount ?? 0) > 0)<tr><td>{{ ___('Tip') }}</td><td class="is-num">{{ $money($order->tip_amount) }}</td></tr>@endif
            @if (($order->discount_amount ?? 0) > 0)<tr><td>{{ ___('Discount') }}</td><td class="is-num">−{{ $money($order->discount_amount) }}</td></tr>@endif
            <tr class="is-total ui-print__strong"><td>{{ ___('Total') }}</td><td class="is-num">{{ $money($order->total) }}</td></tr>
            {{-- P6-272: refund lines (each as a minus) and Net paid row. --}}
            @foreach ($refunds as $r)
                <tr>
                    <td>{{ ___('pos_receipt_refund') }} #{{ $r->id }}</td>
                    <td class="is-num">−{{ $money((float) $r->amount) }}</td>
                </tr>
            @endforeach
            @if ($refunds->isNotEmpty())
                <tr class="is-total ui-print__strong"><td>{{ ___('pos_receipt_net_paid') }}</td><td class="is-num">{{ $money($netPaid) }}</td></tr>
            @endif
        </table>
        @if ($order->payment_method)
            <hr class="ui-print__rule">
            <div>{{ ___('Paid') }}: {{ $order->payment_method === 'coupon' ? ___('Paid by coupon') : payment_method_label($order->payment_method) }}</div>
            @if ($order->amount_tendered !== null)<div>{{ ___('Tendered') }}: {{ $money($order->amount_tendered) }}</div>@endif
            @if ($order->change_due !== null)<div>{{ ___('Change') }}: {{ $money($order->change_due) }}</div>@endif
        @endif
        @include('fiscal.receipt-block', ['store' => $store, 'order' => $order])
    @endif
    <hr class="ui-print__rule">
    <div class="ui-print__center">{{ $options->receipt_footer ?? ___('Thank you!') }}</div>
</x-ui.print>
