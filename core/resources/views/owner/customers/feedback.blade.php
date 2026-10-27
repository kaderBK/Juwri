{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Feedback'))
@section('page_title', ___('Customer feedback'))
@section('page_sub', ___('Ratings and responses.'))

@section('page_actions')
    <form method="get" action="{{ route('stores.feedback.index') }}">
        <select name="store_id" class="ui-input ui-input--sm ui-mods__store" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($rid == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')

<div class="ui-scope ui-fb">

@if(session('success'))<x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>@endif

<nav class="ui-chips ui-fb__filters" aria-label="{{ ___('Customer feedback') }}">
    <a class="ui-chip" href="{{ route('stores.feedback.index') }}?store_id={{ $rid }}" @if(!$rating) aria-current="true" @endif>{{ ___('All') }}</a>
    @for($i=5;$i>=1;$i--)
        <a class="ui-chip" href="{{ route('stores.feedback.index') }}?store_id={{ $rid }}&rating={{ $i }}" @if((int)$rating === $i) aria-current="true" @endif><span class="ui-ltr">{{ $i }}</span><x-ui.icon name="star" size="14" /></a>
    @endfor
</nav>

@forelse($items as $fb)
    <article class="ui-card ui-fb__item" data-status="{{ $fb->status }}">
        <header class="ui-fb__head">
            <span class="ui-fb__stars" role="img" aria-label="{{ (int) $fb->rating }}/5">
                @for ($__s = 1; $__s <= 5; $__s++)<x-ui.icon name="star" size="16" :class="$__s <= (int) $fb->rating ? 'is-on' : ''" />@endfor
            </span>
            <span class="ui-fb__meta">{{ optional($fb->submitted_at)->diffForHumans() }} · {{ ___('Order') }} <span class="ui-ltr">#{{ $fb->order_id }}</span></span>
        </header>
        @if($fb->comment)<p class="ui-fb__comment" dir="auto">{{ $fb->comment }}</p>@endif
        @if($fb->owner_reply)
            <div class="ui-fb__reply-done">
                <span class="ui-overline">{{ ___('You replied') }}</span>
                <p dir="auto">{{ $fb->owner_reply }}</p>
            </div>
        @endif
        @if($fb->status !== 'closed')
        <div class="ui-fb__actions">
            <form method="post" action="{{ route('stores.feedback.respond', $fb->id) }}?store_id={{ $rid }}" class="ui-fb__reply">
                @csrf
                <input name="reply" class="ui-input" placeholder="{{ ___('Reply…') }}" aria-label="{{ ___('Reply…') }}" dir="auto">
                <x-ui.button type="submit" icon="chat" data-ui-busy>{{ ___('Send') }}</x-ui.button>
            </form>
            <form method="post" action="{{ route('stores.feedback.close', $fb->id) }}?store_id={{ $rid }}">@csrf<x-ui.button type="submit" variant="secondary" size="sm" icon="check">{{ ___('Close') }}</x-ui.button></form>
        </div>
        @else
            <div><x-ui.pill>{{ ___('Closed') }}</x-ui.pill></div>
        @endif
    </article>
@empty
    <div class="ui-card">
        <x-ui.empty icon="star" :title="___('No feedback yet.')" />
    </div>
@endforelse

</div>

@endsection
