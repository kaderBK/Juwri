{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  K1: post-payment review funnel. The instant an order confirms on the store's
  own menu, a 5-star card appears; 4-5★ opens the store's Google review page, 1-3★
  captures a private comment (never routed to Google). Reuses the shipped
  FeedbackService decision via StoreController@submitOrderReview.

  Self-contained (inline styles + nonce'd ES5), self-gating: renders NOTHING
  unless the merchant has filled google_review_url and the feature is allowed for
  this store. Hooks the send-order fetch/XHR RESPONSE to learn the track token,
  and stands down when a theme already shows a native rating prompt.
--}}
@php
    $__k1ReviewUrl = isset($store) ? trim((string) store_options($store->id, 'google_review_url', '')) : '';
    $__k1Allowed   = isset($store) && $__k1ReviewUrl !== ''
        && \App\Services\FeatureAccess::storeAllows((int) $store->id, 'support_desk');
@endphp
@if($__k1Allowed)
<script @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
(function () {
    "use strict";

    /* Stand down if the theme already shows a rating surface (e.g. the
       track-page mk-rating-prompt), so the diner is never asked twice. Asked at
       SHOW time: this partial is parsed above the content yield, so at parse
       time no theme markup exists and the check could never match. */
    function themeHasRatingSurface() {
        return !!document.querySelector('#mkRatingPrompt, [data-review-prompt]');
    }

    var FUNNEL_URL = @json(route('store.orderReviewFunnel', ['token' => '__TOKEN__']));
    var CSRF = @json(csrf_token());
    var shown = false;

    function tokenFrom(body) {
        // Prefer an explicit track_token, else parse it out of a track_url.
        try {
            var j = (typeof body === 'string') ? JSON.parse(body) : body;
            if (j && j.track_token) { return String(j.track_token); }
            var u = j && (j.track_url || j.trackUrl);
            if (u) { var m = String(u).match(/order\/track\/([A-Za-z0-9]+)/); if (m) { return m[1]; } }
        } catch (e) {}
        return null;
    }

    function render(token) {
        if (shown) { return; }
        shown = true;
        var card = document.createElement('div');
        card.id = 'mbReviewPrompt';
        card.setAttribute('dir', 'auto');
        card.style.cssText = 'position:fixed;left:50%;bottom:20px;transform:translateX(-50%);z-index:1090;max-width:420px;width:92%;padding:16px;background:#fff;color:#111;border:1px solid rgba(0,0,0,.12);border-radius:14px;box-shadow:0 8px 30px rgba(0,0,0,.18);text-align:center;';
        card.innerHTML =
            '<p style="margin:0 0 10px;font-size:14px;font-weight:700;">' + @json(___('How was your order?')) + '</p>' +
            '<div id="mbrevStars" style="display:flex;justify-content:center;gap:6px;direction:ltr;"></div>' +
            '<div id="mbrevPrivate" hidden style="margin-top:10px;">' +
                '<textarea id="mbrevComment" rows="2" maxlength="2000" placeholder="' + @json(___('Tell us more (optional)')) + '" style="width:100%;padding:10px;font-size:13px;border:1px solid rgba(0,0,0,.15);border-radius:10px;resize:vertical;"></textarea>' +
                '<button type="button" id="mbrevSend" style="margin-top:8px;min-height:40px;width:100%;font-size:14px;font-weight:700;border:0;border-radius:10px;cursor:pointer;background:#111;color:#fff;">' + @json(___('Send feedback')) + '</button>' +
            '</div>' +
            '<p id="mbrevThanks" hidden style="margin:8px 0 0;font-size:12.5px;opacity:.75;">' + @json(___('Thanks for your feedback!')) + '</p>' +
            '<button type="button" id="mbrevClose" aria-label="' + @json(___('Close')) + '" style="position:absolute;top:6px;right:10px;background:none;border:0;font-size:18px;cursor:pointer;opacity:.5;">&times;</button>';
        document.body.appendChild(card);

        var starsWrap = card.querySelector('#mbrevStars');
        for (var i = 1; i <= 5; i++) {
            var b = document.createElement('button');
            b.type = 'button';
            b.setAttribute('data-star', i);
            b.setAttribute('aria-label', i + ' / 5');
            b.textContent = '★';
            b.style.cssText = 'min-width:44px;min-height:44px;font-size:28px;line-height:1;background:none;border:0;cursor:pointer;opacity:.35;color:#f5b301;';
            starsWrap.appendChild(b);
        }
        card.querySelector('#mbrevClose').addEventListener('click', function () { card.remove(); });

        function post(rating, comment, done) {
            fetch(FUNNEL_URL.replace('__TOKEN__', encodeURIComponent(token)), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({ rating: rating, comment: comment || null })
            }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) { done(j || {}); }).catch(function () { done({}); });
        }

        Array.prototype.slice.call(starsWrap.querySelectorAll('button')).forEach(function (btn) {
            btn.addEventListener('click', function () {
                var rating = parseInt(btn.getAttribute('data-star'), 10);
                Array.prototype.slice.call(starsWrap.querySelectorAll('button')).forEach(function (x) {
                    x.style.opacity = (parseInt(x.getAttribute('data-star'), 10) <= rating) ? '1' : '.35';
                });
                post(rating, null, function (res) {
                    if (res.funnel === 'public_review' && res.review_url) {
                        window.open(res.review_url, '_blank', 'noopener');
                        card.querySelector('#mbrevThanks').hidden = false;
                        starsWrap.style.pointerEvents = 'none';
                    } else {
                        // 1-3★ → private comment path, never routed to Google.
                        starsWrap.style.pointerEvents = 'none';
                        card.querySelector('#mbrevPrivate').hidden = false;
                    }
                });
                var sendBtn = card.querySelector('#mbrevSend');
                if (sendBtn) {
                    sendBtn.onclick = function () {
                        post(rating, card.querySelector('#mbrevComment').value || null, function () {
                            card.querySelector('#mbrevPrivate').hidden = true;
                            card.querySelector('#mbrevThanks').hidden = false;
                        });
                    };
                }
            });
        });
    }

    function maybeShow(body) {
        var token = tokenFrom(body);
        if (!token) { return; }
        // Let the theme paint its own prompt first, then decide.
        setTimeout(function () {
            if (themeHasRatingSurface()) { return; }
            render(token);
        }, 300);
    }
    function isSendOrder(u) { return typeof u === 'string' && u.indexOf('/send-order') !== -1; }

    // fetch
    var of = window.fetch;
    if (of) {
        window.fetch = function (input, init) {
            var u = (typeof input === 'string') ? input : (input && input.url);
            var m = (init && init.method) || (input && input.method) || 'GET';
            var isSend = String(m).toUpperCase() === 'POST' && isSendOrder(u);
            var p = of.apply(this, arguments);
            if (isSend && p && p.then) {
                p.then(function (res) {
                    try { res.clone().text().then(function (t) { maybeShow(t); }); } catch (e) {}
                    return res;
                });
            }
            return p;
        };
    }

    // XHR (jQuery $.ajax etc.)
    var op = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method, url) {
        this.__mbRev = (String(method).toUpperCase() === 'POST' && isSendOrder(url));
        if (this.__mbRev) {
            this.addEventListener('load', function () {
                try { maybeShow(this.responseText); } catch (e) {}
            });
        }
        return op.apply(this, arguments);
    };
})();
</script>
@endif
