{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Rota'))
@section('page_title', ___('Rota'))
@section('page_sub', ___('Plan and publish staff shifts.'))

@section('page_actions')
    <form method="get" action="{{ route('stores.rota.index') }}">
        <select name="store_id" class="ui-input ui-input--sm" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($rid == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')
@php
    $prevWeek = $weekStart->copy()->subWeek()->toDateString();
    $nextWeek = $weekStart->copy()->addWeek()->toDateString();
@endphp

<div class="ui-scope ui-rota">
    {{-- session('success') already surfaces as a toast via partials.flash. --}}
    <section class="ui-card ui-rota__bar">
        <div class="ui-rota__week">
            <a class="ui-icon-btn" href="{{ route('stores.rota.index') }}?store_id={{ $rid }}&week={{ $prevWeek }}" aria-label="{{ ___('Previous') }}" title="{{ ___('Previous') }}"><x-ui.icon name="chevron" class="ui-rota__prev" /></a>
            <strong class="ui-rota__range">{{ $weekStart->isoFormat('D MMM') }} – {{ $weekStart->copy()->addDays(6)->isoFormat('D MMM YYYY') }}</strong>
            <a class="ui-icon-btn" href="{{ route('stores.rota.index') }}?store_id={{ $rid }}&week={{ $nextWeek }}" aria-label="{{ ___('Next') }}" title="{{ ___('Next') }}"><x-ui.icon name="chevron" flip /></a>
        </div>
        <div class="ui-rota__actions">
            <x-ui.button variant="secondary" size="sm" icon="wallet" :href="route('stores.rota.payroll').'?store_id='.$rid">{{ ___('Payroll') }}</x-ui.button>
            <form method="post" action="{{ route('stores.rota.publish') }}?store_id={{ $rid }}">
                @csrf
                <input type="hidden" name="week" value="{{ $weekStart->toDateString() }}">
                <x-ui.button type="submit" size="sm" icon="check" data-ui-busy>{{ ___('Publish week') }}</x-ui.button>
            </form>
        </div>
    </section>

    @if($staff->isEmpty())
        <div class="ui-card"><x-ui.empty :title="___('No staff yet — add staff first.')" icon="users" /></div>
    @else
        {{-- Desktop / tablet: staff × day grid, scrolling inside its own card if the week is wider than the screen. --}}
        <section class="ui-card ui-card--flush ui-rota__grid-card" aria-label="{{ ___('Rota') }}">
            <div class="ui-rota__scroll">
                <table class="ui-rota__grid">
                    <thead><tr>
                        <th scope="col">{{ ___('Staff') }}</th>
                        @foreach($days as $d)<th scope="col" @class(['is-today' => $d->isToday()])>{{ $d->isoFormat('ddd D') }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                    @foreach($staff as $member)
                        <tr>
                            <th scope="row">
                                <span class="ui-rota__who">{{ $member->name }}</span>
                                <span class="ui-rota__role">{{ \Juwri\Core\Support\Views\OwnerOpsViewHelpers::teamRoleLabel($member->role) }}</span>
                            </th>
                            @foreach($days as $d)
                                <td @class(['is-today' => $d->isToday()])>
                                    @foreach(\Juwri\Core\Support\Views\OwnerOpsViewHelpers::dayShiftsForMember($shifts, $member->id, $d) as $s)
                                        <div @class(['ui-rota__shift', 'is-published' => $s->published])>
                                            <span class="ui-ltr">{{ $s->starts_at->format('H:i') }}–{{ $s->ends_at->format('H:i') }}</span>
                                            <form method="post" action="{{ route('stores.rota.destroy', $s->id) }}?store_id={{ $rid }}">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="ui-rota__remove" title="{{ ___('Remove') }}" aria-label="{{ ___('Remove') }}"><x-ui.icon name="x" size="14" /></button>
                                            </form>
                                        </div>
                                    @endforeach
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Phones: the same week as a day-by-day agenda (no sideways scrolling). --}}
        <div class="ui-rota__agenda">
            @foreach($days as $d)
                @php $today = \Juwri\Core\Support\Views\OwnerOpsViewHelpers::todayAgendaPairs($staff, $shifts, $d); @endphp
                <section @class(['ui-card', 'ui-card--flush', 'ui-rota__day', 'is-today' => $d->isToday()])>
                    <h2 class="ui-rota__day-title">
                        <span>{{ $d->isoFormat('dddd D MMM') }}</span>
                        <x-ui.tag>{{ $today->count() }}</x-ui.tag>
                    </h2>
                    @foreach($today as [$member, $s])
                        <div class="ui-rota__slot">
                            <span @class(['ui-rota__dot', 'is-published' => $s->published]) aria-hidden="true"></span>
                            <span class="ui-rota__slot-body">
                                <span class="ui-rota__who">{{ $member->name }}</span>
                                <span class="ui-rota__role"><span class="ui-ltr">{{ $s->starts_at->format('H:i') }}–{{ $s->ends_at->format('H:i') }}</span> · {{ \Juwri\Core\Support\Views\OwnerOpsViewHelpers::teamRoleLabel($member->role) }}</span>
                            </span>
                            <form method="post" action="{{ route('stores.rota.destroy', $s->id) }}?store_id={{ $rid }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="ui-icon-btn ui-menu-item__delete" title="{{ ___('Remove') }}" aria-label="{{ ___('Remove') }}"><x-ui.icon name="x" /></button>
                            </form>
                        </div>
                    @endforeach
                </section>
            @endforeach
        </div>
    @endif

    <x-ui.card :title="___('Add shift')">
        <form method="post" action="{{ route('stores.rota.store') }}?store_id={{ $rid }}" class="ui-rota__form">
            @csrf
            <div class="ui-rota__field ui-rota__field--grow">
                <label class="ui-label" for="rs-staff">{{ ___('Staff') }}</label>
                <select id="rs-staff" name="staff_id" class="ui-input" required>
                    @foreach($staff as $member)
                        <option value="{{ $member->id }}">{{ $member->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-rota__field ui-rota__field--grow">
                <label class="ui-label" for="rs-start">{{ ___('Starts') }}</label>
                <input id="rs-start" type="datetime-local" name="starts_at" class="ui-input" required>
            </div>
            <div class="ui-rota__field ui-rota__field--grow">
                <label class="ui-label" for="rs-end">{{ ___('Ends') }}</label>
                <input id="rs-end" type="datetime-local" name="ends_at" class="ui-input" required>
            </div>
            <div class="ui-rota__field">
                <label class="ui-label" for="rs-role">{{ ___('Role') }}</label>
                <input id="rs-role" name="role" class="ui-input" placeholder="{{ ___('optional') }}">
            </div>
            <x-ui.button type="submit" icon="plus">{{ ___('Add') }}</x-ui.button>
        </form>
    </x-ui.card>
</div>
@endsection
