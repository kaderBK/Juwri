{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')
@section('title', ___('COGS & purchasing'))
@section('page_title', ___('COGS & variance'))
@section('page_sub', ___('Purchases, variance, reorder and supplier spend.'))
@section('page_actions')
    <form method="get" action="{{ route('inventory.cogs') }}" class="ui-cogs__store">
        <select name="store_id" class="ui-input ui-input--sm" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            @foreach ($stores as $r)<option value="{{ $r->id }}" @selected($rid == $r->id)>{{ $r->title }}</option>@endforeach
        </select>
    </form>
@endsection
@section('content')
@include('owner.partials.feature-tabs', ['tabs' => juwri_feature_tabs('inventory')])

<div class="ui-scope ui-cogs">
<section class="ui-cogs__stats" aria-label="{{ ___('COGS & variance') }}">
    <x-ui.stat :value="number_format($purchases, 2)" :label="___('Purchases')" icon="wallet" />
    <x-ui.stat :value="number_format($variance['variance'], 2).($variance['variance_pct'] !== null ? ' ('.$variance['variance_pct'].'%)' : '')" :label="___('Variance')" icon="trend-up" :tone="$variance['variance'] < 0 ? 'danger' : 'success'" />
    <x-ui.stat :value="count($spend)" :label="___('Suppliers billed')" icon="users" />
</section>
<div class="ui-cogs__pair">
    <x-ui.card :title="___('Reorder suggestions')">
        @forelse($reorder as $grp)
            <div class="ui-cogs__group">
                <p class="ui-overline">{{ $grp['supplier'] ?? ___('Unassigned') }}</p>
                <ul class="ui-cogs__lines">@foreach($grp['lines'] as $l)<li><span>{{ $l['name'] }}</span><strong class="ui-ltr">{{ $l['suggest_qty'] }}</strong></li>@endforeach</ul>
            </div>
        @empty<x-ui.empty :title="___('Everything is above par.')" icon="check" />@endforelse
    </x-ui.card>
    <x-ui.card :title="___('Supplier spend')">
        @if(count($spend))
            <ul class="ui-cogs__lines">
            @foreach($spend as $s)<li><span>{{ $s['supplier'] ?? '—' }}</span><strong class="ui-ltr">{{ number_format($s['total'] ?? 0, 2) }}</strong></li>@endforeach
            </ul>
        @else
            <x-ui.empty :title="___('No purchases in range.')" icon="wallet" />
        @endif
    </x-ui.card>
</div>
</div>
@endsection
