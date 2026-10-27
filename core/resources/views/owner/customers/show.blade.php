{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Customer Profile'))
@section('page_title', $customer->name ?: ___('Customer Profile'))
@section('page_sub', ___('Customer details and order history'))

@section('page_actions')
    <x-ui.button :href="route('customers.index')" variant="secondary" icon="back">
        {{ ___('Back to List') }}
    </x-ui.button>
@endsection

@section('content')
@php
    $tags = \Juwri\Core\Support\ViewHelpers::tagCollection($customer->tags);
    $initial = strtoupper(mb_substr($customer->name ?: ___('G'), 0, 1));
    $orderCollection = collect($orders->items());
    $completedCount = $orderCollection->where('status', 'completed')->count();
    $activeCount = $orderCollection->whereIn('status', ['pending', 'preparing', 'ready'])->count();
    $statusLabels = [
        'pending' => ___('Pending'),
        'preparing' => ___('Preparing'),
        'ready' => ___('Ready'),
        'completed' => ___('Completed'),
        'cancelled' => ___('Cancelled'),
    ];
@endphp

{{-- Hero --}}
<div class="ui-card">
    <div class="ui-cluster ui-cluster--between ui-card__body">
        <div class="ui-cluster">
            <span class="ui-cluster ui-cluster--center ui-inline ui-text-accent ui-strong ui-fs-lg" style="width:56px;height:56px;">{{ $initial }}</span>
            <div>
                <p class="ui-muted ui-caps">{{ ___('Customer Profile') }}</p>
                <h3 class="ui-section-title">{{ $customer->name ?: ___('Guest') }}</h3>
                <small class="ui-text-2">{{ ___('Joined') }} {{ date_formating($customer->created_at, 'd M, Y') }}</small>
            </div>
        </div>
        <div class="ui-form-grid ui-center">
            <div>
                <span class="ui-muted ui-block">{{ ___('LTV') }}</span>
                <strong class="ui-fs-lg">{{ price_symbol_format($customer->total_spent) }}</strong>
                @php $ltvDrift = abs((float) ($derivedSpend ?? $customer->total_spent) - (float) $customer->total_spent); @endphp
                @if ($ltvDrift >= 0.01)
                    <span class="ui-block">
                        <x-ui.tag tone="warning">{{ ___('Order history: :amount', ['amount' => price_symbol_format($derivedSpend)]) }}</x-ui.tag>
                    </span>
                    <form action="{{ route('customers.recalculate', $customer) }}" method="post"
                          data-confirm="{{ ___('Replace the stored lifetime value with the total of this customer\'s orders?') }}">
                        @csrf
                        <x-ui.button type="submit" variant="ghost" size="sm" icon="refresh">{{ ___('Recalculate') }}</x-ui.button>
                    </form>
                @endif
            </div>
            <div>
                <span class="ui-muted ui-block">{{ ___('Orders') }}</span>
                <strong class="ui-fs-lg">{{ number_format($customer->orders_count) }}</strong>
            </div>
            <div>
                <span class="ui-muted ui-block">{{ ___('Last Visit') }}</span>
                <strong class="ui-fs-lg">{{ $customer->last_order_at ? date_formating($customer->last_order_at, 'd M, Y') : '-' }}</strong>
            </div>
        </div>
    </div>
</div>

<div class="ui-form-grid">
    <div>
        <x-ui.card :title="___('Customer Details')">
            <x-slot:actions>
                <x-ui.tag :tone="$customer->marketing_consent ? 'success' : 'neutral'">
                    {{ $customer->marketing_consent ? ___('Marketing consent') : ___('No consent') }}
                </x-ui.tag>
            </x-slot:actions>

            <div class="ui-stack">
                <div class="ui-cluster ui-cluster--between">
                    <span class="ui-text-2">{{ ___('Email') }}</span>
                    @if($customer->email)
                        <a href="mailto:{{ $customer->email }}" class="ui-end">{{ $customer->email }}</a>
                        {{-- P6-249: the mail provider reported this address dead or as a spam complaint. --}}
                        @php($suppressedReason = \Juwri\Modules\Messaging\Models\EmailSuppression::reasonFor($customer->email))
                        @if($suppressedReason)
                            <x-ui.tag tone="danger" title="{{ ___('Campaigns skip this address. Order and receipt e-mails may not arrive.') }}">
                                {{ $suppressedReason === 'complaint' ? ___('Marked as spam') : ___('E-mail bounced') }}
                            </x-ui.tag>
                        @endif
                    @else
                        <strong>-</strong>
                    @endif
                </div>
                <div class="ui-cluster ui-cluster--between">
                    <span class="ui-text-2">{{ ___('Phone') }}</span>
                    @if($customer->phone)
                        <a href="tel:{{ $customer->phone }}" class="ui-end">{{ $customer->phone }}</a>
                    @else
                        <strong>-</strong>
                    @endif
                </div>
                <div class="ui-cluster ui-cluster--between">
                    <span class="ui-text-2">{{ ___('Source') }}</span>
                    <strong>{{ $customer->source ?: '-' }}</strong>
                </div>
                <div class="ui-cluster ui-cluster--between">
                    <span class="ui-text-2">{{ ___('Consent Date') }}</span>
                    <strong>{{ $customer->consent_at ? date_formating($customer->consent_at, 'd M, Y') : '-' }}</strong>
                </div>
            </div>

            @if($tags->isNotEmpty())
                <div class="ui-cluster" aria-label="{{ ___('Tags') }}">
                    @foreach($tags as $tag)
                        <x-ui.tag tone="accent">{{ $tag }}</x-ui.tag>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    </div>

    <div>
        <x-ui.card :title="___('Tags & Notes')">

            <form action="{{ route('customers.update', $customer->id) }}" method="post" class="ui-stack">
                @csrf
                @method('PUT')
                <div>
                    <label class="ui-label" for="customer-tags">{{ ___('Tags (comma separated)') }}</label>
                    <input id="customer-tags" class="ui-input" type="text" name="tags" value="{{ $tags->implode(', ') }}" placeholder="{{ ___('e.g. VIP, Frequent') }}">
                    <small class="ui-text-2">{{ ___('Add quick labels to identify this customer.') }}</small>
                </div>

                <div>
                    <label class="ui-label" for="customer-notes">{{ ___('Internal Notes') }}</label>
                    <textarea id="customer-notes" class="ui-input" name="notes" rows="6" placeholder="{{ ___('Private notes about this customer') }}">{{ $customer->notes }}</textarea>
                </div>

                <div>
                    <x-ui.button type="submit" icon="check">
                        {{ ___('Save Notes') }}
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</div>

{{-- Order history --}}
<x-ui.card :title="___('Order History')">
    <x-slot:actions>
        <span class="ui-cluster ui-inline">
            <x-ui.tag tone="success">{{ ___(':count completed', ['count' => number_format($completedCount)]) }}</x-ui.tag>
            <x-ui.tag tone="warning">{{ ___(':count active', ['count' => number_format($activeCount)]) }}</x-ui.tag>
        </span>
    </x-slot:actions>

    <div class="ui-table-wrap">
        <table class="ui-table">
            <thead>
            <tr>
                <th>{{ ___('Date') }}</th>
                <th>{{ ___('Type') }}</th>
                <th>{{ ___('Table/Address') }}</th>
                <th>{{ ___('Status') }}</th>
                <th>{{ ___('Action') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse($orders as $order)
                @php
                    $typeLabel = match ($order->type) {
                        'on-table' => ___('On Table'),
                        'takeaway' => ___('Takeaway'),
                        'delivery' => ___('Delivery'),
                        default => ucfirst((string) $order->type),
                    };
                    $statusVariant = $order->status === 'completed' ? 'success' : ($order->status === 'cancelled' ? 'danger' : 'warning');
                @endphp
                <tr>
                    <td data-label="{{ ___('Date') }}">{{ date_formating($order->created_at, 'd M, Y h:i A') }}</td>
                    <td data-label="{{ ___('Type') }}">{{ $typeLabel }}</td>
                    <td data-label="{{ ___('Table/Address') }}">
                        @if($order->type == 'on-table')
                            {{ $order->table_number ?: '-' }}
                        @else
                            {{ \Illuminate\Support\Str::limit((string) $order->address, 42) ?: '-' }}
                        @endif
                    </td>
                    <td data-label="{{ ___('Status') }}">
                        <x-ui.tag :tone="$statusVariant">{{ $statusLabels[$order->status] ?? ucfirst((string) $order->status) }}</x-ui.tag>
                    </td>
                    <td data-label="{{ ___('Action') }}">
                        <x-ui.button :href="route('stores.orders', $order->store_id)" variant="secondary" size="sm" icon="orders">
                            {{ ___('Orders') }}
                        </x-ui.button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">
                        <x-ui.empty :title="___('No orders found for this customer.')" :body="___('Order history will appear after this customer places an order.')" />
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $orders->links() }}
</x-ui.card>
@endsection
