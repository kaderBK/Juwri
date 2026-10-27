{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    $curSign = cur_isolate($postOptions->currency_sign ?? '$');
    $curPos  = (string) currency_pos_effective($postOptions->id ?? null);
    
    $formatPrice = new \Juwri\Core\Support\RawPriceFormatter($curSign, $curPos, currency_decimals($postOptions->currency_code ?? null));
@endphp

@push('style_at_top')
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
    <style nonce="{{ csp_nonce() }}">
        .au-track-container {
            max-width: 600px;
            margin: 40px auto 100px;
            padding: 0 16px;
        }
        .au-track-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .au-track-header h1 {
            font-size: 24px;
            font-weight: 800;
            color: var(--au-text);
            margin-bottom: 8px;
        }
        .au-track-header p {
            color: var(--au-muted);
            font-size: 14px;
        }
        .au-status-card {
            background: var(--au-glass);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--au-border);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 24px;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }
        .au-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 30px;
            font-weight: 700;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 20px;
        }
        .au-status-badge.pending {
            background: rgba(255, 193, 7, 0.1);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.2);
        }
        .au-status-badge.preparing {
            background: rgba(33, 150, 243, 0.1);
            color: #2196f3;
            border: 1px solid rgba(33, 150, 243, 0.2);
        }
        .au-status-badge.ready {
            background: rgba(76, 175, 80, 0.1);
            color: #4caf50;
            border: 1px solid rgba(76, 175, 80, 0.2);
        }
        .au-status-badge.completed {
            background: rgba(0, 230, 118, 0.15);
            color: #00e676;
            border: 1px solid rgba(0, 230, 118, 0.3);
        }
        .au-status-badge.cancelled {
            background: rgba(244, 67, 54, 0.1);
            color: #f44336;
            border: 1px solid rgba(244, 67, 54, 0.2);
        }
        .au-pulse {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: currentColor;
            animation: pulse-glow 1.5s infinite;
        }
        @keyframes pulse-glow {
            0% { transform: scale(0.85); opacity: 0.5; }
            50% { transform: scale(1.15); opacity: 1; }
            100% { transform: scale(0.85); opacity: 0.5; }
        }
        .au-status-title {
            font-size: 20px;
            font-weight: 800;
            color: var(--au-text);
            margin-bottom: 8px;
        }
        .au-status-desc {
            color: var(--au-muted);
            font-size: 14px;
            max-width: 400px;
            margin: 0 auto 30px;
            line-height: 1.6;
        }
        
        /* Progress Steps */
        .au-steps {
            display: flex;
            justify-content: space-between;
            position: relative;
            margin: 40px 0 20px;
        }
        .au-steps::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--au-glass-hi);
            z-index: 1;
        }
        .au-steps-progress {
            position: absolute;
            top: 20px;
            left: 0;
            height: 3px;
            background: var(--theme-color-1, #ffc107);
            z-index: 2;
            transition: width 0.6s ease-in-out;
            width: 0%;
        }
        .au-step-item {
            position: relative;
            z-index: 3;
            text-align: center;
            flex: 1;
        }
        .au-step-dot {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--au-surface);
            border: 3px solid var(--au-border);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
            color: var(--au-muted);
            font-weight: 700;
            transition: all 0.3s ease;
        }
        .au-step-item.active .au-step-dot {
            border-color: var(--theme-color-1, #ffc107);
            background: var(--theme-color-1, #ffc107);
            color: #000;
            box-shadow: 0 0 15px var(--theme-color-0_4, rgba(255, 193, 7, 0.4));
        }
        .au-step-item.completed .au-step-dot {
            border-color: var(--theme-color-1, #ffc107);
            background: var(--au-surface);
            color: var(--theme-color-1, #ffc107);
        }
        .au-step-label {
            font-size: 12px;
            font-weight: 700;
            color: var(--au-muted);
            transition: color 0.3s ease;
        }
        .au-step-item.active .au-step-label {
            color: var(--au-text);
        }
        .au-step-item.completed .au-step-label {
            color: var(--theme-color-1, #ffc107);
        }

        /* T4 order-timeline change motion — change-driven pop/flash on the
           dot class flips updateUI performs (progress-bar width already
           transitions). Neutralised by aurora's global .aurora *{animation:none}
           reduced-motion guard in css/style.css (no per-rule block needed). */
        @keyframes mbStepPop { 0% { transform: scale(.55); } 60% { transform: scale(1.14); } 100% { transform: none; } }
        @keyframes mbStepFlash { 0%, 100% { opacity: 1; } 42% { opacity: .5; } }
        @keyframes mbDone { 0% { transform: scale(.82); } 55% { transform: scale(1.16); } 100% { transform: none; } }
        .au-step-item.completed .au-step-dot { animation: mbStepPop .5s ease; }
        .au-step-item.active .au-step-dot { animation: mbStepFlash .9s ease; }
        .au-step-item:last-child.completed .au-step-dot { animation: mbDone .7s ease; }

        .au-summary-card {
            background: var(--au-glass);
            backdrop-filter: blur(20px);
            border: 1px solid var(--au-border);
            border-radius: 20px;
            padding: 24px;
        }
        .au-summary-title {
            font-size: 16px;
            font-weight: 800;
            color: var(--au-text);
            margin-bottom: 20px;
            border-bottom: 1px solid var(--au-border);
            padding-bottom: 12px;
        }
        .au-summary-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 14px;
        }
        .au-summary-label {
            color: var(--au-muted);
        }
        .au-summary-val {
            color: var(--au-text);
            font-weight: 500;
        }
        .au-summary-mods {
            display: block;
            color: var(--au-muted);
            font-weight: 400;
            font-size: 12px;
            margin-top: 2px;
        }
        .au-summary-totals {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px dashed var(--au-border);
        }
        .au-summary-total-row {
            display: flex;
            justify-content: space-between;
            font-size: 16px;
            font-weight: 800;
            color: var(--au-text);
        }
        .au-btn-back {
            display: block;
            text-align: center;
            margin-top: 30px;
            color: var(--theme-color-1, #ffc107);
            font-weight: 700;
            text-decoration: none;
            font-size: 14px;
            transition: opacity 0.3s;
        }
        .au-btn-back:hover {
            opacity: 0.8;
        }
    </style>
@endpush

@section('content')
<div class="au-ambient"></div>

<div class="au-track-container">
    <div class="au-track-header">
        <h1>{{ $store->title }}</h1>
        <p>{{ ___('Order') }} #{{ $order->id }} &bull; {{ date_formating($order->created_at, 'h:i A') }}</p>
        @include("store_templates._partials.ordered-via")
    </div>

    <div class="au-status-card">
        <div id="status-badge-container">
            <!-- Dynamically populated status badge -->
        </div>

        <div class="au-status-title" id="status-title">---</div>
        <div class="au-status-desc" id="status-desc">---</div>
        @include('store_templates._partials.order-token')
        @include('store_templates._partials.prep-eta')
        @include('store_templates._partials.mk-rating-prompt')
@include('store_templates._partials.guest-account-prompt')
@include('store_templates.shared.payment-pending')
@include('store_templates._partials.track-poll')
@include('store_templates._partials.delivery-substatus')
@include('store_templates._partials.track-notify')

        <div class="au-steps">
            <div class="au-steps-progress" id="steps-progress"></div>
            
            <div class="au-step-item" id="step-received">
                <div class="au-step-dot">1</div>
                <div class="au-step-label">{{ ___('Received') }}</div>
            </div>
            
            <div class="au-step-item" id="step-preparing">
                <div class="au-step-dot">2</div>
                <div class="au-step-label">{{ ___('Preparing') }}</div>
            </div>
            
            <div class="au-step-item" id="step-ready">
                <div class="au-step-dot">3</div>
                <div class="au-step-label" id="step-ready-label">{{ ___('Ready') }}</div>
            </div>
            
            <div class="au-step-item" id="step-completed">
                <div class="au-step-dot">4</div>
                <div class="au-step-label">{{ ___('Completed') }}</div>
            </div>
        </div>
    </div>

    {{-- T5: Curbside "I'm here" arrival ping --}}
    @if(!empty($order->is_curbside))
    <div class="au-summary-card" id="au-curbside-card" style="margin-bottom:24px;text-align:center;">
        <button type="button" id="au-imhere-btn" class="au-cta" style="width:100%;padding:14px;">
            <i class="ico ico-car" aria-hidden="true"><x-ui.icon name="car" /></i> {{ ___("I'm here") }}
        </button>
        <div id="au-imhere-msg" style="display:none;margin-top:10px;font-size:14px;color:var(--au-fx);">{{ ___('Staff notified') }}</div>
    </div>
    <script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
        (function(){
            var btn=document.getElementById('au-imhere-btn');
            if(!btn) return;
            btn.addEventListener('click', function(){
                btn.disabled=true;
                fetch('{{ url('order/track') }}/{{ $order->track_token }}/arrived', {
                    method:'POST',
                    headers:{'X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]')?.content,'Accept':'application/json'}
                }).then(function(r){return r.json();}).then(function(d){
                    btn.style.display='none';
                    var m=document.getElementById('au-imhere-msg'); if(m) m.style.display='block';
                }).catch(function(){ btn.disabled=false; });
            });
        })();
    </script>
    @endif

    <div class="au-summary-card" style="margin-bottom: 24px;">
        <div class="au-summary-title">{{ ___('Customer Details') }}</div>
        <div class="au-summary-item">
            <span class="au-summary-label">{{ ___('Customer Name') }}</span>
            <span class="au-summary-val">{{ $order->customer_name }}</span>
        </div>
        @if($order->phone_number)
            <div class="au-summary-item">
                <span class="au-summary-label">{{ ___('Phone') }}</span>
                <span class="au-summary-val">{{ $order->phone_number }}</span>
            </div>
        @endif
    </div>

    <div class="au-summary-card">
        <div class="au-summary-title">{{ ___('Order Summary') }}</div>
        {{-- The receipt must itemise exactly what the customer is charged, and its
             own arithmetic must add up in front of them. It previously printed
             `menu->price * quantity`, which ignores the chosen VARIANT and every
             EXTRA, and it omitted the subtotal and tax rows entirely: this order
             showed "Quarter Broast x2  $9.00 / Delivery $2.00 / Tip $1.60 /
             Discount -$1.60 / Total $18.80" -- a receipt that is $7.80 short of
             its own total. The loop below mirrors Order::getTotalAttribute(). --}}
        @php
            $auSubtotal = 0;
            $auLines = [];
            foreach ($order->items as $item) {
                if (!$item->menu) { continue; }
                $unit = (float) $item->menu->price;
                if ($item->variation && $item->variant) { $unit = (float) $item->variant->price; }
                $bits = [];
                foreach ($item->itemExtras as $ie) {
                    if ($ie->extra) { $unit += (float) $ie->extra->price; $bits[] = $ie->extra->title; }
                }
                foreach ((array) $item->options as $grp) {
                    foreach ((array) ($grp['selected'] ?? []) as $opt) {
                        $unit += (float) ($opt['price'] ?? 0);
                        if (!empty($opt['name'])) { $bits[] = $opt['name']; }
                    }
                }
                $line = $unit * $item->quantity;
                $auSubtotal += $line;
                /* A MenuVariant carries no label of its own: `options` is a
                   {menuVariantOptionId: choiceIndex} map and the human words live
                   on the MenuVariantOption row. Same resolution index.blade.php
                   uses for the item sheet, so the receipt names the same variant
                   the customer picked. */
                $varLabel = [];
                if ($item->variation && $item->variant) {
                    foreach ((array) ($item->variant->options ?? []) as $optId => $idx) {
                        $vo = optional($item->menu->variantOptions ?? collect())->firstWhere('id', (int) $optId);
                        $choices = (array) ($vo->options ?? []);
                        if (isset($choices[(int) $idx])) { $varLabel[] = $choices[(int) $idx]; }
                    }
                }
                $auNoteBits = array_values(array_filter(array_merge($varLabel, $bits)));
                $auLines[] = [
                    'name'  => $item->menu->name,
                    'qty'   => $item->quantity,
                    'note'  => implode(' · ', $auNoteBits),
                    'total' => $line,
                ];
            }
            $auTax = \App\Models\Order::computeTax($auSubtotal, $postOptions);
        @endphp
        @foreach($auLines as $line)
            <div class="au-summary-item">
                <span class="au-summary-val">
                    {{ $line['name'] }} &times; {{ $line['qty'] }}
                    @if($line['note'])<small class="au-summary-mods">{{ $line['note'] }}</small>@endif
                </span>
                <span class="au-summary-val">{{ $formatPrice($line['total']) }}</span>
            </div>
        @endforeach

        <div class="au-summary-totals">
            <div class="au-summary-item">
                <span class="au-summary-label">{{ ___('Subtotal') }}</span>
                <span class="au-summary-val">{{ $formatPrice($auSubtotal) }}</span>
            </div>
            @if($order->deliveryCharge($postOptions) > 0)
                <div class="au-summary-item">
                    <span class="au-summary-label">{{ ___('Delivery Charge') }}</span>
                    <span class="au-summary-val">{{ $formatPrice($order->deliveryCharge($postOptions)) }}</span>
                </div>
            @endif
            @if($auTax > 0)
                <div class="au-summary-item">
                    <span class="au-summary-label">{{ ___('Tax') }}</span>
                    <span class="au-summary-val">{{ $formatPrice($auTax) }}</span>
                </div>
            @endif
            @if($order->tip_amount > 0)
                <div class="au-summary-item">
                    <span class="au-summary-label">{{ ___('Tip') }}</span>
                    <span class="au-summary-val">{{ $formatPrice($order->tip_amount) }}</span>
                </div>
            @endif
            @if($order->discount_amount > 0)
                <div class="au-summary-item">
                    <span class="au-summary-label">{{ ___('Discount') }}</span>
                    <span class="au-summary-val" style="color:var(--au-veg)">-{{ $formatPrice($order->discount_amount) }}</span>
                </div>
            @endif
            <div class="au-summary-total-row">
                {{-- `total` is the authoritative accessor the customer is charged
                     on; `price` is a stored column that is empty on some orders,
                     and using it here would let the receipt disagree with the
                     lines printed directly above it. --}}
                <span>{{ ___('Total') }}</span>
                <span>{{ $formatPrice($order->total) }}</span>
            </div>
        </div>
    </div>

    <a href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" class="au-btn-back">
        <i class="ico ico-arrow-left" aria-hidden="true"><x-ui.icon name="arrow-left" /></i> {{ ___('Back to Menu') }}
    </a>
</div>
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    {{-- Vanilla JS on purpose: aurora is in layout.blade.php's $mbModern list, so
         jQuery is never loaded on it. This block used to be jQuery, so the whole
         status pill / step progress / poll was dead on the aurora track page —
         the page rendered "---" for the title and never advanced a step. --}}
    (function () {
        function el(id) { return document.getElementById(id); }
        function setText(id, txt) { var n = el(id); if (n) { n.textContent = txt; } }
        {{-- `badge` is a SHORT localized label for the status pill. It is deliberately
             not `title`: the pill is a compact uppercase chip, and titles like
             "Preparing your Food" would overflow it. --}}
        var statuses = {
            unpaid: {
                title: @json(___('Awaiting Payment')),
                desc: @json(___('Please complete your online payment to submit this order to the kitchen.')),
                badge: @json(___('Awaiting Payment')),
                badgeClass: 'cancelled',
                progress: 0,
                activeStep: null
            },
            {{-- `new` is a live order status; without an explicit entry it fell through to
                 `pending` (correct by luck). Kept identical to pending, but explicit. --}}
            new: {
                title: @json(___('Order Received')),
                desc: @json(___('Your order has been received by the kitchen and is awaiting confirmation.')),
                badge: @json(___('Received')),
                badgeClass: 'pending',
                progress: 12.5,
                activeStep: 'step-received'
            },
            pending: {
                title: @json(___('Order Received')),
                desc: @json(___('Your order has been received by the kitchen and is awaiting confirmation.')),
                badge: @json(___('Received')),
                badgeClass: 'pending',
                progress: 12.5,
                activeStep: 'step-received'
            },
            preparing: {
                title: @json(___('Preparing your Food')),
                desc: @json(___('The chef is currently preparing your delicious meal with care.')),
                badge: @json(___('Preparing')),
                badgeClass: 'preparing',
                progress: 37.5,
                activeStep: 'step-preparing'
            },
            ready: {
                title: @json(___('Ready!')),
                desc: @json(___('Your order is ready! Please collect it or wait for our delivery staff.')),
                badge: @json(___('Ready')),
                badgeClass: 'ready',
                progress: 62.5,
                activeStep: 'step-ready'
            },
            completed: {
                title: @json(___('Order Completed')),
                desc: @json(___('Your order has been served. Enjoy your meal and thank you for dining with us!')),
                badge: @json(___('Completed')),
                badgeClass: 'completed',
                progress: 100,
                activeStep: 'step-completed'
            },
            {{-- delivered is terminal (isTerminal): finish the timeline, don't fall
                 back to `pending` and snap the diner to "Received". --}}
            delivered: {
                title: @json(___('Delivered')),
                desc: @json(___('Your order has been delivered. Enjoy your meal and thank you for ordering with us!')),
                badge: @json(___('Delivered')),
                badgeClass: 'completed',
                progress: 100,
                activeStep: 'step-completed'
            },
            {{-- pending_approval: awaiting the kitchen's confirmation — step 0. --}}
            pending_approval: {
                title: @json(___('Awaiting approval')),
                desc: @json(___('Your order has been received and is awaiting confirmation from the restaurant.')),
                badge: @json(___('Awaiting approval')),
                badgeClass: 'pending',
                progress: 12.5,
                activeStep: 'step-received'
            },
            {{-- call_bill is a dine-in state after the food is served — keep the
                 timeline at the served/ready step, only the label changes. --}}
            call_bill: {
                title: @json(___('Bill requested')),
                desc: @json(___('Your bill has been requested. A member of staff will be with you shortly.')),
                badge: @json(___('Bill requested')),
                badgeClass: 'ready',
                progress: 62.5,
                activeStep: 'step-ready'
            },
            cancelled: {
                title: @json(___('Order Cancelled')),
                desc: @json(___('This order has been cancelled. Please contact staff for assistance.')),
                badge: @json(___('Cancelled')),
                badgeClass: 'cancelled',
                progress: 0,
                activeStep: null
            }
        };

        var orderType = @json($order->type);
        if (orderType === 'delivery') {
            setText('step-ready-label', @json(___('On the Way')));
            statuses.ready.desc = @json(___('Your order is out for delivery. Our courier will arrive shortly.'));
            {{-- "Ready" is misleading once it has left the kitchen; "Ready" still fits takeaway. --}}
            statuses.ready.badge = @json(___('On the Way'));
        } else if (orderType === 'takeaway') {
            setText('step-ready-label', @json(___('Ready for Pickup')));
            statuses.ready.desc = @json(___('Your order is packed and ready for pickup at the counter.'));
        }

        function updateUI(status) {
            var cfg = statuses[status] || statuses.pending;
            
            // Set badge — localized label via cfg.badge, never the raw DB status string.
            // Built as nodes (not concatenated HTML) so the label is inserted as text.
            var host = el('status-badge-container');
            if (host) {
                var badge = document.createElement('div');
                badge.className = 'au-status-badge ' + cfg.badgeClass;
                var pulse = document.createElement('span');
                pulse.className = 'au-pulse';
                badge.appendChild(pulse);
                badge.appendChild(document.createTextNode(' ' + cfg.badge));
                host.innerHTML = '';
                host.appendChild(badge);
            }

            // Set title & desc
            setText('status-title', cfg.title);
            setText('status-desc', cfg.desc);

            // Steps styling
            Array.prototype.forEach.call(document.querySelectorAll('.au-step-item'), function (n) {
                n.classList.remove('active', 'completed');
            });
            var bar = el('steps-progress');
            if (bar) { bar.style.width = cfg.progress + '%'; }

            var steps = ['step-received', 'step-preparing', 'step-ready', 'step-completed'];
            var activeIdx = steps.indexOf(cfg.activeStep);

            if (activeIdx !== -1) {
                steps.forEach(function(step, idx) {
                    var n = el(step);
                    if (!n) { return; }
                    if (idx < activeIdx) {
                        n.classList.add('completed');
                    } else if (idx === activeIdx) {
                        n.classList.add('active');
                    }
                });
            } else if (status === 'cancelled') {
                // If cancelled, color dots red
                Array.prototype.forEach.call(document.querySelectorAll('.au-step-dot'), function (n) {
                    n.style.borderColor = '#f44336';
                });
            }
        }

        // Initial setup
        updateUI(@json($order->status));

        // T2: register with the shared single poller (visibility-gated,
        // terminal-stop, live prep-eta + board token).
        window.mbTrackPoll(function (res) { if (res && res.status) { updateUI(res.status); } });
    })();
</script>
@endpush
