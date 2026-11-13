{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Call details'))
@section('page_title', ___('Call details'))
@section('page_sub', ___('Call log'))

@section('page_actions')
    <x-ui.button variant="secondary" size="sm" icon="arrow-left" :href="route('stores.phoneagent.calls.index').'?store_id='.$rid">{{ ___('Back to calls') }}</x-ui.button>
    @include('owner.partials.context-store-select', ['showBranch' => false])
@endsection

@section('content')
<div class="ui-scope ui-channels">
<x-ui.card :title="___('Call details')">
    <dl class="ui-channels__fields">
        <div><dt class="ui-label">{{ ___('Caller') }}</dt><dd class="ui-ltr">{{ $call->from_number ?: '—' }}</dd></div>
        <div><dt class="ui-label">{{ ___('Outcome') }}</dt><dd><x-ui.tag>{{ ___(ucfirst((string) $call->outcome)) }}</x-ui.tag></dd></div>
        <div><dt class="ui-label">{{ ___('Duration') }}</dt><dd class="ui-ltr">{{ $call->duration_seconds !== null ? gmdate('i:s', (int) $call->duration_seconds) : '—' }}</dd></div>
        <div><dt class="ui-label">{{ ___('Order') }}</dt><dd class="ui-ltr">{{ $call->order_id ? '#'.$call->order_id : '—' }}</dd></div>
        <div><dt class="ui-label">{{ ___('Recorded') }}</dt><dd><x-ui.pill :tone="$call->recording ? 'success' : 'neutral'">{{ $call->recording ? ___('Recorded') : ___('Not recorded') }}</x-ui.pill></dd></div>
        <div><dt class="ui-label">{{ ___('When') }}</dt><dd class="ui-channels__muted">{{ $call->created_at?->diffForHumans() }}</dd></div>
    </dl>
</x-ui.card>

<x-ui.card :title="___('Transcript')">
    @php $transcript = is_array($call->transcript) ? $call->transcript : []; @endphp
    @if(empty($transcript))
        <x-ui.empty icon="phone" :title="___('No transcript recorded.')" />
    @else
        <div class="ui-phone-agent__transcript">
            @foreach($transcript as $turn)
                @php
                    $role = is_array($turn) ? ($turn['role'] ?? '') : '';
                    $content = is_array($turn) ? ($turn['text'] ?? $turn['content'] ?? '') : '';
                    $isAgent = in_array($role, ['agent', 'assistant'], true);
                @endphp
                @continue($role === 'system' || $content === '')
                <div class="ui-phone-agent__turn" data-role="{{ $isAgent ? 'agent' : 'caller' }}">
                    <span class="ui-phone-agent__role" dir="auto">{{ $isAgent ? ___('Agent') : ___('Caller') }}</span>
                    <p class="ui-phone-agent__text" dir="auto">{{ $content }}</p>
                </div>
            @endforeach
        </div>
    @endif
</x-ui.card>
</div>
@endsection
