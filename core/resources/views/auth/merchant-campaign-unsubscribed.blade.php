{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('auth.layout')

{{-- Confirmation for the opt-out link in a merchant campaign email. Uses the
     auth layout on purpose — it is opened from an inbox, usually on a device
     with no session, so the dashboard shell would be the wrong frame (and
     would bounce them to a login they did not ask for). --}}

@section('title', isset($confirmUrl) ? ___('Unsubscribe from campaign emails?') : ___('You are unsubscribed'))
@section('auth_heading', isset($confirmUrl) ? ___('Unsubscribe from campaign emails?') : ___('You are unsubscribed'))
@section('auth_sub', isset($confirmUrl) ? ___('Confirm that you want to stop receiving campaign emails from us.') : ___('You will not get campaign emails from us again.'))

@section('content')
<div class="ui-stack" style="text-align: center;">
    @isset($confirmUrl)
        <form method="POST" action="{{ $confirmUrl }}">
            @csrf
            <x-ui.button type="submit" size="lg" block>{{ ___('Yes, unsubscribe me') }}</x-ui.button>
        </form>
    @else
        <div>
            <x-ui.button :href="route('dashboard')" size="lg" block>{{ ___('Go to dashboard') }}</x-ui.button>
        </div>
    @endisset
</div>
@endsection
