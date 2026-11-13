{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Paylink Partner Onboardings'))
@section('header_buttons')
    <x-ui.button variant="secondary" size="sm" icon="back" :href="route('admin.gateways.index')">{{ ___('Payment Gateways') }}</x-ui.button>
@endsection
@section('content')
<x-ui.card variant="flush">
    @if ($counts->isNotEmpty())
        <div class="ui-card__head">
            <span class="ui-cell-tags">
                @foreach ($counts as $status => $count)
                    <x-ui.tag>{{ $status }}: {{ $count }}</x-ui.tag>
                @endforeach
            </span>
        </div>
    @endif
    <div class="ui-table-wrap">
        <table class="ui-table">
            <thead>
                <tr>
                    <th>{{ ___('Restaurant') }}</th>
                    <th>{{ ___('Reseller') }}</th>
                    <th>{{ ___('Status') }}</th>
                    <th>{{ ___('Merchant ID') }}</th>
                    <th>{{ ___('Submitted') }}</th>
                    <th>{{ ___('Activated') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($onboardings as $o)
                    <tr>
                        <td data-primary data-label="{{ ___('Restaurant') }}">{{ $o->store_id }}</td>
                        <td data-label="{{ ___('Reseller') }}">{{ $o->reseller_id ?? '—' }}</td>
                        <td data-label="{{ ___('Status') }}"><x-ui.tag tone="accent">{{ $o->status }}</x-ui.tag></td>
                        <td class="ui-mono" data-label="{{ ___('Merchant ID') }}">{{ $o->provider_merchant_id ?? '—' }}</td>
                        <td data-label="{{ ___('Submitted') }}">{{ optional($o->submitted_at)->diffForHumans() ?? '—' }}</td>
                        <td data-label="{{ ___('Activated') }}">{{ optional($o->activated_at)->diffForHumans() ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="ui-center ui-text-2">{{ ___('No onboarding attempts yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>
@endsection
