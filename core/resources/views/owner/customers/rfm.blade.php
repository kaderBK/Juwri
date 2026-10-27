{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('RFM'))
@section('page_title', ___('RFM segments'))
@section('page_sub', ___('Recency · Frequency · Monetary'))

@section('page_actions')
    <form method="get" action="{{ route('crm.rfm') }}">
        <select name="store_id" class="ui-input ui-input--sm ui-mods__store" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($rid == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')

<div class="ui-scope ui-rfm">

@if(session('success'))<x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>@endif

{{-- WI-A3: an unentitled owner sees this screen with their OWN segment counts,
     blurred. The controller has already dropped the per-guest table — every row
     of it is a name or a phone number — so what is blurred here is genuinely
     safe to have sent. --}}
@php
    $__preview = $preview ?? false;
    $__segTone = ['champions' => 'success', 'loyal' => 'accent', 'new' => 'accent', 'at_risk' => 'warning', 'lapsed' => 'danger', 'others' => 'neutral'];
    $__segIcon = ['champions' => 'star', 'loyal' => 'users', 'new' => 'sparkle', 'at_risk' => 'alert', 'lapsed' => 'clock', 'others' => 'dots'];
@endphp
<x-spotlight.preview :key="$previewKey ?? 'crm_suite'" :active="$__preview">

<div class="ui-rfm__body">
@unless ($__preview)
<div class="ui-rfm__tools">
    <form method="post" action="{{ route('crm.retag') }}?store_id={{ $rid }}">@csrf<x-ui.button type="submit" variant="secondary" size="sm" icon="refresh" data-ui-busy>{{ ___('Recompute auto-tags') }}</x-ui.button></form>
</div>
@endunless

<section class="ui-rfm__segs" aria-label="{{ ___('RFM segments') }}">
    @foreach(['champions','loyal','new','at_risk','lapsed','others'] as $seg)
        <div class="ui-rfm__seg" data-tone="{{ $__segTone[$seg] }}">
            <span class="ui-rfm__seg-icon"><x-ui.icon :name="$__segIcon[$seg]" size="18" /></span>
            <span class="ui-rfm__seg-value ui-ltr">{{ $segments[$seg] ?? 0 }}</span>
            <span class="ui-rfm__seg-label">{{ ___(ucfirst(str_replace('_',' ',$seg))) }}</span>
            {{-- K6: one-click bridge from an RFM segment to a reusable Audience. --}}
            @if(!($__preview ?? false) && ($segments[$seg] ?? 0) > 0 && Route::has('crm.audiences.store'))
                <form method="POST" action="{{ route('crm.audiences.store') }}" class="ui-rfm__seg-form">
                    @csrf
                    <input type="hidden" name="name" value="{{ ___(ucfirst(str_replace('_',' ',$seg))) }} ({{ ___('RFM') }})">
                    <input type="hidden" name="match" value="all">
                    <input type="hidden" name="conditions[0][field]" value="segment">
                    <input type="hidden" name="conditions[0][op]" value="eq">
                    <input type="hidden" name="conditions[0][value]" value="{{ $seg }}">
                    <button type="submit" class="ui-btn ui-btn--secondary ui-btn--sm ui-btn--block">{{ ___('Create audience') }}</button>
                </form>
            @endif
        </div>
    @endforeach
</section>

@if ($__preview)
    {{-- Not the real table: it was dropped server-side. Saying "no customers"
         to an owner who has plenty would be a lie told by an empty loop. --}}
    <div class="ui-card">
        <x-ui.empty icon="users" :title="___('Guest-level scores are part of this feature.')" />
    </div>
@else
<section class="ui-card ui-card--flush">
    @if (collect($scores)->isEmpty())
        <x-ui.empty icon="users" :title="___('No customers with orders yet.')" />
    @else
    <table class="ui-table">
        <thead><tr><th>{{ ___('Guest') }}</th><th class="ui-table__num">R</th><th class="ui-table__num">F</th><th class="ui-table__num">M</th><th>{{ ___('Segment') }}</th></tr></thead>
        <tbody>
        @foreach($scores as $s)
            <tr>
                <td data-primary><a href="{{ route('crm.profile', $s['customer']->id) }}" class="ui-rfm__guest">{{ $s['customer']->name ?: $s['customer']->phone }}</a></td>
                <td class="ui-table__num" data-label="R"><span class="ui-ltr">{{ $s['r'] }}</span></td>
                <td class="ui-table__num" data-label="F"><span class="ui-ltr">{{ $s['f'] }}</span></td>
                <td class="ui-table__num" data-label="M"><span class="ui-ltr">{{ $s['m'] }}</span></td>
                <td data-label="{{ ___('Segment') }}"><x-ui.tag :tone="$__segTone[$s['segment']] ?? 'neutral'">{{ ___(ucfirst(str_replace('_',' ',$s['segment']))) }}</x-ui.tag></td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif
</section>
@endif
</div>

</x-spotlight.preview>
</div>

@endsection
