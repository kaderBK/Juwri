{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Checklists'))
@section('page_title', ___('Checklists & shift notes'))
@section('page_sub', ___('Opening/closing tasks and handovers.'))

@section('page_actions')
    <form method="get" action="{{ route('stores.team.checklists') }}" class="ui-team__date">
        <input type="hidden" name="store_id" value="{{ $rid }}">
        <input type="date" name="date" value="{{ $date }}" class="ui-input ui-input--sm" data-ui-do="submit" aria-label="{{ ___('Date') }}">
    </form>
@endsection

@section('content')
<div class="ui-scope ui-team">
    {{-- session('success') already surfaces as a toast via partials.flash. --}}
    @if(isset($errors) && $errors->any())
        <x-ui.banner tone="danger">{{ $errors->first() }}</x-ui.banner>
    @endif

    <div class="ui-team__grid">
        <x-ui.card :title="___('Today\'s checklists')" variant="flush">
            <x-slot:actions><x-ui.tag>{{ $runs->count() }}</x-ui.tag></x-slot:actions>
            @forelse($runs as $run)
                @php $pct = $run->progress(); @endphp
                <div class="ui-team__run">
                    <div class="ui-team__run-head">
                        <strong class="ui-team__run-name">{{ optional($run->template)->name }}</strong>
                        <x-ui.pill :tone="$pct >= 100 ? 'success' : ($pct > 0 ? 'accent' : 'neutral')"><span class="ui-ltr">{{ $pct }}%</span></x-ui.pill>
                    </div>
                    <span class="ui-ov-meter" aria-hidden="true"><span style="inline-size: {{ min(100, max(0, $pct)) }}%"></span></span>
                    <div class="ui-team__tasks">
                        @foreach((array)$run->item_states as $i => $state)
                            <form method="post" action="{{ route('stores.team.checklists.toggle', $run->id) }}?store_id={{ $rid }}">
                                @csrf
                                <input type="hidden" name="index" value="{{ $i }}">
                                <input type="hidden" name="done" value="{{ empty($state['done']) ? 1 : 0 }}">
                                <button type="submit" @class(['ui-team__task', 'is-done' => !empty($state['done'])]) aria-pressed="{{ !empty($state['done']) ? 'true' : 'false' }}">
                                    <span class="ui-team__box" aria-hidden="true">@if(!empty($state['done']))<x-ui.icon name="check" size="16" />@endif</span>
                                    <span class="ui-team__task-label">{{ $state['label'] }}</span>
                                </button>
                            </form>
                        @endforeach
                    </div>
                </div>
            @empty
                <x-ui.empty :title="___('No checklists for this date.')" icon="list" />
            @endforelse
        </x-ui.card>

        <div class="ui-team__side">
            <x-ui.card :title="___('New checklist template')">
                <form method="post" action="{{ route('stores.team.checklists.templates.store') }}?store_id={{ $rid }}" class="ui-team__form">
                    @csrf
                    <div class="ui-team__field">
                        <label class="ui-label" for="tpl-name">{{ ___('Name') }}</label>
                        <input id="tpl-name" name="name" class="ui-input" placeholder="{{ ___('Name') }}" required>
                    </div>
                    <div class="ui-team__field">
                        <label class="ui-visually-hidden" for="tpl-schedule">{{ ___('Opening') }} / {{ ___('Closing') }}</label>
                        <select id="tpl-schedule" name="schedule" class="ui-input"><option value="opening">{{ ___('Opening') }}</option><option value="closing">{{ ___('Closing') }}</option><option value="daily">{{ ___('Daily') }}</option></select>
                    </div>
                    <div class="ui-team__field">
                        <label class="ui-visually-hidden" for="tpl-items">{{ ___('One task per line') }}</label>
                        <textarea id="tpl-items" name="items" class="ui-input" rows="4" placeholder="{{ ___('One task per line') }}" required></textarea>
                    </div>
                    <x-ui.button type="submit" icon="plus" block>{{ ___('Create') }}</x-ui.button>
                </form>
            </x-ui.card>

            <x-ui.card :title="___('Shift notes')">
                @if($notes->isNotEmpty())
                    <ul class="ui-team__notes">
                        @foreach($notes as $note)<li>{{ $note->body }}</li>@endforeach
                    </ul>
                @endif
                <form method="post" action="{{ route('stores.team.notes.store') }}?store_id={{ $rid }}" class="ui-team__form">
                    @csrf
                    <input type="hidden" name="date" value="{{ $date }}">
                    <label class="ui-visually-hidden" for="note-body">{{ ___('Handover note…') }}</label>
                    <textarea id="note-body" name="body" class="ui-input" rows="2" placeholder="{{ ___('Handover note…') }}" required></textarea>
                    <x-ui.button type="submit" variant="secondary" icon="pencil" block>{{ ___('Add note') }}</x-ui.button>
                </form>
            </x-ui.card>
        </div>
    </div>
</div>
@endsection
