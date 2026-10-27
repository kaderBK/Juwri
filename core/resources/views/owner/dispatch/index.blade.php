{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Dispatch'))
@section('page_title', ___('Dispatch'))
@section('page_sub', ___('Compare courier cost & ETA, then dispatch.'))

@section('page_actions')
    @include('owner.partials.context-store-select', ['showBranch' => false])
@endsection

@section('content')
@include('owner.partials.feature-tabs', ['tabs' => juwri_feature_tabs('dispatch')])

<div class="ui-scope ui-dispatch">
    <div class="ui-dispatch__mode">
        <x-ui.icon name="delivery" />
        <span class="ui-dispatch__mode-text">{{ ___('Dispatch mode') }}: <strong>{{ ucfirst($mode) }}</strong> — {{ ___('change it in store settings.') }}</span>
        <x-ui.tag class="ui-dispatch__count">{{ $orders->count() }}</x-ui.tag>
    </div>

    @forelse($orders as $order)
        @php $orderQuotes = $quotes[$order->id] ?? []; @endphp
        <article class="ui-card ui-dispatch__order">
            <header class="ui-dispatch__head">
                <strong class="ui-dispatch__id ui-ltr">#{{ $order->id }}</strong>
                <x-ui.pill :tone="$order->delivery_status === 'assigned' ? 'accent' : 'warning'">{{ ___(ucfirst($order->delivery_status ?: 'pending')) }}</x-ui.pill>
            </header>
            <p class="ui-dispatch__addr"><x-ui.icon name="pin" size="16" /><span>{{ $order->address ?: '—' }}</span></p>

            @if(count($orderQuotes))
                <ul class="ui-dispatch__quotes" aria-label="{{ ___('Courier') }}">
                    @foreach($orderQuotes as $q)
                        <li>
                            <form method="post" action="{{ route('stores.dispatch.order', $order->id) }}?store_id={{ $rid }}">
                                @csrf
                                <input type="hidden" name="provider" value="{{ $q['provider'] }}">
                                <button type="submit" class="ui-dispatch__quote" title="{{ ___('ETA') }} {{ $q['eta_minutes'] ?? '—' }} min">
                                    <span class="ui-dispatch__provider">{{ ucwords(str_replace('_', ' ', $q['provider'])) }}</span>
                                    <span class="ui-dispatch__fee ui-ltr">{{ number_format($q['fee'], 2) }}</span>
                                    <span class="ui-dispatch__eta">
                                        <x-ui.icon name="clock" size="14" />
                                        @if(!empty($q['eta_minutes']))<span class="ui-ltr">{{ $q['eta_minutes'] }}</span> {{ ___('min') }}@else — @endif
                                    </span>
                                    <span class="ui-dispatch__go" aria-hidden="true"><x-ui.icon name="chevron" size="16" class="ui-flip-rtl" /></span>
                                </button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </article>
    @empty
        <div class="ui-card">
            <x-ui.empty :title="___('No delivery orders awaiting dispatch.')" icon="delivery" />
        </div>
    @endforelse
</div>
@endsection
