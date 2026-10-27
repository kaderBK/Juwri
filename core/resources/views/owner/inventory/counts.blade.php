{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Stock counts'))
@section('page_title', ___('Stock counts'))
@section('page_sub', ___('Count physical stock and reconcile against the system.'))

@section('content')
<div class="ui-scope ui-counts">
<form method="get" action="{{ route('inventory.counts.index') }}" class="ui-counts__context">
    <select name="store_id" class="ui-input" data-ui-do="submit" aria-label="{{ ___('Store') }}">
        <option value="">{{ ___('Select store…') }}</option>
        @foreach ($stores as $r)
            <option value="{{ $r->id }}" @selected($storeId == $r->id)>{{ $r->title }}</option>
        @endforeach
    </select>
    @if($branches->isNotEmpty())
        <select name="branch_id" class="ui-input" data-ui-do="submit" aria-label="{{ ___('Branch') }}">
            <option value="">{{ ___('All branches') }}</option>
            @foreach ($branches as $b)
                <option value="{{ $b->id }}" @selected((int) $branchId === (int) $b->id)>{{ $b->name }}</option>
            @endforeach
        </select>
    @endif
</form>

@if($storeId)
    <x-ui.card :title="___('New stock count')">
        <form method="post" action="{{ route('inventory.counts.store') }}" class="ui-counts__new">
            @csrf
            <input type="hidden" name="store_id" value="{{ $storeId }}">
            <div class="ui-counts__field"><label class="ui-label" for="sc-notes">{{ ___('Notes (optional)') }}</label><input id="sc-notes" class="ui-input" name="notes" placeholder="{{ ___('End of week count') }}"></div>
            @if($branches->isNotEmpty())
                <div class="ui-counts__field">
                    <label class="ui-label" for="sc-branch">{{ ___('Branch') }}</label>
                    <select id="sc-branch" class="ui-input" name="branch_id">
                        <option value="">{{ ___('Whole store') }}</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" @selected((int) $branchId === (int) $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <x-ui.button type="submit" icon="plus">{{ ___('Start count') }}</x-ui.button>
        </form>
        <p class="ui-counts__hint">{{ ___('Starting a count snapshots current system stock for every ingredient. You then enter the physical counts.') }}</p>
    </x-ui.card>
@endif

<x-ui.card :title="___('Counts')" variant="flush">
    @if($counts->isEmpty())
        <x-ui.empty :title="$storeId ? ___('No stock counts yet') : ___('Select a store')"
            :body="$storeId ? ___('Start your first count above.') : ___('Pick a store to view its counts.')" icon="list" />
    @else
        <table class="ui-table">
            <thead><tr>
                <th>{{ ___('Count #') }}</th><th>{{ ___('Status') }}</th>
                @if($branches->isNotEmpty())
                    <th>{{ ___('Branch') }}</th>
                @endif
                <th class="ui-table__num">{{ ___('Items') }}</th><th>{{ ___('Date') }}</th>
                <th>{{ ___('Notes') }}</th><th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
            </tr></thead>
            <tbody>
                @foreach ($counts as $c)
                    <tr>
                        <td data-primary><strong class="ui-ltr">#{{ $c->id }}</strong></td>
                        <td data-label="{{ ___('Status') }}">
                            @if($c->status === 'closed')<x-ui.pill tone="success">{{ ___('Closed') }}</x-ui.pill>
                            @else<x-ui.pill tone="warning">{{ ___('Open') }}</x-ui.pill>@endif
                        </td>
                        @if($branches->isNotEmpty())
                            <td data-label="{{ ___('Branch') }}">{{ optional($branches->firstWhere('id', $c->branch_id))->name ?? ___('Whole store') }}</td>
                        @endif
                        <td class="ui-table__num" data-label="{{ ___('Items') }}"><span class="ui-ltr">{{ $c->items_count }}</span></td>
                        <td data-label="{{ ___('Date') }}"><span class="ui-ltr">{{ optional($c->counted_at ?? $c->created_at)->format('Y-m-d') }}</span></td>
                        <td data-label="{{ ___('Notes') }}">{{ $c->notes ?: '—' }}</td>
                        <td class="ui-catalog__row-actions">
                            <x-ui.button :href="route('inventory.counts.show', $c->id)" :variant="$c->status === 'closed' ? 'secondary' : 'primary'" size="sm" :icon="$c->status === 'closed' ? 'list' : 'pencil'">
                                {{ $c->status === 'closed' ? ___('View') : ___('Enter counts') }}
                            </x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-ui.card>
</div>
@endsection
