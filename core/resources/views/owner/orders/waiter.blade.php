{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')
@section('title', ___('Waiter mode'))
@section('page_title', ___('Waiter mode'))
@section('page_sub', ___('Tableside service runs in the staff app.'))
@section('page_actions')
    @include('owner.partials.context-store-select', ['showBranch' => false])
@endsection
@section('content')
<div class="ui-scope ui-waiter">
    <div class="ui-banner ui-waiter__intro">
        <span class="ui-banner__icon"><x-ui.icon name="users" size="18" /></span>
        <div class="ui-banner__body">{{ ___('Waiters run tableside service (floor plan, tabs, courses, split-by-seat) from the') }} <a href="{{ url('/staff-app') }}">{{ ___('staff app') }}</a>.</div>
    </div>

    <section class="ui-card ui-card--flush">
        <div class="ui-card__head"><h2 class="ui-card__title">{{ ___('Recent waiter calls') }}</h2></div>
        @if($calls->isEmpty())
            <x-ui.empty icon="bell" :title="___('No calls.')" />
        @else
            <table class="ui-table">
                <thead><tr><th>{{ ___('Table') }}</th><th>{{ ___('Type') }}</th><th>{{ ___('Seen') }}</th></tr></thead>
                <tbody>
                @foreach($calls as $c)
                    <tr>
                        <td data-primary><span class="ui-ltr">{{ $c->table_no }}</span></td>
                        <td data-label="{{ ___('Type') }}">{{ ___(ucfirst(str_replace('_',' ',$c->type))) }}</td>
                        <td data-label="{{ ___('Seen') }}">@if($c->seen)<x-ui.pill tone="success">✓</x-ui.pill>@else<x-ui.pill>—</x-ui.pill>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>
</div>
@endsection
