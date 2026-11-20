{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', $batch->name ?: ___('Coupon Batch').' #'.$batch->id)
@section('header_buttons')
    <x-ui.button size="sm" variant="secondary" icon="download" :href="route('admin.coupons.export', ['batch_id' => $batch->id])">{{ ___('Export CSV') }}</x-ui.button>
    <x-ui.button size="sm" variant="secondary" :href="route('admin.coupons.batches.index')">{{ ___('All Batches') }}</x-ui.button>
    <x-ui.button size="sm" variant="secondary" :href="route('admin.coupons.index')">{{ ___('All Coupons') }}</x-ui.button>
@endsection
@section('content')
    <div class="ui-stack">
        @if (session('success'))
            <x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>
        @endif
        <x-ui.card :title="___('Batch Summary')">
            <x-ui.row :title="___('Batch Name')" :sub="$batch->name ?: '—'" />
            <x-ui.row :title="___('Prefix')" :sub="$batch->prefix ?: '—'" />
            <x-ui.row :title="___('Quantity')" :sub="(string) $batch->count" />
            <x-ui.row :title="___('Created')" :sub="date_formating($batch->created_at)" />
        </x-ui.card>

        <x-ui.card variant="flush" :title="___('Generated Codes')">
            <div class="ui-dt__scroll">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th>{{ ___('Coupon') }}</th>
                            <th>{{ ___('Coupon Type') }}</th>
                            <th>{{ ___('Discount') }}</th>
                            <th>{{ ___('Used') }}</th>
                            <th>{{ ___('Limit') }}</th>
                            <th>{{ ___('Expiry at') }}</th>
                            <th>{{ ___('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($coupons as $coupon)
                            <tr>
                                <td><strong class="ui-mono">{{ $coupon->code }}</strong></td>
                                <td>{{ $coupon->coupon_type }}</td>
                                <td>
                                    @if (\App\Models\Coupon::typeIsAmount($coupon->coupon_type))
                                        {{ $coupon->percentage }} {{ $coupon->minorMoneyCurrency() }}
                                    @else
                                        {{ $coupon->percentage }}%
                                    @endif
                                </td>
                                <td>{{ $coupon->used }}</td>
                                <td>{{ (int) $coupon->limit > 0 ? $coupon->limit : ___('Unlimited') }}</td>
                                <td>{{ $coupon->expiry_at ? date_formating($coupon->expiry_at) : ___('Never') }}</td>
                                <td>{{ $bulk->statusOf($coupon) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="ui-card__foot">
                {{ $coupons->links() }}
            </div>
        </x-ui.card>
    </div>
@endsection
