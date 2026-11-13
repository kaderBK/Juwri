{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- countdown family — event-drop with a static countdown card + waitlist CTA + quiet links. --}}
@extends('bio.layout')
@section('page')
    @php
        $p = is_array($profile ?? null) ? $profile : [];
        $cd = is_array($bioSettings['countdown'] ?? null) ? $bioSettings['countdown'] : [];
        $digits = null;
        $target = trim((string) ($cd['target'] ?? ''));
        if ($target !== '') {
            try {
                $t = \Illuminate\Support\Carbon::parse($target);
                if ($t->isFuture()) {
                    $mins = (int) round(abs(now()->diffInMinutes($t)));
                    $digits = ['days' => intdiv($mins, 1440), 'hrs' => intdiv($mins % 1440, 60), 'min' => $mins % 60];
                }
            } catch (\Throwable $e) {
                $digits = null;
            }
        }
        $cdLabel = trim((string) ($cd['label'] ?? ''));
        $cdTitle = trim((string) ($cd['title'] ?? ''));
        $ctaLabel = trim((string) ($cd['cta_label'] ?? '')) ?: ___('Join the Waitlist');
        $ctaUrl = trim((string) ($cd['cta_url'] ?? ''));
    @endphp
    <div class="bio-in">
        @include('bio._profile')
        @if ($digits !== null || $cdTitle !== '')
            <div class="bio-countdown">
                @if ($cdLabel !== '')<em>{{ $cdLabel }}</em>@endif
                @if ($cdTitle !== '')<b>{{ $cdTitle }}</b>@endif
                @if ($digits !== null)
                    <div class="bio-countdown__digits">
                        <div><b>{{ str_pad((string) $digits['days'], 2, '0', STR_PAD_LEFT) }}</b><span>{{ ___('days') }}</span></div>
                        <div><b>{{ str_pad((string) $digits['hrs'], 2, '0', STR_PAD_LEFT) }}</b><span>{{ ___('hrs') }}</span></div>
                        <div><b>{{ str_pad((string) $digits['min'], 2, '0', STR_PAD_LEFT) }}</b><span>{{ ___('min') }}</span></div>
                    </div>
                @endif
                <a class="bio-countdown__cta" href="{{ $ctaUrl !== '' ? $ctaUrl : '#' }}" rel="noopener">{{ $ctaLabel }}</a>
            </div>
        @endif
        @include('bio._blocks')
        @include('bio._pow')
    </div>
@endsection
