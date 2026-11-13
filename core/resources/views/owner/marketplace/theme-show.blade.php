{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

{{-- ST3 (features-run-4): Theme Store detail inside the dashboard. Data from
     App\Http\Controllers\MarketplaceController@show. Buy/apply POST to the
     EXISTING marketplace.buy / marketplace.apply routes (ThemePurchaseService)
     — no commerce is re-implemented. Live preview opens the try-before-buy URL. --}}

@section('title', $theme->name)
@section('page_title', $theme->name)

@section('content')
{{-- P0-7: surface top-up dead-end (shortfall above single top-up max) --}}
@if(session('store_error'))
    <div class="ui-note" data-tone="warning">{{ session('store_error') }}</div>
@endif
@php $isFree = (float) $theme->price <= 0; $sellerName = $theme->seller?->name ?: ___('Platform'); $main = $screenshots->first(); @endphp
<div><a class="ui-muted" href="{{ route('user.store.themes') }}">← {{ ___('All themes') }}</a></div>

<div class="ui-form-grid">
    <div>
        <div class="ui-ratio-16x9 ui-bg-inset ui-clip">
            @if($main)<img src="{{ $main->url() }}" alt="{{ $theme->name }}" class="ui-w-full ui-fill" style="object-fit:cover;">
            @elseif($previewUrl)<img src="{{ $previewUrl }}" alt="{{ $theme->name }}" class="ui-w-full ui-fill" style="object-fit:cover;">
            @else<span class="ui-cluster ui-cluster--center ui-text-2">{{ ___('No preview') }}</span>@endif
        </div>
        @if($screenshots->count() > 1)
            <div class="ui-cluster ui-scroll">
                @foreach($screenshots as $shot)
                    <img src="{{ $shot->url() }}" alt="" class="ui-bordered" style="width:110px;height:70px;object-fit:cover;">
                @endforeach
            </div>
        @endif

        <div class="ui-card"><div class="ui-card__body">
            <h3 class="ui-section-title">{{ ___('About this theme') }}</h3>
            @include('owner.partials.rich-description', ['text' => $version->manifest['description'] ?? $theme->tagline ?? ''])
        </div></div>

        @if($versions->isNotEmpty())
            <div class="ui-card"><div class="ui-card__body">
                <h3 class="ui-section-title">{{ ___('Changelog') }}</h3>
                @foreach($versions as $v)
                    <div class="ui-cluster ui-rule-top">
                        <code>{{ $v->semver }}</code>
                        <span class="ui-muted">{{ $v->review_notes ?: ___('Reviewed & quality-gated') }}</span>
                        <span class="ui-muted ui-ms-auto">{{ $v->created_at?->diffForHumans() }}</span>
                    </div>
                @endforeach
            </div></div>
        @endif
    </div>

    <div>
        <div class="ui-card"><div class="ui-card__body" x-data="{ buying:false, applying:false, entitled:{{ $entitled ? 'true' : 'false' }}, applied:false, msg:'', shortfall:0, topup:false }">
            <h3 class="ui-section-title">{{ $theme->name }}</h3>
            <div class="ui-text-2">{{ ___('by :developer', ['developer' => $sellerName]) }}
                @if((int) $theme->rating_count > 0)· <span class="ui-text-warning">★</span> {{ number_format($theme->rating_avg, 1) }} ({{ $theme->rating_count }})@endif
            </div>
            <div class="ui-fs-lg ui-strong {{ $isFree ? 'ui-text-success' : 'ui-text-accent' }}">{{ $isFree ? ___('Free') : price_symbol_format($theme->price) }}</div>
            @if($theme->tagline)<p class="ui-muted">{{ $theme->tagline }}</p>@endif

            <a class="ui-btn ui-btn--secondary ui-w-full" href="{{ route('marketplace.preview', $theme->slug) }}" target="_blank" rel="noopener">
                {{ ___('Live preview (demo store)') }}</a>

            {{-- Buy: unowned paid themes debit the wallet (ST4). Insufficient
                 balance (402) reveals the top-up-the-shortfall-and-continue flow. --}}
            @if(!$isFree)
                <button class="ui-btn ui-w-full" x-show="!entitled && !topup" :disabled="buying"
                        @click="buying=true; msg='';
                            fetch('{{ route('user.store.buy.theme', $theme->slug) }}', {method:'POST', headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}','Accept':'application/json'}})
                            .then(r => r.json().then(d => ({ok:r.ok, d})))
                            .then(({ok, d}) => { buying=false;
                                if(ok && d.ok){ entitled=true; }
                                else if(d.status==='shortfall'){ shortfall=d.shortfall; topup=true; msg=d.message||''; }
                                else { msg = d.message || '{{ ___('Purchase could not be completed. Check your wallet balance.') }}'; } })
                            .catch(() => { buying=false; msg='{{ ___('Something went wrong. Please try again.') }}'; })">
                    <span x-show="!buying">{{ ___('Buy license') }} — {{ price_symbol_format($theme->price) }}</span>
                    <span x-show="buying">{{ ___('Processing…') }}</span>
                </button>
                <p class="ui-muted" x-show="!entitled && !topup">{{ ___('Charged to your wallet balance. One purchase covers all your stores.') }}</p>

                {{-- ST4 shortfall: fund the exact gap through the existing wallet
                     top-up gateway; the purchase auto-completes on payment success. --}}
                <div x-show="topup" x-cloak class="ui-rule-top">
                    <p class="ui-small" x-text="msg"></p>
                    @if($topupEnabled && $topupGateways->isNotEmpty())
                        <form method="POST" action="{{ route('user.store.topup.continue') }}">
                            @csrf
                            <input type="hidden" name="purchase" value="theme">
                            <input type="hidden" name="id" value="{{ $theme->id }}">
                            <label class="ui-label ui-small">{{ ___('Pay with') }}</label>
                            <select name="payment_method" class="ui-input" required>
                                @foreach($topupGateways as $g)<option value="{{ $g->id }}">{{ $g->payment_title ?? $g->payment_folder }}</option>@endforeach
                            </select>
                            <button type="submit" class="ui-btn ui-w-full">{{ ___('Top up & complete purchase') }}</button>
                        </form>
                        <p class="ui-muted">{{ ___('Any credit beyond this purchase stays in your wallet. If you cancel the payment, nothing is charged.') }}</p>
                    @else
                        <p class="ui-muted">{{ ___('Top up your wallet to complete this purchase.') }}</p>
                    @endif
                </div>
            @endif

            {{-- Apply picker: entitled (owned or free) themes apply to a chosen restaurant. --}}
            <div x-show="entitled" @if(!$entitled && !$isFree) x-cloak @endif>
                @if($stores->isEmpty())
                    <p class="ui-muted">{{ ___('Create a store first, then apply the theme.') }}</p>
                @else
                    <div class="ui-note" data-tone="success" x-show="applied" x-cloak>✓ {{ ___('Applied to your store.') }}</div>
                    <label class="ui-label ui-small">{{ ___('Apply to store') }}</label>
                    <select class="ui-input" x-ref="applyTarget">
                        @foreach($stores as $r)<option value="{{ $r->id }}">{{ $r->title }}</option>@endforeach
                    </select>
                    <button class="ui-btn ui-w-full" :disabled="applying"
                            @click="applying=true; msg='';
                                fetch('{{ route('marketplace.apply', $theme->slug) }}', {method:'POST', headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}','Accept':'application/json','Content-Type':'application/json'}, body: JSON.stringify({store_id: $refs.applyTarget.value})})
                                .then(r => r.json().then(d => ({ok:r.ok, d})))
                                .then(({ok, d}) => { applying=false; if(ok && d.ok){ applied=true; } else { msg = d.message || '{{ ___('Could not apply this theme.') }}'; } })
                                .catch(() => { applying=false; msg='{{ ___('Something went wrong. Please try again.') }}'; })">
                        <span x-show="!applying">{{ ___('Apply to this store') }}</span>
                        <span x-show="applying">{{ ___('Applying…') }}</span>
                    </button>
                @endif
            </div>
            <p class="ui-text-danger ui-small" x-show="msg" x-text="msg"></p>
        </div></div>
    </div>
</div>

@if($publishedReviews->isNotEmpty())
    <div class="ui-card"><div class="ui-card__body">
        <h3 class="ui-section-title">{{ ___('Reviews') }}</h3>
        @foreach($publishedReviews as $rev)
            <div class="ui-rule-top">
                <div class="ui-text-warning ui-small">{{ str_repeat('★', (int) $rev->rating) }}{{ str_repeat('☆', 5 - (int) $rev->rating) }}
                    <span class="ui-text-2">{{ $rev->user?->name }}</span></div>
                @if($rev->body)<p class="ui-small">{{ $rev->body }}</p>@endif
            </div>
        @endforeach
    </div></div>
@endif
@endsection
