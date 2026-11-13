{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- bento family — link-in-bio-as-mini-website. Compact header + a tile grid of blocks (Universe). --}}
@extends('bio.layout')
@section('page')
    <div class="bio-in">
        @include('bio._profile_row')
        @include('bio._blocks')
        @include('bio._pow')
    </div>
@endsection
