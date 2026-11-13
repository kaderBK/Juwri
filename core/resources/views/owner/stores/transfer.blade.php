{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Ownership transfer'))
@section('page_title', ___('Ownership transfer'))
@section('page_sub', ___('Move this store to another owner, or move a branch between your own stores.'))

@section('content')
<div class="ui-scope ui-xfer">
@if(session('success'))<x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>@endif
@if(session('error'))<x-ui.banner tone="danger">{{ session('error') }}</x-ui.banner>@endif
@if(session('warning'))<x-ui.banner tone="warning">{{ session('warning') }}</x-ui.banner>@endif
@php $impBlocked = \Juwri\Core\Support\ImpersonationRoutes::blocksNow('transfer.store.offer'); @endphp
@if($impBlocked)
    <x-ui.banner tone="warning">{{ ___('These actions are disabled during a support session. Ask the account owner to perform them, or exit impersonation first.') }}</x-ui.banner>
@endif

{{-- ============ Offers waiting for YOU ============ --}}
@if($incoming->isNotEmpty())
<x-ui.card :title="___('Stores offered to you')" class="ui-xfer__incoming">
    <ul class="ui-xfer__offers">
    @foreach($incoming as $t)
        <li class="ui-xfer__offer">
            <span class="ui-row__icon"><x-ui.icon name="store" size="20" /></span>
            <div class="ui-xfer__offer-body">
                <div class="ui-xfer__offer-title">{{ optional($t->store)->title ?? ___('A store') }}</div>
                <div class="ui-xfer__offer-sub">{{ ___('Accepting moves the store, its menu, staff and backups to your account. Your plan must have room for another store.') }}</div>
            </div>
            <div class="ui-xfer__offer-actions">
                <form method="POST" action="{{ route('transfer.accept', $t->token) }}"
                      data-confirm="{{ ___('Accept this store into your account?') }}">
                    @csrf<x-ui.button type="submit" size="sm" icon="check" :disabled="$impBlocked">{{ ___('Accept') }}</x-ui.button>
                </form>
                <form method="POST" action="{{ route('transfer.decline', $t->token) }}">
                    @csrf<x-ui.button type="submit" variant="secondary" size="sm" :disabled="$impBlocked">{{ ___('Decline') }}</x-ui.button>
                </form>
            </div>
        </li>
    @endforeach
    </ul>
</x-ui.card>
@endif

<div class="ui-xfer__grid">
    {{-- ============ Transfer THIS store ============ --}}
    <x-ui.card :title="___('Transfer this store to another owner')">
        <p class="ui-xfer__lead">
            {{ ___('The recipient must accept before anything moves. Your plan/subscription, wallet, API tokens and webhooks stay with your account.') }}
        </p>
        <form method="POST" action="{{ route('transfer.store.offer') }}" class="ui-xfer__inline">
            @csrf
            <div class="ui-xfer__field">
                <label class="ui-label" for="xfer-email">{{ ___('Recipient email') }}</label>
                <input type="email" id="xfer-email" name="to_email" class="ui-input" required placeholder="owner@example.com" dir="ltr" autocomplete="off">
            </div>
            <x-ui.button type="submit" icon="share" :disabled="$impBlocked">{{ ___('Send offer') }}</x-ui.button>
        </form>

        @if($outgoing->isNotEmpty())
            <div class="ui-xfer__pending">
                <p class="ui-overline">{{ ___('Pending offers you sent') }}</p>
                <ul class="ui-xfer__sent">
                @foreach($outgoing as $t)
                    <li>
                        <span class="ui-xfer__sent-what">{{ optional($t->store)->title }} <span aria-hidden="true" class="ui-xfer__arrow">→</span> <span class="ui-ltr">{{ $t->to_email }}</span></span>
                        <form method="POST" action="{{ route('transfer.cancel', $t->token) }}">
                            @csrf<x-ui.button type="submit" variant="ghost" size="sm" class="ui-xfer__cancel" :disabled="$impBlocked">{{ ___('Cancel') }}</x-ui.button>
                        </form>
                    </li>
                @endforeach
                </ul>
            </div>
        @endif
    </x-ui.card>

    {{-- ============ Move a branch ============ --}}
    <x-ui.card :title="___('Move a branch to another of your stores')">
        @if($branches->isEmpty() || $others->isEmpty())
            <x-ui.empty icon="store" :title="___('You need at least one branch here and another store to move a branch.')" />
        @else
            <form method="POST" action="{{ route('transfer.branch.move') }}" class="ui-xfer__move"
                  data-confirm="{{ ___('Move this branch to the selected store?') }}">
                @csrf
                <div class="ui-xfer__field">
                    <label class="ui-label" for="xfer-branch">{{ ___('Branch') }}</label>
                    <select id="xfer-branch" name="branch_id" class="ui-input" required>
                        @foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
                    </select>
                </div>
                <div class="ui-xfer__field">
                    <label class="ui-label" for="xfer-target">{{ ___('Destination store') }}</label>
                    <select id="xfer-target" name="target_store_id" class="ui-input" required>
                        @foreach($others as $o)<option value="{{ $o->id }}">{{ $o->title }}</option>@endforeach
                    </select>
                </div>
                <div class="ui-xfer__move-btn">
                    <x-ui.button type="submit" variant="secondary" icon="refresh" :disabled="$impBlocked">{{ ___('Move branch') }}</x-ui.button>
                </div>
            </form>
        @endif
    </x-ui.card>
</div>

{{-- ============ E2: transfer rules ============ --}}
<x-ui.card :title="___('Transfer rules')">
    <form method="POST" action="{{ route('transfer.settings') }}" class="ui-xfer__inline">
        @csrf
        <div class="ui-xfer__field ui-xfer__field--sm">
            <label class="ui-label" for="xfer-cooldown">{{ ___('Cooldown after a transfer (days)') }}</label>
            <input type="number" id="xfer-cooldown" min="0" max="365" name="transfer_cooldown_days" class="ui-input" inputmode="numeric"
                   value="{{ (int) (store_options($store->id, 'transfer_cooldown_days', '14') ?: 14) }}">
        </div>
        <x-ui.button type="submit" variant="secondary" icon="check" :disabled="$impBlocked">{{ ___('Save') }}</x-ui.button>
    </form>
    <p class="ui-xfer__hint">{{ ___('Block a fresh transfer offer for this many days after one is accepted. 0 disables the cooldown.') }}</p>
</x-ui.card>
</div>
@endsection
