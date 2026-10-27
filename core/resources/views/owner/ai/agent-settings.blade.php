{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('AI ordering'))
@section('page_title', ___('AI ordering'))
@section('page_sub', ___('A chat assistant on your menu that answers questions and takes orders. Also exposes an agent (MCP) endpoint.'))

@section('content')
<div class="ui-scope ui-agent">
    <x-ui.card class="ui-agent__hero">
        <div class="ui-agent__hero-text">
            <p class="ui-overline">{{ ___('Agents') }}</p>
            <h2 class="ui-agent__title">{{ ___('Chat & order') }}</h2>
            <p class="ui-agent__sub">{{ ___('Enable the on-menu widget, or let external AI agents order via MCP.') }}</p>
        </div>
        <form method="get" action="{{ route('agent.settings.page') }}" class="ui-agent__store">
            <label class="ui-visually-hidden" for="agent-store">{{ ___('Store') }}</label>
            <select id="agent-store" name="store" class="ui-input" data-ui-do="submit">
                @foreach($stores as $r)
                    <option value="{{ $r->id }}" @selected($activeId==$r->id)>{{ $r->title }}</option>
                @endforeach
            </select>
        </form>
    </x-ui.card>

    @if(session('success'))<x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>@endif

    @if($stores->isEmpty())
        <div class="ui-card">
            <x-ui.empty icon="store" :title="___('Create a store first.')" />
        </div>
    @elseif($activeId)
    <div class="ui-agent__grid">
        <x-ui.card :title="___('Chat widget')">
            <form action="{{ route('agent.settings.save', $activeId) }}" method="post" class="ui-agent__form">
                @csrf
                {{-- ZERO-TOUCH A4.1: the widget is live on storefront themes + hybrid voice input --}}
                <x-ui.switch name="agent_chat_enabled" id="agent_chat_enabled" :checked="($opts->agent_chat_enabled ?? '')==='1'"
                    :label="___('Show the chat & order widget on my menu')"
                    :description="___('Shows as a floating chat button on your menu page.').' '.___('Customers can also dictate messages — a microphone appears automatically when their browser supports voice input.')" />
                <div>
                    <label class="ui-label" for="agent_chat_welcome">{{ ___('Button text') }}</label>
                    <input id="agent_chat_welcome" class="ui-input" type="text" name="agent_chat_welcome" maxlength="120" value="{{ $opts->agent_chat_welcome ?? '' }}" placeholder="{{ ___('Chat & order') }}">
                </div>
                <div>
                    <label class="ui-label" for="agent_chat_prompt">{{ ___('Assistant persona / instructions (optional)') }}</label>
                    <textarea id="agent_chat_prompt" class="ui-input" name="agent_chat_prompt" rows="4" maxlength="2000" placeholder="{{ ___('Friendly, concise. Suggest popular dishes.') }}">{{ $opts->agent_chat_prompt ?? '' }}</textarea>
                </div>
                <div class="sticky-savebar">
                    <x-ui.button type="submit" icon="check" data-ui-busy>{{ ___('Save') }}</x-ui.button>
                </div>
            </form>
            <x-ai-badge :store-id="$activeId" class="ui-agent__badge" />
            <p class="ui-agent__hint">
                {{ ___('Requires the AI chatbot feature on your plan. Replies are metered against your AI usage.') }}
            </p>

            {{-- ZERO-TOUCH Track B4: read-only effective voice (STT) state. --}}
            <div class="ui-agent__voice">
                <h3 class="ui-agent__h">{{ ___('Voice input & voice notes') }}</h3>
                @if(!empty($sttChain))
                    @php $stt = $sttChain[0]; @endphp
                    <p class="ui-agent__hint">
                        <x-ui.pill tone="success">{{ ___('Ready') }}</x-ui.pill>
                        {{ ___('Voice is transcribed by') }}
                        <b>{{ $stt['provider'] }}</b>@if(!empty($stt['model'])) · <code>{{ $stt['model'] }}</code>@endif
                        <span>({{ $stt['key_source'] === 'byok' ? ___('your API key') : ($stt['key_source'] === 'reseller' ? ___('reseller key') : ___('platform key')) }})</span>
                    </p>
                    <p class="ui-agent__hint">{{ ___('Customers can dictate in chat, and order voice notes are shown to staff as text (toggle in store settings).') }}</p>
                @else
                    <p class="ui-agent__hint">
                        <x-ui.pill>{{ ___('Not configured') }}</x-ui.pill>
                        {{ ___('No voice-capable AI provider is set up. Add a Groq or OpenAI key in Integrations to enable mic input and voice-note transcripts. Chat still works by typing.') }}
                    </p>
                @endif
            </div>
        </x-ui.card>

        <x-ui.card :title="___('For external AI agents')">
            <p class="ui-agent__hint ui-agent__hint--top">{{ ___('Give an AI agent (with an agent-scope storefront token) these endpoints:') }}</p>
            <dl class="ui-agent__endpoints">
                <div><dt>{{ ___('MCP (JSON-RPC)') }}</dt><dd><code>{{ url('/mcp/storefront') }}</code></dd></div>
                <div><dt>{{ ___('Manifest') }}</dt><dd><code>{{ url('/agent/'.$activeId.'/manifest') }}</code></dd></div>
                <div><dt>{{ ___('Menu for agents') }}</dt><dd><code>{{ url('/agent/'.$activeId.'/llms.txt') }}</code></dd></div>
            </dl>
            <x-ui.button :href="route('api.index')" variant="secondary" size="sm" icon="settings">{{ ___('Create an agent-scope token') }}</x-ui.button>

            {{-- AO7 #36: embeddable chat/menu widget for external sites --}}
            <div class="ui-agent__embed">
                <h3 class="ui-agent__h">{{ ___('Embed on your website') }}</h3>
                <p class="ui-agent__hint ui-agent__hint--top">{{ ___('Paste this where you want your menu + chat to appear.') }}</p>
                @php $embed = '<iframe src="'.url('/'.$activeSlug).'" style="width:100%;height:640px;border:0;border-radius:12px" title="Menu" loading="lazy"></iframe>'; @endphp
                <textarea id="agent-embed" class="ui-input ui-agent__code" rows="3" readonly data-ui-do="select" aria-label="{{ ___('Embed on your website') }}">{{ $embed }}</textarea>
                <x-ui.button variant="secondary" size="sm" icon="share" data-ui-do="copy" data-ui-copy-target="agent-embed" :data-ui-copied="___('Copied')"><span data-ui-copy-label>{{ ___('Copy embed code') }}</span></x-ui.button>
            </div>
        </x-ui.card>
    </div>

    {{-- AO9 #45: transcript dashboard --}}
    <section class="ui-card ui-agent__convos" x-data="agentTranscripts({{ $activeId }})" x-init="load()">
        <div class="ui-card__head">
            <h2 class="ui-card__title">{{ ___('Conversations') }}</h2>
            <div class="ui-card__actions">
                <x-ui.button variant="ghost" size="sm" icon="refresh" @click="load()">{{ ___('Refresh') }}</x-ui.button>
            </div>
        </div>
        <p class="ui-agent__hint ui-agent__hint--top">{{ ___('Recent chats between customers and your assistant. Click one to read the full transcript.') }}</p>

        <div class="ui-agent__split">
            <div class="ui-agent__threads">
                <template x-if="!conversations.length">
                    <x-ui.empty icon="chat" :title="___('No conversations yet.')" />
                </template>
                <ul class="ui-agent__thread-list">
                    <template x-for="c in conversations" :key="c.id">
                        <li>
                            <button type="button" @click="open(c.id)" class="ui-agent__thread" :aria-pressed="activeConv===c.id ? 'true' : 'false'">
                                <span class="ui-agent__thread-top">
                                    <b class="ui-ltr">#<span x-text="c.id"></span></b>
                                    <span class="ui-agent__thread-tags">
                                        <span x-show="c.handoff" x-cloak title="{{ ___('Needs a human') }}" class="ui-pill" data-tone="danger">{{ ___('Handoff') }}</span>
                                        <span class="ui-tag" data-tone="neutral" x-text="c.channel"></span>
                                    </span>
                                </span>
                                <span class="ui-agent__thread-sub">
                                    <span x-text="c.messages"></span> {{ ___('messages') }}
                                    <template x-if="c.order_id"><span> · {{ ___('ordered') }} <span class="ui-ltr">#<span x-text="c.order_id"></span></span></span></template>
                                </span>
                            </button>
                        </li>
                    </template>
                </ul>
            </div>

            <div class="ui-agent__transcript">
                <template x-if="activeConv === null">
                    <p class="ui-agent__hint">{{ ___('Select a conversation to read it.') }}</p>
                </template>
                <template x-if="loadingMsgs"><p class="ui-agent__hint">{{ ___('Loading…') }}</p></template>
                <div x-show="activeConv !== null && !loadingMsgs" class="ui-agent__msgs">
                    <template x-for="(m,i) in messages" :key="i">
                        <div class="ui-agent__msg" :class="m.role==='user' ? 'is-user' : 'is-bot'">
                            <div class="ui-agent__msg-role" x-text="m.role + (m.has_tool ? ' · 🛠' : '')"></div>
                            <div class="ui-agent__msg-text" x-text="m.content"></div>
                        </div>
                    </template>
                    <template x-if="activeConv !== null && !messages.length && !loadingMsgs">
                        <p class="ui-agent__hint">{{ ___('No messages in this conversation.') }}</p>
                    </template>
                </div>
            </div>
        </div>
    </section>
    @endif
</div>

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
function agentTranscripts(storeId) {
    return {
        storeId: storeId,
        conversations: [], messages: [], activeConv: null, loadingMsgs: false,
        async load() {
            if (!this.storeId) return;
            try {
                const r = await fetch('{{ url('ai-ordering') }}/' + this.storeId + '/transcripts', { headers: { Accept: 'application/json' } });
                const d = await r.json();
                this.conversations = d.conversations || [];
            } catch (e) { this.conversations = []; }
        },
        async open(id) {
            this.activeConv = id; this.loadingMsgs = true; this.messages = [];
            try {
                const r = await fetch('{{ url('ai-ordering') }}/' + this.storeId + '/transcripts/' + id, { headers: { Accept: 'application/json' } });
                const d = await r.json();
                this.messages = d.messages || [];
            } catch (e) { this.messages = []; }
            this.loadingMsgs = false;
        },
    };
}
</script>
@endpush
@endsection
