{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- MARKETPLACE T2-4: post-delivery rating prompt for the order track page.
     Shown ONLY for delivered/completed orders that came in from the customer
     marketplace (orders.source = 'marketplace'). One prompt per order — a
     menu_reviews row carrying this order_id flips the card to a thanks state.
     Self-contained like prep-eta: inline styles + nonce'd JS, no per-theme CSS. --}}
@php
    // F13: direct (QR) orders become rateable when the platform opts in
    // (settings.storefront_direct_ratings, dark). Marketplace orders always are.
    $mkTrackRateable = isset($order)
        && in_array($order->status, ['delivered', 'completed'], true)
        && $order->items->first()?->item_id
        && ((($order->source ?? null) === 'marketplace') || config('settings.storefront_direct_ratings', false));
    $mkTrackRated = $mkTrackRateable
        && \App\Models\MenuReview::where('order_id', $order->id)->exists();
@endphp
@if($mkTrackRateable)
<div id="mkRatingPrompt" dir="auto"
     style="margin:16px auto 0;max-width:420px;padding:16px;border:1px solid rgba(0,0,0,.12);border-radius:14px;text-align:center;">
    <div id="mkRateThanks"{!! $mkTrackRated ? '' : ' hidden' !!}>
        <p style="margin:0;font-size:14px;font-weight:700;">{{ ___('Thanks for rating your order!') }}</p>
        <p style="margin:4px 0 0;font-size:12.5px;opacity:.75;">{{ ___('Your review will appear once approved.') }}</p>
    </div>
    <form id="mkRateForm"{!! $mkTrackRated ? ' hidden' : '' !!}>
        <p style="margin:0 0 10px;font-size:14px;font-weight:700;">{{ ___('How was your order?') }}</p>
        <div id="mkRateStars" role="radiogroup" aria-label="{{ ___('Rating') }}"
             style="display:flex;justify-content:center;gap:4px;direction:ltr;">
            @for($mkStar = 1; $mkStar <= 5; $mkStar++)
                <button type="button" class="mk-rate-star" data-star="{{ $mkStar }}"
                        aria-label="{{ $mkStar }} / 5"
                        style="min-width:44px;min-height:44px;font-size:26px;line-height:1;background:none;border:0;cursor:pointer;opacity:.35;">&#9733;</button>
            @endfor
        </div>
        <textarea id="mkRateBody" maxlength="{{ (int) config('marketplace.review.body_max', 2000) }}" rows="2"
                  placeholder="{{ ___('Tell us more (optional)') }}"
                  style="width:100%;margin-top:10px;padding:10px;font-size:13px;border:1px solid rgba(0,0,0,.15);border-radius:10px;resize:vertical;"></textarea>
        <button type="submit" id="mkRateSubmit" disabled
                style="margin-top:10px;min-height:44px;width:100%;font-size:14px;font-weight:700;border:0;border-radius:10px;cursor:pointer;">
            {{ ___('Submit rating') }}
        </button>
        <p id="mkRateError" hidden style="margin:8px 0 0;font-size:12.5px;color:#c0392b;"></p>
    </form>
</div>
<script nonce="{{ csp_nonce() }}">
(function () {
    "use strict";
    var form = document.getElementById('mkRateForm');
    if (!form || form.hidden) { return; }
    var stars = Array.prototype.slice.call(document.querySelectorAll('.mk-rate-star'));
    var submit = document.getElementById('mkRateSubmit');
    var errEl = document.getElementById('mkRateError');
    var picked = 0;
    function paint() {
        stars.forEach(function (b) {
            b.style.opacity = (parseInt(b.getAttribute('data-star'), 10) <= picked) ? '1' : '.35';
        });
        submit.disabled = picked < 1;
    }
    stars.forEach(function (b) {
        b.addEventListener('click', function () {
            picked = parseInt(b.getAttribute('data-star'), 10);
            paint();
        });
    });
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (picked < 1) { return; }
        submit.disabled = true;
        errEl.hidden = true;
        fetch(@json(route('store.orderTrackReview', $order->track_token)), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
            body: JSON.stringify({ stars: picked, body: document.getElementById('mkRateBody').value || null })
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
            if (res.ok && res.j && res.j.success) {
                form.hidden = true;
                document.getElementById('mkRateThanks').hidden = false;
            } else {
                errEl.textContent = (res.j && res.j.message) ? res.j.message : @json(___('Something went wrong. Please try again.'));
                errEl.hidden = false;
                submit.disabled = false;
            }
        }).catch(function () {
            errEl.textContent = @json(___('Something went wrong. Please try again.'));
            errEl.hidden = false;
            submit.disabled = false;
        });
    });
})();
</script>
@endif
