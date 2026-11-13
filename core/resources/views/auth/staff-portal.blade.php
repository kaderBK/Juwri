{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('auth.layout')

@section('title', ___('Pick a restaurant') . ' - ' . brand_title())

@section('auth_heading', ___('Where to today?'))
@section('auth_sub', ___('Pick the restaurant you want to manage.'))

@section('content')

@php
    $staff = function_exists('staff_session') ? staff_session() : null;
    $stores = $stores ?? ($staff ? $staff->stores : collect());
@endphp

@if ($stores->count())
    <ul class="ui-auth__picker">
        @foreach ($stores as $r)
            <li>
                <a class="ui-auth__pick" href="{{ route('dashboard') }}?store_id={{ $r->id }}">
                    <span class="ui-auth__pick-avatar" aria-hidden="true">{{ strtoupper(substr($r->title ?? '?', 0, 1)) }}</span>
                    <span class="ui-auth__pick-text">
                        <strong>{{ $r->title }}</strong>
                        @if (! empty($r->address))<span>{{ Str::limit($r->address, 40) }}</span>@endif
                    </span>
                    <x-ui.icon name="chevron" size="18" flip />
                </a>
            </li>
        @endforeach
    </ul>
@else
    <x-ui.empty icon="store" :title="___('No restaurants assigned')" :body="___('Ask your owner to grant you access.')" />
@endif

@endsection

@section('auth_footer')
    @if (Route::has('staff.logout'))
        <form method="POST" action="{{ route('staff.logout') }}">
            @csrf
            <button type="submit" class="ui-auth__linkbtn">{{ ___('Log out') }}</button>
        </form>
    @endif
@endsection
