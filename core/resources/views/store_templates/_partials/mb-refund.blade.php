{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    Shared customer refund-request wiring for the vertical themes. Each theme's
    order-history render adds, per row:
      • a button [data-refund-request="{orderId}"] when o.canRequestRefund
      • a <span class="mb-refund mb-refund--{status}"> when o.refundStatus,
        labelled via window.mbRefundLabel(status)

    This partial provides the label helper, the delegated POST handler, and the
    badge skin. Included once per theme in the real-auth block (order history only
    exists for a signed-in customer, which requires real auth). Namespaced .mb-*.
--}}
<style nonce="{{ csp_nonce() }}">
    .mb-refund{display:inline-flex;align-items:center;font-size:11.5px;font-weight:700;padding:5px 11px;border-radius:100px;line-height:1}
    .mb-refund--requested{background:rgba(120,110,180,.15);color:#6b5fb0}
    .mb-refund--approved{background:rgba(22,163,74,.13);color:#16a34a}
    .mb-refund--rejected{background:rgba(220,38,38,.1);color:#dc2626}
</style>
<script nonce="{{ csp_nonce() }}">
(function () {
    var CSRF = @json(csrf_token());
    var RID = @json((int) $store->id);
    var BASE = @json(url('restaurant'));
    var LABELS = {
        requested: @json(___('Refund requested')),
        approved:  @json(___('Refunded to wallet')),
        rejected:  @json(___('Refund declined'))
    };
    var APPROVED_CARD = @json(___('Refunded to card'));
    var T = {
        prompt: @json(___('Tell us why (optional):')),
        sent:   @json(___('Refund request sent')),
        fail:   @json(___('Something went wrong. Please try again.'))
    };
    // method: 'store_credit' (default) | 'original_payment' — an approved refund
    // to the original card reads "Refunded to card", otherwise "Refunded to wallet".
    window.mbRefundLabel = function (s, method) {
        if (s === 'approved' && method === 'original_payment') { return APPROVED_CARD; }
        return LABELS[s] || '';
    };

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-refund-request]');
        if (!btn) { return; }
        e.preventDefault();
        var id = btn.getAttribute('data-refund-request');
        var reason = window.prompt(T.prompt);
        if (reason === null) { return; }
        btn.disabled = true;
        fetch(BASE + '/' + RID + '/orders/' + id + '/refund-request', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                       'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ reason: reason })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, b: j }; }); })
        .then(function (res) {
            if (res.ok && res.b.success) {
                var badge = document.createElement('span');
                badge.className = 'mb-refund mb-refund--requested';
                badge.textContent = LABELS.requested;
                btn.replaceWith(badge);
                if (window.toast) { toast(T.sent); }
            } else {
                btn.disabled = false;
                if (window.toast) { toast((res.b && res.b.message) || T.fail); }
            }
        }).catch(function () { btn.disabled = false; });
    });
})();
</script>
