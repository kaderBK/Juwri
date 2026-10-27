{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- social_grid family — big avatar, a wall of branded social tiles, then a couple of buttons (LinkMe). --}}
@extends('bio.layout')
@section('page')
    <div class="bio-in">
        @include('bio._profile')
        @include('bio._blocks')
        @include('bio._pow')
    </div>
@endsection
