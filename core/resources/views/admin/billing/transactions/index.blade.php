{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Transactions'))
@section('header_buttons')
    <x-ui.button variant="secondary" size="sm" icon="orders" :href="route('admin.receipts')">{{ ___('Charge history') }}</x-ui.button>
@endsection
@section('content')
<div class="ui-stack">
    <section class="ui-kpis" aria-label="{{ ___('Transactions') }}">
        @foreach ([
            [price_symbol_format($stats['revenue30d']), ___('Revenue · 30 days'), 'wallet', null],
            [number_format($stats['success30d']), ___('Successful (30 days)'), 'check', 'success'],
            [number_format($stats['pending']), ___('Pending'), 'clock', 'warning'],
            [number_format($stats['failed7d']), ___('Failed (7 days)'), 'error', 'danger'],
        ] as [$__value, $__label, $__icon, $__tone])
            <x-ui.stat :value="$__value" :label="$__label" :icon="$__icon" :tone="$__tone" />
        @endforeach
    </section>
    @if (! empty($stats['revenue30dOther']))
        {{-- Bug batch 7 · 3: revenue in other currencies, never added to the tile above. --}}
        <p class="ui-text-2">{{ ___('Other currencies') }}: <span class="ui-ltr">{{ \Juwri\Core\Money\CurrencyTotals::label($stats['revenue30dOther']) }}</span></p>
    @endif

    <form method="GET" action="{{ route('admin.transactions.index') }}" class="ui-cluster">
        <select name="status" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Status') }}">
            <option value="">{{ ___('All statuses') }}</option>
            @foreach (['success' => ___('Paid'), 'pending' => ___('Pending'), 'failed' => ___('Failed'), 'cancel' => ___('Cancelled')] as $__v => $__l)
                <option value="{{ $__v }}" @selected(request('status') === $__v)>{{ $__l }}</option>
            @endforeach
        </select>
        <select name="gateway" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Gateway') }}">
            <option value="">{{ ___('All gateways') }}</option>
            @foreach ($gateways as $gatewayOption)
                <option value="{{ $gatewayOption }}" @selected(request('gateway') === $gatewayOption)>{{ ucfirst($gatewayOption) }}</option>
            @endforeach
        </select>
        <select name="days" class="ui-input ui-input--sm ui-input--auto" aria-label="{{ ___('Date') }}">
            <option value="all" @selected(! in_array(request('days'), ['7', '30', '90'], true))>{{ ___('All time') }}</option>
            @foreach (['7' => ___('Last 7 days'), '30' => ___('Last 30 days'), '90' => ___('Last 90 days')] as $__v => $__l)
                <option value="{{ $__v }}" @selected(request('days') === $__v)>{{ $__l }}</option>
            @endforeach
        </select>
        <input type="search" name="search" value="{{ request('search') }}" class="ui-input ui-input--sm ui-input--auto"
               placeholder="{{ ___('User, email or product') }}" aria-label="{{ ___('User, email or product') }}">
        <x-ui.button type="submit" size="sm" icon="filter">{{ ___('Filter') }}</x-ui.button>
        <x-ui.button variant="ghost" size="sm" :href="route('admin.transactions.index')">{{ ___('Reset') }}</x-ui.button>
    </form>

    <x-ui.card variant="flush" data-ui-bulk-scope>
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th class="ui-dt__narrow"><input type="checkbox" data-ui-check-all aria-label="{{ ___('Select all') }}"></th>
                        <th>{{ ___('Invoice') }}</th>
                        <th>{{ ___('User') }}</th>
                        <th>{{ ___('Product') }}</th>
                        <th>{{ ___('Gateway') }}</th>
                        <th>{{ ___('Amount') }}</th>
                        <th>{{ ___('Status') }}</th>
                        <th>{{ ___('Date') }}</th>
                        <th class="ui-end">{{ ___('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($transactions as $tx)
                        @php
                            $plan = $tx->product_id ? ($plans[$tx->product_id] ?? null) : null;
                            $title = $tx->displayName() ?: ($tx->displayDescription() ?: '—');
                            if ($plan) {
                                $title = ! empty($plan->translations->{get_lang()}->name) ? $plan->translations->{get_lang()}->name : $plan->name;
                                $title .= ' ('.plan_interval_text($tx->frequency).')';
                            }
                            $tone = ['success' => 'success', 'pending' => 'warning', 'failed' => 'danger', 'cancel' => 'danger'][$tx->status] ?? 'neutral';
                            $statusLabel = ['success' => ___('Paid'), 'pending' => ___('Pending'), 'failed' => ___('Failed'), 'cancel' => ___('Cancelled')][$tx->status] ?? ucfirst((string) $tx->status);
                        @endphp
                        <tr>
                            <td data-label=""><input type="checkbox" class="quick-check" value="{{ $tx->id }}" aria-label="{{ ___('Select') }}"></td>
                            <td data-primary data-label="{{ ___('Invoice') }}"><span class="ui-mono">{{ $tx->invoice_number ?: '#'.$tx->id }}</span></td>
                            <td data-label="{{ ___('User') }}">
                                @if ($tx->user)
                                    <a href="{{ route('admin.users.edit', $tx->user->id) }}">{{ $tx->user->name }}</a>
                                    <small class="ui-cell-sub">{{ $tx->user->email }}</small>
                                @elseif ($tx->store)
                                    {{-- Order payments carry store_id and no user_id (P1-20 follow-up). --}}
                                    <a href="{{ store_url($tx->store) }}" target="_blank" rel="noopener">{{ $tx->store->title }}</a>
                                    <small class="ui-cell-sub">{{ ___('Store') }}</small>
                                @elseif ($tx->user_id)
                                    <span class="ui-text-2">#{{ $tx->user_id }}</span>
                                @else
                                    <span class="ui-text-2">—</span>
                                @endif
                            </td>
                            <td data-label="{{ ___('Product') }}">{{ $title }}</td>
                            <td data-label="{{ ___('Gateway') }}">
                                @if ($tx->transaction_gatway)<x-ui.tag>{{ $tx->transaction_gatway }}</x-ui.tag>@else<span class="ui-text-2">—</span>@endif
                                @if ($tx->transaction_method && $tx->transaction_method !== $tx->transaction_gatway)
                                    <small class="ui-cell-sub">{{ $tx->transaction_method }}</small>
                                @endif
                                {{-- WI-H3: the gateway payment reference, so an operator sees one row per payment
                                     (a historical duplicate repaired by the migration reads "<ref>#dup<id>"). --}}
                                @if ($tx->payment_id)
                                    <small class="ui-cell-sub ui-mono ui-break">{{ $tx->payment_id }}</small>
                                @endif
                            </td>
                            <td data-label="{{ ___('Amount') }}">{{ price_symbol_format($tx->amount) }}</td>
                            <td data-label="{{ ___('Status') }}"><x-ui.tag :tone="$tone">{{ $statusLabel }}</x-ui.tag></td>
                            <td data-label="{{ ___('Date') }}"><span title="{{ $tx->created_at?->format('Y-m-d H:i:s') }}">{{ $tx->created_at?->diffForHumans() }}</span></td>
                            <td class="ui-end" data-label="">
                                <div class="ui-cell-actions">
                                    <button type="button" class="ui-icon-btn ui-icon-btn--ghost" data-toggle="slidePanel" data-url="{{ route('admin.transactions.show', $tx->id) }}"
                                            title="{{ ___('Details') }}" aria-label="{{ ___('Details') }}"><x-ui.icon name="info" size="18" /></button>
                                    {{-- P3-6 / F4-5: the rail answers whether THIS row is refundable (reversal txn +
                                         grant revoke + wallet credit + commission reversal). --}}
                                    @if (\App\Http\Controllers\Admin\TransactionController::refundable($tx))
                                        <form method="POST" action="{{ route('admin.transactions.refund', $tx->id) }}" data-ui-danger
                                              data-ui-confirm="{{ ___('Refund this purchase? The feature/capacity is revoked immediately and the amount is credited to the customer\'s wallet. This cannot be undone.') }}">
                                            @csrf
                                            <button type="submit" class="ui-icon-btn ui-icon-btn--ghost ui-text-danger" title="{{ ___('Refund') }}" aria-label="{{ ___('Refund') }}"><x-ui.icon name="back" size="18" /></button>
                                        </form>
                                    @endif
                                    {{-- P1-23 part 6: only a platform purchase; a payment made to a store is confirmed by its owner --}}
                                    @if (\App\Http\Controllers\Admin\TransactionController::markablePaid($tx))
                                        <form method="POST" action="{{ route('admin.transactions.update', $tx->id) }}" data-ui-confirm="{{ ___('Are you sure?') }}">
                                            @csrf
                                            @method('PUT')
                                            <button type="submit" class="ui-icon-btn ui-icon-btn--ghost ui-text-success" title="{{ ___('Mark as Paid') }}" aria-label="{{ ___('Mark as Paid') }}"><x-ui.icon name="check" size="18" /></button>
                                        </form>
                                    @endif
                                    @if ($tx->status === 'success' && $tx->amount > 0 && Route::has('invoice'))
                                        <a class="ui-icon-btn ui-icon-btn--ghost" href="{{ route('invoice', $tx->id) }}" target="_blank" rel="noopener"
                                           title="{{ ___('Invoice') }}" aria-label="{{ ___('Invoice') }}"><x-ui.icon name="print" size="18" /></a>
                                    @endif
                                    {{-- P6-246: a paid payment is never deleted; it can be voided with a reason (kept + logged) --}}
                                    @if ($tx->voided_at)
                                        <span class="ui-badge" title="{{ $tx->void_reason }}">{{ ___('Voided') }}</span>
                                    @elseif ($tx->isPaidRecord())
                                        <form method="POST" action="{{ route('admin.transactions.destroy', $tx->id) }}" data-ui-confirm="{{ ___('Void this paid payment? It stays on record.') }}" data-ui-danger class="ui-cluster">
                                            @csrf
                                            @method('DELETE')
                                            <input type="text" name="reason" required maxlength="500" class="ui-input ui-input--sm" placeholder="{{ ___('Reason to void') }}" aria-label="{{ ___('Reason to void') }}">
                                            <button type="submit" class="ui-icon-btn ui-icon-btn--ghost ui-text-danger" title="{{ ___('Void') }}" aria-label="{{ ___('Void') }}"><x-ui.icon name="trash" size="18" /></button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('admin.transactions.destroy', $tx->id) }}" data-ui-confirm="{{ ___('Are you sure?') }}" data-ui-danger>
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="ui-icon-btn ui-icon-btn--ghost ui-text-danger" title="{{ ___('Delete') }}" aria-label="{{ ___('Delete') }}"><x-ui.icon name="trash" size="18" /></button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="ui-center ui-text-2">{{ ___('No transactions found.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="ui-card__foot ui-cluster--between">
            <x-ui.button type="button" variant="secondary" size="sm" icon="trash" :data-ui-bulk="route('admin.transactions.delete')">{{ ___('Delete selected') }}</x-ui.button>
            @if ($transactions->hasPages())
                <div>{{ $transactions->withQueryString()->links() }}</div>
            @endif
        </div>
    </x-ui.card>
</div>
@endsection
