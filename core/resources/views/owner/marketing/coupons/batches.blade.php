{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Coupon Batches'))
@section('page_title', ___('Coupon Batches'))
@section('page_sub', ___('History of generated coupon batches.'))

@section('page_actions')
    <x-ui.button icon="plus" :href="route('stores.coupons.bulk.create')">{{ ___('Bulk Create') }}</x-ui.button>
    <x-ui.button variant="secondary" icon="back" :href="route('stores.coupons.index')">{{ ___('Back to Coupons') }}</x-ui.button>
@endsection

@section('content')
<div class="ui-scope ui-coupons">
    @if ($batches->isEmpty())
        <div class="ui-card">
            <x-ui.empty :title="___('No coupon batches yet')" :body="___('Generate a batch of coupon codes to see it here.')" icon="gift">
                <x-ui.button :href="route('stores.coupons.bulk.create')" icon="plus">{{ ___('Bulk Create') }}</x-ui.button>
            </x-ui.empty>
        </div>
    @else
        <section class="ui-card ui-card--flush">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>{{ ___('Batch Name') }}</th>
                        <th>{{ ___('Prefix') }}</th>
                        <th>{{ ___('Generated') }}</th>
                        <th>{{ ___('Remaining Codes') }}</th>
                        <th>{{ ___('Created') }}</th>
                        <th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($batches as $batch)
                        <tr>
                            <td class="ui-ltr" data-label="#">{{ $batch->id }}</td>
                            <td data-primary>{{ $batch->name ?: '—' }}</td>
                            <td data-label="{{ ___('Prefix') }}"><code class="ui-coupons__code">{{ $batch->prefix ?: '—' }}</code></td>
                            <td class="ui-ltr" data-label="{{ ___('Generated') }}">{{ $batch->count }}</td>
                            <td class="ui-ltr" data-label="{{ ___('Remaining Codes') }}">{{ $batch->coupons_count }}</td>
                            <td data-label="{{ ___('Created') }}">{{ date_formating($batch->created_at) }}</td>
                            <td class="ui-catalog__row-actions">
                                <x-ui.button size="sm" variant="secondary" :href="route('stores.coupons.batches.show', $batch->id)">{{ ___('View') }}</x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if ($batches->hasPages())
                <div class="ui-catalog__pager">{{ $batches->links() }}</div>
            @endif
        </section>
    @endif
</div>
@endsection
