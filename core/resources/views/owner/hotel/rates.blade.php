{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Rates'))
@section('page_title', ___('Rate calendar'))
@section('page_sub', $store->title.' - '.___('Per-night price overrides. Blank cells fall back to the base rate.'))

@php
    use Illuminate\Support\Carbon;

    // Lane A (HotelPmsSetupController) supplies these; defaults keep the blade
    // renderable pre-merge / defensive.
    //   $roomTypes / $ratePlans  selector option lists
    //   $selectedTypeId / $selectedPlanId  current (type, plan) being edited
    //   $selectedType  object w/ base_rate (placeholder / fallback)
    //   $month  'Y-m'   $days  list<Y-m-d> for the month
    //   $rates  map[Y-m-d => price] — the per-date OVERRIDE rows only (null = none)
    $roomTypes      = collect($roomTypes ?? []);
    $ratePlans      = collect($ratePlans ?? []);
    $selectedTypeId = $selectedTypeId ?? request('room_type_id');
    $selectedPlanId = $selectedPlanId ?? request('rate_plan_id');
    $selectedType   = $selectedType ?? $roomTypes->firstWhere('id', (int) $selectedTypeId);
    $currency       = trim($currency ?? '');
    $month          = $month ?? (request('month') ?: now()->format('Y-m'));
    $rates          = $rates ?? [];

    try { $monthStart = Carbon::parse($month.'-01')->startOfMonth(); }
    catch (\Throwable $e) { $monthStart = now()->startOfMonth(); $month = $monthStart->format('Y-m'); }

    $days = $days ?? [];
    if (empty($days)) {
        $cursor = $monthStart->copy();
        $end = $monthStart->copy()->endOfMonth();
        while ($cursor <= $end) { $days[] = $cursor->toDateString(); $cursor->addDay(); }
    }

    $prevMonth = $monthStart->copy()->subMonth()->format('Y-m');
    $nextMonth = $monthStart->copy()->addMonth()->format('Y-m');
    $baseRate  = $selectedType->base_rate ?? null;
    $leadBlanks = (int) $monthStart->dayOfWeek; // 0 = Sunday … 6 = Saturday

    $hasBackend = Route::has('hotel.pms.rates.bulk');
    $indexUrl   = Route::has('hotel.pms.rates') ? route('hotel.pms.rates') : url()->current();

    // Weekday headers (Sun-first, matches Carbon dayOfWeek indexing).
    $weekdays = [];
    $wref = Carbon::parse('2023-01-01'); // a Sunday
    for ($i = 0; $i < 7; $i++) { $weekdays[] = $wref->copy()->addDays($i)->translatedFormat('D'); }
@endphp

@if($hasBackend)
@section('page_actions')
    <x-ui.button :href="$indexUrl.'?room_type_id='.$selectedTypeId.'&rate_plan_id='.$selectedPlanId.'&month='.$prevMonth" variant="ghost" icon="back">{{ ___('Previous') }}</x-ui.button>
    <x-ui.button :href="$indexUrl.'?room_type_id='.$selectedTypeId.'&rate_plan_id='.$selectedPlanId.'&month='.$nextMonth" variant="ghost" icon="chevron">{{ ___('Next') }}</x-ui.button>
@endsection
@endif

@section('content')
@unless($hasBackend)
    <x-ui.empty :title="___('PMS backend pending')" :body="___('The rate calendar appears here once the booking engine is enabled.')" />
@else
<div class="ui-stack">
    {{-- Type / plan / month selector (plain GET — CSP-clean, server-driven). --}}
    <div class="ui-card">
        <div class="ui-card__body">
            <form method="get" action="{{ $indexUrl }}" class="ui-form-grid">
                <div>
                    <label class="ui-label ui-small">{{ ___('Room type') }}</label>
                    <select name="room_type_id" class="ui-input ui-input--sm">
                        <option value="">{{ ___('Select a type') }}</option>
                        @foreach($roomTypes as $t)
                            <option value="{{ $t->id }}" @selected((int) $selectedTypeId === (int) $t->id)>{{ $t->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="ui-label ui-small">{{ ___('Rate plan') }}</label>
                    <select name="rate_plan_id" class="ui-input ui-input--sm">
                        <option value="">{{ ___('Base (no plan)') }}</option>
                        @foreach($ratePlans as $pl)
                            <option value="{{ $pl->id }}" @selected((int) $selectedPlanId === (int) $pl->id)>{{ $pl->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="ui-label ui-small">{{ ___('Month') }}</label>
                    <input type="month" name="month" value="{{ $month }}" class="ui-input ui-input--sm">
                </div>
                <div>
                    <x-ui.button type="submit" variant="secondary" size="sm" icon="calendar" class="ui-w-full">{{ ___('Show') }}</x-ui.button>
                </div>
            </form>
        </div>
    </div>

    @if(!$selectedType)
        <x-ui.empty :title="___('Pick a room type')" :body="___('Choose a room type (and optionally a plan) above to edit its nightly prices for the month.')" />
    @else
        <form action="{{ route('hotel.pms.rates.bulk') }}" method="post">
            @csrf
            {{-- SPEC §5 rates-bulk contract: room_type_id, rate_plan_id (nullable), rates map. --}}
            <input type="hidden" name="room_type_id" value="{{ $selectedTypeId }}">
            <input type="hidden" name="rate_plan_id" value="{{ $selectedPlanId }}">
            <input type="hidden" name="month" value="{{ $month }}">

            <div class="ui-card">
                <div class="ui-cluster ui-cluster--between ui-card__head">
                    <h3 class="ui-section-title">{{ $selectedType->name }} · {{ $monthStart->translatedFormat('F Y') }}</h3>
                    <span class="ui-muted">{{ ___('Base rate') }}: <strong>{{ $currency !== '' ? $currency.' ' : '' }}{{ number_format((float) ($baseRate ?? 0), 2) }}</strong></span>
                </div>
                <div class="ui-card__body">
                    <p class="ui-muted">{{ ___('Enter a price to override this night. Clear a cell and save to remove the override — the base rate applies again.') }}</p>

                    {{-- 7-column month grid. Leading blanks align the 1st to its weekday. --}}
                    <div style="display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:.35rem;">
                        @foreach($weekdays as $wd)
                            <div class="ui-muted ui-center ui-strong">{{ $wd }}</div>
                        @endforeach
                        @for($i = 0; $i < $leadBlanks; $i++)
                            <div></div>
                        @endfor
                        @foreach($days as $d)
                            @php $dc = Carbon::parse($d); @endphp
                            <div class="ui-bordered {{ $dc->isWeekend() ? 'ui-bg-inset' : '' }}">
                                <div class="ui-muted ui-center">{{ $dc->format('j') }}</div>
                                <input type="number" name="rates[{{ $d }}]" value="{{ isset($rates[$d]) && $rates[$d] !== null ? $rates[$d] : '' }}"
                                       step="0.01" min="0" class="ui-input ui-input--sm ui-center"
                                       placeholder="{{ $baseRate !== null ? number_format((float) $baseRate, 2, '.', '') : '' }}"
                                       aria-label="{{ $dc->translatedFormat('D d M') }}">
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="ui-cluster ui-cluster--end ui-card__foot">
                    <x-ui.button type="submit" icon="check">{{ ___('Save rates') }}</x-ui.button>
                </div>
            </div>
        </form>
    @endif
</div>
@endunless
@endsection
