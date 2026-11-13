{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- book: the store page of a laundry. Reads only $vm. Each service is a row with a quantity: pieces for a per-piece service, kilos for a
     per-kg one. The rows are a plain form (POST, no script needed): "Choose a time" saves the cart and goes to the pickup times. theme.js adds
     the steppers and the running count. A laundry has no item sheet and no running total here: the server quotes the cart on the next step. --}}
@php
    $__s = $vm['store'];
    $__ctx = $vm['context'];
    $__types = $__ctx['order_types'];
    $__cats = $vm['catalog']['categories'];
    $__items = $vm['catalog']['items'];
    $__addr = $__s['contact']['address'] ?? null;
    $__boot = ['units' => [
        'none' => $kit->s('No service chosen'),
        'pieces' => $kit->s(':count pieces'), 'kg' => $kit->s(':kg kg'),
    ]];
@endphp
@include('store_templates.book.sections.header')
<script type="application/json" data-book-boot nonce="{{ csp_nonce() }}">@json($__boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
<form id="bk-form" method="post" action="{{ $vm['endpoints']['lines'] }}" data-book-laundry>@csrf</form>
<main class="c-main c-bkmain" id="main">
    <div class="c-bkmain__list">
        @include('store_templates.book.components.messages', ['messages' => $messages ?? []])
        <div class="c-bkcontrols">
            <section class="c-bkorder" data-section="order_type">
                @if (count($__types) > 1)
                <div class="c-seg c-bkseg" role="radiogroup" aria-label="{{ $kit->s('How') }}">
                    @foreach ($__types as $__t)
                    <label class="c-seg__opt"><input class="c-seg__input u-vh" type="radio" name="type" value="{{ $__t['key'] }}" form="bk-form" @checked($__t['key'] === $__ctx['selected_order_type'])><span class="c-seg__face">{{ $kit->icon($__t['key'] === 'delivery' ? 'truck' : 'store') }}<span>{{ $__t['key'] === 'delivery' ? $kit->s('We collect and deliver') : $kit->s('I drop off') }}</span></span></label>
                    @endforeach
                </div>
                @elseif (count($__types) === 1)
                <input type="hidden" name="type" value="{{ $__types[0]['key'] }}" form="bk-form">
                @endif
                @if ($__addr)<p class="c-bkloc">{{ $kit->icon('map-pin') }}<span>{{ $kit->flow($__addr) }}</span></p>@endif
            </section>
            @if (count($__cats) > 1)
            <nav class="c-bknav" data-section="category_nav" aria-label="{{ $kit->s('Service categories') }}"><ul class="c-bknav__list">@foreach ($__cats as $__c)<li><a class="c-chip" href="#cat-{{ $__c['id'] }}">{{ $__c['name'] }}</a></li>@endforeach</ul></nav>
            @endif
        </div>
        <div class="c-catalog" data-section="catalog">
            @forelse ($__cats as $__c)
            <section class="c-cat" id="cat-{{ $__c['id'] }}" aria-labelledby="h-{{ $__c['id'] }}"><h2 class="c-cat__title" id="h-{{ $__c['id'] }}">{{ $__c['name'] }}</h2>
                <ul class="c-svcs">
                @foreach ($__c['item_ids'] as $__id)
                    @php($__card = $__items[$__id])
                    @include('store_templates.book.components.laundry-service', ['card' => $__card])
                @endforeach
                </ul>
            </section>
            @empty
            @include('store_templates._kit.components.state-card', ['tone' => 'muted', 'icon' => 'scissors', 'title' => $kit->s('No services yet'), 'body' => $kit->s('This business has not added services to book.')])
            @endforelse
            <p class="c-bkempty" data-book-noresults hidden>{{ $kit->s('No services match your search.') }}</p>
        </div>
        @include('store_templates._kit.sections.info', ['pay' => $pay ?? []])
    </div>
    <aside class="c-bkpanel" data-section="cart_bar" aria-labelledby="bkpanel-title">
        <div class="c-bkpanel__scroll">
            <h2 class="c-bkpanel__title" id="bkpanel-title">{{ $kit->s('Your booking') }}</h2>
            <ul class="c-bklines" data-book-lines></ul>
            <p class="c-bkpanel__empty" data-book-empty>{{ $kit->s('Choose one or more services to start.') }}</p>
        </div>
        <div class="c-selfoot c-selfoot--panel">
            <p class="c-selfoot__text" role="status" aria-live="polite"><span data-book-summary>{{ $kit->s('No service chosen') }}</span></p>
            <button class="c-btn c-btn--primary c-btn--lg c-btn--block c-continue" type="submit" form="bk-form" data-book-continue><span>{{ $kit->s('Choose a time') }}</span></button>
        </div>
    </aside>
</main>
@include('store_templates._kit.sections.footer')
<div class="c-cartbar c-bkbar" data-section="cart_bar" role="complementary" aria-label="{{ $kit->s('Your booking') }}" data-book-bar>
    <button class="c-bkbar__pill" type="submit" form="bk-form"><span class="c-bkbar__sum"><span class="c-bkbar__line" data-book-summary>{{ $kit->s('No service chosen') }}</span></span><span class="c-bkbar__go"><span>{{ $kit->s('Choose a time') }}</span>{{ $kit->icon('arrow-right') }}</span></button>
</div>
