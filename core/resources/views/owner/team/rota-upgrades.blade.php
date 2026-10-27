{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Rota pool'))
@section('page_title', ___('Open shifts & swaps'))
@section('page_sub', ___('Approve claims and swaps.'))

@section('page_actions')
    <form method="get" action="{{ route('stores.rotaplus.index') }}">
        <select name="store_id" class="ui-input ui-input--sm" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($rid == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')
@php
    $statusTone = ['pending' => 'warning', 'requested' => 'warning', 'accepted' => 'accent', 'approved' => 'success', 'rejected' => 'danger'];
    $statusLabel = ['pending' => ___('Pending'), 'requested' => ___('Requested'), 'accepted' => ___('Accepted'), 'approved' => ___('Approved'), 'rejected' => ___('Rejected')];
@endphp

<div class="ui-scope ui-rota">
    {{-- session('success') already surfaces as a toast via partials.flash. --}}
    @if(isset($errors) && $errors->any())
        <x-ui.banner tone="danger">{{ $errors->first() }}</x-ui.banner>
    @endif

    <div class="ui-rota__pool">
        <x-ui.card :title="___('Pending claims')" variant="flush">
            <x-slot:actions><x-ui.tag :tone="$pendingClaims->isNotEmpty() ? 'warning' : 'neutral'">{{ $pendingClaims->count() }}</x-ui.tag></x-slot:actions>
            @forelse($pendingClaims as $shift)
                <x-ui.row icon="users" tone="warning" :title="optional($shift->starts_at)?->isoFormat('ddd D MMM HH:mm')" :sub="___('claimed by').' #'.$shift->claimed_by">
                    <x-slot:trail>
                        <form method="post" action="{{ route('stores.rotaplus.approveClaim', $shift->id) }}?store_id={{ $rid }}">
                            @csrf
                            <x-ui.button type="submit" variant="success" size="sm" icon="check">{{ ___('Approve') }}</x-ui.button>
                        </form>
                    </x-slot:trail>
                </x-ui.row>
            @empty
                <x-ui.empty :title="___('No pending claims.')" icon="users" />
            @endforelse
        </x-ui.card>

        <x-ui.card :title="___('Open shifts')" variant="flush">
            <x-slot:actions><x-ui.tag>{{ $openShifts->count() }}</x-ui.tag></x-slot:actions>
            @forelse($openShifts as $shift)
                <x-ui.row icon="calendar" tone="neutral" :title="optional($shift->starts_at)?->isoFormat('ddd D MMM HH:mm')">
                    @if($shift->claim_status)
                        <x-slot:trail><x-ui.pill :tone="$statusTone[$shift->claim_status] ?? 'neutral'">{{ $statusLabel[$shift->claim_status] ?? $shift->claim_status }}</x-ui.pill></x-slot:trail>
                    @endif
                </x-ui.row>
            @empty
                <x-ui.empty :title="___('No open shifts.')" icon="calendar" />
            @endforelse
        </x-ui.card>
    </div>

    <x-ui.card :title="___('Swap requests')" variant="flush">
        <x-slot:actions><x-ui.tag>{{ $swaps->count() }}</x-ui.tag></x-slot:actions>
        @forelse($swaps as $swap)
            @php
                // Wrapped LTR so the "#from → #to" arrow keeps its direction inside RTL text.
                $swapSub = new \Illuminate\Support\HtmlString('<span class="ui-ltr">'.e('#'.$swap->requester_staff_id.' → #'.($swap->target_staff_id ?? '?')).'</span>');
            @endphp
            <x-ui.row icon="refresh" :title="___('Shift').' #'.$swap->shift_id" :sub="$swapSub">
                <x-slot:trail>
                    <span class="ui-rota__trail">
                        <x-ui.pill :tone="$statusTone[$swap->status] ?? 'neutral'">{{ $statusLabel[$swap->status] ?? $swap->status }}</x-ui.pill>
                        @if($swap->status === 'accepted')
                            <form method="post" action="{{ route('stores.rotaplus.approveSwap', $swap->id) }}?store_id={{ $rid }}">
                                @csrf
                                <x-ui.button type="submit" variant="success" size="sm" icon="check">{{ ___('Approve') }}</x-ui.button>
                            </form>
                        @endif
                    </span>
                </x-slot:trail>
            </x-ui.row>
        @empty
            <x-ui.empty :title="___('No swap requests.')" icon="refresh" />
        @endforelse
    </x-ui.card>
</div>
@endsection
