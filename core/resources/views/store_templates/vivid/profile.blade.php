{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    use Illuminate\Support\Str;
    $curSign = cur_isolate($postOptions->currency_sign ?? '$');
    $curPos  = (string) currency_pos_effective($postOptions->id ?? null);
    $formatPrice = new \Juwri\Core\Support\RawPriceFormatter($curSign, $curPos, currency_decimals($postOptions->currency_code ?? null));

    /* theme-pack-blade: mint/reuse a CustomerToken for F1 (meal plans) + F2 (following) */
    $customerApiToken = null;
    if ($customer) {
        $sessKey = 'vd_customer_api_token_'.$customer->id;
        $cached  = session($sessKey);
        if ($cached) {
            $tok = \Juwri\Modules\Customers\Models\CustomerToken::where('token', hash('sha256', $cached))->first();
            if (!$tok || $tok->isExpired()) { $cached = null; }
        }
        if (!$cached) {
            $cached = \Juwri\Modules\Customers\Models\CustomerToken::issue($customer, 'vivid-storefront', 30);
            session([$sessKey => $cached]);
        }
        $customerApiToken = $cached;
    }
    $mealPlansEnabled = \Juwri\Modules\Ordering\Services\MealPlanService::enabled($store->id);
    $isFollowing      = ($customer && method_exists($store, 'isFollowedBy')) ? $store->isFollowedBy($customer) : false;
    $isVerifiedStore  = method_exists($store, 'isVerified') ? $store->isVerified() : (bool) ($store->is_verified ?? false);
@endphp

@push('style_at_top')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Fraunces:opsz,wght@9..144,400;9..144,600;9..144,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
    <style nonce="{{ csp_nonce() }}">.cv-orders-link{display:flex;align-items:center;justify-content:center;gap:9px;width:100%;margin:14px 0 2px;padding:11px 16px;border-radius:12px;text-decoration:none;color:inherit;font-weight:700;border:1px solid rgba(128,128,128,.28)}.cv-orders-link .ic{width:18px;height:18px;flex:none}</style>
@endpush

@section('content')
<div class="vd" id="vd">
    <div class="vd-mesh" aria-hidden="true">
        <span class="vd-blob vd-blob-1"></span>
        <span class="vd-blob vd-blob-2"></span>
        <span class="vd-blob vd-blob-3"></span>
    </div>

    <main class="vd-account">
        <header class="vd-account-head">
            <a class="vd-account-back" href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" aria-label="{{ ___('Back to Menu') }}">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 5l-7 7 7 7"/></svg>
            </a>
            <h1 class="vd-account-title">{{ ___('My Account') }}</h1>
        </header>

        <div class="vd-me-card">
            <span class="vd-me-avatar">{{ Str::upper(Str::substr($customer->name ?? 'G', 0, 1)) }}</span>
            <div class="vd-me-info">
                <b>{{ $customer->name }}</b>
                <small>{{ $customer->phone ?? $customer->email }}</small>
            </div>
            <button class="vd-ghostbtn" id="vd-logout-btn" type="button">{{ ___('Logout') }}</button>
        </div>

        <a class="vd-ghostbtn cv-orders-link" href="{{ route('customer.account', $store->id) }}"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2h9l3 3v17H6V2Z"/><path d="M9 8h6M9 12h6M9 16h4"/></svg><span>{{ ___('My Orders') }}</span></a>

        {{-- Native Share (replaces the retired shared floating pill); gated on the
             per-store share.fallback_button toggle, same as the 13 in-menu themes. --}}
        @if(theme_setting($store->id, 'share.fallback_button'))
        <button class="vd-ghostbtn" id="vd-share-menu" type="button" data-copied="{{ ___('Link copied') }}"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8a3 3 0 1 0-2.83-4M6 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm12 7a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM8.6 13.5l6.8 3.9M15.4 6.6L8.6 10.5"/></svg><span>{{ ___('Share this menu') }}</span></button>
        @endif

        @include('store_templates._partials.account-extras')

        @if(isset($storeCredit) && $storeCredit > 0)
        <div class="vd-credit-banner">
            <span>{{ ___('Store Credit') }}</span>
            <b>{{ $formatPrice($storeCredit) }}</b>
        </div>
        @endif

        {{-- F1 · my meal plans --}}
        @if($mealPlansEnabled)
        <section class="vd-orders" id="vd-mealplans-block">
            <h3 class="vd-orders-h3">{{ ___('My meal plans') }}</h3>
            <div id="vd-mealplans-list"><p style="color:var(--vd-muted)">{{ ___('Loading…') }}</p></div>
            <div class="vd-orders-empty" id="vd-mealplans-empty" style="display:none">
                <div style="font-size:40px">📅</div>
                <p>{{ ___('No meal plans yet. Build a cart and tap “Make this a meal plan”.') }}</p>
                <a class="vd-ghostbtn" href="{{ storefront_localized_link(route('publicView', $store->slug)) }}">{{ ___('View Menu') }}</a>
            </div>
        </section>
        @endif

        {{-- F2 · following --}}
        @if($isFollowing)
        <section class="vd-orders" id="vd-following-block">
            <h3 class="vd-orders-h3">{{ ___('Following') }}</h3>
            <div class="vd-order-card" style="display:flex;align-items:center;gap:12px">
                <div style="flex:1;min-width:0">
                    <b>{{ $store->title }}@if($isVerifiedStore) <i class="ico ico-check" style="color:var(--vd-accent)" aria-hidden="true"><x-ui.icon name="check" /></i>@endif</b>
                    <small style="display:block;color:var(--vd-muted)">{{ ___('You follow this store') }}</small>
                </div>
                <button class="vd-ghostbtn" id="vd-unfollow-btn" type="button">{{ ___('Unfollow') }}</button>
            </div>
        </section>
        @endif
    </main>
</div>
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
(function () {
    "use strict";
    var csrf = @json(csrf_token());
    var slug = @json($store->slug);
    var menuUrl = @json(storefront_localized_link(route('publicView', $store->slug)));

    var shareBtn = document.getElementById('vd-share-menu');
    if (shareBtn) shareBtn.addEventListener('click', function () {
        if (navigator.share) { navigator.share({ title: document.title, url: menuUrl }).catch(function () {}); return; }
        var span = shareBtn.querySelector('span');
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(menuUrl).then(function () {
                if (!span) return;
                var orig = span.textContent;
                span.textContent = shareBtn.getAttribute('data-copied') || orig;
                setTimeout(function () { span.textContent = orig; }, 1600);
            }).catch(function () {});
        }
    });

    var logout = document.getElementById('vd-logout-btn');
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

    var mpList = document.getElementById('vd-mealplans-list');
    function statusLabel(s) { return ({ active: @json(___('Active')), paused: @json(___('Paused')), cancelled: @json(___('Cancelled')) })[s] || s; }
    function renderPlan(p) {
        var actions = '';
        if (p.status === 'active') {
            actions = '<button class="vd-ghostbtn" data-mp="pause" data-id="'+p.id+'">'+@json(___('Pause'))+'</button>' +
                      '<button class="vd-ghostbtn" data-mp="skip" data-id="'+p.id+'">'+@json(___('Skip next'))+'</button>' +
                      '<button class="vd-ghostbtn" data-mp="cancel" data-id="'+p.id+'">'+@json(___('Cancel'))+'</button>';
        } else if (p.status === 'paused') {
            actions = '<button class="vd-ghostbtn" data-mp="resume" data-id="'+p.id+'">'+@json(___('Resume'))+'</button>' +
                      '<button class="vd-ghostbtn" data-mp="cancel" data-id="'+p.id+'">'+@json(___('Cancel'))+'</button>';
        }
        var next = p.next_run ? ('<small style="display:block;color:var(--vd-muted)">'+@json(___('Next order'))+': <span dir="ltr">'+esc(p.next_run)+'</span></small>') : '';
        return '<div class="vd-order-card" data-plan="'+p.id+'" style="margin-bottom:12px">' +
            '<div class="vd-order-top"><div><b>'+esc(p.name || p.cadence)+'</b><small style="display:block;color:var(--vd-muted)">'+esc(p.cadence)+'</small></div>' +
            '<span class="vd-order-status">'+esc(statusLabel(p.status))+'</span></div>' + next +
            (actions ? '<div class="vd-order-actions" style="margin-top:8px">'+actions+'</div>' : '') + '</div>';
    }
    function loadPlans() {
        if (!mpList) return;
        fetch(EP.mealPlan, { headers: th(false) }).then(function (r) { return r.json(); }).then(function (d) {
            var plans = (d && d.data) || [];
            var empty = document.getElementById('vd-mealplans-empty');
            if (!plans.length) { mpList.innerHTML = ''; if (empty) empty.style.display = ''; return; }
            if (empty) empty.style.display = 'none';
            mpList.innerHTML = plans.map(renderPlan).join('');
        }).catch(function () { mpList.innerHTML = '<p style="color:var(--vd-muted)">'+@json(___('Could not load plans.'))+'</p>'; });
    }
    if (mpList) {
        loadPlans();
        mpList.addEventListener('click', function (e) {
            var b = e.target.closest('[data-mp]'); if (!b) return;
            var action = b.getAttribute('data-mp'), id = b.getAttribute('data-id');
            if (action === 'cancel' && !window.confirm(@json(___('Cancel this meal plan?')))) return;
            b.disabled = true;
            fetch(EP.mealPlan + '/' + id + '/' + action, { method: 'POST', headers: th(true), body: '{}' })
                .then(function (r) { return r.json(); }).then(function () { loadPlans(); })
                .catch(function () { b.disabled = false; });
        });
    }

    var unfollow = document.getElementById('vd-unfollow-btn');
    if (unfollow) unfollow.addEventListener('click', function () {
        unfollow.disabled = true;
        fetch(EP.follow, { method: 'DELETE', headers: th(true), body: '{}' })
            .then(function (r) { return r.json(); }).then(function (d) {
                if (d && d.following === false) { var blk = document.getElementById('vd-following-block'); if (blk) blk.style.display = 'none'; }
                else { unfollow.disabled = false; }
            }).catch(function () { unfollow.disabled = false; });
    });
})();
</script>
@endpush
