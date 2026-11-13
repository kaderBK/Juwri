{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- store_rows family — creator/storefront rows (Stan / Throne). Left-aligned header + product rows. --}}
@extends('bio.layout')
@section('page')
    <div class="bio-in">
        @include('bio._profile_row')
        @include('bio._blocks')
        @include('bio._pow')
    </div>
@endsection
