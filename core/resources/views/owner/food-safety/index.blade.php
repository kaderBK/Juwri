{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Food safety'))
@section('page_title', ___('Food safety'))
@section('page_sub', ___('Temperature checks, incidents and compliance.'))

@section('page_actions')
    @include('owner.partials.context-store-select', ['showBranch' => false])
@endsection

@section('content')
@php
    $pct = (int) round($compliance * 100);
    $pctTone = $pct >= 90 ? 'success' : ($pct >= 70 ? 'warning' : 'danger');
@endphp
<div class="ui-scope ui-fsafe">

@if(session('success'))
    <x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>
@endif

<div class="ui-fsafe__top">
    <section class="ui-card ui-fsafe__score" data-tone="{{ $pctTone }}">
        <p class="ui-overline">{{ ___('30-day compliance') }}</p>
        <p class="ui-fsafe__pct ui-ltr">{{ $pct }}%</p>
        <span class="ui-ov-meter" aria-hidden="true"><span style="inline-size: {{ max(0, min(100, $pct)) }}%"></span></span>
    </section>

    <x-ui.card :title="___('Add a check')" class="ui-fsafe__add">
        <form method="post" action="{{ route('stores.foodsafety.checks.store') }}?store_id={{ $rid }}" class="ui-fsafe__form">
            @csrf
            <div class="ui-fsafe__field ui-fsafe__field--grow"><label class="ui-label" for="fs-name">{{ ___('Name') }}</label><input id="fs-name" name="name" class="ui-input" required></div>
            <div class="ui-fsafe__field">
                <label class="ui-label" for="fs-kind">{{ ___('Kind') }}</label>
                <select id="fs-kind" name="kind" class="ui-input">
                    <option value="temperature">{{ ___('Temperature') }}</option>
                    <option value="appliance">{{ ___('Appliance') }}</option>
                    <option value="cleaning">{{ ___('Cleaning') }}</option>
                </select>
            </div>
            <div class="ui-fsafe__field ui-fsafe__field--num"><label class="ui-label" for="fs-min">{{ ___('Min') }}</label><input id="fs-min" name="target_min" type="number" step="0.1" class="ui-input" inputmode="decimal"></div>
            <div class="ui-fsafe__field ui-fsafe__field--num"><label class="ui-label" for="fs-max">{{ ___('Max') }}</label><input id="fs-max" name="target_max" type="number" step="0.1" class="ui-input" inputmode="decimal"></div>
            <x-ui.button type="submit" icon="plus">{{ ___('Add') }}</x-ui.button>
            <input type="hidden" name="frequency" value="daily">
        </form>
    </x-ui.card>
</div>

<x-ui.card :title="___('Checks')" variant="flush">
    <x-slot:actions>
        <x-ui.button :href="route('stores.foodsafety.labels').'?store_id='.$rid" variant="secondary" size="sm" icon="print">{{ ___('Expiry labels') }}</x-ui.button>
    </x-slot:actions>
    @if(count($checks))
        <ul class="ui-fsafe__checks">
        @foreach($checks as $c)
            <li @class(['ui-fsafe__check', 'is-off' => ! $c->active])>
                <span class="ui-row__icon" aria-hidden="true"><x-ui.icon :name="$c->kind === 'cleaning' ? 'sparkle' : ($c->kind === 'appliance' ? 'kitchen' : 'flame')" size="20" /></span>
                <span class="ui-fsafe__check-body">
                    <strong>{{ $c->name }}</strong>
                    <span class="ui-fsafe__check-sub">
                        {{ ___($c->kind) }}
                        @if($c->target_min !== null || $c->target_max !== null)
                            <x-ui.tag><span class="ui-ltr">{{ $c->target_min }}–{{ $c->target_max }}°</span></x-ui.tag>
                        @endif
                        @unless($c->active)<x-ui.pill>{{ ___('off') }}</x-ui.pill>@endunless
                    </span>
                </span>
                <span class="ui-catalog__row-actions">
                    <form method="post" action="{{ route('stores.foodsafety.checks.toggle', $c->id) }}?store_id={{ $rid }}">@csrf<button type="submit" class="ui-icon-btn" aria-label="{{ $c->active ? ___('Disable') : ___('Enable') }}" title="{{ $c->active ? ___('Disable') : ___('Enable') }}"><x-ui.icon :name="$c->active ? 'pause' : 'check'" /></button></form>
                    <form method="post" action="{{ route('stores.foodsafety.checks.destroy', $c->id) }}?store_id={{ $rid }}" data-confirm="{{ ___('Remove?') }}" data-confirm-danger>@csrf @method('DELETE')<button type="submit" class="ui-icon-btn ui-menu-item__delete" aria-label="{{ ___('Delete') }}" title="{{ ___('Delete') }}"><x-ui.icon name="trash" /></button></form>
                </span>
            </li>
        @endforeach
        </ul>
    @else
        <x-ui.empty :title="___('No checks configured.')" icon="check" />
    @endif
</x-ui.card>

<x-ui.card :title="___('Recent logs')" variant="flush">
    @if($logs->isEmpty())
        <x-ui.empty :title="___('No logs yet.')" icon="list" />
    @else
        <table class="ui-table">
            <thead><tr><th>{{ ___('When') }}</th><th>{{ ___('Kind') }}</th><th>{{ ___('Value') }}</th><th>{{ ___('Note') }}</th></tr></thead>
            <tbody>
            @foreach($logs as $l)
                <tr @class(['ui-fsafe__breach' => $l->breach])>
                    <td data-primary><span class="ui-ltr">{{ optional($l->logged_at)->format('d M H:i') }}</span></td>
                    <td data-label="{{ ___('Kind') }}">{{ ___($l->kind) }}</td>
                    <td data-label="{{ ___('Value') }}"><span class="ui-fsafe__val"><span class="ui-ltr">{{ $l->value !== null ? $l->value . '°' : '—' }}</span> @if($l->breach)<x-ui.pill tone="danger">{{ ___('breach') }}</x-ui.pill>@endif</span></td>
                    <td data-label="{{ ___('Note') }}">{{ $l->note }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</x-ui.card>

</div>
@endsection
