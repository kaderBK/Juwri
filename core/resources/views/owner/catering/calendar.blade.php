{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')
@section('title', ___('Catering calendar'))
@section('page_title', ___('Catering calendar & pipeline'))
@section('page_sub', ___('Events by date and stage.'))
@section('page_actions')
    <form method="get" action="{{ route('stores.catering.calendar') }}" class="ui-catcal__month">
        <input type="hidden" name="store_id" value="{{ $rid }}">
        <input type="month" name="month" value="{{ $month }}" class="ui-input ui-input--sm" data-ui-do="submit" aria-label="{{ ___('Month') }}">
    </form>
@endsection
@section('content')
@include('owner.partials.feature-tabs', ['tabs' => juwri_feature_tabs('catering')])

<div class="ui-scope ui-catcal">

@if(session('success'))<x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>@endif

<x-ui.card :title="___('Events this month')" variant="flush">
    <x-slot:actions><x-ui.tag>{{ count($events) }}</x-ui.tag></x-slot:actions>
    @if(count($events))
        <ul class="ui-catcal__events">
        @foreach($events as $e)
            <li>
                <span class="ui-catcal__date">
                    <strong class="ui-ltr">{{ optional($e->event_at)->format('d') }}</strong>
                    <small>{{ optional($e->event_at)->format('D') }}</small>
                </span>
                <span class="ui-catcal__body">
                    <span class="ui-catcal__title">{{ $e->name }}</span>
                    <span class="ui-catcal__sub"><span class="ui-ltr">{{ optional($e->event_at)->format('d M H:i') }}</span> · <span class="ui-ltr">{{ $e->headcount ?? '?' }}p</span></span>
                </span>
                <x-ui.pill>{{ $e->status }}</x-ui.pill>
            </li>
        @endforeach
        </ul>
    @else
        <x-ui.empty :title="___('No events this month.')" icon="calendar" />
    @endif
</x-ui.card>

<div class="ui-catcal__board" data-pipeline>
    @foreach($stages as $stage)
    <section class="ui-catcal__col">
        <header class="ui-catcal__col-head">
            <h2 class="ui-overline">{{ ___(ucfirst($stage)) }}</h2>
            <x-ui.tag>{{ count($pipeline[$stage]) }}</x-ui.tag>
        </header>
        @foreach($pipeline[$stage] as $req)
            <div class="ui-catcal__card">
                <span class="ui-catcal__title">{{ $req->name }}</span>
                <span class="ui-catcal__sub ui-ltr">{{ optional($req->event_at)->format('d M') }}</span>
                @if($stage === 'accepted')
                    <form method="post" action="{{ route('stores.catering.fulfill', $req->id) }}?store_id={{ $rid }}">@csrf<x-ui.button type="submit" variant="secondary" size="sm" icon="check">{{ ___('Fulfill') }}</x-ui.button></form>
                @elseif($stage === 'fulfilled')
                    <form method="post" action="{{ route('stores.catering.close', $req->id) }}?store_id={{ $rid }}">@csrf<x-ui.button type="submit" variant="ghost" size="sm">{{ ___('Close') }}</x-ui.button></form>
                @endif
            </div>
        @endforeach
    </section>
    @endforeach
</div>

</div>
@endsection
