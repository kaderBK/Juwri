{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- story_card family — conversational headline + one swipe card + tiny outline links (Milkshake). --}}
@extends('bio.layout')
@section('page')
    @php $p = is_array($profile ?? null) ? $profile : []; @endphp
    <div class="bio-in">
        @if (! empty($p['name']) || ! empty($p['sub']))<p class="bio-story-kicker">{{ $p['sub'] ?: $p['name'] }}</p>@endif
        @if (! empty($p['tagline']))<h1 class="bio-story-head">{{ $p['tagline'] }}</h1>@endif
        @include('bio._blocks')
        @include('bio._pow')
    </div>
@endsection
