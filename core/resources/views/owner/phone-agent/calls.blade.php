{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Call log'))
@section('page_title', ___('Call log'))
@section('page_sub', ___('Every call your AI phone agent handled.'))

@section('page_actions')
    @include('owner.partials.context-store-select', ['showBranch' => false])
@endsection

@section('content')
<div class="ui-scope ui-channels">
<x-ui.card :title="___('Call log')" variant="flush">
    @if($calls->isEmpty())
        <x-ui.empty icon="phone" :title="___('No calls yet.')" />
    @else
        <table class="ui-table">
            <thead><tr><th>{{ ___('Caller') }}</th><th>{{ ___('Outcome') }}</th><th>{{ ___('Duration') }}</th><th>{{ ___('Order') }}</th><th>{{ ___('Recorded') }}</th><th>{{ ___('When') }}</th><th><span class="ui-visually-hidden">{{ ___('View') }}</span></th></tr></thead>
            <tbody>
            @foreach($calls as $c)
                <tr>
                    <td data-primary><span class="ui-ltr">{{ $c->from_number ?: '—' }}</span></td>
                    <td data-label="{{ ___('Outcome') }}"><x-ui.tag>{{ ___(ucfirst($c->outcome)) }}</x-ui.tag></td>
                    <td data-label="{{ ___('Duration') }}"><span class="ui-ltr">{{ $c->duration_seconds !== null ? gmdate('i:s', (int) $c->duration_seconds) : '—' }}</span></td>
                    <td data-label="{{ ___('Order') }}"><span class="ui-ltr">{{ $c->order_id ? '#'.$c->order_id : '—' }}</span></td>
                    <td data-label="{{ ___('Recorded') }}"><x-ui.pill :tone="$c->recording ? 'success' : 'neutral'">{{ $c->recording ? ___('Recorded') : ___('Not recorded') }}</x-ui.pill></td>
                    <td data-label="{{ ___('When') }}"><span class="ui-channels__muted">{{ $c->created_at?->diffForHumans() }}</span></td>
                    <td class="ui-catalog__row-actions"><x-ui.button :href="route('stores.phoneagent.calls.show', $c->id).'?store_id='.$rid" variant="secondary" size="sm">{{ ___('View') }}</x-ui.button></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="ui-catalog__pager">{{ $calls->withQueryString()->links() }}</div>
    @endif
</x-ui.card>
</div>
@endsection
