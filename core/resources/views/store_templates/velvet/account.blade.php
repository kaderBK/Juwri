{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    use Illuminate\Support\Str;
    $curSign = cur_isolate($postOptions->currency_sign ?? '$');
    $curPos  = (string) currency_pos_effective($postOptions->id ?? null);
    $curDecimals = currency_decimals($postOptions->currency_code ?? null);
    $formatPrice = new \Juwri\Core\Support\TrimmedPriceFormatter($curSign, $curPos, $curDecimals);

    /* theme-pack-blade: mint/reuse a CustomerToken for F1 (meal plans) + F2 (following) */
    $customerApiToken = null;
    if ($customer) {
        $sessKey = 'cv_customer_api_token_'.$customer->id;
        $cached  = session($sessKey);
        if ($cached) {
            $tok = \Juwri\Modules\Customers\Models\CustomerToken::where('token', hash('sha256', $cached))->first();
            if (!$tok || $tok->isExpired()) { $cached = null; }
        }
        if (!$cached) {
            $cached = \Juwri\Modules\Customers\Models\CustomerToken::issue($customer, 'velvet-storefront', 30);
            session([$sessKey => $cached]);
        }
        $customerApiToken = $cached;
    }
    $mealPlansEnabled = \Juwri\Modules\Ordering\Services\MealPlanService::enabled($store->id);
    $isFollowing     = ($customer && method_exists($store, 'isFollowedBy')) ? $store->isFollowedBy($customer) : false;
    $isVerifiedStore = method_exists($store, 'isVerified') ? $store->isVerified() : (bool) ($store->is_verified ?? false);
    $walletOn = \App\Services\FeatureAccess::storeAllows($store->id, 'customer_wallet');
    $refundWindow = (int) (store_options($store->id, 'refund_request_window_days') ?? 14);
@endphp

@push('style_at_top')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
@endpush

@section('content')
<div id="velvet-app" class="velvet-container {{ current_language()->direction === 'rtl' ? 'rtl-dir' : 'ltr-dir' }}">
    <div class="v-ambient-1"></div>
    <div class="v-ambient-2"></div>

    <main class="v-account-page">
        <header class="v-account-page-head">
            <a class="v-account-back" href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" aria-label="{{ ___('Back to Menu') }}">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M15 5l-7 7 7 7"/></svg>
            </a>
            <h1 class="v-account-page-title">{{ ___('My Orders') }}</h1>
            <a href="{{ route('customer.profile', $store->id) }}" style="display:inline-block;margin-top:6px;font-size:13px;font-weight:700;text-decoration:none;color:inherit;opacity:.7;">{{ ___('Profile & settings') }}</a>
        </header>

        <div class="v-account-hello v-account-hello-page">
            <span class="v-account-avatar v-account-avatar-lg">{{ Str::upper(Str::substr($customer->name ?? 'G', 0, 1)) }}</span>
            <div>
                <b style="font-family:var(--v-font-headings);font-size:18px">{{ $customer->name }}</b>
                <div style="color:var(--v-text-muted);font-size:13px">{{ $customer->phone ?? $customer->email }}</div>
            </div>
            <button class="v-account-logout" id="v-logout-btn" style="width:auto;margin:0;padding:8px 16px">{{ ___('Logout') }}</button>
        </div>

        @if($walletOn)
        <div class="v-credit-banner">
            <div><span>{{ ___('Store Credit') }}</span> <b>{{ $formatPrice($storeCredit ?? 0) }}</b></div>
            <button type="button" class="v-ghostbtn" data-mb-wallet-open>{{ ___('Add funds') }}</button>
        </div>
        @endif

        {{-- F1 · my meal plans --}}
        @if($mealPlansEnabled)
        <section class="v-orders-block" id="cvMealPlansBlock">
            <h3 class="v-orders-h3">{{ ___('My meal plans') }}</h3>
            <div id="cvMealPlansList"><p style="color:var(--v-text-muted)">{{ ___('Loading…') }}</p></div>
            <div class="v-orders-empty" id="cvMealPlansEmpty" hidden>
                <p>{{ ___('No meal plans yet. Build a cart and tap “Make this a meal plan”.') }}</p>
                <a class="v-ghostbtn" href="{{ storefront_localized_link(route('publicView', $store->slug)) }}">{{ ___('View Menu') }}</a>
            </div>
        </section>
        @endif

        {{-- F2 · following (per-restaurant customer → this brand) --}}
        @if($isFollowing)
        <section class="v-orders-block" id="cvFollowingBlock">
            <h3 class="v-orders-h3">{{ ___('Following') }}</h3>
            <div class="v-order-card" id="cvFollowingRow" style="flex-direction:row;align-items:center;justify-content:space-between;gap:12px">
                <div>
                    <b class="v-order-id">{{ $store->title }}@if($isVerifiedStore) ✓@endif</b>
                    <p class="v-order-date">{{ ___('You follow this store') }}</p>
                </div>
                <button class="v-ghostbtn" id="cvUnfollowBtn" type="button" style="margin:0">{{ ___('Unfollow') }}</button>
            </div>
        </section>
        @endif

        <section class="v-orders-block">
            <h3 class="v-orders-h3">{{ ___('Order History') }}</h3>
            <p class="v-reorder-msg" id="v-reorder-msg" style="display:none"></p>
            @forelse($orders as $order)
                <div class="v-order-card">
                    <div class="v-order-top">
                        <div>
                            <p class="v-order-date">{{ optional($order->created_at)->format('M d, Y h:i A') ?? '—' }}</p>
                            <b class="v-order-id">#{{ $order->id }}</b>
                        </div>
                        <div style="text-align:end">
                            <span class="v-order-status">{{ ___(ucfirst($order->status)) }}</span>
                            <p class="v-order-total">{{ $formatPrice($order->total) }}</p>
                        </div>
                    </div>
                    @if($order->items && $order->items->count())
                    <div class="v-order-lines">
                        @foreach($order->items as $item)
                            <div class="v-order-line">
                                {{ $item->quantity }}× {{ $item->menu->name ?? ___('Unknown Item') }}
                                @if($item->variation_title)<small>{{ $item->variation_title }}</small>@endif
                                @if($item->itemExtras && $item->itemExtras->count())
                                    <small>+ {{ \Juwri\Core\Support\ViewHelpers::extraTitles($item->itemExtras) }}</small>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @endif
                    <div class="v-order-actions">
                        @if($order->track_token)
                            <a class="v-ghostbtn" href="{{ route('store.orderTrack', $order->track_token) }}">{{ ___('Track Order') }}</a>
                        @endif
                        <button class="v-ghostbtn js-v-reorder" data-reorder-url="{{ route('customer.orders.reorder', [$store->id, $order->id]) }}" type="button">{{ ___('Reorder') }}</button>
                        @if($walletOn)
                            @php($__re = $order->customerRefundEligibility($refundWindow))
                            @if($order->refund_request_status === 'requested')
                                <span class="mb-refund mb-refund--requested">{{ ___('Refund requested') }}</span>
                            @elseif($order->refund_request_status === 'approved')
                                <span class="mb-refund mb-refund--approved">{{ $order->refund_method === 'original_payment' ? ___('Refunded to card') : ___('Refunded to wallet') }}</span>
                            @elseif($order->refund_request_status === 'rejected')
                                <span class="mb-refund mb-refund--rejected">{{ ___('Refund declined') }}</span>
                            @elseif($__re['eligible'])
                                <button class="v-ghostbtn" data-refund-request="{{ $order->id }}" type="button">{{ ___('Request refund') }}</button>
                            @endif
                        @endif
                    </div>
                </div>
            @empty
                <div class="v-orders-empty">
                    <div style="font-size:44px;opacity:.5">🍽</div>
                    <p>{{ ___('You have not placed any orders yet.') }}</p>
                    <a class="v-ghostbtn" href="{{ storefront_localized_link(route('publicView', $store->slug)) }}">{{ ___('View Menu') }}</a>
                </div>
            @endforelse
        </section>
    </main>
@if($walletOn)
    @include('store_templates._partials.mb-wallet-sheet', ['store' => $store])
    @include('store_templates._partials.mb-refund', ['store' => $store])
    <style nonce="{{ csp_nonce() }}">
        .mba-sheet{--mba-surface:var(--v-bg-sheet,#1a1814);--mba-ink:var(--v-text-primary,#f5f4f0);--mba-muted:var(--v-text-muted,#a3a097);--mba-border:var(--v-hairline,rgba(212,175,55,.15));--mba-hair:var(--v-hairline,rgba(212,175,55,.15));--mba-in-bg:var(--v-bg-card,#161512);--mba-accent:var(--v-accent,#D4AF37);--mba-on-accent:#0f0e0c;--mba-accent-ring:color-mix(in srgb, var(--v-accent,#D4AF37) 20%, transparent);--mba-radius:16px}
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

    var logout = document.getElementById('v-logout-btn');
    if (logout) logout.addEventListener('click', function () {
        fetch(@json(route('customer.logout', $store->id)), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        }).then(function () { window.location.href = menuUrl; });
    });

    /* theme-pack-blade F1 (meal plans) + F2 (unfollow) */
    var TOKEN = @json($customerApiToken);
    var EP = { mealPlan: @json(url('customer/meal-plan')), follow: @json(url('r/'.$store->slug.'/follow')) };
    function th(json) {
        var h = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf };
        if (json) h['Content-Type'] = 'application/json';
        if (TOKEN) h['Authorization'] = 'Bearer ' + TOKEN;
        return h;
    }
    function esc(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    var mpList = document.getElementById('cvMealPlansList');
    function statusLabel(s) {
        return ({ active: @json(___('Active')), paused: @json(___('Paused')), cancelled: @json(___('Cancelled')) })[s] || s;
    }
    function renderPlan(p) {
        var actions = '';
        if (p.status === 'active') {
            actions = '<button class="v-ghostbtn" data-mp="pause" data-id="'+p.id+'">'+@json(___('Pause'))+'</button>' +
                      '<button class="v-ghostbtn" data-mp="skip" data-id="'+p.id+'">'+@json(___('Skip next'))+'</button>' +
                      '<button class="v-ghostbtn" data-mp="cancel" data-id="'+p.id+'">'+@json(___('Cancel'))+'</button>';
        } else if (p.status === 'paused') {
            actions = '<button class="v-ghostbtn" data-mp="resume" data-id="'+p.id+'">'+@json(___('Resume'))+'</button>' +
                      '<button class="v-ghostbtn" data-mp="cancel" data-id="'+p.id+'">'+@json(___('Cancel'))+'</button>';
        }
        var next = p.next_run ? ('<small style="color:var(--v-text-muted)">'+@json(___('Next order'))+': <span dir="ltr">'+esc(p.next_run)+'</span></small>') : '';
        return '<div class="v-order-card" data-plan="'+p.id+'">' +
            '<div class="v-order-top"><div><b class="v-order-id">'+esc(p.name || p.cadence)+'</b><p class="v-order-date">'+esc(p.cadence)+'</p></div>' +
            '<span class="v-order-status">'+esc(statusLabel(p.status))+'</span></div>' +
            '<div class="v-order-lines">'+next+'</div>' +
            (actions ? '<div class="v-order-actions">'+actions+'</div>' : '') + '</div>';
    }
    function loadPlans() {
        if (!mpList) return;
        fetch(EP.mealPlan, { headers: th(false) })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var plans = (d && d.data) || [];
                var empty = document.getElementById('cvMealPlansEmpty');
                if (!plans.length) { mpList.innerHTML = ''; if (empty) empty.hidden = false; return; }
                if (empty) empty.hidden = true;
                mpList.innerHTML = plans.map(renderPlan).join('');
            })
            .catch(function () { mpList.innerHTML = '<p style="color:var(--v-text-muted)">'+@json(___('Could not load plans.'))+'</p>'; });
    }
    if (mpList) {
        loadPlans();
        mpList.addEventListener('click', function (e) {
            var b = e.target.closest('[data-mp]'); if (!b) return;
            var action = b.dataset.mp, id = b.dataset.id;
            if (action === 'cancel' && !window.confirm(@json(___('Cancel this meal plan?')))) return;
            b.disabled = true;
            fetch(EP.mealPlan + '/' + id + '/' + action, { method: 'POST', headers: th(true), body: '{}' })
                .then(function (r) { return r.json(); })
                .then(function () { loadPlans(); })
                .catch(function () { b.disabled = false; });
        });
    }

    var unfollow = document.getElementById('cvUnfollowBtn');
    if (unfollow) unfollow.addEventListener('click', function () {
        unfollow.disabled = true;
        fetch(EP.follow, { method: 'DELETE', headers: th(true), body: '{}' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.following === false) {
                    var blk = document.getElementById('cvFollowingBlock');
                    if (blk) blk.style.display = 'none';
                } else { unfollow.disabled = false; }
            })
            .catch(function () { unfollow.disabled = false; });
    });

    document.querySelectorAll('.js-v-reorder').forEach(function (btn) {
        btn.addEventListener('click', function () {
            btn.disabled = true;
            fetch(btn.getAttribute('data-reorder-url'), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            }).then(function (r) { return r.json(); }).then(function (data) {
                btn.disabled = false;
                var msg = document.getElementById('v-reorder-msg');
                if (!data.success && (!data.items || !data.items.length)) {
                    if (msg) { msg.style.display = 'block'; msg.textContent = data.message || 'Unable to reorder.'; }
                    return;
                }
                localStorage.setItem('v_reorder_' + slug, JSON.stringify(data.items || []));
                window.location.href = data.redirect_url || menuUrl;
            }).catch(function () { btn.disabled = false; });
        });
    });
})();
</script>
@endpush
