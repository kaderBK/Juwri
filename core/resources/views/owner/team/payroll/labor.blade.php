{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Labor cost'))
@section('page_title', ___('Labor vs sales'))
@section('page_sub', ___('Labor cost as a share of sales.'))

@section('page_actions')
    <form method="get" action="{{ route('stores.payroll.labor') }}">
        <select name="store_id" class="ui-input ui-input--sm" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($rid == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')
@include('owner.partials.feature-tabs', ['tabs' => juwri_feature_tabs('payroll')])

<div class="ui-scope ui-hr">
    <x-ui.card>
        <form method="get" action="{{ route('stores.payroll.labor') }}" class="ui-hr__bar">
            <input type="hidden" name="store_id" value="{{ $rid }}">
            <div class="ui-hr__field">
                <label class="ui-label" for="lb-from">{{ ___('From') }}</label>
                <input id="lb-from" type="date" name="from" value="{{ $from }}" class="ui-input">
            </div>
            <div class="ui-hr__field">
                <label class="ui-label" for="lb-to">{{ ___('To') }}</label>
                <input id="lb-to" type="date" name="to" value="{{ $to }}" class="ui-input">
            </div>
            <x-ui.button type="submit" icon="refresh">{{ ___('Run') }}</x-ui.button>
        </form>
    </x-ui.card>

    <section class="ui-hr__kpis" aria-label="{{ ___('Labor vs sales') }}">
        <x-ui.stat :value="number_format($sales, 2)" :label="___('Sales')" icon="trend-up" />
        <x-ui.stat :value="number_format($labor, 2)" :label="___('Labor cost')" icon="users" />
    </section>

    <x-ui.card :title="___('Labor %')" :class="$warn ? 'ui-hr__pct is-warn' : 'ui-hr__pct'">
        @if($warn)
            <x-slot:actions><x-ui.pill tone="danger">{{ ___('Above threshold') }}</x-ui.pill></x-slot:actions>
        @endif
        <p class="ui-hr__pct-value"><span class="ui-ltr">{{ $laborPct === null ? '—' : $laborPct.'%' }}</span></p>
        @if($laborPct !== null)
            <span @class(['ui-ov-meter', 'is-exhausted' => $warn]) aria-hidden="true"><span style="inline-size: {{ min(100, max(0, $laborPct)) }}%"></span></span>
        @endif
    </x-ui.card>
</div>
@endsection
