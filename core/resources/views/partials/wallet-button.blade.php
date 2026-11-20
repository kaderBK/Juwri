{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    #23 Apple/Google Pay — Stripe Payment Request Button.
    Include after an order is placed, passing the restaurant slug and order id:
        @include('partials.wallet-button', ['walletRestaurant' => $store->slug, 'walletOrderId' => $order->id, 'walletTrackToken' => $order->track_token])
    The track token binds the PaymentIntent to this order's diner (AUDIT-authz #3).
    Renders nothing unless the device supports a wallet AND the restaurant has
    Stripe configured. Falls back silently to the normal payment flow otherwise.
--}}
@php($walletRestaurant = $walletRestaurant ?? null)
@php($walletOrderId = $walletOrderId ?? null)
@php($walletTrackToken = $walletTrackToken ?? null)
@if($walletRestaurant && $walletOrderId && $walletTrackToken)
<div id="wallet-pay" style="display:none;margin:12px 0"></div>
<script src="https://js.stripe.com/v3/"></script>
<script nonce="{{ csp_nonce() }}">
(async function () {
    try {
        const cfg = await (await fetch(@json(url($walletRestaurant.'/wallet/config').'?order_id='.$walletOrderId))).json();
        if (!cfg.enabled || !cfg.publishable_key) return;

        const stripe = Stripe(cfg.publishable_key);

        // Fetch the server-computed amount + client secret for this order.
        const intentRes = await fetch(@json(url($walletRestaurant.'/wallet/intent')), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
            body: JSON.stringify({ order_id: @json($walletOrderId), track_token: @json($walletTrackToken) })
        });
        if (!intentRes.ok) return;
        const intent = await intentRes.json();

        const pr = stripe.paymentRequest({
            country: cfg.country || 'US',
            currency: intent.currency,
            // Minor units straight from the server (KWD 3.380 → 3380, JPY 1000 → 1000) — never amount × 100.
            total: { label: 'Order #' + @json($walletOrderId), amount: intent.amount_minor },
            requestPayerName: true,
        });

        const canPay = await pr.canMakePayment();
        if (!canPay) return; // no wallet on this device -> keep normal flow

        const el = document.getElementById('wallet-pay');
        el.style.display = 'block';
        stripe.elements().create('paymentRequestButton', { paymentRequest: pr }).mount('#wallet-pay');

        pr.on('paymentmethod', async (ev) => {
            const { error } = await stripe.confirmCardPayment(
                intent.client_secret,
                { payment_method: ev.paymentMethod.id },
                { handleActions: false }
            );
            if (error) { ev.complete('fail'); return; }
            ev.complete('success');
            location.reload();
        });
    } catch (e) { /* fall back to normal payment options */ }
})();
</script>
@endif
