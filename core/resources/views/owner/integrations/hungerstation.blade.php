{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('HungerStation'))
@section('page_title', ___('HungerStation'))
@section('page_sub', ___('Connect your HungerStation account, map branches to vendors, and watch order events.'))

@section('page_actions')
    @if(!empty($stores) && count($stores) > 1)
    <form method="get">
        <label class="ui-visually-hidden" for="hs-store">{{ ___('Store') }}</label>
        <select id="hs-store" name="store" class="ui-input ui-input--sm" data-ui-do="submit">
            @foreach($stores as $r)
                <option value="{{ $r->id }}" @selected((int)$active === (int)$r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
    @endif
@endsection

@section('content')
<div class="ui-scope ui-hs" data-hs="{{ $active }}" data-csrf="{{ csrf_token() }}">
    @include('owner.integrations.partials.relay-notice')

    {{-- Hero --}}
    <x-ui.card class="ui-hs__hero">
        <span class="ui-hs__hero-icon" data-tone="{{ $connected ? 'success' : 'neutral' }}"><x-ui.icon name="delivery" size="24" /></span>
        <div>
            <p class="ui-overline">{{ ___('HungerStation') }}</p>
            <h2 class="ui-hs__title">{{ $connected ? ___('Connected') : ___('Not connected yet') }}</h2>
            <p class="ui-hs__sub">{{ ___('BYOK — your own HungerStation partner credentials. Never shared.') }}</p>
        </div>
    </x-ui.card>

    {{-- Connect --}}
    <x-ui.card :title="___('Connect credentials')">
        <p class="ui-hs__sub ui-hs__lead">{{ ___('From the HungerStation Partner Portal.') }}</p>
        <div class="ui-hs__creds">
            <div><label class="ui-label" for="hs-client-id">client_id</label><input class="ui-input ui-ltr" id="hs-client-id" placeholder="client_id" autocomplete="off"></div>
            <div><label class="ui-label" for="hs-client-secret">client_secret</label><input class="ui-input ui-ltr" id="hs-client-secret" type="password" placeholder="client_secret" autocomplete="off"></div>
            <div><label class="ui-label" for="hs-chain-id">chain_id <span class="ui-hs__muted">({{ ___('optional') }})</span></label><input class="ui-input ui-ltr" id="hs-chain-id" placeholder="chain_id ({{ ___('optional') }})"></div>
            <div><label class="ui-label" for="hs-webhook-secret">webhook_secret <span class="ui-hs__muted">({{ ___('optional') }})</span></label><input class="ui-input ui-ltr" id="hs-webhook-secret" type="password" placeholder="webhook_secret ({{ ___('optional') }})" autocomplete="off"></div>
        </div>
        <div class="ui-hs__connect">
            <x-ui.button type="button" id="hs-connect-btn" icon="check">{{ ___('Save & connect') }}</x-ui.button>
            <span id="hs-connect-msg" class="ui-hs__sub" role="status"></span>
        </div>
    </x-ui.card>

    {{-- Branch → vendor mapping --}}
    <x-ui.card :title="___('Branch → vendor mapping')" variant="flush">
        <p class="ui-hs__sub ui-hs__pad">{{ ___('Match each branch to its HungerStation vendor id.') }}</p>
        @if($branches->isEmpty())
            <x-ui.empty icon="store" :title="___('No branches.')" />
        @else
        <table class="ui-table ui-hs__map">
            <thead><tr><th>{{ ___('Branch') }}</th><th>{{ ___('Vendor id') }}</th><th>{{ ___('Chain id') }}</th><th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th></tr></thead>
            <tbody>
                @foreach($branches as $b)
                <tr data-branch="{{ $b->id }}">
                    <td data-primary><strong>{{ $b->name }}</strong></td>
                    <td data-label="{{ ___('Vendor id') }}"><input class="ui-input ui-input--sm ui-ltr hs-vendor" value="{{ $b->hs_vendor_id }}" placeholder="VENDOR_ID" aria-label="{{ ___('Vendor id') }}"></td>
                    <td data-label="{{ ___('Chain id') }}"><input class="ui-input ui-input--sm ui-ltr hs-chain" value="{{ $b->hs_chain_id }}" placeholder="CHAIN_ID" aria-label="{{ ___('Chain id') }}"></td>
                    <td class="ui-catalog__row-actions"><button type="button" class="ui-btn ui-btn--secondary ui-btn--sm hs-map-btn">{{ ___('Save') }}</button></td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </x-ui.card>

    {{-- Event log --}}
    <x-ui.card :title="___('Event log')" variant="flush">
        <p class="ui-hs__sub ui-hs__pad">{{ ___('Recent inbound/outbound HungerStation events.') }}</p>
        @if($events->isEmpty())
            <x-ui.empty icon="list" :title="___('No events yet.')" />
        @else
        <table class="ui-table">
            <thead><tr><th>{{ ___('Kind') }}</th><th>{{ ___('Dir') }}</th><th>{{ ___('Order') }}</th><th>{{ ___('OK') }}</th><th>{{ ___('When') }}</th></tr></thead>
            <tbody>
                @foreach($events as $e)
                <tr>
                    <td data-primary><code>{{ $e->kind }}</code></td>
                    <td data-label="{{ ___('Dir') }}"><x-ui.tag :tone="$e->direction === 'in' ? 'accent' : 'neutral'">{{ $e->direction }}</x-ui.tag></td>
                    <td data-label="{{ ___('Order') }}"><span class="ui-ltr">{{ $e->hs_order_id ?? '—' }}</span></td>
                    <td data-label="{{ ___('OK') }}"><x-ui.pill :tone="$e->ok ? 'success' : 'danger'">{{ $e->ok ? '✓' : '✗' }}</x-ui.pill></td>
                    <td data-label="{{ ___('When') }}"><span class="ui-ltr ui-hs__muted">{{ $e->created_at }}</span></td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </x-ui.card>
</div>

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
// Turbo swaps <body> on in-app nav without firing DOMContentLoaded; bind to
// turbo:load too, WeakSet-guarded so init runs once per DOM instance. Without
// this the page is inert when reached via the sidebar link.
(function () {
    var hsReady = (window.__hsReady = window.__hsReady || new WeakSet());
    function hsInit() {
    var root = document.querySelector('[data-hs]');
    if (!root || hsReady.has(root)) return;
    hsReady.add(root);
    var post = root.dataset.hs, csrf = root.dataset.csrf;
    var H = { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' };

    var cb = document.getElementById('hs-connect-btn');
    if (cb) cb.addEventListener('click', function () {
        var msg = document.getElementById('hs-connect-msg');
        fetch('{{ url('hungerstation') }}/' + post + '/connect', { method: 'POST', headers: H, credentials: 'same-origin', body: JSON.stringify({
            client_id: document.getElementById('hs-client-id').value,
            client_secret: document.getElementById('hs-client-secret').value,
            chain_id: document.getElementById('hs-chain-id').value,
            webhook_secret: document.getElementById('hs-webhook-secret').value
        }) }).then(function (r) { msg.textContent = r.ok ? '{{ ___('Connected ✓') }}' : '{{ ___('Check the fields.') }}'; });
    });

    document.querySelectorAll('.hs-map-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row = btn.closest('[data-branch]');
            fetch('{{ url('hungerstation') }}/' + post + '/map-branch', { method: 'POST', headers: H, credentials: 'same-origin', body: JSON.stringify({
                branch_id: parseInt(row.dataset.branch, 10),
                hs_vendor_id: row.querySelector('.hs-vendor').value,
                hs_chain_id: row.querySelector('.hs-chain').value
            }) }).then(function (r) { btn.textContent = r.ok ? '✓' : '!'; setTimeout(function () { btn.textContent = '{{ ___('Save') }}'; }, 1500); });
        });
    });
    }
    document.addEventListener('DOMContentLoaded', hsInit);
    document.addEventListener('turbo:load', hsInit);
})();
</script>
@endpush
@endsection
