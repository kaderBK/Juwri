{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')
@section('title', ___('WhatsApp Inbox'))
@section('page_title', ___('WhatsApp Inbox'))
@section('page_sub', $store->title)
@section('content')

<div class="ui-scope ui-wa ui-wainbox">

<nav class="ui-wa__tools" aria-label="{{ ___('WhatsApp') }}">
    <x-ui.button :href="route('stores.whatsappSettings', $store->id)" variant="secondary" size="sm" icon="settings">{{ ___('Settings') }}</x-ui.button>
    <x-ui.button :href="route('stores.whatsappCampaign', $store->id)" variant="secondary" size="sm" icon="share">{{ ___('Campaign') }}</x-ui.button>
    <x-ui.button :href="route('stores.whatsappTemplates', $store->id)" variant="secondary" size="sm" icon="list">{{ ___('Templates') }}</x-ui.button>
</nav>

@if ($threads->isEmpty())
    <div class="ui-card">
        <x-ui.empty icon="chat" :title="___('No conversations yet.')" :body="___('No inbound messages yet. Customer replies will appear here.')" />
    </div>
@else
    <section class="ui-wainbox__stats" aria-label="{{ ___('Inbox') }}">
        <x-ui.stat :value="number_format($threads->count())" :label="___('Conversations')" icon="chat" />
        <x-ui.stat :value="number_format($threads->sum('unhandled'))" :label="___('New')" icon="bell" tone="success" />
    </section>

    @foreach ($threads as $thread)
        @php
            $__who = $thread['name'] ?: $thread['phone'];
        @endphp
        <article class="ui-card ui-wainbox__thread" @if ($thread['unhandled'] > 0) data-unread @endif>
            <header class="ui-wainbox__head">
                <span class="ui-wainbox__avatar" aria-hidden="true">@if ($thread['name']){{ mb_strtoupper(mb_substr(trim($thread['name']), 0, 1)) }}@else<x-ui.icon name="phone" size="18" />@endif</span>
                <span class="ui-wainbox__who">
                    <strong class="ui-wainbox__name @if (! $thread['name']) ui-ltr @endif">{{ $__who }}</strong>
                    @if ($thread['name'])<span class="ui-wainbox__phone ui-ltr">{{ $thread['phone'] }}</span>@endif
                </span>
                @if ($thread['unhandled'] > 0)
                    <x-ui.pill tone="success">{{ $thread['unhandled'] }} {{ ___('new') }}</x-ui.pill>
                @endif
            </header>

            <ol class="ui-wainbox__msgs">
                @foreach ($thread['messages'] as $msg)
                    <li class="ui-wainbox__msg">
                        <p class="ui-wainbox__bubble" dir="auto">{{ $msg->body }}</p>
                        <time class="ui-wainbox__time">{{ optional($msg->received_at)->diffForHumans() }}</time>
                    </li>
                @endforeach
            </ol>

            <form method="post" action="{{ route('stores.whatsappInbox.reply', $store->id) }}" class="ui-wainbox__reply">
                @csrf
                <input type="hidden" name="to" value="{{ $thread['phone'] }}">
                <label class="ui-visually-hidden" for="wa-reply-{{ $loop->index }}">{{ ___('Reply') }}</label>
                <input type="text" id="wa-reply-{{ $loop->index }}" name="body" class="ui-input ui-wainbox__input" placeholder="{{ ___('Type a reply...') }}" required dir="auto">
                <x-ui.button type="submit" icon="chat" data-ui-busy>{{ ___('Send') }}</x-ui.button>
            </form>
        </article>
    @endforeach
@endif
</div>

@endsection
