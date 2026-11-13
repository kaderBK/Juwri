{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Connect AI'))
@section('page_title', ___('Connect AI assistants'))
@section('page_sub', ___('Let ChatGPT, Claude, and Cursor work with your store over MCP.'))

@section('content')
@php
    $servers = [
        ['name' => ___('Admin'), 'url' => $endpoints['admin'], 'who' => ___('You — manage menu, orders, coupons in natural language'), 'auth' => ___('API token (Bearer)'), 'icon' => 'settings'],
        ['name' => ___('Storefront'), 'url' => $endpoints['storefront'], 'who' => ___('A diner\'s agent — browse menu, place unpaid order'), 'auth' => ___('Storefront token (agent scope)'), 'icon' => 'store'],
        ['name' => ___('Customer'), 'url' => $endpoints['customer'], 'who' => ___('A signed-in diner — their orders, tracking, reorder'), 'auth' => ___('Customer token (OTP login)'), 'icon' => 'users'],
        ['name' => ___('Catalog'), 'url' => $endpoints['catalog'], 'who' => ___('Anyone — discover your store across the platform'), 'auth' => ___('Public (no token)'), 'icon' => 'search'],
    ];
@endphp
<div class="ui-scope ui-cai">
    <x-ui.card class="ui-cai__hero">
        <p class="ui-overline">{{ ___('Model Context Protocol') }}</p>
        <h2 class="ui-cai__title">{{ ___('Your MCP endpoints') }}</h2>
        <p class="ui-cai__sub">{{ ___('Point an AI assistant at these endpoints to manage your store, let diners order, and track orders in natural language.') }}</p>
        <div class="ui-cai__links">
            <x-ui.button :href="route('api.index')" variant="secondary" size="sm" icon="settings">{{ ___('Manage tokens') }}</x-ui.button>
            <x-ui.button :href="route('developers').'#mcp'" target="_blank" rel="noopener" variant="secondary" size="sm" icon="book-open">{{ ___('MCP documentation') }}</x-ui.button>
            <x-ui.button :href="url('/mcp')" target="_blank" rel="noopener" variant="secondary" size="sm" icon="external">{{ ___('Public setup guide') }}</x-ui.button>
        </div>
    </x-ui.card>

    {{-- Endpoint table --}}
    <x-ui.card :title="___('Four MCP servers')" variant="flush">
        <table class="ui-table">
            <thead>
                <tr>
                    <th>{{ ___('Server') }}</th>
                    <th>{{ ___('Endpoint') }}</th>
                    <th>{{ ___('Who it is for') }}</th>
                    <th>{{ ___('Auth') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($servers as $s)
                    <tr>
                        <td data-primary><span class="ui-cai__server"><x-ui.icon :name="$s['icon']" size="18" /> {{ $s['name'] }}</span></td>
                        <td data-label="{{ ___('Endpoint') }}"><code class="ui-cai__url">{{ $s['url'] }}</code></td>
                        <td data-label="{{ ___('Who it is for') }}"><span class="ui-cai__cell">{{ $s['who'] }}</span></td>
                        <td data-label="{{ ___('Auth') }}"><x-ui.tag>{{ $s['auth'] }}</x-ui.tag></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.card>

    {{-- Client config snippets --}}
    <x-ui.card :title="___('Connect your AI client')">
        <p class="ui-cai__sub">{{ ___('Admin server — replace YOUR_TOKEN with an API token from') }} <a href="{{ route('api.index') }}">{{ ___('Manage tokens') }}</a>.</p>

        <h3 class="ui-cai__client">{{ ___('Claude Code') }}</h3>
        <pre class="ui-cai__code"><code>claude mcp add --transport http {{ mcp_server_name('admin') }} {{ $endpoints['admin'] }} \
  --header "Authorization: Bearer YOUR_TOKEN"</code></pre>

        <h3 class="ui-cai__client">{{ ___('Claude Desktop') }} <code>claude_desktop_config.json</code></h3>
        <pre class="ui-cai__code"><code>{
  "mcpServers": {
    "{{ mcp_server_name('admin') }}": {
      "type": "http",
      "url": "{{ $endpoints['admin'] }}",
      "headers": { "Authorization": "Bearer YOUR_TOKEN" }
    }
  }
}</code></pre>

        <h3 class="ui-cai__client">{{ ___('Cursor') }} <code>.cursor/mcp.json</code></h3>
        <pre class="ui-cai__code"><code>{
  "mcpServers": {
    "{{ mcp_server_name('admin') }}": {
      "url": "{{ $endpoints['admin'] }}",
      "headers": { "Authorization": "Bearer YOUR_TOKEN" }
    }
  }
}</code></pre>

        <p class="ui-cai__sub ui-cai__gap">{{ ___('Catalog server is public — no token needed:') }}</p>
        <pre class="ui-cai__code"><code>claude mcp add --transport http {{ mcp_server_name('catalog') }} {{ $endpoints['catalog'] }}</code></pre>
    </x-ui.card>

    {{-- Storefront tokens for diner-agent ordering --}}
    <x-ui.card :title="___('Per-store agent tokens')">
        <p class="ui-cai__sub">{{ ___('The storefront MCP needs a per-store token with the agent scope. Issue and manage them on the') }} <a href="{{ route('api.index') }}">{{ ___('API tokens') }}</a> {{ ___('page.') }}</p>
        @if($storefrontTokens->isEmpty())
            <x-ui.empty icon="settings" :title="___('No storefront tokens yet.')">
                <x-ui.button :href="route('api.index')" variant="secondary">{{ ___('Manage tokens') }}</x-ui.button>
            </x-ui.empty>
        @else
            <x-ui.list>
                @foreach($storefrontTokens as $t)
                    <div class="ui-row">
                        <span class="ui-row__icon"><x-ui.icon name="store" size="22" /></span>
                        <span class="ui-row__body">
                            <span class="ui-row__title">{{ optional($t->store)->title }}</span>
                            <span class="ui-row__sub"><code>{{ implode(', ', (array) $t->scopes) }}</code></span>
                        </span>
                        @if(($activeId ?? 0) === (int) $t->store_id)
                            <span class="ui-row__trail"><x-ui.tag tone="accent">{{ ___('Current store') }}</x-ui.tag></span>
                        @endif
                    </div>
                @endforeach
            </x-ui.list>
        @endif
    </x-ui.card>
</div>
@endsection
