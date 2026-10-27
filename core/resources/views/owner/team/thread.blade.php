{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', $channel->name)
@section('page_title', $channel->name)
@section('page_sub', ___('Channel'))

@section('content')

@if(session('success'))<div class="ui-note" data-tone="success">{{ session('success') }}</div>@endif

<div class="ui-card"><div class="ui-card__body" style="max-height:440px;overflow:auto">
    @forelse($messages as $m)
        <div>
            <div class="ui-muted">{{ $m->from_owner ? ___('You') : ('#'.$m->sender_staff_id) }} · {{ $m->created_at->diffForHumans() }}</div>
            <div>{{ $m->body }}</div>
        </div>
    @empty
        <p class="ui-text-2">{{ ___('No messages yet.') }}</p>
    @endforelse
</div></div>

@if($channel->type !== 'dm' || true)
<div class="ui-card"><div class="ui-card__body">
    <form method="post" action="{{ route('stores.team.post', $channel->id) }}?store_id={{ $rid }}">
        @csrf
        <div class="ui-input-group">
            <input name="body" class="ui-input" placeholder="{{ ___('Write a message…') }}" maxlength="4000" required>
            <button class="ui-btn">{{ ___('Send') }}</button>
        </div>
    </form>
</div></div>
@endif

@endsection
