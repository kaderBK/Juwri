{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Team inbox'))
@section('page_title', ___('Team inbox'))
@section('page_sub', ___('Channels, announcements and messages.'))

@section('page_actions')
    <form method="get" action="{{ route('stores.team.index') }}">
        <select name="store_id" class="ui-input ui-input--sm" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($rid == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')
@php
    $channelIcon = ['announcement' => 'bell', 'group' => 'users', 'dm' => 'chat', 'team' => 'users'];
    $channelType = ['team' => ___('Whole team'), 'group' => ___('Group'), 'announcement' => ___('Announcement')];
@endphp

<div class="ui-scope ui-team">
    {{-- session('success') already surfaces as a toast via partials.flash. --}}
    @if(isset($errors) && $errors->any())
        <x-ui.banner tone="danger">{{ $errors->first() }}</x-ui.banner>
    @endif

    <div class="ui-team__grid">
        <x-ui.card :title="___('Channels')" variant="flush">
            <x-slot:actions><x-ui.tag>{{ $channels->count() }}</x-ui.tag></x-slot:actions>
            @forelse($channels as $c)
                <x-ui.row :href="route('stores.team.thread', $c->id).'?store_id='.$rid" :icon="$channelIcon[$c->type] ?? 'chat'" :tone="$c->type === 'announcement' ? 'warning' : 'accent'" :title="$c->name" :sub="$channelType[$c->type] ?? $c->type">
                    @if(($unread[$c->id] ?? 0) > 0)
                        <x-slot:trail>
                            <span class="ui-team__unread ui-ltr">{{ $unread[$c->id] }}</span>
                            <x-ui.icon name="chevron" size="18" class="ui-row__chevron" />
                        </x-slot:trail>
                    @endif
                </x-ui.row>
            @empty
                <x-ui.empty :title="___('No channels yet.')" icon="chat" />
            @endforelse
        </x-ui.card>

        <x-ui.card :title="___('New channel')">
            <form method="post" action="{{ route('stores.team.channels.store') }}?store_id={{ $rid }}" class="ui-team__form">
                @csrf
                <div class="ui-team__field">
                    <label class="ui-label" for="ch-name">{{ ___('Channel name') }}</label>
                    <input id="ch-name" name="name" class="ui-input" placeholder="{{ ___('Channel name') }}" required>
                </div>
                <div class="ui-team__field">
                    <label class="ui-label" for="ch-type">{{ ___('Type') }}</label>
                    <select id="ch-type" name="type" class="ui-input">
                        <option value="team">{{ ___('Whole team') }}</option>
                        <option value="group">{{ ___('Group') }}</option>
                        <option value="announcement">{{ ___('Announcement') }}</option>
                    </select>
                </div>
                <div class="ui-team__field">
                    <label class="ui-label" for="ch-members">{{ ___('Members') }}</label>
                    <select id="ch-members" name="staff_ids[]" class="ui-input" multiple>@foreach($staff as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select>
                </div>
                <label class="ui-check ui-team__check" for="ack">
                    <input type="checkbox" name="require_ack" value="1" id="ack">
                    <span>{{ ___('Require acknowledgement') }}</span>
                </label>
                <x-ui.button type="submit" icon="plus" block>{{ ___('Create') }}</x-ui.button>
            </form>
        </x-ui.card>
    </div>
</div>
@endsection
