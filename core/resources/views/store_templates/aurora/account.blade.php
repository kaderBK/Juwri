{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    $curSign = cur_isolate($postOptions->currency_sign ?? '$');
    $curPos  = (string) currency_pos_effective($postOptions->id ?? null);
    if (!function_exists('moneyFormat')) {
        /* $dp comes from the STORE's currency: this page is not rendered through
           StoreController@publicView, so config('settings.currency_code') is the
           platform's, not the store's. Concatenating the raw float here printed
           artifacts like 1.7999999523162842 verbatim. */
        function moneyFormat($amount, $curSign, $curPos, $dp = 2) {
            $str = number_format((float) $amount, $dp, '.', '');
            return $curPos === '1' ? ($curSign . $str) : ($str . $curSign);
        }
    }

    /* theme-pack-blade: mint/reuse a CustomerToken for F1 (meal plans) + F2 (following) API calls */
    $customerApiToken = null;
    if ($customer) {
        $sessKey = 'au_customer_api_token_'.$customer->id;
        $cached  = session($sessKey);
        if ($cached) {
            $tok = \Juwri\Modules\Customers\Models\CustomerToken::where('token', hash('sha256', $cached))->first();
            if (!$tok || $tok->isExpired()) { $cached = null; }
        }
        if (!$cached) {
            $cached = \Juwri\Modules\Customers\Models\CustomerToken::issue($customer, 'aurora-storefront', 30);
            session([$sessKey => $cached]);
        }
        $customerApiToken = $cached;
    }
    $mealPlansEnabled = \Juwri\Modules\Ordering\Services\MealPlanService::enabled($store->id);
    // Customer wallet (store credit) + refund-request wiring
    $walletOn = \App\Services\FeatureAccess::storeAllows($store->id, 'customer_wallet');
    $refundWindow = (int) (store_options($store->id, 'refund_request_window_days') ?? 14);
    $isFollowing = ($customer && method_exists($store, 'isFollowedBy')) ? $store->isFollowedBy($customer) : false;
    $isVerifiedStore = method_exists($store, 'isVerified') ? $store->isVerified() : (bool) ($store->is_verified ?? false);
@endphp

@push('style_at_top')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$postOptions->store_template.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$postOptions->store_template.'/css/style.css'))) }}">
    <style nonce="{{ csp_nonce() }}">
        body {
            background-color: var(--au-bg-page, #0A1320);
        }
        .au-account-container {
            max-width: 800px;
            margin: 40px auto;
            padding: 0 20px;
            color: var(--au-text, #ffffff);
            font-family: 'Saudi Riyal', 'Inter', sans-serif;
        }
        .au-acct-card {
            background: var(--au-bg, #111C2A);
            border-radius: 12px;
            padding: 24px;
            border: 1px solid var(--au-border, #1E2D40);
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            color: var(--au-text, #ffffff);
        }
        .au-acct-card-header {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--au-border, #1E2D40);
            color: var(--au-text, #ffffff);
        }
        .au-order-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .au-order-item {
            border-bottom: 1px solid var(--au-border, #1E2D40);
            padding: 16px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .au-order-item:last-child {
            border-bottom: none;
        }
        .au-status-pill {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .au-status-pending { background: #fff3cd; color: #856404; }
        .au-status-completed { background: #d4edda; color: #155724; }
        .au-status-cancelled { background: #f8d7da; color: #721c24; }
        .au-account-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
        }
        .au-account-field label {
            display: block;
            color: var(--au-muted);
            font-size: 12px;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: .08em;
        }
        .au-account-field input,
        .au-account-field textarea {
            width: 100%;
            background: var(--au-input-bg);
            border: 1px solid var(--au-border);
            border-radius: 10px;
            color: var(--au-text);
            padding: 10px 12px;
            font: inherit;
        }
        .au-account-field textarea {
            min-height: 82px;
            resize: vertical;
        }
        .au-account-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            margin-top: 18px;
        }
        .au-account-btn {
            border: 0;
            border-radius: 999px;
            background: var(--au-accent);
            color: #12121a;
            cursor: pointer;
            font-weight: 800;
            padding: 10px 18px;
        }
        .au-account-btn.ghost {
            background: var(--au-glass-2);
            color: var(--au-text);
            border: 1px solid var(--au-border);
        }
        .au-address-list {
            display: grid;
            gap: 12px;
            margin-top: 18px;
        }
        .au-address-item {
            align-items: flex-start;
            background: var(--au-glass);
            border: 1px solid var(--au-border);
            border-radius: 12px;
            display: flex;
            gap: 12px;
            justify-content: space-between;
            padding: 14px;
        }
        .au-default-pill {
            display: inline-flex;
            border-radius: 999px;
            background: rgba(245,181,74,.14);
            color: var(--au-accent);
            font-size: 11px;
            font-weight: 800;
            margin-left: 8px;
            padding: 3px 8px;
            text-transform: uppercase;
        }
        .au-alert {
            border-radius: 12px;
            margin-bottom: 18px;
            padding: 12px 14px;
        }
        .au-alert.success {
            background: rgba(52,211,153,.12);
            border: 1px solid rgba(52,211,153,.25);
            color: #bbf7d0;
        }
        .au-alert.error {
            background: rgba(248,113,113,.12);
            border: 1px solid rgba(248,113,113,.25);
            color: #fecaca;
        }
        .au-order-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 10px;
        }
        
        @media (max-width: 600px) {
            .au-order-item { flex-direction: column; align-items: flex-start; gap: 10px; }
            .au-address-item { flex-direction: column; }
            .au-order-actions { justify-content: stretch; }
        }
    </style>
@endpush

@section('content')
<div class="aurora">
    <div class="au-ambient"></div>
    <div class="au-app">
        <header class="au-header" style="background: var(--au-glass); padding: 15px 20px;">
            <div class="au-header-inner" style="max-width:800px; margin:0 auto; display:flex; justify-content:space-between; align-items:center;">
                <div class="au-brand">
                    <a data-mb-back href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" style="text-decoration:none; color:var(--au-text); display:flex; align-items:center; gap:10px;">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
                        <h2 style="color:var(--au-text); margin:0;">{{ $store->title }}</h2>
                    </a>
                </div>
                <div class="au-actions">
                    <button type="button" id="au-logout-btn" style="background:var(--au-glass-2); border:none; padding:8px 16px; border-radius:var(--au-r-sm); color:var(--au-text); font-weight:600;">{{ ___('Logout') }}</button>
                </div>
            </div>
        </header>

        <div class="au-account-container">
            <h3 style="margin-bottom:4px; color:var(--au-text);">{{ ___('My Orders') }}</h3>
            <p style="margin:0 0 20px; color:var(--au-muted);">{{ $customer->name }}
                · <a href="{{ route('customer.profile', $store->id) }}" style="color:var(--au-accent); text-decoration:none;">{{ ___('Profile & settings') }}</a></p>

            @if(session('success'))
                <div class="au-alert success">{{ session('success') }}</div>
            @endif

            @if($errors->any())
                <div class="au-alert error">{{ $errors->first() }}</div>
            @endif

            <div id="au-reorder-feedback" class="au-alert error" style="display:none;"></div>

            @if($walletOn)
                <div class="au-acct-card">
                    <div class="au-acct-card-header">{{ ___('Wallet') }}</div>
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                        <div>
                            <div style="font-size:12px; text-transform:uppercase; letter-spacing:.08em; color:var(--au-muted);">{{ ___('Store credit balance') }}</div>
                            <div style="font-size:1.6rem; font-weight:800; color:var(--au-accent); margin-top:4px;">{{ moneyFormat($storeCredit ?? 0, $curSign, $curPos, currency_decimals($postOptions->currency_code ?? null)) }}</div>
                        </div>
                        <button type="button" class="au-account-btn" data-mb-wallet-open>{{ ___('Add funds') }}</button>
                    </div>
                </div>
            @endif

            <div class="au-acct-card">
                <div class="au-acct-card-header">{{ ___('Order History') }}</div>
                @if($orders->count() > 0)
                    <ul class="au-order-list">
                        @foreach($orders as $order)
                            <li class="au-order-item" style="flex-direction: column; align-items: stretch; gap: 10px;">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                                <div>
                                    <h4 style="margin:0 0 5px 0; font-size:16px; color:var(--au-text);">#{{ $order->id }}</h4>
                                    <div style="color:var(--au-faint); font-size:13px;">{{ optional($order->created_at)->format('M d, Y h:i A') ?? '—' }}</div>
                                </div>
                                <div style="text-align:right;">
                                    <div style="font-size:16px; font-weight:700; color:var(--au-accent); margin-bottom:5px;">{{ moneyFormat($order->total, $curSign, $curPos, currency_decimals($postOptions->currency_code ?? null)) }}</div>
                                    @if($order->status == 'unpaid')
                                        <span class="au-status-pill au-status-pending">{{ ___('Payment not completed') }}</span>
                                    @elseif($order->status == 'pending')
                                        <span class="au-status-pill au-status-pending">{{ ucfirst($order->status) }}</span>
                                    @elseif($order->status == 'completed')
                                        <span class="au-status-pill au-status-completed">{{ ucfirst($order->status) }}</span>
                                    @else
                                        <span class="au-status-pill au-status-cancelled">{{ ucfirst($order->status) }}</span>
                                    @endif
                                </div>
                            </div>
                            @if($order->items && $order->items->count() > 0)
                            <div style="background: var(--au-glass); border-radius: 8px; padding: 12px; margin-top: 10px;">
                                <div style="font-size: 12px; text-transform: uppercase; color: var(--au-muted); margin-bottom: 8px; letter-spacing: 0.5px;">{{ ___('Order Items') }}</div>
                                <ul style="list-style: none; padding: 0; margin: 0; font-size: 14px;">
                                    @foreach($order->items as $item)
                                        <li style="display: flex; justify-content: space-between; margin-bottom: 6px; padding-bottom: 6px; border-bottom: 1px dashed var(--au-border);">
                                            <div style="flex: 1; padding-right: 15px;">
                                                <span style="font-weight: 500;">{{ $item->quantity }}x {{ $item->menu->name ?? ___('Unknown Item') }}</span>
                                                @if($item->variation_title)
                                                    <div style="font-size: 12px; color: var(--au-muted); margin-top: 2px;">{{ $item->variation_title }}</div>
                                                @endif
                                                @if($item->itemExtras && $item->itemExtras->count() > 0)
                                                    <div style="font-size: 12px; color: var(--au-muted); margin-top: 2px;">
                                                        + @foreach($item->itemExtras as $extra) {{ $extra->extra->title ?? '' }}@if(!$loop->last), @endif @endforeach
                                                    </div>
                                                @endif
                                                @if($item->order_note)
                                                    <div style="font-size: 12px; color: var(--au-faint); margin-top: 2px; font-style: italic;">"{{ $item->order_note }}"</div>
                                                @endif
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                            @endif
                            <div class="au-order-actions">
                                <button
                                    type="button"
                                    class="au-account-btn ghost js-au-reorder"
                                    data-reorder-url="{{ route('customer.orders.reorder', [$store->id, $order->id]) }}"
                                >
                                    {{ ___('Reorder This Order') }}
                                </button>
                                @if($walletOn)
                                    @php($__re = $order->customerRefundEligibility($refundWindow))
                                    @if($order->refund_request_status === 'requested')
                                        <span class="mb-refund mb-refund--requested">{{ ___('Refund requested') }}</span>
                                    @elseif($order->refund_request_status === 'approved')
                                        <span class="mb-refund mb-refund--approved">{{ $order->refund_method === 'original_payment' ? ___('Refunded to card') : ___('Refunded to wallet') }}</span>
                                    @elseif($order->refund_request_status === 'rejected')
                                        <span class="mb-refund mb-refund--rejected">{{ ___('Refund declined') }}</span>
                                    @elseif($__re['eligible'])
                                        <button type="button" class="au-account-btn ghost" data-refund-request="{{ $order->id }}">{{ ___('Request refund') }}</button>
                                    @endif
                                @endif
                            </div>
                        </li>
                        @endforeach
                    </ul>
                    @include('store_templates._partials.orders-older', ['nextBefore' => $nextBefore ?? null])
                @else
                    <div style="text-align:center; padding:40px 0;">
                        <svg viewBox="0 0 24 24" fill="var(--au-border-hi)" width="48" height="48" style="margin-bottom:15px;"><path d="M19 6h-2c0-2.76-2.24-5-5-5S7 3.24 7 6H5c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zm-7-3c1.66 0 3 1.34 3 3H9c0-1.66 1.34-3 3-3zm7 17H5V8h14v12zm-7-8c-1.66 0-3-1.34-3-3H7c0 2.76 2.24 5 5 5s5-2.24 5-5h-2c0 1.66-1.34 3-3 3z"/></svg>
                        <p style="color:var(--au-faint);">{{ ___('You have not placed any orders yet.') }}</p>
                        <a href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" style="display:inline-block; margin-top:10px; background:var(--au-accent); color:#000; padding:10px 20px; border-radius:var(--au-r-pill); font-weight:600;" data-tc-text="texts.view_menu">{{ theme_setting($store->id, 'texts.view_menu', ___('View Menu')) }}</a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

@if($walletOn)
    @include('store_templates._partials.mb-wallet-sheet', ['store' => $store])
    @include('store_templates._partials.mb-refund', ['store' => $store])
    <style nonce="{{ csp_nonce() }}">
        .mba-sheet{--mba-surface:var(--au-bg,#111C2A);--mba-ink:var(--au-text,#fff);--mba-muted:var(--au-muted,#8aa);
            --mba-border:var(--au-border,#1E2D40);--mba-hair:var(--au-border,#1E2D40);--mba-in-bg:var(--au-input-bg,#0d1826);
            --mba-accent:var(--au-accent,#f5b54a);--mba-on-accent:#12121a;--mba-accent-ring:var(--au-border,#1E2D40);--mba-radius:14px}
    </style>
@endif
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    var logoutEndpoint = "{{ route('customer.logout', $store->id) }}";
    var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var reorderRedirectUrl = "{{ storefront_localized_link(route('publicView', $store->slug)) }}";
    var reorderCartKey = "aurora_cart_{{ $store->slug }}";
    var reorderPendingKey = "aurora_reorder_pending_{{ $store->slug }}";
    var reorderNoticeKey = "aurora_reorder_notice_{{ $store->slug }}";
    var reorderFeedback = document.getElementById('au-reorder-feedback');

    var logoutBtn = document.getElementById('au-logout-btn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function() {
            fetch(logoutEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json'
                }
            })
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    window.location.href = "{{ storefront_localized_link(route('publicView', $store->slug)) }}";
                }
            });
        });
    }

    var accountToggle = document.querySelector('#au-account-menu > button');
    var accountMenu = document.querySelector('.au-lang-menu');
    if (accountToggle && accountMenu) {
        accountToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            accountMenu.classList.toggle('show');
        });
        document.addEventListener('click', function() {
            accountMenu.classList.remove('show');
        });
    }

    /* ── theme-pack-blade F1 (meal plans) + F2 (unfollow) ── */
    var TOKEN = @json($customerApiToken);
    var TP_EP = {
        mealPlan: @json(url('customer/meal-plan')),
        follow: @json(url('r/'.$store->slug.'/follow')),
    };
    function tpHeaders(json) {
        var h = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken };
        if (json) h['Content-Type'] = 'application/json';
        if (TOKEN) h['Authorization'] = 'Bearer ' + TOKEN;
        return h;
    }
    function tpEsc(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    var mpList = document.getElementById('au-mealplans-list');
    function tpStatusLabel(s) {
        return ({ active: @json(___('Active')), paused: @json(___('Paused')), cancelled: @json(___('Cancelled')) })[s] || s;
    }
    function tpRenderPlan(p) {
        var actions = '';
        if (p.status === 'active') {
            actions = '<button class="au-account-btn ghost" data-mp="pause" data-id="'+p.id+'">'+@json(___('Pause'))+'</button>' +
                      '<button class="au-account-btn ghost" data-mp="skip" data-id="'+p.id+'">'+@json(___('Skip next'))+'</button>' +
                      '<button class="au-account-btn ghost" data-mp="cancel" data-id="'+p.id+'">'+@json(___('Cancel'))+'</button>';
        } else if (p.status === 'paused') {
            actions = '<button class="au-account-btn ghost" data-mp="resume" data-id="'+p.id+'">'+@json(___('Resume'))+'</button>' +
                      '<button class="au-account-btn ghost" data-mp="cancel" data-id="'+p.id+'">'+@json(___('Cancel'))+'</button>';
        }
        var next = p.next_run ? ('<div style="color:var(--au-muted);font-size:13px;margin-top:4px;">'+@json(___('Next order'))+': <span dir="ltr">'+tpEsc(p.next_run)+'</span></div>') : '';
        return '<div class="au-address-item" style="flex-direction:column;align-items:stretch;" data-plan="'+p.id+'">' +
            '<div style="display:flex;justify-content:space-between;align-items:flex-start;">' +
            '<div><b style="color:var(--au-text);">'+tpEsc(p.name || p.cadence)+'</b>'+next+'</div>' +
            '<span class="au-status-pill au-status-'+(p.status === 'active' ? 'completed' : (p.status === 'paused' ? 'pending' : 'cancelled'))+'">'+tpEsc(tpStatusLabel(p.status))+'</span></div>' +
            (actions ? '<div class="au-order-actions" style="justify-content:flex-start;margin-top:10px;">'+actions+'</div>' : '') + '</div>';
    }
    function tpLoadPlans() {
        if (!mpList) return;
        fetch(TP_EP.mealPlan, { headers: tpHeaders(false) })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var plans = (d && d.data) || [];
                var empty = document.getElementById('au-mealplans-empty');
                if (!plans.length) { mpList.innerHTML = ''; if (empty) empty.style.display = 'block'; return; }
                if (empty) empty.style.display = 'none';
                mpList.innerHTML = plans.map(tpRenderPlan).join('');
            })
            .catch(function () { mpList.innerHTML = '<p style="color:var(--au-faint);">'+@json(___('Could not load plans.'))+'</p>'; });
    }
    if (mpList) {
        tpLoadPlans();
        mpList.addEventListener('click', function (e) {
            var b = e.target.closest('[data-mp]'); if (!b) return;
            var action = b.dataset.mp, id = b.dataset.id;
            if (action === 'cancel' && !window.confirm(@json(___('Cancel this meal plan?')))) return;
            b.disabled = true;
            fetch(TP_EP.mealPlan + '/' + id + '/' + action, { method: 'POST', headers: tpHeaders(true), body: '{}' })
                .then(function (r) { return r.json(); })
                .then(function () { tpLoadPlans(); })
                .catch(function () { b.disabled = false; });
        });
    }

    var unfollowBtn = document.getElementById('au-unfollow-btn');
    if (unfollowBtn) unfollowBtn.addEventListener('click', function () {
        unfollowBtn.disabled = true;
        fetch(TP_EP.follow, { method: 'DELETE', headers: tpHeaders(true), body: '{}' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.following === false) {
                    var card = document.getElementById('au-following-card');
                    if (card) card.style.display = 'none';
                } else { unfollowBtn.disabled = false; }
            })
            .catch(function () { unfollowBtn.disabled = false; });
    });

    document.querySelectorAll('.js-au-reorder').forEach(function(button) {
        button.addEventListener('click', function() {
            if (reorderFeedback) {
                reorderFeedback.style.display = 'none';
                reorderFeedback.textContent = '';
            }

            button.disabled = true;

            fetch(button.getAttribute('data-reorder-url'), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json'
                }
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                button.disabled = false;

                if (!data.success && (!data.items || !data.items.length)) {
                    if (reorderFeedback) {
                        reorderFeedback.textContent = data.message || "{{ ___('Unable to reorder this order right now.') }}";
                        reorderFeedback.style.display = 'block';
                    }
                    return;
                }

                localStorage.setItem(reorderCartKey, JSON.stringify({
                    items: data.items || [],
                    timestamp: Date.now()
                }));
                sessionStorage.setItem(reorderPendingKey, '1');

                if (data.message) {
                    sessionStorage.setItem(reorderNoticeKey, data.message);
                } else {
                    sessionStorage.removeItem(reorderNoticeKey);
                }

                window.location.href = data.redirect_url || reorderRedirectUrl;
            })
            .catch(function() {
                button.disabled = false;
                if (reorderFeedback) {
                    reorderFeedback.textContent = "{{ ___('Unable to reorder this order right now.') }}";
                    reorderFeedback.style.display = 'block';
                }
            });
        });
    });
</script>
@endpush
