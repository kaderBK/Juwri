{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- broadsheet family — newspaper: masthead, dateline, headline links, dispatch story, stats strip (The Daily Crumb). --}}
@extends('bio.layout')
@section('page')
    @php
        $p = is_array($profile ?? null) ? $profile : [];
        $dateline = trim((string) ($bioSettings['dateline'] ?? ''));
    @endphp
    <div class="bio-in">
        @if ($dateline !== '' || ! empty($p['sub']))
            <div class="bio-dateline"><span>{{ $p['sub'] ?? '' }}</span><span>{{ $dateline ?: now()->translatedFormat('D, d M Y') }}</span></div>
        @endif
        @if (! empty($p['name']))<h1 class="bio-masthead">{{ $p['name'] }}</h1>@endif
        @if (! empty($p['tagline']))<p class="bio-motto">{{ $p['tagline'] }}</p>@endif
        @if (! empty($p['status']))<div class="bio-dbl">{{ $p['status'] }}</div>@endif
        @include('bio._blocks')
        @include('bio._pow')
    </div>
@endsection
