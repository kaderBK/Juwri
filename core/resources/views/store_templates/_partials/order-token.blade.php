{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- PARITY-RUN T2-4: "Your number is N — watch the board".

     Shown on the order-tracking page every checkout (web, kiosk web mode and
     the QR menu) funnels into, so all 17 themes get the tie-in from one file —
     the same shape as _partials/prep-eta.

     Renders nothing at all unless the order actually carries a token: a store
     with the board off, or an order type the owner did not opt in, has none. --}}
@php
    $boardToken = isset($order) && $order->token_number ? (int) $order->token_number : null;
    $boardPost = $boardToken ? ($store ?? null) : null;
    $boardLink = $boardToken && $boardPost && \Juwri\Modules\Kitchen\Services\OrderBoard\OrderBoardService::visible((int) $boardPost->id)
        ? \Juwri\Modules\Kitchen\Services\OrderBoard\OrderBoardService::url($boardPost, $order->branch_id ? (int) $order->branch_id : null)
        : null;
@endphp
@if($boardToken)
    <div class="order-token-line" dir="auto"
         style="margin:16px auto 0;text-align:center;line-height:1.35;">
        <div style="font-size:13px;font-weight:600;opacity:.7;text-transform:uppercase;letter-spacing:.04em;">
            {{ ___('Your number') }}
        </div>
        <div id="orderTokenNumber" style="font-size:40px;font-weight:800;font-variant-numeric:tabular-nums;">{{ $boardToken }}</div>
        @if($boardLink)
            <a href="{{ $boardLink }}" target="_blank" rel="noopener"
               style="font-size:13px;font-weight:600;opacity:.75;text-decoration:underline;">
                {{ ___('Watch the order board') }}
            </a>
        @else
            <div style="font-size:13px;opacity:.6;">{{ ___('Watch the screen in store for your number.') }}</div>
        @endif
    </div>
@endif
