{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- menu_card family — printed-menu editorial. Framed card, menu-line links, reservation + hours. --}}
@extends('bio.layout')
@section('page')
    <div class="bio-in">
        <div class="bio-frame">
            @include('bio._profile')
            @include('bio._blocks')
        </div>
        @include('bio._pow')
    </div>
@endsection
