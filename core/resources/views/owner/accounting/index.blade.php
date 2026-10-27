{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Accounting'))
@section('page_title', ___('Accounting'))
@section('page_sub', ___('Sync paid orders to QuickBooks or Xero.'))

@section('page_actions')
    <form method="get" action="{{ route('stores.accounting.index') }}">
        <select name="store_id" class="ui-input ui-input--sm" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($rid == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')
@php $hasCred = !empty($secrets['access_token']); @endphp

<div class="ui-scope ui-books">
    {{-- session('success') already surfaces as a toast via partials.flash. --}}
    <x-ui.card :title="___('Connection')">
        <x-slot:actions>
            <x-ui.pill :tone="$connected ? 'success' : 'neutral'">{{ $connected ? ___('Connected') : ___('Not connected yet') }}</x-ui.pill>
            @if($connected)
                <form method="post" action="{{ route('stores.accounting.disconnect') }}?store_id={{ $rid }}" data-confirm="{{ ___('Disconnect accounting?') }}" data-confirm-danger>
                    @csrf
                    <x-ui.button type="submit" variant="danger" size="sm">{{ ___('Disconnect') }}</x-ui.button>
                </form>
            @endif
        </x-slot:actions>

        <form method="post" action="{{ route('stores.accounting.connect') }}?store_id={{ $rid }}" class="ui-books__form">
            @csrf
            <div class="ui-books__grid">
                <div class="ui-books__field ui-books__field--wide">
                    <label class="ui-label" for="ac-provider">{{ ___('Provider') }}</label>
                    <select id="ac-provider" name="provider" class="ui-input" required>
                        @foreach($providers as $p)
                            <option value="{{ $p }}" @selected(($secrets['provider'] ?? '') === $p)>{{ ucfirst($p) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="ui-books__field ui-books__field--wide">
                    <label class="ui-label" for="ac-base">{{ ___('API base URL') }}</label>
                    <input id="ac-base" name="base_url" class="ui-input ui-ltr" value="{{ $secrets['base_url'] ?? '' }}" required placeholder="https://api.xero.com/api.xro/2.0" inputmode="url">
                </div>
                <div class="ui-books__field ui-books__field--wide">
                    <label class="ui-label" for="ac-access">{{ ___('Access token') }}</label>
                    <input id="ac-access" type="password" name="access_token" class="ui-input" autocomplete="off" placeholder="{{ $hasCred ? '•••••••• ' . ___('leave blank to keep') : '' }}" @if(!$hasCred) required @endif>
                </div>
                <div class="ui-books__field ui-books__field--wide">
                    <label class="ui-label" for="ac-refresh">{{ ___('Refresh token') }}</label>
                    <input id="ac-refresh" type="password" name="refresh_token" class="ui-input" autocomplete="off" placeholder="{{ !empty($secrets['refresh_token']) ? '•••••••• ' . ___('leave blank to keep') : '' }}">
                </div>
                <div class="ui-books__field ui-books__field--wide">
                    <label class="ui-label" for="ac-tenant">{{ ___('Tenant / Realm id') }}</label>
                    <input id="ac-tenant" name="tenant_id" class="ui-input ui-ltr" value="{{ $secrets['tenant_id'] ?? '' }}">
                </div>
                <div class="ui-books__field ui-books__field--wide">
                    <label class="ui-label" for="ac-token-url">{{ ___('Token URL') }}</label>
                    <input id="ac-token-url" name="token_url" class="ui-input ui-ltr" value="{{ $secrets['token_url'] ?? '' }}" inputmode="url">
                </div>
                <div class="ui-books__field ui-books__field--wide">
                    <label class="ui-label" for="ac-client">{{ ___('Client id') }}</label>
                    <input id="ac-client" name="client_id" class="ui-input ui-ltr" value="{{ $secrets['client_id'] ?? '' }}">
                </div>
                <div class="ui-books__field ui-books__field--wide">
                    <label class="ui-label" for="ac-secret">{{ ___('Client secret') }}</label>
                    <input id="ac-secret" type="password" name="client_secret" class="ui-input" autocomplete="off" placeholder="{{ !empty($secrets['client_secret']) ? '••••••••' : '' }}">
                </div>
            </div>
            <p class="ui-books__note ui-books__note--flush">{{ ___('One-click OAuth connect is coming soon; paste your tokens for now. Credentials are stored encrypted.') }}</p>
            <div class="ui-books__submit">
                <x-ui.button type="submit" icon="check" data-ui-busy>{{ ___('Save connection') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    @if($failedCount > 0)
        <x-ui.banner tone="warning">
            <div class="ui-cluster ui-grow">
                <span class="ui-grow">{{ ___(':count orders did not sync to your accounting provider.', ['count' => $failedCount]) }}</span>
                <form method="post" action="{{ route('stores.accounting.retry-failed') }}?store_id={{ $rid }}">
                    @csrf
                    <x-ui.button type="submit" variant="secondary" size="sm" icon="refresh">{{ ___('Retry all failed') }}</x-ui.button>
                </form>
            </div>
        </x-ui.banner>
    @endif

    <x-ui.card :title="___('Sync log')" variant="flush">
        <x-slot:actions><x-ui.tag>{{ $log->count() }}</x-ui.tag></x-slot:actions>
        @if($log->isEmpty())
            <x-ui.empty :title="___('No invoices synced yet.')" icon="refresh" />
        @else
            <table class="ui-table">
                <thead><tr>
                    <th>{{ ___('Order') }}</th>
                    <th>{{ ___('Status') }}</th>
                    <th>{{ ___('Provider') }}</th>
                    <th>{{ ___('External id') }}</th>
                    <th>{{ ___('When') }}</th>
                    <th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th>
                </tr></thead>
                <tbody>
                @foreach($log as $row)
                    <tr>
                        <td data-primary><span class="ui-ltr">#{{ $row->order_id }}</span></td>
                        <td data-label="{{ ___('Status') }}">
                            @if($row->status === 'failed')
                                <x-ui.tag tone="danger">{{ ___('Failed') }}</x-ui.tag>
                                @if($row->error)
                                    <div class="ui-small ui-muted">{{ $row->error }}</div>
                                @endif
                            @else
                                <x-ui.tag tone="success">{{ ___('Synced') }}</x-ui.tag>
                            @endif
                        </td>
                        <td data-label="{{ ___('Provider') }}"><x-ui.tag>{{ ucfirst($row->provider) }}</x-ui.tag></td>
                        <td data-label="{{ ___('External id') }}">@if($row->external_id)<code>{{ $row->external_id }}</code>@else<span class="ui-books__muted">—</span>@endif</td>
                        <td data-label="{{ ___('When') }}">{{ $row->created_at?->diffForHumans() }}</td>
                        <td class="ui-catalog__row-actions">
                            <form method="post" action="{{ route('stores.accounting.retry', $row->order_id) }}?store_id={{ $rid }}">
                                @csrf
                                <x-ui.button type="submit" variant="secondary" size="sm" icon="refresh">{{ ___('Re-sync') }}</x-ui.button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </x-ui.card>
</div>
@endsection
