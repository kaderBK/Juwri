{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle §8: the bag — a full-height sheet on a phone, a drawer from the inline end (440 px) on desktop; focus trap, Esc, scrim and
     the inert page behind are the core's. The sticky button opens the checkout. --}}
<div id="cart" class="c-overlay c-overlay--drawer" data-juwri-sheet role="dialog" aria-modal="true" aria-labelledby="bag-title" @unless (($open ?? null) === 'cart') hidden @endunless>
    <div class="c-overlay__scrim" data-juwri-overlay aria-hidden="true"></div>
    <div class="c-drawer">
        <div class="c-drawer__head"><h2 class="c-drawer__title" id="bag-title" tabindex="-1">{{ $kit->s('Bag') }} (<bdi data-juwri-count>0</bdi>)</h2><button class="c-iconbtn" type="button" data-juwri-close aria-label="{{ $kit->s('Close') }}">{{ $kit->icon('x') }}</button></div>
        <div class="c-drawer__scroll">
            @include('store_templates.aisle.components.bag-body', ['idp' => 'd'])
            <div class="c-queue" data-juwri-queue>
                <template data-juwri-queue-item><div class="c-state c-state--warning"><div class="c-state__body"><h3 class="c-state__title" data-juwri-queue-state></h3><p class="c-state__text"><span data-juwri-queue-total></span> <span data-juwri-queue-detail></span></p><div class="c-state__actions"><button class="c-btn c-btn--primary" type="button" data-juwri-queue-confirm hidden>{{ $kit->s('Confirm') }}</button><button class="c-btn c-btn--secondary" type="button" data-juwri-queue-retry hidden>{{ $kit->s('Try again') }}</button><button class="c-btn c-btn--ghost" type="button" data-juwri-queue-dismiss>{{ $kit->s('Cancel') }}</button></div></div></div></template>
            </div>
        </div>
        <div class="c-drawer__foot" data-juwri-filled hidden>
            <button class="c-btn c-btn--primary c-btn--lg c-btn--block c-bag__cta" type="button" data-juwri-open="checkout" aria-controls="checkout" aria-expanded="false">{{ $kit->icon('lock') }}<span>{{ $kit->s('Checkout securely') }}</span><span aria-hidden="true">·</span><span data-juwri-total></span></button>
        </div>
    </div>
</div>
