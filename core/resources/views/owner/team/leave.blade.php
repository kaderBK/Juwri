{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Leave'))
@section('page_title', ___('Leave & time-off'))
@section('page_sub', ___('Requests, approvals and team calendar.'))

@section('page_actions')
    <form method="get" action="{{ route('stores.leave.index') }}">
        <select name="store_id" class="ui-input ui-input--sm" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($rid == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')
<div class="ui-scope ui-hr">
    {{-- session('success') already surfaces as a toast via partials.flash. --}}
    @if(isset($errors) && $errors->any())
        <x-ui.banner tone="danger">{{ $errors->first() }}</x-ui.banner>
    @endif

    <div class="ui-hr__grid">
        <div class="ui-hr__main">
            <x-ui.card :title="___('Pending approvals')" variant="flush">
                <x-slot:actions><x-ui.tag :tone="$pending->isNotEmpty() ? 'warning' : 'neutral'">{{ $pending->count() }}</x-ui.tag></x-slot:actions>
                @if($pending->isEmpty())
                    <x-ui.empty :title="___('No pending requests.')" icon="calendar" />
                @else
                    <table class="ui-table">
                        <thead><tr><th>{{ ___('Staff') }}</th><th>{{ ___('Type') }}</th><th>{{ ___('Dates') }}</th><th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th></tr></thead>
                        <tbody>
                        @foreach($pending as $req)
                            <tr>
                                <td data-primary>{{ optional($req->staff)->name }}</td>
                                <td data-label="{{ ___('Type') }}"><x-ui.tag tone="accent">{{ optional($req->type)->name }}</x-ui.tag></td>
                                <td data-label="{{ ___('Dates') }}">
                                    <span class="ui-hr__dates">
                                        <span class="ui-ltr">{{ optional($req->from_date)->toDateString() }} → {{ optional($req->to_date)->toDateString() }}</span>
                                        @if(($conflicts[$req->id] ?? 0) > 0)<x-ui.pill tone="warning">{{ ___('shift conflict') }}</x-ui.pill>@endif
                                    </span>
                                </td>
                                <td class="ui-catalog__row-actions">
                                    <form method="post" action="{{ route('stores.leave.approve', $req->id) }}?store_id={{ $rid }}">@csrf<x-ui.button type="submit" variant="success" size="sm" icon="check">{{ ___('Approve') }}</x-ui.button></form>
                                    <form method="post" action="{{ route('stores.leave.reject', $req->id) }}?store_id={{ $rid }}">@csrf<x-ui.button type="submit" variant="secondary" size="sm" icon="x" class="ui-hr__reject">{{ ___('Reject') }}</x-ui.button></form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </x-ui.card>

            <x-ui.card :title="___('Approved this month')" variant="flush">
                <x-slot:actions><x-ui.tag>{{ $calendar->count() }}</x-ui.tag></x-slot:actions>
                @forelse($calendar as $req)
                    @php
                        $leaveSub = new \Illuminate\Support\HtmlString(e(optional($req->type)->name).' · <span class="ui-ltr">'.e(optional($req->from_date)->toDateString().' → '.optional($req->to_date)->toDateString()).'</span>');
                    @endphp
                    <x-ui.row icon="calendar" tone="success" :title="optional($req->staff)->name" :sub="$leaveSub" />
                @empty
                    <x-ui.empty :title="___('No approved leave this month.')" icon="calendar" />
                @endforelse
            </x-ui.card>
        </div>

        <div class="ui-hr__side">
            <x-ui.card :title="___('Request leave')">
                <form method="post" action="{{ route('stores.leave.request') }}?store_id={{ $rid }}" class="ui-hr__form">
                    @csrf
                    <div class="ui-hr__field ui-hr__field--full">
                        <label class="ui-label" for="lv-staff">{{ ___('Staff') }}</label>
                        <select id="lv-staff" name="staff_id" class="ui-input">@foreach($staff as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select>
                    </div>
                    <div class="ui-hr__field ui-hr__field--full">
                        <label class="ui-label" for="lv-type">{{ ___('Type') }}</label>
                        <select id="lv-type" name="leave_type_id" class="ui-input">@foreach($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select>
                    </div>
                    <div class="ui-hr__field">
                        <label class="ui-label" for="lv-from">{{ ___('From') }}</label>
                        <input id="lv-from" type="date" name="from_date" class="ui-input" required>
                    </div>
                    <div class="ui-hr__field">
                        <label class="ui-label" for="lv-to">{{ ___('To') }}</label>
                        <input id="lv-to" type="date" name="to_date" class="ui-input" required>
                    </div>
                    <label class="ui-check ui-hr__check ui-hr__field--full" for="hd">
                        <input type="checkbox" name="half_day" value="1" id="hd">
                        <span>{{ ___('Half day') }}</span>
                    </label>
                    <x-ui.button type="submit" icon="check" block class="ui-hr__field--full">{{ ___('Submit request') }}</x-ui.button>
                </form>
            </x-ui.card>

            <x-ui.card :title="___('Leave types')" variant="flush">
                <ul class="ui-hr__types">
                    @foreach($types as $t)
                        <li>
                            <span class="ui-hr__type-name">{{ $t->name }}</span>
                            <span class="ui-hr__type-meta">
                                <span class="ui-ltr">{{ $t->annual_allowance_days }}d</span>
                                <x-ui.tag :tone="$t->paid ? 'success' : 'neutral'">{{ $t->paid ? ___('paid') : ___('unpaid') }}</x-ui.tag>
                            </span>
                        </li>
                    @endforeach
                </ul>
                <form method="post" action="{{ route('stores.leave.types.store') }}?store_id={{ $rid }}" class="ui-hr__inline">
                    @csrf
                    <label class="ui-visually-hidden" for="lt-name">{{ ___('Name') }}</label>
                    <input id="lt-name" name="name" class="ui-input" placeholder="{{ ___('Name') }}" required>
                    <label class="ui-visually-hidden" for="lt-days">{{ ___('Days') }}</label>
                    <input id="lt-days" type="number" name="annual_allowance_days" class="ui-input ui-hr__days" placeholder="{{ ___('Days') }}" value="0" inputmode="numeric">
                    <x-ui.button type="submit" variant="secondary" icon="plus">{{ ___('Add') }}</x-ui.button>
                </form>
            </x-ui.card>
        </div>
    </div>
</div>
@endsection
