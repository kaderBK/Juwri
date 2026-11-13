{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Services'))
@section('page_title', ___('Bookable services'))
@section('page_sub', ___('Duration, price and deposit of each service. Buffers keep time free before or after and are never shown to customers.'))

@section('page_actions')
    @include('owner.bookings._store-switch')
@endsection

@section('content')
<div class="ui-scope ui-stack">
    @include('owner.bookings._nav')

    @php
        $depositTypes = ['none' => ___('No deposit'), 'amount' => ___('Fixed amount'), 'percent' => ___('Percent of the price')];
    @endphp

    @foreach($services as $row)
        @php $s = $row['model']; $k = 'sv-'.$s->id; @endphp
        <x-ui.card :title="$s->name">
            <x-slot:actions>
                <span class="ui-muted">{{ $row['meta'] }}@if($row['deposit']) · {{ ___('Deposit') }} {{ $row['deposit'] }}@endif</span>
                @unless($s->active)<x-ui.tag>{{ ___('Off') }}</x-ui.tag>@endunless
                <form method="post" action="{{ route('bookings.owner.services.delete', ['service' => $s->id, 'store_id' => $store->id]) }}">
                    @csrf
                    <x-ui.button type="submit" variant="ghost" size="sm" icon="trash">{{ ___('Remove') }}</x-ui.button>
                </form>
            </x-slot:actions>
            @include('owner.bookings._service-form', ['action' => route('bookings.owner.services.update', ['service' => $s->id, 'store_id' => $store->id]), 'k' => $k, 's' => $s, 'row' => $row, 'depositTypes' => $depositTypes, 'isNew' => false])
        </x-ui.card>
    @endforeach

    <x-ui.card :title="___('Add a service')">
        @include('owner.bookings._service-form', ['action' => route('bookings.owner.services.store', ['store_id' => $store->id]), 'k' => 'sv-new', 's' => null, 'row' => null, 'depositTypes' => $depositTypes, 'isNew' => true])
    </x-ui.card>
</div>
@endsection
