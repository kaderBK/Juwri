{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Financial Reports'))
@section('page_title', ___('Financial Reports'))
@section('page_sub', ___('Trial balance, P&L, balance sheet and VAT.'))

{{-- HPMS E14(f): dir-aware print view (window.print, nonce'd — the rooms-print idiom). --}}
@section('page_actions')
    <x-ui.button variant="secondary" icon="print" data-print-report>{{ ___('Print') }}</x-ui.button>
@endsection

@section('content')
@php($vatDec = (int) ($vat['decimals'] ?? 2))
@include('owner.partials.feature-tabs', ['tabs' => juwri_feature_tabs('accounting')])

<div class="ui-scope ui-books">
    {{-- session('success') already surfaces as a toast via partials.flash. --}}
    <x-ui.card>
        <form method="get" action="{{ route('stores.books.reports') }}" class="ui-books__filter">
            <input type="hidden" name="store_id" value="{{ $rid }}">
            <div class="ui-books__field">
                <label class="ui-label" for="rp-from">{{ ___('From') }}</label>
                <input id="rp-from" type="date" name="from" value="{{ $from }}" class="ui-input">
            </div>
            <div class="ui-books__field">
                <label class="ui-label" for="rp-to">{{ ___('To') }}</label>
                <input id="rp-to" type="date" name="to" value="{{ $to }}" class="ui-input">
            </div>
            <x-ui.button type="submit" icon="refresh">{{ ___('Run') }}</x-ui.button>
        </form>
        <div class="ui-cluster ui-books__exports">
            <x-ui.button :href="route('stores.books.export', ['store_id' => $rid, 'type' => 'journals', 'from' => $from, 'to' => $to, 'branch_id' => $branchId])" variant="secondary" icon="download">{{ ___('Export journals') }}</x-ui.button>
            <x-ui.button :href="route('stores.books.export', ['store_id' => $rid, 'type' => 'trial_balance', 'from' => $from, 'to' => $to, 'branch_id' => $branchId])" variant="secondary" icon="download">{{ ___('Export trial balance') }}</x-ui.button>
            <x-ui.button :href="route('stores.books.export', ['store_id' => $rid, 'type' => 'vat', 'from' => $from, 'to' => $to, 'branch_id' => $branchId])" variant="secondary" icon="download">{{ ___('Export VAT') }}</x-ui.button>
        </div>
    </x-ui.card>

    <div class="ui-books__reports">
        <x-ui.card :title="___('Profit & Loss')" variant="flush">
            <table class="ui-books__ledger ui-books__statement">
                <tbody>
                @foreach($pl['revenue'] as $row)
                    <tr><td>{{ $row['account']->displayName() }}</td><td class="ui-books__ledger-num"><span class="ui-ltr">{{ number_format($row['amount'], $vatDec) }}</span></td></tr>
                @endforeach
                <tr class="is-total"><td>{{ ___('Total revenue') }}</td><td class="ui-books__ledger-num"><span class="ui-ltr">{{ number_format($pl['total_revenue'], $vatDec) }}</span></td></tr>
                @foreach($pl['expenses'] as $row)
                    <tr class="ui-books__out"><td>{{ $row['account']->displayName() }}</td><td class="ui-books__ledger-num"><span class="ui-ltr">({{ number_format($row['amount'], $vatDec) }})</span></td></tr>
                @endforeach
                <tr class="is-total"><td>{{ ___('Total expenses') }}</td><td class="ui-books__ledger-num"><span class="ui-ltr">({{ number_format($pl['total_expense'], $vatDec) }})</span></td></tr>
                <tr class="is-grand"><td>{{ ___('Net income') }}</td><td class="ui-books__ledger-num"><span class="ui-ltr">{{ number_format($pl['net_income'], $vatDec) }}</span></td></tr>
                </tbody>
            </table>
        </x-ui.card>

        <x-ui.card :title="___('Balance Sheet')" variant="flush">
            <x-slot:actions>
                <x-ui.pill :tone="$balanceSheet['balanced'] ? 'success' : 'danger'">{{ $balanceSheet['balanced'] ? ___('Balanced') : ___('Out of balance') }}</x-ui.pill>
            </x-slot:actions>
            <table class="ui-books__ledger ui-books__statement">
                <tbody>
                <tr class="is-total"><td>{{ ___('Total assets') }}</td><td class="ui-books__ledger-num"><span class="ui-ltr">{{ number_format($balanceSheet['total_assets'], $vatDec) }}</span></td></tr>
                <tr><td>{{ ___('Total liabilities') }}</td><td class="ui-books__ledger-num"><span class="ui-ltr">{{ number_format($balanceSheet['total_liabilities'], $vatDec) }}</span></td></tr>
                <tr><td>{{ ___('Retained earnings') }}</td><td class="ui-books__ledger-num"><span class="ui-ltr">{{ number_format($balanceSheet['retained_earnings'], $vatDec) }}</span></td></tr>
                <tr class="is-grand"><td>{{ ___('Total equity') }}</td><td class="ui-books__ledger-num"><span class="ui-ltr">{{ number_format($balanceSheet['total_equity'], $vatDec) }}</span></td></tr>
                </tbody>
            </table>
        </x-ui.card>

        @php($vatDec = (int) ($vat['decimals'] ?? 2))
        <x-ui.card :title="___('VAT Report')" variant="flush">
            {{-- P6-259: the store's own rate (none for a fixed tax), and the period split per rate --}}
            @if ($vat['rate'] !== null)
                <x-slot:actions><x-ui.tag><span class="ui-ltr">{{ rtrim(rtrim(number_format((float) $vat['rate'], 2, '.', ''), '0'), '.') }}%</span></x-ui.tag></x-slot:actions>
            @endif
            <table class="ui-books__ledger ui-books__statement">
                <tbody>
                @foreach ($vat['rates'] ?? [] as $r)
                <tr><td>{{ ___('Sales at') }} <span class="ui-ltr">{{ $r['rate'] === null ? '-' : rtrim(rtrim(number_format((float) $r['rate'], 2, '.', ''), '0'), '.').'%' }}</span></td><td class="ui-books__ledger-num"><span class="ui-ltr">{{ number_format($r['net'], $vatDec) }} / {{ number_format($r['vat'], $vatDec) }}</span></td></tr>
                @endforeach
                <tr><td>{{ ___('Output VAT') }}</td><td class="ui-books__ledger-num"><span class="ui-ltr">{{ number_format($vat['output_vat'], $vatDec) }}</span></td></tr>
                <tr><td>{{ ___('Input VAT') }}</td><td class="ui-books__ledger-num"><span class="ui-ltr">{{ number_format($vat['input_vat'], $vatDec) }}</span></td></tr>
                <tr class="is-grand"><td>{{ ___('Net VAT due') }}</td><td class="ui-books__ledger-num"><span class="ui-ltr">{{ number_format($vat['net_vat_due'], $vatDec) }}</span></td></tr>
                </tbody>
            </table>
        </x-ui.card>

        <x-ui.card :title="___('Trial Balance')" variant="flush">
            <x-slot:actions>
                <x-ui.pill :tone="$trialBalance['balanced'] ? 'success' : 'danger'">{{ $trialBalance['balanced'] ? ___('Balanced') : ___('Out') }}</x-ui.pill>
            </x-slot:actions>
            {{-- Bounded scroll: a long chart of accounts scrolls inside the card, never the page. --}}
            <div class="ui-books__scroll">
                <table class="ui-books__ledger">
                    <thead><tr>
                        <th>{{ ___('Account') }}</th>
                        <th class="ui-books__ledger-num">{{ ___('Debit') }}</th>
                        <th class="ui-books__ledger-num">{{ ___('Credit') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach($trialBalance['rows'] as $row)
                        <tr>
                            <td><code>{{ $row['account']->code }}</code></td>
                            <td class="ui-books__ledger-num"><span class="ui-ltr">{{ $row['debit'] ? number_format($row['debit'], $vatDec) : '' }}</span></td>
                            <td class="ui-books__ledger-num"><span class="ui-ltr">{{ $row['credit'] ? number_format($row['credit'], $vatDec) : '' }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot><tr>
                        <td>{{ ___('Total') }}</td>
                        <td class="ui-books__ledger-num"><span class="ui-ltr">{{ number_format($trialBalance['total_debit'], $vatDec) }}</span></td>
                        <td class="ui-books__ledger-num"><span class="ui-ltr">{{ number_format($trialBalance['total_credit'], $vatDec) }}</span></td>
                    </tr></tfoot>
                </table>
            </div>
        </x-ui.card>
    </div>

    <x-ui.card :title="___('Period close')">
        <p class="ui-books__note">{{ ___('Latest closed period') }}: <strong class="ui-ltr">{{ $periodLocked ?: ___('none') }}</strong></p>
        <form method="post" action="{{ route('stores.books.period.lock') }}?store_id={{ $rid }}" class="ui-books__filter">
            @csrf
            <div class="ui-books__field">
                <label class="ui-visually-hidden" for="rp-period">{{ ___('Period close') }}</label>
                <input id="rp-period" type="month" name="period" class="ui-input" required>
            </div>
            <x-ui.button type="submit" variant="danger" icon="clock">{{ ___('Close period') }}</x-ui.button>
        </form>
        <form method="post" action="{{ route('stores.books.period.unlock') }}?store_id={{ $rid }}" class="ui-books__filter" data-confirm="{{ ___('Reopen this period and every later closed month?') }}">
            @csrf
            <div class="ui-books__field">
                <label class="ui-visually-hidden" for="rp-reopen">{{ ___('Reopen period') }}</label>
                <input id="rp-reopen" type="month" name="period" class="ui-input" required>
            </div>
            <div class="ui-books__field ui-books__field--wide">
                <label class="ui-visually-hidden" for="rp-reopen-reason">{{ ___('Why reopen? (kept in the activity log)') }}</label>
                <input id="rp-reopen-reason" type="text" name="reason" class="ui-input" minlength="5" maxlength="500" required placeholder="{{ ___('Why reopen? (kept in the activity log)') }}">
            </div>
            <x-ui.button type="submit" variant="secondary" icon="refresh">{{ ___('Reopen period') }}</x-ui.button>
        </form>
    </x-ui.card>
</div>
@endsection

@push('scripts_at_bottom')
<style nonce="{{ csp_nonce() }}">
    @media print { [data-print-report] { display: none !important; } body { background: #fff; } }
</style>
<script nonce="{{ csp_nonce() }}">
    document.querySelector('[data-print-report]')?.addEventListener('click', function () { window.print(); });
</script>
@endpush
