{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Menu Cache'))
@section('content')
<div class="ui-stack">
    <section class="ui-kpis" aria-label="{{ ___('Menu Cache') }}">
        <x-ui.stat :value="$cacheDriver" :label="___('Cache driver')" icon="database" />
        <x-ui.stat :value="$ttl > 0 ? $ttl.'s' : ___('Disabled')" :label="___('Menu payload TTL')" icon="clock" :tone="$ttl > 0 ? null : 'danger'" />
        <x-ui.stat :value="$etagOn ? ___('On') : ___('Off')" :label="___('Storefront ETag')" icon="tag" />
        <x-ui.stat :value="$liveTokens.' / '.$stores->count()" :label="___('Tokens present (this page)')" icon="refresh" />
    </section>

    <p class="ui-small ui-text-2">
        {{ ___('Each store keys its cached storefront menu by a token. A menu edit replaces the token, which is what makes the new prices appear immediately. A missing token is harmless — the next storefront request rebuilds the menu from the database.') }}
        {{ ___('TTL and ETag are set by the MENU_CACHE_TTL and MENU_ETAG environment variables.') }}
    </p>

    <x-ui.card variant="flush" :title="___('Menu Cache')">
        <x-slot:actions>
            <form method="GET" class="ui-cluster" role="search">
                <input type="search" name="q" value="{{ $q }}" class="ui-input ui-input--sm ui-input--auto"
                       placeholder="{{ ___('Search store name or slug') }}" aria-label="{{ ___('Search store name or slug') }}">
                <x-ui.button type="submit" variant="secondary" size="sm" icon="search">{{ ___('Search') }}</x-ui.button>
            </form>
        </x-slot:actions>
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ ___('Store') }}</th>
                        <th>{{ ___('Cache token') }}</th>
                        <th class="ui-end">{{ ___('Action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($stores as $store)
                        <tr data-menu-cache-row="{{ $store->id }}">
                            <td data-primary data-label="{{ ___('Store') }}">
                                <a href="{{ route('publicView', $store->slug) }}" target="_blank" rel="noopener">{{ $store->title }}</a>
                                <small class="ui-cell-sub">{{ $store->slug }}</small>
                            </td>
                            <td data-label="{{ ___('Cache token') }}">
                                @if ($tokens[$store->id] !== null)
                                    <code class="ui-mono ui-break" data-menu-cache-token>{{ $tokens[$store->id] }}</code>
                                @else
                                    <x-ui.tag tone="warning" data-menu-cache-token>{{ ___('Not cached — rebuilt on the next storefront request') }}</x-ui.tag>
                                @endif
                            </td>
                            <td class="ui-end" data-label="">
                                <form method="POST" action="{{ route('admin.menu-cache.bust', $store->id) }}">
                                    @csrf
                                    <x-ui.button type="submit" variant="secondary" size="sm" icon="refresh">{{ ___('Rebuild') }}</x-ui.button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="ui-center ui-text-2">{{ ___('No stores.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($stores->hasPages())
            <div class="ui-card__foot">{{ $stores->links() }}</div>
        @endif
    </x-ui.card>
</div>
@endsection
