{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Catering'))
@section('page_title', ___('Catering'))
@section('page_sub', ___('Event and large-order requests.'))

@section('page_actions')
    @include('owner.partials.context-store-select', ['showBranch' => false])
@endsection

@section('content')
@include('owner.partials.feature-tabs', ['tabs' => juwri_feature_tabs('catering')])

<div class="ui-scope ui-catering">

@if(session('success'))
    <x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>
@endif
{{-- C4: after accepting a deposit-armed request, hand the owner a copyable pay link
     to send the customer (catering deposits are async — no in-form payment). --}}
@if(session('catering_deposit_link'))
    <div class="ui-banner ui-catering__link" role="status" data-catering-deposit-link>
        <span class="ui-banner__icon"><x-ui.icon name="wallet" size="18" /></span>
        <div class="ui-banner__body">
            <strong>{{ ___('Deposit link') }}:</strong>
            <input type="text" class="ui-input ui-input--sm ui-ltr" readonly
                   value="{{ session('catering_deposit_link') }}" data-ui-do="select" aria-label="{{ ___('Deposit link') }}">
            <small>{{ ___('Copy and send this to the customer.') }}</small>
        </div>
    </div>
@endif
@if(isset($errors) && $errors->any())
    <x-ui.banner tone="danger">{{ $errors->first() }}</x-ui.banner>
@endif

<nav class="ui-chips ui-catering__filters" aria-label="{{ ___('Status') }}">
    @foreach(['' => ___('All'), 'requested' => ___('Requested'), 'quoted' => ___('Quoted'), 'accepted' => ___('Accepted'), 'declined' => ___('Declined')] as $key => $label)
        <x-ui.chip :href="route('stores.catering.index').'?store_id='.$rid.($key ? '&status='.$key : '')" :active="$status === ($key ?: null)">{{ $label }}</x-ui.chip>
    @endforeach
</nav>

@forelse($requests as $req)
    @php
        $statusTone = ['requested' => 'neutral', 'quoted' => 'accent', 'accepted' => 'success', 'declined' => 'danger'][$req->status] ?? 'neutral';
        $canQuote = in_array($req->status, ['requested', 'quoted']);
    @endphp
    <article class="ui-card ui-catering__req">
        <div class="ui-catering__main">
            <header class="ui-catering__head">
                <h2 class="ui-catering__name">{{ $req->name }}</h2>
                <x-ui.pill :tone="$statusTone">{{ ___(ucfirst($req->status)) }}</x-ui.pill>
                {{-- C4: deposit lifecycle badge (pending until the customer pays the link, then paid). --}}
                @if($req->deposit_status)
                    <x-ui.tag :tone="['pending' => 'warning', 'paid' => 'success'][$req->deposit_status] ?? 'neutral'" data-deposit-badge>{{ ___('Deposit') }}: {{ ___($req->deposit_status) }}@if((float) $req->deposit_amount > 0) · <span class="ui-ltr">{{ number_format((float) $req->deposit_amount, 2) }}</span>@endif</x-ui.tag>
                @endif
            </header>
            <ul class="ui-catering__meta">
                <li><x-ui.icon name="calendar" size="16" /><span>{{ $req->event_at->format('D, d M Y H:i') }}</span></li>
                <li><x-ui.icon name="users" size="16" /><span><span class="ui-ltr">{{ $req->headcount }}</span> {{ ___('guests') }}</span></li>
                @if($req->phone)<li><x-ui.icon name="phone" size="16" /><span class="ui-ltr">{{ $req->phone }}</span></li>@endif
                @if($req->email)<li><x-ui.icon name="chat" size="16" /><span class="ui-ltr">{{ $req->email }}</span></li>@endif
            </ul>
            @if($req->message)<p class="ui-catering__msg" dir="auto">{{ $req->message }}</p>@endif
            @if($req->quote_total !== null || $req->order_id)
                <div class="ui-catering__facts">
                    @if($req->quote_total !== null)<span><span class="ui-catering__k">{{ ___('Quoted') }}:</span> <strong class="ui-ltr">{{ number_format($req->quote_total, 2) }}</strong></span>@endif
                    @if($req->order_id)<a href="{{ route('stores.orders', $rid) }}" class="ui-catering__order">{{ ___('Order') }} <span class="ui-ltr">#{{ $req->order_id }}</span></a>@endif
                </div>
            @endif
        </div>
        @if($canQuote || $req->status === 'quoted')
            <div class="ui-catering__actions">
                @if($canQuote)
                    <form method="post" action="{{ route('stores.catering.quote', $req->id) }}?store_id={{ $rid }}" class="ui-catering__quote">
                        @csrf
                        <input type="number" step="0.01" min="0" name="quote_total" class="ui-input" placeholder="{{ ___('Quote total') }}" aria-label="{{ ___('Quote total') }}" value="{{ $req->quote_total }}" required inputmode="decimal">
                        <x-ui.button type="submit" variant="secondary">{{ ___('Quote') }}</x-ui.button>
                    </form>
                @endif
                @if($req->status === 'quoted')
                    <form method="post" action="{{ route('stores.catering.accept', $req->id) }}?store_id={{ $rid }}">
                        @csrf
                        <x-ui.button type="submit" variant="success" icon="check" block>{{ ___('Accept → create order') }}</x-ui.button>
                    </form>
                @endif
                @if($canQuote)
                    <form method="post" action="{{ route('stores.catering.decline', $req->id) }}?store_id={{ $rid }}">
                        @csrf
                        <x-ui.button type="submit" variant="ghost" icon="x" block class="ui-catering__decline">{{ ___('Decline') }}</x-ui.button>
                    </form>
                @endif
            </div>
        @endif
    </article>
@empty
    <div class="ui-card"><x-ui.empty :title="___('No catering requests.')" icon="calendar" /></div>
@endforelse

</div>
@endsection
