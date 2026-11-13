{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- table: the kiosk screens board (start, idle warning, done, staff exit, ordering paused, printer offline, offline). Every screen is the real
     component the kiosk uses; here they sit side by side. Reads $vm, $start and $orderData (the done screen's order). --}}
@extends('store_templates._kit.layout', ['theme' => 'table', 'bodyClass' => 'p-states p-kboard'])
@section('content')
@include('store_templates._kit.kiosk.board')
@endsection
