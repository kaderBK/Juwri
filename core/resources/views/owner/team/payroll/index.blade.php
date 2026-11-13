{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Payroll'))
@section('page_title', ___('Payroll'))
@section('page_sub', ___('Pay runs and approvals.'))

@section('page_actions')
    <form method="get" action="{{ route('stores.payroll.index') }}">
        <select name="store_id" class="ui-input ui-input--sm" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($rid == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')
@include('owner.partials.feature-tabs', ['tabs' => juwri_feature_tabs('payroll')])

@php $runTone = ['draft' => 'neutral', 'pending_approval' => 'warning', 'approved' => 'success', 'paid' => 'accent']; @endphp

<div class="ui-scope ui-hr">
    {{-- session('success') already surfaces as a toast via partials.flash. --}}
    @if(isset($errors) && $errors->any())
        <x-ui.banner tone="danger">{{ $errors->first() }}</x-ui.banner>
    @endif

    <x-ui.card :title="___('New pay run')">
        <form method="post" action="{{ route('stores.payroll.generate') }}?store_id={{ $rid }}" class="ui-hr__bar">
            @csrf
            <div class="ui-hr__field">
                <label class="ui-label" for="pr-start">{{ ___('Period start') }}</label>
                <input id="pr-start" type="date" name="period_start" class="ui-input" required>
            </div>
            <div class="ui-hr__field">
                <label class="ui-label" for="pr-end">{{ ___('Period end') }}</label>
                <input id="pr-end" type="date" name="period_end" class="ui-input" required>
            </div>
            <x-ui.button type="submit" icon="plus" data-ui-busy>{{ ___('Generate') }}</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card :title="___('Payroll')" variant="flush">
        <x-slot:actions><x-ui.tag>{{ $runs->count() }}</x-ui.tag></x-slot:actions>
        @if($runs->isEmpty())
            <x-ui.empty :title="___('No pay runs yet.')" icon="wallet" />
        @else
            <table class="ui-table">
                <thead><tr><th>{{ ___('Period') }}</th><th>{{ ___('Status') }}</th><th class="ui-table__num">{{ ___('Total net') }}</th><th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th></tr></thead>
                <tbody>
                @foreach($runs as $run)
                    <tr>
                        <td data-primary><span class="ui-ltr">{{ optional($run->period_start)->toDateString() }} → {{ optional($run->period_end)->toDateString() }}</span></td>
                        <td data-label="{{ ___('Status') }}"><x-ui.pill :tone="$runTone[$run->status] ?? 'neutral'">{{ ___(ucfirst(str_replace('_',' ',$run->status))) }}</x-ui.pill></td>
                        <td class="ui-table__num" data-label="{{ ___('Total net') }}"><strong class="ui-ltr">{{ number_format((float)$run->total_net, 2) }}</strong></td>
                        <td class="ui-catalog__row-actions">
                            <x-ui.button variant="secondary" size="sm" :href="route('stores.payroll.show', $run->id).'?store_id='.$rid">{{ ___('Open') }}</x-ui.button>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </x-ui.card>
</div>
@endsection
