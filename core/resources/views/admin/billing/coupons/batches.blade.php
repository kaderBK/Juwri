{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Coupon Batches'))
@section('header_buttons')
    <x-ui.button size="sm" icon="plus" :href="route('admin.coupons.bulk.create')">{{ ___('Bulk Create') }}</x-ui.button>
    <x-ui.button size="sm" variant="secondary" :href="route('admin.coupons.index')">{{ ___('Back to Coupons') }}</x-ui.button>
@endsection
@section('content')
    <x-ui.card variant="flush">
        @if ($batches->isEmpty())
            <x-ui.empty :title="___('No coupon batches yet')" :description="___('Generate a batch of coupon codes to see it here.')" />
        @else
            <div class="ui-dt__scroll">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>{{ ___('Batch Name') }}</th>
                            <th>{{ ___('Prefix') }}</th>
                            <th>{{ ___('Generated') }}</th>
                            <th>{{ ___('Remaining Codes') }}</th>
                            <th>{{ ___('Created') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($batches as $batch)
                            <tr>
                                <td>{{ $batch->id }}</td>
                                <td>{{ $batch->name ?: '—' }}</td>
                                <td><span class="ui-mono">{{ $batch->prefix ?: '—' }}</span></td>
                                <td>{{ $batch->count }}</td>
                                <td>{{ $batch->coupons_count }}</td>
                                <td>{{ date_formating($batch->created_at) }}</td>
                                <td>
                                    <x-ui.button size="sm" variant="secondary" :href="route('admin.coupons.batches.show', $batch->id)">{{ ___('View') }}</x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="ui-card__foot">
                {{ $batches->links() }}
            </div>
        @endif
    </x-ui.card>
@endsection
