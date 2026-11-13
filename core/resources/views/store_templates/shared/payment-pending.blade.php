{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Syria A5 — shared wallet_transfer payment-pending banner for the order-track
     page. Renders nothing unless this order is a wallet_transfer order.

     DOM CONTRACT (Lane C styles against this — do NOT change the markup):
       <div class="mb-paypending" data-mb-paypending data-state="awaiting-claim|claimed|confirmed">
         .mb-paypending-title
         .mb-paypending-txn   (the claimed txn id wrapped in <bdi>)
         .mb-paypending-hint

     States:
       awaiting-claim — pending, no claim submitted yet (or a rejected claim to retry)
       claimed        — customer submitted a txn-id; awaiting owner confirmation
       confirmed      — owner confirmed; the order is paid

     `$walletTx` is the latest wallet_transfer transaction for this order (any
     status), supplied by ManagesOrders::trackOrder. --}}
@php
    $__wt = $walletTx ?? null;
@endphp
@if($__wt)
    @php
        $__paid = (bool) ($order->is_paid ?? false);
        $__details = $__wt->details ?? null;
        $__txn = optional($__details)->txn_id;
        $__claimed = ! empty(optional($__details)->claimed_at);
        $__rejected = ! empty(optional($__details)->rejected);

        if ($__paid && ($order->payment_gateway ?? null) === 'wallet_transfer') {
            $__state = 'confirmed';
        } elseif ($__wt->status === \Juwri\Modules\Payments\Models\Transaction::STATUS_PENDING) {
            $__state = ($__claimed && ! $__rejected) ? 'claimed' : 'awaiting-claim';
        } else {
            $__state = null;
        }

        $__titles = [
            'awaiting-claim' => ___('Payment pending'),
            'claimed'        => ___('Payment awaiting confirmation'),
            'confirmed'      => ___('Payment confirmed'),
        ];
        $__hints = [
            'awaiting-claim' => ___('Complete your wallet transfer, then submit your transaction ID.'),
            'claimed'        => ___('The restaurant is verifying your transfer. Your order is confirmed once they approve it.'),
            'confirmed'      => ___('The restaurant confirmed your wallet transfer.'),
        ];
    @endphp

    @if($__state)
        @once
            <style nonce="{{ csp_nonce() }}">
                .mb-paypending{border:1px solid currentColor;border-radius:12px;padding:14px 16px;margin-block:12px;opacity:.92}
                .mb-paypending-title{font-weight:700;margin-block-end:4px}
                .mb-paypending-txn{font-variant-numeric:tabular-nums;margin-block-end:4px}
                .mb-paypending-txn:empty{display:none}
                .mb-paypending-hint{font-size:.9em;opacity:.8}
            </style>
        @endonce

        <div class="mb-paypending" data-mb-paypending data-state="{{ $__state }}">
            <div class="mb-paypending-title">{{ $__titles[$__state] }}</div>
            <div class="mb-paypending-txn">@if(! empty($__txn)){{ ___('Transaction ID') }}: <bdi>{{ $__txn }}</bdi>@endif</div>
            <div class="mb-paypending-hint">{{ $__hints[$__state] }}</div>
        </div>
    @endif
@endif

{{-- Owner decision 2026-10-01: a Stripe/Razorpay card payment of a 3-decimal total is charged rounded down to the
     provider's step — the order page shows what was actually charged. Independent of the wallet_transfer banner. --}}
@php($__roundingLines = \Juwri\Modules\Payments\Services\GatewayRounding::receiptLines(\Juwri\Modules\Payments\Services\GatewayRounding::orderTransaction($order ?? null)))
@if($__roundingLines)
    <dl class="mb-rounding" data-rounding-lines>
        @foreach($__roundingLines as $__label => $__value)
            <div><dt>{{ $__label }}</dt><dd dir="ltr">{{ $__value }}</dd></div>
        @endforeach
    </dl>
@endif
