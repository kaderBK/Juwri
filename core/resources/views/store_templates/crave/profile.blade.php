{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    use Illuminate\Support\Str;
    $curSign = cur_isolate($postOptions->currency_sign ?? '$');
    $curPos  = (string) currency_pos_effective($postOptions->id ?? null);
    $curDecimals = currency_decimals($postOptions->currency_code ?? null);
    $formatPrice = new \Juwri\Core\Support\PriceFormatter((string) $curSign, (string) $curPos, (int) $curDecimals);

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
            $cached = \Juwri\Modules\Customers\Models\CustomerToken::issue($customer, 'crave-storefront', 30);
            session([$sessKey => $cached]);
        }
        $customerApiToken = $cached;
    }
    $mealPlansEnabled = \Juwri\Modules\Ordering\Services\MealPlanService::enabled($store->id);
    /* per-restaurant customers → "Following" is this brand's follow state */
    $isFollowing = ($customer && method_exists($store, 'isFollowedBy')) ? $store->isFollowedBy($customer) : false;
    $isVerifiedStore = method_exists($store, 'isVerified') ? $store->isVerified() : (bool) ($store->is_verified ?? false);
@endphp

@push('style_at_top')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
    <style nonce="{{ csp_nonce() }}">.cv-orders-link{display:flex;align-items:center;justify-content:center;gap:9px;width:100%;margin:14px 0 2px}.cv-orders-link .ic{width:18px;height:18px;flex:none}</style>
@endpush

@section('content')
<div class="crave" id="crave">
    <main class="cv-views">
        <section class="view is-active" data-view="profile">
            <div class="view-scroll">
                <header class="page-head sub">
                    <a class="fab back inline" href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" aria-label="{{ ___('Back to Menu') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg>
                    </a>
                    <h1>{{ ___('My Account') }}</h1>
                </header>

                <div class="me-card">
                    <span class="me-avatar">{{ Str::upper(Str::substr($customer->name, 0, 1)) }}</span>
                    <div>
                        <b>{{ $customer->name }}</b>
                        <p class="tk-label">{{ $customer->phone ?? $customer->email }}</p>
                    </div>
                    <button class="minibtn" id="cvLogoutBtn" type="button">{{ ___('Logout') }}</button>
                </div>

                <a class="ghostbtn cv-orders-link" href="{{ route('customer.account', $store->id) }}"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2h9l3 3v17H6V2Z"/><path d="M9 8h6M9 12h6M9 16h4"/></svg><span>{{ ___('My Orders') }}</span></a>

                @include('store_templates._partials.account-extras')

                {{-- F1 · my meal plans --}}
                @if($mealPlansEnabled)
                <section class="orders-block" id="cvMealPlansBlock">
                    <h3 class="cv-h3">{{ ___('My meal plans') }}</h3>
                    <div class="orders-list" id="cvMealPlansList">
                        <p class="tk-label">{{ ___('Loading…') }}</p>
                    </div>
                    <div class="grid-empty" id="cvMealPlansEmpty" hidden style="padding:20px 0">
                        <p>{{ ___('No meal plans yet. Build a cart and tap “Make this a meal plan”.') }}</p>
                        <a class="ghostbtn" href="{{ storefront_localized_link(route('publicView', $store->slug)) }}">{{ ___('View Menu') }}</a>
                    </div>
                </section>
                @endif

                {{-- F2 · following (per-restaurant customer → this brand) --}}
                @if($isFollowing)
                <section class="orders-block" id="cvFollowingBlock">
                    <h3 class="cv-h3">{{ ___('Following') }}</h3>
                    <div class="orders-list">
                        <div class="order-row" id="cvFollowingRow">
                            <svg class="ic" viewBox="0 0 24 24"><path d="M12 21s-7-5.7-7-11a7 7 0 1 1 14 0c0 5.3-7 11-7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                            <div><b>{{ $store->title }}@if($isVerifiedStore) ✓@endif</b><small>{{ ___('You follow this store') }}</small></div>
                            <button class="minibtn" id="cvUnfollowBtn" type="button">{{ ___('Unfollow') }}</button>
                        </div>
                    </div>
                </section>
                @endif

                {{-- The owner's Terms / Privacy / About / Help — public pages, shown
                     here exactly as they are to a guest on the in-app profile view. --}}
                <section class="orders-block">
                    <h3 class="cv-h3">{{ ___('Information') }}</h3>
                    @include('store_templates._partials.info-links-inline')
                </section>

                <div class="tail-space tall"></div>
            </div>
        </section>
    </main>
    <div class="toast" id="cvToast" role="status"></div>
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

    /* F1 · meal plans list + actions */
    var mpList = document.getElementById('cvMealPlansList');
    function statusLabel(s) {
        return ({ active: @json(___('Active')), paused: @json(___('Paused')), cancelled: @json(___('Cancelled')) })[s] || s;
    }
    function renderPlan(p) {
        var actions = '';
        if (p.status === 'active') {
            actions = '<button class="ghostbtn sm" data-mp="pause" data-id="'+p.id+'">'+@json(___('Pause'))+'</button>' +
                      '<button class="ghostbtn sm" data-mp="skip" data-id="'+p.id+'">'+@json(___('Skip next'))+'</button>' +
                      '<button class="ghostbtn sm" data-mp="cancel" data-id="'+p.id+'">'+@json(___('Cancel'))+'</button>';
        } else if (p.status === 'paused') {
            actions = '<button class="ghostbtn sm" data-mp="resume" data-id="'+p.id+'">'+@json(___('Resume'))+'</button>' +
                      '<button class="ghostbtn sm" data-mp="cancel" data-id="'+p.id+'">'+@json(___('Cancel'))+'</button>';
        }
        var next = p.next_run ? ('<small>'+@json(___('Next order'))+': <span dir="ltr">'+esc(p.next_run)+'</span></small>') : '';
        var cad = esc(p.name || p.cadence);
        return '<div class="track-card" style="margin-bottom:12px" data-plan="'+p.id+'">' +
            '<div class="track-top"><div><b>'+cad+'</b><p class="tk-label">'+esc(p.cadence)+'</p></div>' +
            '<span class="order-status">'+esc(statusLabel(p.status))+'</span></div>' +
            '<div>'+next+'</div>' +
            (actions ? '<div class="waiter-row" style="margin-top:8px">'+actions+'</div>' : '') + '</div>';
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
            .catch(function () { mpList.innerHTML = '<p class="tk-label">'+@json(___('Could not load plans.'))+'</p>'; });
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

    /* F2 · unfollow */
    var unfollow = document.getElementById('cvUnfollowBtn');
    if (unfollow) unfollow.addEventListener('click', function () {
        unfollow.disabled = true;
        fetch(EP.follow, { method: 'DELETE', headers: th(true), body: '{}' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.following === false) {
                    var blk = document.getElementById('cvFollowingBlock');
                    if (blk) blk.hidden = true;
                } else { unfollow.disabled = false; }
            })
            .catch(function () { unfollow.disabled = false; });
    });
})();
</script>
@endpush
