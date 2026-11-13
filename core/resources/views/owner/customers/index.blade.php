{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Customers'))
@section('page_title', ___('Customers CRM'))
@section('page_sub', ___('Customer profiles, spend, and marketing segments'))

@section('page_actions')
    <x-ui.button :href="route('customers.guestPrivacy')" variant="ghost" icon="users">{{ ___('Guest privacy request') }}</x-ui.button>
    <x-ui.button :href="route('customers.export', request()->all())" variant="secondary" icon="download" data-busy-download :data-busy-label="___('Preparing…')">{{ ___('Export CSV') }}</x-ui.button>
@endsection

@section('content')
@php
    $customerCollection = collect($customers->items());
    $totalSpent = $customerCollection->sum(fn ($customer) => (float) $customer->total_spent);
    $totalOrders = $customerCollection->sum(fn ($customer) => (int) $customer->orders_count);
    $consentedCount = $customerCollection->where('marketing_consent', true)->count();
    $segment = request('segment', '');
    $segments = [
        '' => ___('All'),
        'new_this_month' => ___('New This Month'),
        'top_spenders' => ___('Top Spenders'),
        'lapsed' => ___('Lapsed'),
        'consented' => ___('Marketing Consented'),
    ];
@endphp

<div class="ui-scope ui-crm">
    <section class="ui-orders-kpis" aria-label="{{ ___('Customer relationships') }}">
        <div class="ui-stat"><span class="ui-stat__icon"><x-ui.icon name="users" /></span><span class="ui-stat__value">{{ number_format($customers->count()) }}</span><span class="ui-stat__label">{{ ___('Shown customers') }}</span></div>
        <div class="ui-stat"><span class="ui-stat__icon"><x-ui.icon name="trend-up" /></span><span class="ui-stat__value">{{ price_symbol_format($totalSpent) }}</span><span class="ui-stat__label">{{ ___('Shown revenue') }}</span></div>
        <div class="ui-stat"><span class="ui-stat__icon"><x-ui.icon name="orders" /></span><span class="ui-stat__value">{{ number_format($totalOrders) }}</span><span class="ui-stat__label">{{ ___('Orders') }}</span></div>
        <div class="ui-stat" data-tone="success"><span class="ui-stat__icon"><x-ui.icon name="check" /></span><span class="ui-stat__value">{{ number_format($consentedCount) }}</span><span class="ui-stat__label">{{ ___('Consented') }}</span></div>
    </section>

    <form action="{{ route('customers.index') }}" method="get" class="ui-orders-toolbar">
        <label class="ui-orders-search__field">
            <x-ui.icon name="search" size="18" />
            <span class="ui-visually-hidden">{{ ___('Search customers') }}</span>
            <input id="customers-search" type="search" name="q" value="{{ request('q') }}" placeholder="{{ ___('Search name, email, phone...') }}">
        </label>
        <div class="ui-chips" role="group" aria-label="{{ ___('Customer segments') }}">
            @foreach($segments as $value => $label)
                <button type="submit" name="segment" value="{{ $value }}" class="ui-chip" @if($segment === $value) aria-current="true" @endif>{{ $label }}</button>
            @endforeach
        </div>
    </form>

    <section class="ui-card ui-card--flush">
        <table class="ui-table">
            <thead>
            <tr>
                <th>{{ ___('Customer') }}</th>
                <th>{{ ___('Contact') }}</th>
                <th class="ui-table__num">{{ ___('Total Spent') }}</th>
                <th class="ui-table__num">{{ ___('Orders') }}</th>
                <th>{{ ___('Last Visit') }}</th>
                <th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
            </tr>
            </thead>
            <tbody>
            @forelse($customers as $customer)
                @php
                    $tags = \Juwri\Core\Support\ViewHelpers::tagCollection($customer->tags);
                @endphp
                <tr>
                    <td data-primary>
                        <span class="ui-crm__who">
                            <span class="ui-crm__avatar" aria-hidden="true">{{ strtoupper(mb_substr($customer->name ?: ___('G'), 0, 1)) }}</span>
                            <span class="ui-crm__name">
                                <strong>{{ $customer->name ?: ___('Guest') }}</strong>
                                @if($tags->isNotEmpty())<small>{{ $tags->implode(', ') }}</small>@endif
                            </span>
                        </span>
                    </td>
                    <td data-label="{{ ___('Contact') }}">
                        <span class="ui-crm__contact">
                            @if($customer->email)<a href="mailto:{{ $customer->email }}">{{ $customer->email }}</a>@endif
                            @if($customer->phone)<a href="tel:{{ $customer->phone }}" class="ui-ltr">{{ $customer->phone }}</a>@endif
                            @if($customer->marketing_consent)
                                <x-ui.pill tone="success">{{ ___('Consented') }}</x-ui.pill>
                            @else
                                <x-ui.pill>{{ ___('No consent') }}</x-ui.pill>
                            @endif
                        </span>
                    </td>
                    <td class="ui-table__num" data-label="{{ ___('Total Spent') }}"><strong>{{ price_symbol_format($customer->total_spent) }}</strong></td>
                    <td class="ui-table__num" data-label="{{ ___('Orders') }}">{{ number_format($customer->orders_count) }}</td>
                    <td data-label="{{ ___('Last Visit') }}"><span class="ui-orders-muted">{{ $customer->last_order_at ? date_formating($customer->last_order_at, 'd M, Y') : '-' }}</span></td>
                    <td class="ui-catalog__row-actions">
                        <x-ui.button :href="route('customers.show', $customer->id)" variant="secondary" size="sm">{{ ___('View') }}</x-ui.button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <x-ui.empty :title="___('No Customers Found')" :body="___('Customers appear here when they register or place orders.')" icon="users" />
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </section>

    {{ $customers->links() }}
</div>
@endsection
