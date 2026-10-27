{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@php
    $statusLabels = [
        'pending' => ___('Pending'),
        'preparing' => ___('Preparing'),
        'ready' => ___('Ready'),
        'completed' => ___('Completed'),
        'cancelled' => ___('Cancelled'),
    ];
    $status = (string) $order->status;
    $isCancelled = $status === 'cancelled';
    // The owner flow, in order. "delivered" (driver lane) reads as the last step.
    $steps = [
        'pending' => $order->created_at,
        'preparing' => $order->preparing_at,
        'ready' => $order->ready_at,
        'completed' => $order->completed_at ?? $order->delivered_at,
    ];
    $flow = array_keys($steps);
    $currentIdx = array_search($status === 'delivered' ? 'completed' : $status, $flow, true);
    $next = ['pending' => 'preparing', 'preparing' => 'ready', 'ready' => 'completed'][$status] ?? null;
    $nextLabel = ['preparing' => ___('Start'), 'ready' => ___('Ready'), 'completed' => ___('Complete')][$next] ?? null;

    $typeLabel = match ($order->type) {
        'on-table' => $order->table_number ? ___('Table').' '.$order->table_number : ___('Table'),
        'takeaway' => ___('Takeaway'),
        'delivery' => ___('Delivery'),
        'room_service' => $order->service_location ? ___('Room :room', ['room' => $order->service_location]) : ___('Room service'),
        default => ucfirst((string) $order->type),
    };
    $phoneDigits = preg_replace('/[^0-9+]/', '', (string) $order->phone_number);
    $mapsUrl = function_exists('order_delivery_maps_url') ? order_delivery_maps_url($order) : null;
@endphp

@section('title', ___('Order').' #'.$order->id)
@section('page_title', ___('Order').' #'.$order->id)
@section('page_sub', $store->title.' · '.date_formating($order->created_at, 'd M Y, h:i A'))

@section('page_actions')
    <x-ui.button :href="route('stores.orders', ['store' => $store->id, 'order' => $order->id])" variant="secondary" icon="back">{{ ___('Orders') }}</x-ui.button>
    <x-ui.button type="button" variant="secondary" icon="print" data-order-print>{{ ___('Print Receipt') }}</x-ui.button>
    <x-ui.button :href="route('stores.orders.receiptPdf', $order->id)" variant="secondary" icon="print" target="_blank">{{ ___('Download PDF') }}</x-ui.button>
    @if($order->slot_hold_id || $order->substitution_option_id || $order->picking_status || $order->items->contains(fn ($i) => (int) $i->weight_g > 0))
        <x-ui.button :href="route('stores.picking.show', $order->id)" variant="secondary" icon="check" data-order-picking>{{ ___('Picking') }}</x-ui.button>
    @endif
@endsection

@section('content')
<div class="ui-scope ui-od" data-order-id="{{ $order->id }}" data-status="{{ $status }}"
     data-status-url="{{ route('stores.updateOrderStatus', $order->id) }}"
     data-failed-text="{{ ___('Unable to update order.') }}">

    {{-- Status: where the order is, with the next step as the one primary action. --}}
    <section class="ui-card ui-od__status" aria-label="{{ ___('Status') }}">
        <div class="ui-od__status-head">
            <x-ui.pill :tone="$isCancelled ? 'danger' : (['pending' => 'warning', 'preparing' => 'accent', 'ready' => 'success'][$status] ?? 'neutral')">{{ $statusLabels[$status] ?? ucfirst($status) }}</x-ui.pill>
            <span class="ui-od__type"><x-ui.tag>{{ $typeLabel }}</x-ui.tag>
                @if($order->token_number)<x-ui.tag tone="accent">{{ ___('Number') }} {{ $order->token_number }}</x-ui.tag>@endif
                @if($order->is_rush)<x-ui.tag tone="danger">{{ ___('RUSH') }}</x-ui.tag>@endif
            </span>
        </div>

        @unless($isCancelled)
            <ol class="ui-od__steps">
                @foreach($steps as $key => $at)
                    @php $i = array_search($key, $flow, true); @endphp
                    <li @if($currentIdx !== false && $i < $currentIdx) data-done @elseif($i === $currentIdx) data-current @endif>
                        <span class="ui-od__dot" aria-hidden="true">@if($currentIdx !== false && $i < $currentIdx)<x-ui.icon name="check" size="14" />@endif</span>
                        <span class="ui-od__step-label">{{ $statusLabels[$key] }}</span>
                        <span class="ui-od__step-time ui-ltr">{{ $at ? date_formating($at, 'h:i A') : '' }}</span>
                    </li>
                @endforeach
            </ol>
        @endunless

        <div class="ui-od__actions">
            @if($next)
                <button type="button" class="ui-btn ui-btn--lg" data-order-set="{{ $next }}">
                    <span class="ui-btn__icon"><x-ui.icon name="check" /></span>{{ $nextLabel }}
                </button>
            @endif
            @if(! $isCancelled && ! in_array($status, ['completed', 'delivered'], true))
                <button type="button" class="ui-btn ui-btn--danger" data-order-set="cancelled" data-confirm-text="{{ ___('Cancel order') }}?">{{ ___('Cancel order') }}</button>
            @endif
        </div>
    </section>

    <div class="ui-od__grid">
        {{-- Lines + totals --}}
        <x-ui.card :title="___('Items')" class="ui-od__items">
            <ul class="ui-od__lines">
                @foreach($order->items as $item)
                    @php
                        $unit = $item->unit_price !== null ? (float) $item->unit_price
                            : (float) (($item->variation && $item->variant) ? $item->variant->price : ($item->menu->price ?? 0));
                        $extras = $item->itemExtras->filter(fn ($e) => $e->extra);
                        $extrasSum = $extras->sum(fn ($e) => (float) ($e->price ?? $e->extra->price));
                        $line = ($unit + $extrasSum + $item->snapshotUnitPrice()) * $item->quantity;
                        // P3-09: a picked grocery line (actual weight, kept replacement, removed) is worth its final amount
                        if ($item->final_minor !== null) {
                            $line = \Juwri\Core\Money\Money::majorFromMinor((int) $item->final_minor, strtoupper((string) ($order->currency_code ?: store_currency((int) $order->store_id))));
                        }
                    @endphp
                    <li>
                        <span class="ui-order__qty ui-ltr">{{ (int) $item->quantity }}×</span>
                        <span class="ui-order__item">
                            <strong>{{ $item->menu->name ?? ___('Item') }}</strong>
                            @foreach((array) $item->options as $grp)
                                <span class="ui-order__note">{{ ($grp['title'] ?? '') }}: {{ collect($grp['selected'] ?? [])->pluck('option')->implode(', ') }}</span>
                            @endforeach
                            @if($extras->isNotEmpty())
                                <span class="ui-order__note">{{ $extras->pluck('extra.title')->implode(', ') }}</span>
                            @endif
                            @if($item->order_note)<em class="ui-order__note">{{ $item->order_note }}</em>@endif
                            @if($item->pick_status === 'removed')<em class="ui-order__note" data-line-removed>{{ ___('Removed') }}</em>@endif
                            @if($item->pick_status === 'replaced' && $item->replacement_name)<em class="ui-order__note" data-line-replaced>{{ ___('Replaced with :name', ['name' => $item->replacement_name]) }}</em>@endif
                        </span>
                        <span class="ui-od__line-price">{{ price_symbol_format($line) }}</span>
                    </li>
                @endforeach
            </ul>

            <dl class="ui-od__totals">
                <div><dt>{{ ___('Subtotal') }}</dt><dd>{{ price_symbol_format($money['subtotal']) }}</dd></div>
                @if(($money['tax'] ?? 0) > 0)<div><dt>{{ ___('Tax') }}</dt><dd>{{ price_symbol_format($money['tax']) }}</dd></div>@endif
                @if($order->deliveryCharge($postOptions) > 0)<div><dt>{{ ___('Delivery Charge') }}</dt><dd>{{ price_symbol_format($order->deliveryCharge($postOptions)) }}</dd></div>@endif
                @if((float) $order->tip_amount > 0)<div><dt>{{ ___('Tip') }}</dt><dd>{{ price_symbol_format($order->tip_amount) }}</dd></div>@endif
                @if((float) $order->discount_amount > 0)<div><dt>{{ ___('Discount') }}</dt><dd>−{{ price_symbol_format($order->discount_amount) }}</dd></div>@endif
                <div class="ui-od__grand"><dt>{{ ___('Total') }}</dt><dd>{{ price_symbol_format($money['grand']) }}</dd></div>
                {{-- Stripe/Razorpay 3-decimal round-down (owner decision): what the card was actually charged. --}}
                @foreach(\Juwri\Modules\Payments\Services\GatewayRounding::receiptLines(\Juwri\Modules\Payments\Services\GatewayRounding::orderTransaction($order)) as $label => $value)
                    <div data-rounding-line><dt>{{ $label }}</dt><dd class="ui-ltr">{{ $value }}</dd></div>
                @endforeach
            </dl>
            <div class="ui-od__paid">
                @if($order->payment_method === 'charge_to_room')
                    <x-ui.pill tone="accent">{{ ___('Charged to room folio') }}</x-ui.pill>
                @elseif($order->is_paid)
                    <x-ui.pill tone="success">{{ ___('Paid') }}</x-ui.pill>
                @else
                    <x-ui.pill tone="warning">{{ ___('Unpaid') }}</x-ui.pill>
                @endif
                @if($order->refund_request_status === 'requested')
                    <x-ui.pill tone="warning" title="{{ $order->refund_request_reason }}">{{ ___('Refund requested') }}</x-ui.pill>
                @endif
            </div>
        </x-ui.card>

        {{-- Customer --}}
        <x-ui.card :title="___('Customer')" class="ui-od__customer">
            <p class="ui-od__name">{{ $order->customer_name ?: ___('Guest') }}</p>
            @if($order->phone_number)
                <p class="ui-od__phone ui-ltr">{{ $order->phone_number }}</p>
                <div class="ui-od__contact">
                    <x-ui.button :href="'tel:'.$phoneDigits" variant="secondary" size="sm" icon="phone">{{ ___('Call') }}</x-ui.button>
                    <x-ui.button :href="'https://wa.me/'.ltrim($phoneDigits, '+')" variant="secondary" size="sm" icon="chat" target="_blank" rel="noopener">{{ ___('WhatsApp') }}</x-ui.button>
                </div>
            @endif
            @if($order->address)
                <p class="ui-od__addr">{{ $order->address }}</p>
                @if($mapsUrl)<x-ui.button :href="$mapsUrl" variant="link" size="sm" icon="pin" target="_blank" rel="noopener">{{ ___('Open in Google Maps') }}</x-ui.button>@endif
            @endif
            @if($order->message)
                <div class="ui-od__msg"><span class="ui-overline">{{ ___('Notes') }}</span><p>{{ $order->message }}</p></div>
            @endif
            @if($order->voiceNote)
                <div class="ui-order__voice">
                    <x-ui.tag tone="accent"><x-ui.icon name="mic" size="14" />{{ ___('Voice note') }}</x-ui.tag>
                    <audio controls preload="none" src="{{ route('stores.orders.voiceNote', $order->id) }}"></audio>
                </div>
                @if(filled($order->voiceNote->transcript))
                    <p class="ui-order__note">📝 {{ $order->voiceNote->transcript }}</p>
                @endif
            @endif
        </x-ui.card>

        {{-- P6-176: KSA — a standard (B2B) ZATCA tax invoice for a business buyer (name, VAT number, national address). --}}
        @if(! empty($b2bInvoice))
            <x-ui.card :title="___('Tax invoice for a business')" class="ui-od__b2b" data-b2b-invoice>
                @if($order->is_paid)
                    <p class="ui-muted">{{ ___('For a VAT-registered business buyer. Replaces the simplified invoice of this order; the amounts stay the same.') }}</p>
                    <form method="POST" action="{{ route('stores.orders.taxInvoice', $order->id) }}" class="ui-form-grid">
                        @csrf
                        <div class="ui-form-grid__full"><x-ui.field name="buyer_name" :label="___('Buyer legal name')" :value="old('buyer_name')" required /></div>
                        <div class="ui-form-grid__full"><x-ui.field name="buyer_vat" :label="___('Buyer VAT number')" :value="old('buyer_vat')" inputmode="numeric" maxlength="15" :help="___('15 digits, starts and ends with 3.')" required /></div>
                        <div class="ui-form-grid__full"><x-ui.field name="buyer_street" :label="___('Street')" :value="old('buyer_street')" required /></div>
                        <div><x-ui.field name="buyer_building_number" :label="___('Building number')" :value="old('buyer_building_number')" inputmode="numeric" maxlength="4" required /></div>
                        <div><x-ui.field name="buyer_additional_number" :label="___('Additional number')" :value="old('buyer_additional_number')" inputmode="numeric" maxlength="4" required /></div>
                        <div><x-ui.field name="buyer_district" :label="___('District')" :value="old('buyer_district')" required /></div>
                        <div><x-ui.field name="buyer_city" :label="___('City')" :value="old('buyer_city')" required /></div>
                        <div><x-ui.field name="buyer_postal_code" :label="___('Postal code')" :value="old('buyer_postal_code')" inputmode="numeric" maxlength="5" required /></div>
                        <div class="ui-form-grid__full"><button type="submit" class="ui-btn">{{ ___('Issue tax invoice') }}</button></div>
                    </form>
                @else
                    <p class="ui-muted">{{ ___('A tax invoice can be issued once the order is paid.') }}</p>
                @endif
            </x-ui.card>
        @endif
    </div>
</div>
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    (function () {
        var root = document.querySelector('.ui-od');
        if (!root) { return; }
        var token = document.querySelector('meta[name="csrf-token"]')?.content || '';

        // Status buttons post to the same JSON endpoint the Orders list uses, then reload.
        root.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-order-set]');
            if (!btn) { return; }
            var run = function () {
                btn.setAttribute('aria-busy', 'true');
                var body = new FormData();
                body.append('status', btn.getAttribute('data-order-set'));
                fetch(root.dataset.statusUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' }, body: body, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d && d.success) { window.location.reload(); return; }
                        btn.removeAttribute('aria-busy');
                        if (window.ownerUi) { window.ownerUi.toast((d && d.message) || root.dataset.failedText, { tone: 'danger' }); }
                    })
                    .catch(function () {
                        btn.removeAttribute('aria-busy');
                        if (window.ownerUi) { window.ownerUi.toast(root.dataset.failedText, { tone: 'danger' }); }
                    });
            };
            var confirmText = btn.getAttribute('data-confirm-text');
            if (confirmText && window.dashConfirm) {
                window.dashConfirm({ body: confirmText, danger: true }).then(function (ok) { if (ok) { run(); } });
            } else {
                run();
            }
        });

        document.querySelectorAll('[data-order-print]').forEach(function (b) {
            b.addEventListener('click', function () { window.print(); });
        });
    })();
</script>
@endpush
