{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Wallet ledger'))
@section('header_buttons')
    <x-ui.button size="sm" icon="plus" :href="route('admin.users.index')"
                 :title="___('Credits are granted from the user edit page.')">{{ ___('Grant credit') }}</x-ui.button>
@endsection
@section('content')
<div class="ui-stack">
    {{-- Stats --}}
    <section class="ui-kpis" aria-label="{{ ___('Wallet ledger') }}">
        @foreach ([
            [price_symbol_format($stats['credits30d']), ___('Credits (30 days)'), 'wallet', 'success'],
            [price_symbol_format($stats['debits30d']), ___('Debits (30 days)'), 'receipt', 'danger'],
            [price_symbol_format($stats['net30d']), ___('Net (30 days)'), 'wallet', null],
            [number_format($stats['topups30d']), ___('Top-ups (30 days)'), 'card', 'warning'],
        ] as [$__value, $__label, $__icon, $__tone])
            <x-ui.stat :value="$__value" :label="$__label" :icon="$__icon" :tone="$__tone" />
        @endforeach
    </section>

    <form method="GET" class="ui-cluster">
        <select name="type" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Type') }}">
            <option value="">{{ ___('All types') }}</option>
            <option value="credit" @selected(request('type') === 'credit')>{{ ___('Credit') }}</option>
            <option value="debit" @selected(request('type') === 'debit')>{{ ___('Debit') }}</option>
        </select>
        <select name="reason" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Reason') }}">
            <option value="">{{ ___('All reasons') }}</option>
            @foreach ($reasons as $reasonOption)
                <option value="{{ $reasonOption }}" @selected(request('reason') === $reasonOption)>{{ $reasonOption }}</option>
            @endforeach
        </select>
        <input type="search" name="user" value="{{ request('user') }}" class="ui-input ui-input--sm ui-input--auto"
               placeholder="{{ ___('User name or email') }}" aria-label="{{ ___('User name or email') }}">
        <x-ui.button type="submit" size="sm" icon="filter">{{ ___('Filter') }}</x-ui.button>
    </form>

    <x-ui.card :title="___('Wallet ledger')" variant="flush">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                <tr>
                    <th>{{ ___('Time') }}</th>
                    <th>{{ ___('User') }}</th>
                    <th>{{ ___('Type') }}</th>
                    <th>{{ ___('Amount') }}</th>
                    <th>{{ ___('Balance after') }}</th>
                    <th>{{ ___('Reason') }}</th>
                    <th>{{ ___('Granted by') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($transactions as $tx)
                    @php
                        $owner = $users->get($tx->user_id);
                        $grantedBy = $tx->granted_by ? $users->get($tx->granted_by) : null;
                    @endphp
                    <tr>
                        <td data-label="{{ ___('Time') }}">
                            <span title="{{ $tx->created_at?->format('Y-m-d H:i:s') }}">{{ $tx->created_at?->diffForHumans() }}</span>
                        </td>
                        <td data-primary data-label="{{ ___('User') }}">
                            @if ($owner)
                                <a href="{{ route('admin.users.edit', $owner->id) }}">{{ $owner->name }}</a>
                                <small class="ui-cell-sub">{{ $owner->email }}</small>
                            @else
                                <span class="ui-text-2">#{{ $tx->user_id }}</span>
                            @endif
                        </td>
                        <td data-label="{{ ___('Type') }}">
                            @if ($tx->type === 'credit')
                                <x-ui.tag tone="success">{{ ___('Credit') }}</x-ui.tag>
                            @else
                                <x-ui.tag tone="danger">{{ ___('Debit') }}</x-ui.tag>
                            @endif
                        </td>
                        <td data-label="{{ ___('Amount') }}">{{ price_symbol_format($tx->amount) }}</td>
                        <td data-label="{{ ___('Balance after') }}">{{ price_symbol_format($tx->balance_after) }}</td>
                        <td data-label="{{ ___('Reason') }}">
                            @if ($tx->reason)
                                <code class="ui-mono">{{ $tx->reason }}</code>
                            @else
                                —
                            @endif
                        </td>
                        <td data-label="{{ ___('Granted by') }}">{{ $grantedBy->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="ui-center ui-text-2">{{ ___('No wallet transactions yet.') }}</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($transactions->hasPages())
            <div class="ui-card__foot">{{ $transactions->withQueryString()->links() }}</div>
        @endif
    </x-ui.card>
</div>
@endsection
