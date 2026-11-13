{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Owner mobile app (server item S4, Apple 3.1.3): shown instead of any purchase
     page inside the native app. Deliberately NO link, button or URL to where the
     purchase can be made. --}}
@extends('owner.layout')

@section('title', ___('Not available in the app'))

@section('content')
    <div class="ui-scope ui-appblock">
        <x-ui.card>
            <x-ui.empty :title="___('Not available in the app')" :body="___('Plans, add-ons and credits can’t be purchased in the app. Everything you already have stays available here.')" icon="info" />
        </x-ui.card>
    </div>
@endsection
