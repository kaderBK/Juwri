{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- book: the store page of a store that sells through the booking engine. Reads only $vm. The services are a plain form
     (GET, no script needed): tick the services, "Choose a time" goes to the booking engine. theme.js adds the running
     summary, the item sheet and the search. --}}
@php
    $__s = $vm['store'];
    $__accepting = $__s['status']['accepting_bookings'] ?? true;
    $__ctx = $vm['context'];
    $__types = $__ctx['order_types'];
    $__next = $__ctx['next_free_slot']['label'] ?? null;
    $__addr = $__s['contact']['address'] ?? null;
    $__cats = $vm['catalog']['categories'];
    $__items = $vm['catalog']['items'];
    $__boot = ['quote' => $vm['endpoints']['quote'] ?? null, 'home' => $vm['endpoints']['home'], 'units' => [
        'none' => $kit->s('No service chosen'),
        'counts' => [1 => $kit->s('One service'), 2 => $kit->s('Two services'), 3 => $kit->s('Three services'), 4 => $kit->s('Four services')],
        'min' => $kit->s(':count min'), 'h' => $kit->s(':count h'), 'hm' => $kit->s(':hours h :minutes min'),
        'located' => $kit->s('Location added'), 'locate_failed' => $kit->s('We could not get your location. Enter it above.'),
    ]];
    $__where = collect([$__addr, $__next ? $kit->s('Next free: :time', ['time' => $__next]) : null])->filter()->implode(' · ');
@endphp
@include('store_templates.book.sections.header')
<script type="application/json" data-book-boot nonce="{{ csp_nonce() }}">@json($__boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
<form id="bk-form" method="get" action="{{ $vm['endpoints']['times'] }}" data-book-form></form>
<main class="c-main c-bkmain" id="main">
    <div class="c-bkmain__list">
        @include('store_templates.book.components.messages', ['messages' => $messages ?? []])
        <div class="c-bkcontrols">
            <section class="c-bkorder" data-section="order_type">
                @if (count($__types) > 1)
                <div class="c-seg c-bkseg" role="radiogroup" aria-label="{{ $kit->s('Where') }}">
                    @foreach ($__types as $__t)
                    <label class="c-seg__opt"><input class="c-seg__input u-vh" type="radio" name="location" value="{{ \Juwri\Core\Support\ViewHelpers::bookLocation($__t['key']) }}" form="bk-form" data-book-location @checked($__t['key'] === $__ctx['selected_order_type'])><span class="c-seg__face">{{ $kit->icon($__t['key'] === 'at_home' ? 'house' : 'store') }}<span>{{ $__t['label'] }}</span></span></label>
                    @endforeach
                </div>
                @else
                <input type="hidden" name="location" value="venue" form="bk-form">
                @endif
                @if (\Juwri\Core\Support\ViewHelpers::hasKey($__types, 'at_home'))
                <fieldset class="c-bkgeo" data-book-geo hidden><legend class="c-bkgeo__title">{{ $kit->s('Your location') }}</legend>
                    <p class="c-bkgeo__help">{{ $kit->s('Add your location so we can check that you are in the service area.') }}</p>
                    <div class="c-bkgeo__row"><div class="c-field"><label class="c-field__label" for="bk-lat">{{ $kit->s('Latitude') }}</label><input class="c-input" id="bk-lat" type="number" step="any" inputmode="decimal" name="lat" form="bk-form" dir="ltr" data-book-lat></div><div class="c-field"><label class="c-field__label" for="bk-lng">{{ $kit->s('Longitude') }}</label><input class="c-input" id="bk-lng" type="number" step="any" inputmode="decimal" name="lng" form="bk-form" dir="ltr" data-book-lng></div></div>
                    <button class="c-btn c-btn--secondary" type="button" data-book-locate hidden>{{ $kit->icon('map-pin') }}<span>{{ $kit->s('Use my location') }}</span></button>
                    <p class="c-bkgeo__help" role="status" aria-live="polite" data-book-geo-status></p>
                </fieldset>
                @endif
                @if ($__where !== '')<p class="c-bkloc">{{ $kit->icon('map-pin') }}<span>{{ $kit->flow($__where) }}</span></p>@endif
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
                    @include('store_templates.book.components.service', ['card' => $__card, 'accepting' => $__accepting])
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
            <button class="c-btn c-btn--primary c-btn--lg c-btn--block c-continue" type="submit" form="bk-form" data-book-continue @disabled(! $__accepting)><span>{{ $kit->s('Choose a time') }}</span><bdi class="c-money" data-book-total></bdi></button>
        </div>
    </aside>
</main>
@include('store_templates._kit.sections.footer')
<div class="c-cartbar c-bkbar" data-section="cart_bar" role="complementary" aria-label="{{ $kit->s('Your booking') }}" data-book-bar>
    <button class="c-bkbar__pill" type="submit" form="bk-form" @disabled(! $__accepting)><span class="c-bkbar__sum"><span class="c-bkbar__line" data-book-summary>{{ $kit->s('No service chosen') }}</span><bdi class="c-money" data-book-total></bdi></span><span class="c-bkbar__go"><span>{{ $kit->s('Choose a time') }}</span>{{ $kit->icon('arrow-right') }}</span></button>
</div>
