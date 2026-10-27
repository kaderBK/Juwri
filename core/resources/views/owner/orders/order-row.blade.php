{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- One order on the owner Orders screen (and in the page's poll JSON). Owner UI kit
     card: a table-like row on wide screens, a stacked card on phones. Keep
     data-order-id / data-status / data-search and the order-detail template —
     the page script filters, updates, merges and prints through them. --}}
@php
    $statusLabels = [
        'pending' => ___('Pending'),
        'preparing' => ___('Preparing'),
        'ready' => ___('Ready'),
        'completed' => ___('Completed'),
        'cancelled' => ___('Cancelled'),
    ];
    // "Table :number" collides with the "Table Number" lang key (the slug drops the
    // placeholder), so the table number is appended rather than interpolated.
    $typeLabel = match ($order->type) {
        'on-table' => $order->table_number ? ___('Table').' '.$order->table_number : ___('Table'),
        'takeaway' => ___('Takeaway'),
        'delivery' => ___('Delivery'),
        'room_service' => $order->service_location
            ? ___('Room :room', ['room' => $order->service_location])
            : ___('Room service'),
        default => ucfirst((string) $order->type),
    };
    $typeIcon = match ($order->type) {
        'on-table' => 'dine-in',
        'delivery' => 'delivery',
        'room_service' => 'bed',
        default => 'pickup',
    };
    $searchText = strtolower(trim($typeLabel.' '.$order->customer_name.' '.$order->phone_number.' '.$order->address.' '.$order->status.' '.$order->id));
    $placedToday = $order->created_at && $order->created_at->isToday();
@endphp

<article class="ui-order" data-order-id="{{ $order->id }}" data-status="{{ $order->status }}" data-search="{{ $searchText }}" x-show="matchesOrder($el)" x-transition.opacity>
    <div class="ui-order__meta">
        <div class="ui-order__id">
            <span class="ui-ltr">#{{ $order->id }}</span>
            <time datetime="{{ $order->created_at?->toIso8601String() }}" title="{{ date_formating($order->created_at, 'd, M Y h:i A') }}">{{ $placedToday ? date_formating($order->created_at, 'h:i A') : date_formating($order->created_at, 'd M, h:i A') }}</time>
        </div>
        <div class="ui-order__tags">
            <x-ui.tag><x-ui.icon :name="$typeIcon" size="14" />{{ $typeLabel }}</x-ui.tag>
            {{-- PARITY-RUN T2: the board number the customer is waiting on, so staff
                 can answer "what number am I?" from this list. --}}
            @if($order->token_number)
                <x-ui.tag tone="accent">{{ ___('Number') }} {{ $order->token_number }}</x-ui.tag>
            @endif
            {{-- D5: rush/priority chip. --}}
            @if($order->is_rush)
                <x-ui.tag tone="danger"><x-ui.icon name="flame" size="14" />{{ ___('RUSH') }}</x-ui.tag>
            @endif
        </div>
    </div>

    <ul class="ui-order__items" aria-label="{{ ___('Menu') }}">
        @foreach($order->items as $item)
            @if($item->menu)
                <li>
                    <span class="ui-order__qty ui-ltr">{{ (int) $item->quantity }}×</span>
                    <span class="ui-order__item">
                        <strong>{{ $item->menu->name }}</strong>
                        @if($item->variant_title)<span class="ui-order__note">{{ $item->variant_title }}</span>@endif
                        @if($item->order_note)<em class="ui-order__note">{{ $item->order_note }}</em>@endif
                        {{-- T6-4 seam closed: choice-group selections (order_items.options snapshot) --}}
                        @foreach((array) $item->options as $grp)
                            <span class="ui-order__note">{{ ($grp['title'] ?? '') }}: {{ collect($grp['selected'] ?? [])->pluck('option')->implode(', ') }}</span>
                        @endforeach
                        @if($item->itemExtras && $item->itemExtras->count())
                            <span class="ui-order__note">{{ $item->itemExtras->pluck('extra.title')->filter()->implode(', ') }}</span>
                        @endif
                    </span>
                </li>
            @endif
        @endforeach
    </ul>

    <div class="ui-order__customer">
        <strong>{{ $order->customer_name ?: ___('Guest') }}</strong>
        @if(!empty($order->phone_number))
            <a class="ui-order__phone ui-ltr" href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $order->phone_number) }}">{{ $order->phone_number }}</a>
        @endif
        @if(!empty($order->address))
            <span class="ui-order__note">{{ $order->address }}@if($mapsUrl = order_delivery_maps_url($order)) · <a href="{{ $mapsUrl }}" target="_blank" rel="noopener">{{ ___('Map') }}</a>@endif</span>
        @endif
        @if(!empty($order->message))
            <em class="ui-order__note">{{ $order->message }}</em>
        @endif
        @if($order->voiceNote)
            <div class="ui-order__voice">
                <x-ui.tag tone="accent">
                    <x-ui.icon name="mic" size="14" />{{ ___('Voice note') }}
                    @if($order->voiceNote->duration_seconds)
                        · {{ gmdate('i:s', (int) $order->voiceNote->duration_seconds) }}
                    @endif
                </x-ui.tag>
                <audio controls preload="none" src="{{ route('stores.orders.voiceNote', $order->id) }}"></audio>
            </div>
            {{-- ZERO-TOUCH Track B3: auto-transcribed text under the player. --}}
            @if(filled($order->voiceNote->transcript))
                <span class="ui-order__note" title="{{ ___('Voice note transcript') }}">📝 {{ $order->voiceNote->transcript }}</span>
            @endif
        @endif
    </div>

    <div class="ui-order__money">
        <strong class="ui-order__total">{{ price_symbol_format($order->price) }}</strong>
        @if($order->tip_amount > 0)
            <span class="ui-order__note">{{ ___('Tip') }} +{{ price_symbol_format($order->tip_amount) }}</span>
        @endif
        <span class="ui-order__pills">
            @if($order->is_paid)
                <x-ui.pill tone="success">{{ ___('Paid') }}</x-ui.pill>
            @endif
            {{-- HP-B4: a charge-to-room order is settled on the guest folio, not unpaid.
                 A distinct chip (with the room) stops the runner collecting cash again. --}}
            @if($order->payment_method === 'charge_to_room')
                <x-ui.pill tone="accent">{{ ___('Room · Folio') }}@if($order->service_location) · {{ $order->service_location }}@endif</x-ui.pill>
            @endif
            {{-- Customer refund request state --}}
            @if($order->refund_request_status === 'requested')
                <x-ui.pill tone="warning" title="{{ $order->refund_request_reason }}">{{ ___('Refund requested') }}</x-ui.pill>
            @elseif($order->refund_request_status === 'approved')
                <x-ui.pill tone="success">{{ $order->refund_method === 'original_payment' ? ___('Refunded to card') : ___('Refunded to credit') }}</x-ui.pill>
            @elseif($order->refund_request_status === 'rejected')
                <x-ui.pill>{{ ___('Refund declined') }}</x-ui.pill>
            @endif
        </span>
        {{-- P6-203: 2Checkout and Qrpay adapters report `supportsRefund()=false`, so the in-app
             refund buttons record a TRACKED MANUAL refund — the money must move in the provider's
             dashboard first. Tell the owner before they click. When either adapter grows a live
             refund driver, drop the entry from the gateway list below. The same list lives in
             core/tests/Modules/Ordering/ManualRefundNoteTest.php — keep them aligned. --}}
        @if($order->is_paid && in_array((string) $order->payment_gateway, ['two_checkout', 'qrpay'], true))
            <div class="ui-order__manual-refund-note" data-manual-refund-note data-gateway="{{ $order->payment_gateway }}" role="note">
                <x-ui.icon name="alert" size="14" />
                <span>{{ ___('This gateway has no refund API. Refund the customer in the provider dashboard, then mark the order refunded here.') }}</span>
            </div>
        @endif
    </div>

    <div class="ui-order__actions">
        <label class="ui-order__status">
            <span class="ui-visually-hidden">{{ ___('Status') }}</span>
            <select @change="updateStatus($event.target, @js(route('stores.updateOrderStatus', $order->id)))">
                @foreach($statusLabels as $value => $label)
                    <option value="{{ $value }}" @selected($order->status === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <x-ui.icon name="chevron-down" size="16" />
        </label>
        <div class="ui-order__buttons">
            @if($order->status == 'pending')
                <button type="button" class="ui-icon-btn ui-icon-btn--filled" @click="completeOrder($event, @js(route('stores.completeOrder', $order->id)))" aria-label="{{ ___('Complete') }}" title="{{ ___('Complete') }}">
                    <x-ui.icon name="check" />
                </button>
            @endif
            @if($order->refund_request_status === 'requested' && ! $order->isFrozenHistory())
                {{-- Customer asked for a refund: approve (-> store credit) or decline. Imported history (J52b) is read-only: no refund. --}}
                <button type="button" class="ui-icon-btn"
                        @click="refundRequestAction($event, @js(route('stores.refundOrderToCredit', $order->id)))"
                        title="{{ ___('Approve refund to store credit') }}" aria-label="{{ ___('Approve refund') }}">
                    <x-ui.icon name="wallet" />
                </button>
                @if($order->is_paid)
                <button type="button" class="ui-icon-btn"
                        @click="refundRequestAction($event, @js(route('stores.refundOrderToOriginal', $order->id)), '{{ ___('Refund this order to the original card/payment?') }}')"
                        title="{{ ___('Refund to original card/payment') }}" aria-label="{{ ___('Refund to card') }}">
                    <x-ui.icon name="card" />
                </button>
                @endif
                <button type="button" class="ui-icon-btn"
                        @click="refundRequestAction($event, @js(route('stores.rejectRefundRequest', $order->id)))"
                        title="{{ ___('Decline refund request') }}" aria-label="{{ ___('Decline refund') }}">
                    <x-ui.icon name="x" />
                </button>
            @endif
            @if(Route::has('stores.orders.show'))
                <a class="ui-icon-btn" href="{{ route('stores.orders.show', $order->id) }}" aria-label="{{ ___('View Order') }}" title="{{ ___('View Order') }}"><x-ui.icon name="chevron" class="ui-flip-rtl" /></a>
            @endif
            <button type="button" class="ui-icon-btn" @click="openOrder({{ $order->id }})" aria-label="{{ ___('Print Receipt') }}" title="{{ ___('Print Receipt') }}">
                <x-ui.icon name="print" />
            </button>
            <button type="button" class="ui-icon-btn ui-order__delete" @click="deleteOrder($event, @js(route('stores.deleteOrder', $order->id)))" aria-label="{{ ___('Delete') }}" title="{{ ___('Delete') }}">
                <x-ui.icon name="trash" />
            </button>
        </div>
    </div>

    <template id="order-detail-{{ $order->id }}">
        <div class="orders-receipt">
            <table>
                <tr><td>{{ ___('Time') }}</td><td>{{ date_formating($order->created_at, 'd, M Y h:i A') }}</td></tr>
                <tr><td>{{ ___('Customer') }}</td><td>{{ $order->customer_name }}</td></tr>
                <tr><td>{{ ___('Type') }}</td><td>{{ $typeLabel }}</td></tr>
                @if(!empty($order->phone_number))
                    <tr><td>{{ ___('Phone') }}</td><td>{{ $order->phone_number }}</td></tr>
                @endif
                @if(!empty($order->address))
                    <tr><td>{{ ___('Address') }}</td><td>{{ $order->address }}@if($mapsUrl = order_delivery_maps_url($order))<br><a href="{{ $mapsUrl }}" target="_blank" rel="noopener">{{ ___('Open in Google Maps') }}</a>@endif</td></tr>
                @endif
                @if(!empty($order->message))
                    <tr><td>{{ ___('Message') }}</td><td>{{ $order->message }}</td></tr>
                @endif
                @if($order->voiceNote)
                    <tr><td>{{ ___('Voice note') }}</td><td><audio controls preload="none" style="height:34px;max-width:220px" src="{{ route('stores.orders.voiceNote', $order->id) }}"></audio></td></tr>
                    {{-- ZERO-TOUCH Track B3: transcript row under the player. --}}
                    @if(filled($order->voiceNote->transcript))
                        <tr><td>{{ ___('Voice note transcript') }}</td><td class="ui-order__muted">📝 {{ $order->voiceNote->transcript }}</td></tr>
                    @endif
                @endif
                @if($order->payment_method === 'charge_to_room')
                    <tr><td>{{ ___('Payment') }}</td><td>{{ ___('Charged to room folio') }}@if($order->service_location) · {{ $order->service_location }}@endif</td></tr>
                @elseif($order->is_paid)
                    <tr><td>{{ ___('Payment') }}</td><td>{{ ___('Paid') }}</td></tr>
                @endif
            </table>
            <hr>
            <table>
                <thead>
                <tr>
                    <th>{{ ___('Menu') }}</th>
                    <th>{{ ___('Price') }}</th>
                </tr>
                </thead>
                <tbody>
                {{-- Priced receipt lines: each dish (quantity × unit price), then its
                     choice-group picks (order-time snapshot) and extras, each × quantity. --}}
                @php
                    $receiptLines = [];
                    foreach ($order->items as $line) {
                        if (! $line->menu) { continue; } // no closure in a view: PHP 8.4 keeps ~250 bytes per render of each (P6-36)
                        $qty = $line->quantity;
                        $receiptLines[] = ['dish' => $line->menu->name, 'variant' => $line->variant_title, 'qty' => $qty, 'amount' => $line->menu->price * $qty];
                        foreach ((array) $line->options as $group) {
                            foreach ((array) ($group['selected'] ?? []) as $pick) {
                                $receiptLines[] = ['label' => ($group['title'] ?? '').': '.($pick['option'] ?? ''), 'amount' => (float) ($pick['price'] ?? 0) * $qty];
                            }
                        }
                        foreach ($line->itemExtras as $added) {
                            if (! $added->extra) { continue; }
                            $receiptLines[] = ['label' => $added->extra->title, 'amount' => $added->extra->price * $qty];
                        }
                    }
                @endphp
                @foreach($receiptLines as $receiptLine)
                    <tr>
                        @isset($receiptLine['dish'])
                            <td>{{ $receiptLine['dish'] }} @if($receiptLine['variant'])<small>{{ $receiptLine['variant'] }}</small> @endif{{ ___('x :quantity', ['quantity' => $receiptLine['qty']]) }}</td>
                        @else
                            <td>{{ $receiptLine['label'] }}</td>
                        @endisset
                        <td>{{ price_symbol_format($receiptLine['amount']) }}</td>
                    </tr>
                @endforeach
                {{-- HP-B4: room-service orders carry a tray charge in the grand total
                     (Order::computeMoney); show it so the lines sum to Total. --}}
                @php $__tray = \App\Models\Order::trayCharge($order->type, (float) $order->subtotal, $order->store_id); @endphp
                @if($order->type === 'room_service' && $__tray > 0)
                    <tr><td>{{ ___('Tray charge') }}</td><td>{{ price_symbol_format($__tray) }}</td></tr>
                @endif
                @if($order->deliveryCharge($postOptions ?? null) > 0)
                    <tr><td>{{ ___('Delivery Charge') }}</td><td>{{ price_symbol_format($order->deliveryCharge($postOptions ?? null)) }}</td></tr>
                @endif
                @if(@$postOptions->restaurant_taxx_charge)
                    <tr><td>{{ ___('Tax Charge') }}</td><td>{{ $postOptions->restaurant_taxx_charge }}%</td></tr>
                @endif
                </tbody>
                <tfoot>
                <tr><th>{{ ___('Total') }}</th><td>{{ price_symbol_format($order->price) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </template>
</article>
