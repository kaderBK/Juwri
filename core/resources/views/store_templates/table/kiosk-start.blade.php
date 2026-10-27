{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- table: the kiosk start screen of `{slug}/kiosk` (P3-06): language buttons, the store, "Tap to start" (or "Ordering is paused"), the hidden idle and
     staff PIN dialogs. Reads only $vm and $start (KitStorefront::kioskStart). --}}
@extends('store_templates._kit.layout', ['theme' => 'table', 'bodyClass' => 'p-kstart p-kiosk'])
@section('content')
<main class="c-kstartmain" id="main">@include('store_templates._kit.kiosk.start', ['heading' => 'h1'])</main>
@endsection
@section('dialogs')
@include('store_templates._kit.kiosk.pin', ['live' => true])
@endsection
