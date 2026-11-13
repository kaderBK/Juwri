{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- chat family — the page is a conversation: sticky bar, greeting bubbles, links as quick-reply chips, one CTA (Messgme). --}}
@extends('bio.layout')
@section('page')
    @php $p = is_array($profile ?? null) ? $profile : []; @endphp
    <div class="bio-chatbar">
        <div class="bio-avatar bio-avatar--chat">
            @if (! empty($p['avatar']))<img src="{{ $p['avatar'] }}" alt="{{ $p['name'] ?? '' }}">@else<span aria-hidden="true">{{ $p['initial'] ?? '◈' }}</span>@endif
        </div>
        <div class="bio-chatbar__id">
            @if (! empty($p['name']))<b>{{ $p['name'] }}</b>@endif
            @if (! empty($p['status']))<span><i aria-hidden="true"></i>{{ $p['status'] }}</span>@endif
        </div>
    </div>
    <div class="bio-in bio-chat">
        @if (! empty($p['tagline']))<div class="bio-msg">{{ $p['tagline'] }}</div>@endif
        @include('bio._blocks')
        @include('bio._pow')
    </div>
@endsection
