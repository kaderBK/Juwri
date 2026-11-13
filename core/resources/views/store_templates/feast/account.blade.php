{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    use Illuminate\Support\Str;
    $curSign = cur_isolate($postOptions->currency_sign ?? '$');
    $curPos  = (string) currency_pos_effective($postOptions->id ?? null);
    $curDecimals = currency_decimals($postOptions->currency_code ?? null);
    $formatPrice = new \Juwri\Core\Support\TrimmedPriceFormatter($curSign, $curPos, $curDecimals);

    /* theme-pack-blade: mint/reuse a CustomerToken for F1 (meal plans) + F2 (following) API calls */
    $customerApiToken = null;
    if ($customer) {
        $sessKey = 'cv_customer_api_token_'.$customer->id;
        $cached  = session($sessKey);
        if ($cached) {
            $tok = \Juwri\Modules\Customers\Models\CustomerToken::where('token', hash('sha256', $cached))->first();
            if (!$tok || $tok->isExpired()) { $cached = null; }
        }
        if (!$cached) {
            $cached = \Juwri\Modules\Customers\Models\CustomerToken::issue($customer, 'feast-storefront', 30);
            session([$sessKey => $cached]);
        }
        $customerApiToken = $cached;
    }
    $mealPlansEnabled = \Juwri\Modules\Ordering\Services\MealPlanService::enabled($store->id);
    $isFollowing = ($customer && method_exists($store, 'isFollowedBy')) ? $store->isFollowedBy($customer) : false;
    $isVerifiedStore = method_exists($store, 'isVerified') ? $store->isVerified() : (bool) ($store->is_verified ?? false);
    $walletOn = \App\Services\FeatureAccess::storeAllows($store->id, 'customer_wallet');
    $refundWindow = (int) (store_options($store->id, 'refund_request_window_days') ?? 14);
@endphp

@push('style_at_top')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
    {{-- FEAST-F5 · apply the stored appearance BEFORE first paint so a dark-face
         customer never gets a white flash. Kept inline and tiny on purpose. --}}
    <script nonce="{{ csp_nonce() }}">
        (function () {
            try {
                var f = localStorage.getItem('ff_face_' + @json($store->id));
                if (f === 'dark' || f === 'light') document.documentElement.setAttribute('data-ff-face', f);
            } catch (e) {}
            /* FEAST-F16 · marks that JS is alive, which is what arms the
               scroll-reveal. Without it the menu renders plainly instead of
               staying at opacity 0 forever. */
            document.documentElement.classList.add('ff-js');
        })();
    </script>
@endpush

@section('content')
<div id="feast-app" class="feast-container {{ current_language()->direction === 'rtl' ? 'rtl-dir' : 'ltr-dir' }}">
    <div class="ff-ambient-1"></div>
    <div class="ff-ambient-2"></div>

    <main class="ff-account-page">
        <header class="ff-account-page-head">
            <a class="ff-account-back" href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" aria-label="{{ ___('Back to Menu') }}">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M15 5l-7 7 7 7"/></svg>
            </a>
            <h1 class="ff-account-page-title">{{ ___('My Orders') }}</h1>
            <a href="{{ route('customer.profile', $store->id) }}" style="display:inline-block;margin-top:6px;font-size:13px;font-weight:700;text-decoration:none;color:inherit;opacity:.7;">{{ ___('Profile & settings') }}</a>
        </header>

        <div class="ff-account-hello ff-account-hello-page">
            <span class="ff-account-avatar ff-account-avatar-lg">{{ Str::upper(Str::substr($customer->name ?? 'G', 0, 1)) }}</span>
            <div>
                <b style="font-family:var(--ff-font-headings);font-size:18px">{{ $customer->name }}</b>
                <div style="color:var(--ff-text-muted);font-size:13px">{{ $customer->phone ?? $customer->email }}</div>
            </div>
            <button class="ff-account-logout" id="ff-logout-btn" style="width:auto;margin:0;padding:8px 16px">{{ ___('Logout') }}</button>
        </div>

        @if($walletOn)
        <div class="ff-credit-banner">
            <div><span>{{ ___('Store Credit') }}</span> <b>{{ $formatPrice($storeCredit ?? 0) }}</b></div>
            <button type="button" class="ff-ghostbtn" data-mb-wallet-open>{{ ___('Add funds') }}</button>
        </div>
        @endif

        {{-- F1 · my meal plans --}}
        @if($mealPlansEnabled)
        <section class="ff-orders-block" id="cvMealPlansBlock">
            <h3 class="ff-orders-h3">{{ ___('My meal plans') }}</h3>
            <div id="cvMealPlansList"><p style="color:var(--ff-text-muted);font-size:13px">{{ ___('Loading…') }}</p></div>
            <div id="cvMealPlansEmpty" hidden class="ff-orders-empty" style="padding:24px 0">
                <p>{{ ___('No meal plans yet. Build a cart and tap “Make this a meal plan”.') }}</p>
                <a class="ff-ghostbtn" href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" data-tc-text="texts.view_menu">{{ theme_setting($store->id, 'texts.view_menu', ___('View Menu')) }}</a>
            </div>
        </section>
        @endif

        {{-- F2 · following (per-restaurant customer → this brand) --}}
        @if($isFollowing)
        <section class="ff-orders-block" id="cvFollowingBlock">
            <h3 class="ff-orders-h3">{{ ___('Following') }}</h3>
            <div class="ff-order-card" id="cvFollowingRow" style="display:flex;align-items:center;gap:12px">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--ff-accent)" stroke-width="1.8"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                <div style="flex:1">
                    <b class="ff-order-id">{{ $store->title }}@if($isVerifiedStore) ✓@endif</b>
                    <small style="display:block;color:var(--ff-text-muted);font-size:12px">{{ ___('You follow this store') }}</small>
                </div>
                <button class="ff-ghostbtn" id="cvUnfollowBtn" type="button">{{ ___('Unfollow') }}</button>
            </div>
        </section>
        @endif

        <section class="ff-orders-block">
            <h3 class="ff-orders-h3">{{ ___('Order History') }}</h3>
            <p class="ff-reorder-msg" id="ff-reorder-msg" style="display:none"></p>
            @forelse($orders as $order)
                <div class="ff-order-card">
                    <div class="ff-order-top">
                        <div>
                            <p class="ff-order-date">{{ optional($order->created_at)->format('M d, Y h:i A') ?? '—' }}</p>
                            <b class="ff-order-id">#{{ $order->id }}</b>
                        </div>
                        <div style="text-align:end">
                            <span class="ff-order-status">{{ $order->status === 'unpaid' ? ___('Payment not completed') : ___(ucfirst($order->status)) }}</span>
                            <p class="ff-order-total">{{ $formatPrice($order->total) }}</p>
                        </div>
                    </div>
                    @if($order->items && $order->items->count())
                    <div class="ff-order-lines">
                        @foreach($order->items as $item)
                            <div class="ff-order-line">
                                {{ $item->quantity }}× {{ $item->menu->name ?? ___('Unknown Item') }}
                                @if($item->variation_title)<small>{{ $item->variation_title }}</small>@endif
                                @if($item->itemExtras && $item->itemExtras->count())
                                    <small>+ {{ \Juwri\Core\Support\ViewHelpers::extraTitles($item->itemExtras) }}</small>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @endif
                    <div class="ff-order-actions">
                        @if($order->track_token)
                            <a class="ff-ghostbtn" href="{{ route('store.orderTrack', $order->track_token) }}">{{ ___('Track Order') }}</a>
                        @endif
                        <button class="ff-ghostbtn js-ff-reorder" data-reorder-url="{{ route('customer.orders.reorder', [$store->id, $order->id]) }}" type="button">{{ ___('Reorder') }}</button>
                        @if($walletOn)
                            @php($__re = $order->customerRefundEligibility($refundWindow))
                            @if($order->refund_request_status === 'requested')
                                <span class="mb-refund mb-refund--requested">{{ ___('Refund requested') }}</span>
                            @elseif($order->refund_request_status === 'approved')
                                <span class="mb-refund mb-refund--approved">{{ $order->refund_method === 'original_payment' ? ___('Refunded to card') : ___('Refunded to wallet') }}</span>
                            @elseif($order->refund_request_status === 'rejected')
                                <span class="mb-refund mb-refund--rejected">{{ ___('Refund declined') }}</span>
                            @elseif($__re['eligible'])
                                <button class="ff-ghostbtn" data-refund-request="{{ $order->id }}" type="button">{{ ___('Request refund') }}</button>
                            @endif
                        @endif
                    </div>
                </div>
            @empty
                <div class="ff-orders-empty">
                    <div style="font-size:44px;opacity:.5">🍽</div>
                    <p>{{ ___('You have not placed any orders yet.') }}</p>
                    <a class="ff-ghostbtn" href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" data-tc-text="texts.view_menu">{{ theme_setting($store->id, 'texts.view_menu', ___('View Menu')) }}</a>
                </div>
            @endforelse
@include('store_templates._partials.orders-older', ['nextBefore' => $nextBefore ?? null])
        </section>
    </main>
@if($walletOn)
    @include('store_templates._partials.mb-wallet-sheet', ['store' => $store])
    @include('store_templates._partials.mb-refund', ['store' => $store])
    <style nonce="{{ csp_nonce() }}">
        .mba-sheet{--mba-surface:var(--ff-bg-sheet,#fff);--mba-ink:var(--ff-ink,#14161c);--mba-muted:var(--ff-muted,#6b7280);--mba-border:var(--ff-line,#e6e8ee);--mba-hair:var(--ff-line,#e6e8ee);--mba-in-bg:var(--ff-surface-2,#eef0f4);--mba-accent:var(--ff-accent,#e0592b);--mba-on-accent:var(--ff-accent-ink,#fff);--mba-accent-ring:color-mix(in srgb, var(--ff-accent,#e0592b) 20%, transparent);--mba-radius:var(--ff-r-lg,22px)}
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

    var logout = document.getElementById('ff-logout-btn');
    if (logout) logout.addEventListener('click', function () {
        fetch(@json(route('customer.logout', $store->id)), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        }).then(function () { window.location.href = menuUrl; });
    });

    /* theme-pack-blade F1 (meal plans) + F2 (unfollow) */
    var TOKEN = @json($customerApiToken);
    var EP = {
        mealPlan: @json(url('customer/meal-plan')),
        follow: @json(url('r/'.$store->slug.'/follow')),
    };
    function th(json) {
        var h = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf };
        if (json) h['Content-Type'] = 'application/json';
        if (TOKEN) h['Authorization'] = 'Bearer ' + TOKEN;
        return h;
    }
    function esc(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    var mpList = document.getElementById('cvMealPlansList');
    function statusLabel(s) { return ({ active: @json(___('Active')), paused: @json(___('Paused')), cancelled: @json(___('Cancelled')) })[s] || s; }
    function renderPlan(p) {
        var actions = '';
        if (p.status === 'active') {
            actions = '<button class="ff-ghostbtn" data-mp="pause" data-id="'+p.id+'">'+@json(___('Pause'))+'</button>' +
                      '<button class="ff-ghostbtn" data-mp="skip" data-id="'+p.id+'">'+@json(___('Skip next'))+'</button>' +
                      '<button class="ff-ghostbtn" data-mp="cancel" data-id="'+p.id+'">'+@json(___('Cancel'))+'</button>';
        } else if (p.status === 'paused') {
            actions = '<button class="ff-ghostbtn" data-mp="resume" data-id="'+p.id+'">'+@json(___('Resume'))+'</button>' +
                      '<button class="ff-ghostbtn" data-mp="cancel" data-id="'+p.id+'">'+@json(___('Cancel'))+'</button>';
        }
        var next = p.next_run ? ('<small style="display:block;color:var(--ff-text-muted);font-size:12px;margin-top:4px">'+@json(___('Next order'))+': <span dir="ltr">'+esc(p.next_run)+'</span></small>') : '';
        return '<div class="ff-order-card" data-plan="'+p.id+'" style="margin-bottom:12px">' +
            '<div class="ff-order-top"><div><b class="ff-order-id">'+esc(p.name || p.cadence)+'</b>' +
            '<p class="ff-order-date">'+esc(p.cadence)+'</p></div><span class="ff-order-status">'+esc(statusLabel(p.status))+'</span></div>' +
            next + (actions ? '<div class="ff-order-actions" style="margin-top:8px">'+actions+'</div>' : '') + '</div>';
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
            .catch(function () { mpList.innerHTML = '<p style="color:var(--ff-text-muted);font-size:13px">'+@json(___('Could not load plans.'))+'</p>'; });
    }
    if (mpList) {
        loadPlans();
        mpList.addEventListener('click', function (e) {
            var b = e.target.closest('[data-mp]'); if (!b) return;
            var action = b.getAttribute('data-mp'), id = b.getAttribute('data-id');
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

    document.querySelectorAll('.js-ff-reorder').forEach(function (btn) {
        btn.addEventListener('click', function () {
            btn.disabled = true;
            fetch(btn.getAttribute('data-reorder-url'), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            }).then(function (r) { return r.json(); }).then(function (data) {
                btn.disabled = false;
                var msg = document.getElementById('ff-reorder-msg');
                if (!data.success && (!data.items || !data.items.length)) {
                    if (msg) { msg.style.display = 'block'; msg.textContent = data.message || 'Unable to reorder.'; }
                    return;
                }
                localStorage.setItem('ff_reorder_' + slug, JSON.stringify(data.items || []));
                window.location.href = data.redirect_url || menuUrl;
            }).catch(function () { btn.disabled = false; });
        });
    });
})();
</script>
@endpush
