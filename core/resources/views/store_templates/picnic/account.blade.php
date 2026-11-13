{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    use Illuminate\Support\Str;
    $curSign = cur_isolate($postOptions->currency_sign ?? '$');
    $curPos  = (string) currency_pos_effective($postOptions->id ?? null);
    $formatPrice = new \Juwri\Core\Support\RawPriceFormatter($curSign, $curPos, currency_decimals($postOptions->currency_code ?? null));
    $walletOn = \App\Services\FeatureAccess::storeAllows($store->id, 'customer_wallet');
    $refundWindow = (int) (store_options($store->id, 'refund_request_window_days') ?? 14);
@endphp

@push('style_at_top')
    {{-- no web-font CDN: blocked by CSP, see index.blade.php --}}
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
@endpush

@section('content')
<div class="picnic" id="picnic">
    <main class="views">
        <section class="view is-active" data-view="account">
            <div class="view-scroll">
                <header class="page-head sub">
                    <a class="fab back inline" href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" aria-label="{{ ___('Back to Menu') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg>
                    </a>
                    <h1>{{ ___('My Orders') }}</h1>
                    <a href="{{ route('customer.profile', $store->id) }}" style="display:inline-block;margin-top:6px;font-size:13px;font-weight:700;text-decoration:none;color:inherit;opacity:.7;">{{ ___('Profile & settings') }}</a>
                </header>

                <div class="me-card">
                    <span class="me-avatar">{{ Str::upper(Str::substr($customer->name, 0, 1)) }}</span>
                    <div>
                        <b>{{ $customer->name }}</b>
                        <p class="tk-label">{{ $customer->phone ?? $customer->email }}</p>
                    </div>
                    <button class="minibtn" id="cvLogoutBtn" type="button">{{ ___('Logout') }}</button>
                </div>

                                @if($walletOn)
                <section class="orders-block">
                    <h3>{{ ___('Wallet') }}</h3>
                    <div class="track-card">
                        <div class="track-top">
                            <div><p class="tk-label">{{ ___('Store credit balance') }}</p><b style="font-size:1.5rem">{{ $formatPrice($storeCredit ?? 0) }}</b></div>
                            <div class="tk-eta"><button type="button" class="ghostbtn" data-mb-wallet-open>{{ ___('Add funds') }}</button></div>
                        </div>
                    </div>
                </section>
                @endif

                <section class="orders-block">
                    <h3>{{ ___('Order History') }}</h3>
                    <p class="coupon-msg" id="cvReorderMsg" hidden></p>
                    <div class="orders-list">
                    @forelse($orders as $order)
                        <div class="track-card">
                            <div class="track-top">
                                <div><p class="tk-label">{{ $order->created_at?->format('M d, Y h:i A') }}</p><b>#{{ $order->id }}</b></div>
                                <div class="tk-eta">
                                    <span class="order-status">{{ $order->status === 'unpaid' ? ___('Payment not completed') : ___(ucfirst($order->status)) }}</span>
                                    <p class="tk-label">{{ $formatPrice($order->total) }}</p>
                                </div>
                            </div>
                            @if($order->items && $order->items->count())
                            <div class="order-lines">
                                @foreach($order->items as $item)
                                    <div class="order-line">
                                        <span>{{ $item->quantity }}× {{ $item->menu->name ?? ___('Unknown Item') }}
                                            @if($item->variation_title)<small>{{ $item->variation_title }}</small>@endif
                                            @if($item->itemExtras && $item->itemExtras->count())
                                                <small>+ {{ \Juwri\Core\Support\ViewHelpers::extraTitles($item->itemExtras) }}</small>
                                            @endif
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                            @endif
                            <div class="waiter-row">
                                @if($order->track_token)
                                    <a class="ghostbtn" href="{{ route('store.orderTrack', $order->track_token) }}">{{ ___('Track Order') }}</a>
                                @endif
                                <button class="ghostbtn js-cv-reorder" data-reorder-url="{{ route('customer.orders.reorder', [$store->id, $order->id]) }}" type="button">{{ ___('Reorder') }}</button>
                                @if($walletOn)
                                    @php($__re = $order->customerRefundEligibility($refundWindow))
                                    @if($order->refund_request_status === 'requested')
                                        <span class="mb-refund mb-refund--requested">{{ ___('Refund requested') }}</span>
                                    @elseif($order->refund_request_status === 'approved')
                                        <span class="mb-refund mb-refund--approved">{{ $order->refund_method === 'original_payment' ? ___('Refunded to card') : ___('Refunded to wallet') }}</span>
                                    @elseif($order->refund_request_status === 'rejected')
                                        <span class="mb-refund mb-refund--rejected">{{ ___('Refund declined') }}</span>
                                    @elseif($__re['eligible'])
                                        <button class="ghostbtn" data-refund-request="{{ $order->id }}" type="button">{{ ___('Request refund') }}</button>
                                    @endif
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="grid-empty">
                            <span class="empty-emoji">🧺</span>
                            <p>{{ ___('You have not placed any orders yet.') }}</p>
                            <a class="ghostbtn" href="{{ storefront_localized_link(route('publicView', $store->slug)) }}">{{ ___('View Menu') }}</a>
                        </div>
                    @endforelse
@include('store_templates._partials.orders-older', ['nextBefore' => $nextBefore ?? null])
                    </div>
                </section>
                <div class="tail-space tall"></div>
            </div>
        </section>
    </main>
    <div class="toast" id="cvToast" role="status"></div>
@if($walletOn)
    @include('store_templates._partials.mb-wallet-sheet', ['store' => $store])
    @include('store_templates._partials.mb-refund', ['store' => $store])
    <style nonce="{{ csp_nonce() }}">
        .mba-sheet{--mba-surface:var(--paper,#fff);--mba-ink:var(--ink,#17232e);--mba-muted:var(--ink-2,#525d69);--mba-border:var(--stroke-2,#dfe2e7);--mba-hair:var(--stroke-2,#dfe2e7);--mba-in-bg:var(--orange-soft,#fdeae2);--mba-accent:var(--orange,#f4581c);--mba-on-accent:var(--paper,#fff);--mba-accent-ring:color-mix(in srgb, var(--orange,#f4581c) 20%, transparent);--mba-radius:var(--r-sheet,28px)}
    </style>
@endif
</div>
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
(function () {
    "use strict";
    var csrf = @json(csrf_token());
    var slug = @json($store->slug);
    var menuUrl = @json(storefront_localized_link(route('publicView', $store->slug)));

    var logout = document.getElementById('cvLogoutBtn');
    if (logout) logout.addEventListener('click', function () {
        fetch(@json(route('customer.logout', $store->id)), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        }).then(function () { window.location.href = menuUrl; });
    });

    document.querySelectorAll('.js-cv-reorder').forEach(function (btn) {
        btn.addEventListener('click', function () {
            btn.disabled = true;
            fetch(btn.getAttribute('data-reorder-url'), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            }).then(function (r) { return r.json(); }).then(function (data) {
                btn.disabled = false;
                var msg = document.getElementById('cvReorderMsg');
                if (!data.success && (!data.items || !data.items.length)) {
                    if (msg) { msg.hidden = false; msg.className = 'coupon-msg err'; msg.textContent = data.message || 'Unable to reorder.'; }
                    return;
                }
                localStorage.setItem('cv_reorder_' + slug, JSON.stringify(data.items || []));
                window.location.href = data.redirect_url || menuUrl;
            }).catch(function () { btn.disabled = false; });
        });
    });
})();
</script>
@endpush
