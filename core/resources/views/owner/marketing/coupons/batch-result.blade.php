{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', $batch->name ?: ___('Coupon Batch').' #'.$batch->id)
@section('page_title', $batch->name ?: ___('Coupon Batch').' #'.$batch->id)
@section('page_sub', ___('Generated Codes'))

@section('page_actions')
    <x-ui.button variant="secondary" icon="download" :href="route('stores.coupons.export', ['batch_id' => $batch->id])">{{ ___('Export CSV') }}</x-ui.button>
    <x-ui.button variant="secondary" :href="route('stores.coupons.batches.index')">{{ ___('All Batches') }}</x-ui.button>
    <x-ui.button variant="secondary" icon="back" :href="route('stores.coupons.index')">{{ ___('All Coupons') }}</x-ui.button>
@endsection

@section('content')
<div class="ui-scope ui-coupons">
    @if (session('success'))
        <x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>
    @endif

    <x-ui.card :title="___('Batch Summary')">
        <x-ui.row :title="___('Batch Name')" :sub="$batch->name ?: '—'" />
        <x-ui.row :title="___('Prefix')" :sub="$batch->prefix ?: '—'" />
        <x-ui.row :title="___('Quantity')" :sub="(string) $batch->count" />
        <x-ui.row :title="___('Created')" :sub="date_formating($batch->created_at)" />
    </x-ui.card>

    <section class="ui-card ui-card--flush">
        <div class="ui-card__head">
            <h2 class="ui-card__title">{{ ___('Generated Codes') }}</h2>
        </div>
        <table class="ui-table">
            <thead>
                <tr>
                    <th>{{ ___('Code') }}</th>
                    <th>{{ ___('Type') }}</th>
                    <th class="ui-table__num">{{ ___('Value') }}</th>
                    <th>{{ ___('Used / Limit') }}</th>
                    <th>{{ ___('Expires') }}</th>
                    <th>{{ ___('Status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($coupons as $coupon)
                    <tr>
                        <td data-primary><code class="ui-coupons__code">{{ $coupon->code }}</code></td>
                        <td data-label="{{ ___('Type') }}">{{ $coupon->coupon_type === 'percentage' ? ___('Percentage') : ___('Fixed') }}</td>
                        <td class="ui-table__num" data-label="{{ ___('Value') }}"><strong class="ui-ltr">{{ $coupon->coupon_type === 'percentage' ? $coupon->percentage.'%' : price_format($coupon->percentage) }}</strong></td>
                        <td class="ui-ltr" data-label="{{ ___('Used / Limit') }}">{{ $coupon->used }} / {{ (int) $coupon->limit > 0 ? $coupon->limit : ___('Unlimited') }}</td>
                        <td data-label="{{ ___('Expires') }}">{{ $coupon->expiry_at ? $coupon->expiry_at->copy()->setTimezone($storeTz)->format('d M Y') : ___('Never') }}</td>
                        <td data-label="{{ ___('Status') }}">{{ $bulk->statusOf($coupon) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if ($coupons->hasPages())
            <div class="ui-catalog__pager">{{ $coupons->links() }}</div>
        @endif
    </section>
</div>
@endsection
