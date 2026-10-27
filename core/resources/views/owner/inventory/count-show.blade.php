{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Stock count').' #'.$count->id)
@section('page_title', ___('Stock count').' #'.$count->id)
@section('page_sub', $count->status === 'closed' ? ___('Closed') : ___('Open — enter physical counts'))

@section('page_actions')
    <x-ui.button :href="route('inventory.counts.index', ['store_id' => $count->store_id])" variant="secondary" icon="back">
        {{ ___('Back') }}
    </x-ui.button>
@endsection

@section('content')
@php
    $closed = $count->status === 'closed';
    $varianceValue = 0;
    foreach ($count->items as $it) {
        $varianceValue += (float) $it->variance * (float) ($it->ingredient->cost_per_unit ?? 0);
    }
@endphp

<div class="ui-card ui-card--inset">
    <div class="ui-cluster ui-cluster--between ui-card__body">
        <div>
            <p class="ui-small ui-caps ui-text-accent">{{ ___('Stock count') }}</p>
            <h3 class="ui-section-title">#{{ $count->id }} — {{ $closed ? ___('Closed') : ___('Open') }}</h3>
            <p class="ui-text-2">{{ $count->notes }}</p>
        </div>
        @if($closed)
            <div>
                <span class="ui-muted ui-block">{{ ___('Variance value') }}</span>
                <strong class="ui-fs-lg">{{ price_symbol_format(round($varianceValue, 2)) }}</strong>
            </div>
        @endif
    </div>
</div>

<form method="post" action="{{ route('inventory.counts.save', $count->id) }}">
    @csrf @method('PUT')
    <x-ui.card :title="___('Ingredients')">
        @if($count->items->isEmpty())
            <x-ui.empty :title="___('No ingredients')" :body="___('No ingredients were captured in this count.')" />
        @else
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead><tr>
                        <th>{{ ___('Ingredient') }}</th>
                        <th class="ui-end">{{ ___('System (theoretical)') }}</th>
                        <th class="ui-end">{{ ___('Counted (actual)') }}</th>
                        <th class="ui-end">{{ ___('Variance') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($count->items as $it)
                            @php
                                $hasCount = $it->counted_qty !== null;
                                $liveVar = $hasCount ? (float) $it->counted_qty - (float) $it->theoretical_qty : null;
                            @endphp
                            <tr>
                                <td data-label="{{ ___('Ingredient') }}"><strong>{{ $it->ingredient->name ?? '—' }}</strong> <small class="ui-text-2">{{ $it->ingredient->unit ?? '' }}</small></td>
                                <td class="ui-end" data-label="{{ ___('System (theoretical)') }}">{{ rtrim(rtrim(number_format($it->theoretical_qty, 3), '0'), '.') }}</td>
                                <td class="ui-end" data-label="{{ ___('Counted (actual)') }}">
                                    @if($closed)
                                        {{ $hasCount ? rtrim(rtrim(number_format($it->counted_qty, 3), '0'), '.') : '—' }}
                                    @else
                                        <input class="ui-input ui-input--sm ui-ms-auto" style="max-width:110px" type="number" step="0.001" min="0"
                                            name="counted[{{ $it->id }}]" value="{{ $hasCount ? rtrim(rtrim(number_format($it->counted_qty, 3), '0'), '.') : '' }}">
                                    @endif
                                </td>
                                <td class="ui-end" data-label="{{ ___('Variance') }}">
                                    @php $v = $closed ? (float) $it->variance : $liveVar; @endphp
                                    @if($v === null)
                                        <span class="ui-text-2">—</span>
                                    @else
                                        <span class="{{ $v < 0 ? 'ui-text-danger' : ($v > 0 ? 'ui-text-success' : 'ui-text-2') }}">
                                            {{ $v > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($v, 3), '0'), '.') }}
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    @if(!$closed)
        <div class="ui-cluster ui-cluster--end">
            <x-ui.button type="submit" variant="secondary" icon="check">{{ ___('Save counts') }}</x-ui.button>
        </div>
    @endif
</form>

@if(!$closed)
    <x-ui.card>
        <div class="ui-cluster ui-cluster--between">
            <p class="ui-text-2">{{ ___('Closing posts variance corrections to the ledger so system stock matches your physical count.') }}</p>
            <form method="post" action="{{ route('inventory.counts.close', $count->id) }}"
                data-confirm="{{ ___('Close this count and correct stock? This cannot be undone.') }}">@csrf
                <x-ui.button type="submit" icon="check">{{ ___('Close & reconcile') }}</x-ui.button>
            </form>
        </div>
    </x-ui.card>
@endif
@endsection
