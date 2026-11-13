{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- photo_hero family — full-width cover media, id overlapping the fade, stacked blocks below (Beacons/Linkr). --}}
@extends('bio.layout')
@section('page')
    <div class="bio-photohero">
        @include('bio._profile_hero')
        <div class="bio-in bio-in--hero">
            @include('bio._blocks')
            @include('bio._pow')
        </div>
    </div>
@endsection
