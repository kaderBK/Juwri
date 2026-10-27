{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- HP-B4: guest folio statement handed to the guest; RTL for ar/ur markets. --}}
@php
    $cur = $statement['currency'].' ';
    $folioRows = collect($statement['charges'])->map(fn ($r) => $r + ['k' => 'c'])
        ->merge(collect($statement['payments'])->map(fn ($r) => $r + ['k' => 'p']))
        ->sortBy('id');
@endphp
<x-ui.print :title="___('Folio').' #'.$stay->id" autoprint>
    <h1 class="ui-print__title">{{ $store->title }}</h1>
    <p class="ui-print__sub">{{ ___('Guest folio statement') }}</p>

    <div class="ui-print__block">
        <div class="ui-print__strong">{{ $stay->guest_name }}</div>
        <div>{{ ___('Room') }} #{{ $stay->room->table_no ?? $stay->table_id }} · {{ ___('Folio') }} #{{ $stay->id }}</div>
        <div class="ui-print__meta">
            {{ ___('Checked in') }}: {{ optional($stay->checked_in_at)->format('d M Y, H:i') }}
            @if ($stay->checked_out_at) · {{ ___('Checked out') }}: {{ $stay->checked_out_at->format('d M Y, H:i') }}@endif
        </div>
    </div>

    <table class="ui-print__table">
        <thead>
            <tr><th>{{ ___('Date') }}</th><th>{{ ___('Description') }}</th><th class="is-num">{{ ___('Charge') }}</th><th class="is-num">{{ ___('Payment') }}</th></tr>
        </thead>
        <tbody>
            @foreach ($folioRows as $row)
                <tr>
                    <td>{{ optional($row['at'])->format('d M, H:i') }}</td>
                    <td>{{ $row['reason'] }}</td>
                    <td class="is-num">{{ $row['k'] === 'c' ? $cur.number_format($row['amount'], 2) : '' }}</td>
                    <td class="is-num">{{ $row['k'] === 'p' ? $cur.number_format($row['amount'], 2) : '' }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><th colspan="2" class="is-num">{{ ___('Total charges') }}</th><td class="is-num">{{ $cur.number_format($statement['total_charges'], 2) }}</td><td></td></tr>
            <tr><th colspan="2" class="is-num">{{ ___('Total payments') }}</th><td></td><td class="is-num">{{ $cur.number_format($statement['total_payments'], 2) }}</td></tr>
            <tr class="is-total"><th colspan="2" class="is-num">{{ ___('Balance due') }}</th><td colspan="2" class="is-num">{{ $cur.number_format($statement['balance'], 2) }}</td></tr>
        </tfoot>
    </table>
</x-ui.print>
