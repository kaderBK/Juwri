{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Waitlist'))
@section('page_title', ___('Waitlist'))
@section('page_sub', $store->title.' - '.___('Walk-in queue with quoted wait times.'))

@section('content')
<div class="ui-scope ui-stack ui-wait">
    {{-- Hero --}}
    <x-ui.card variant="inset" class="ui-wait__hero">
        <div>
            <p class="ui-overline">{{ ___('Waitlist') }}</p>
            <h2 class="ui-card__title">{{ ___('Live queue') }}</h2>
            <p class="ui-muted">{{ ___('Add walk-ins, notify when ready, mark seated.') }}</p>
        </div>
        <dl class="ui-facts">
            <div><dt>{{ ___('Active') }}</dt><dd>{{ $active->count() }}</dd></div>
            <div><dt>{{ ___('Avg turn') }}</dt><dd>{{ $avgTurnMinutes }}m</dd></div>
        </dl>
    </x-ui.card>

    <x-ui.card :title="___('Add party')">
        <form action="{{ route('waitlist.add', $store->id) }}" method="post" class="ui-form-grid">
            @csrf
            <x-ui.field name="name" :label="___('Name')" required maxlength="120" />
            <x-ui.field name="phone" type="tel" :label="___('Phone')" maxlength="30" />
            <x-ui.field name="party_size" type="number" :label="___('Party size')" :value="1" required min="1" max="99" />
            <div class="ui-actions ui-form-grid__full">
                <x-ui.button type="submit" icon="plus" data-ui-busy>{{ ___('Add to queue') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    {{-- google-partner D6: Reserve with Google (waitlist). Dark — shown only once the
         owner wired platform RwG creds; the per-store toggle lives here. --}}
    @if (!empty($rwgConfigured))
    <x-ui.card :title="___('Reserve with Google — Waitlist')">
        <form action="{{ route('waitlist.rwg-settings', $store->id) }}" method="post" class="ui-form-grid">
            @csrf
            <x-ui.switch name="rwg_waitlist_enabled" id="rwg_waitlist_enabled" :checked="!empty($rwgEnabled)"
                         :label="___('Accept Google waitlist joins')" :description="___('Google-sourced parties are tagged in the queue below.')" />
            <x-ui.field name="waitlist_max_party" type="number" :label="___('Max party size')" :value="$rwgMaxParty ?? 12" min="1" max="99" />
            <div class="ui-actions ui-form-grid__full">
                <x-ui.button type="submit" icon="check">{{ ___('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>
    @endif

    <x-ui.card :title="___('Active queue')" variant="flush">
        @if ($active->isEmpty())
            <x-ui.empty :title="___('Queue is empty. Add a walk-in to start.')" icon="users" />
        @else
            <table class="ui-table">
                <thead><tr><th class="ui-table__num">{{ ___('#') }}</th><th>{{ ___('Name') }}</th><th>{{ ___('Phone') }}</th><th class="ui-table__num">{{ ___('Party') }}</th><th class="ui-table__num">{{ ___('Quoted') }}</th><th>{{ ___('Status') }}</th><th class="ui-table__num">{{ ___('Actions') }}</th></tr></thead>
                <tbody>
                    @foreach ($active as $i => $e)
                        <tr>
                            <td class="ui-table__num" data-label="{{ ___('#') }}">{{ $i + 1 }}</td>
                            <td data-label="{{ ___('Name') }}"><strong>{{ $e->name }}</strong>@if (($e->source ?? 'store') === 'google') <x-ui.tag>{{ ___('Google') }}</x-ui.tag>@endif</td>
                            <td data-label="{{ ___('Phone') }}" class="ui-ltr">{{ $e->phone ?? '—' }}</td>
                            <td class="ui-table__num" data-label="{{ ___('Party') }}">{{ $e->party_size }}</td>
                            <td class="ui-table__num" data-label="{{ ___('Quoted') }}">
                                <span>{{ $e->quoted_wait }}m</span>
                                <x-ui.tag tone="accent">~{{ $e->estimatedWait($avgTurnMinutes) }} {{ ___('min') }}</x-ui.tag>
                            </td>
                            <td data-label="{{ ___('Status') }}"><x-ui.pill :tone="\Juwri\Core\Support\Views\OwnerOpsViewHelpers::waitlistStatusTone($e->status)">{{ ___($e->status) }}</x-ui.pill></td>
                            <td class="ui-table__num" data-label="{{ ___('Actions') }}">
                                <div class="ui-actions">
                                    @if ($e->status === 'waiting')
                                        <form action="{{ route('waitlist.notify', [$store->id, $e->id]) }}" method="post">@csrf<x-ui.button type="submit" variant="ghost" size="sm" icon="bell">{{ ___('Notify') }}</x-ui.button></form>
                                    @endif
                                    <form action="{{ route('waitlist.seat', [$store->id, $e->id]) }}" method="post">@csrf<x-ui.button type="submit" size="sm" icon="check">{{ ___('Seat') }}</x-ui.button></form>
                                    <form action="{{ route('waitlist.leave', [$store->id, $e->id]) }}" method="post">@csrf<x-ui.button type="submit" variant="danger" size="sm" icon="x">{{ ___('Left') }}</x-ui.button></form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-ui.card>

    @if ($history->isNotEmpty())
        <x-ui.card :title="___('Recent history')" variant="flush">
            <table class="ui-table">
                <thead><tr><th>{{ ___('Name') }}</th><th class="ui-table__num">{{ ___('Party') }}</th><th>{{ ___('Status') }}</th><th>{{ ___('When') }}</th></tr></thead>
                <tbody>
                    @foreach ($history as $e)
                        <tr>
                            <td data-label="{{ ___('Name') }}">{{ $e->name }}@if (($e->source ?? 'store') === 'google') <x-ui.tag>{{ ___('Google') }}</x-ui.tag>@endif</td>
                            <td class="ui-table__num" data-label="{{ ___('Party') }}">{{ $e->party_size }}</td>
                            <td data-label="{{ ___('Status') }}"><x-ui.pill :tone="\Juwri\Core\Support\Views\OwnerOpsViewHelpers::waitlistStatusTone($e->status)">{{ ___($e->status) }}</x-ui.pill></td>
                            <td data-label="{{ ___('When') }}">{{ $e->updated_at?->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>
    @endif
</div>
@endsection
