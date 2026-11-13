{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P3-06 the hidden kiosk dialogs every kiosk page carries (the core JS opens them): idle warning and the staff PIN pad --}}
@include('store_templates._kit.kiosk.idle', ['live' => true])
@include('store_templates._kit.kiosk.pin', ['live' => true])
