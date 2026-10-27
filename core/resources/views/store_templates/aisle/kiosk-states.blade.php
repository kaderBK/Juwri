{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle: the kiosk screens board (start, idle warning, done, staff exit, ordering paused, printer offline, offline). Reads $vm, $start and $orderData. --}}
@extends('store_templates._kit.layout', ['theme' => 'aisle', 'bodyClass' => 'p-states p-kboard'])
@section('content')
@include('store_templates._kit.kiosk.board')
@endsection
