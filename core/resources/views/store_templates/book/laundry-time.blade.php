{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- book: the laundry's step 2 — choose the pickup (or drop-off) time and, when we bring it back, the delivery time. ONE hold covers both.
     Reads only $vm (page = checkout of the laundry flow). --}}
@extends('store_templates._kit.layout', ['theme' => 'book', 'bodyClass' => 'p-time p-laundry'])
@php
    $__c = $vm['page']['checkout'];
    $__f = $__c['form'];
    $__rows = $__c['summary_rows'] ?? [];
    $__ret = $__c['return_slots'] ?? null;
    $__keep = collect($__f['hidden'])->firstWhere('name', 'pickup_keep');
    $__keepR = collect($__f['hidden'])->firstWhere('name', 'return_keep');
@endphp
@section('content')
@include('store_templates.book.components.topbar', ['back' => $__f['back'], 'title' => $kit->s('Choose a time'), 'step' => $__c['step_label'] ?? null])
<main class="c-pagemain c-timepage" id="main">
    <form id="tp-refresh" method="get" action="{{ $__f['refresh'] }}" data-book-refresh>@foreach ($__f['hidden'] as $__h)@if (! in_array($__h['name'], ['pickup_keep', 'return_keep'], true))<input type="hidden" name="{{ $__h['name'] }}" value="{{ $__h['value'] }}">@endif @endforeach<input type="hidden" name="pickup" value="{{ $__keep['value'] ?? '' }}" data-book-keep="pickup"><input type="hidden" name="return" value="{{ $__keepR['value'] ?? '' }}" data-book-keep="return"></form>
    <form id="tp-hold" method="post" action="{{ $__f['action'] }}">@csrf @foreach ($__f['hidden'] as $__h)@if (! in_array($__h['name'], ['pickup_keep', 'return_keep'], true))<input type="hidden" name="{{ $__h['name'] }}" value="{{ $__h['value'] }}">@endif @endforeach</form>
    <div class="c-timepage__main">
        @include('store_templates.book.components.messages', ['messages' => $__c['messages'] ?? []])
        <div class="c-sum"><div class="c-sum__text"><p class="c-sum__name">{{ $__rows[0]['label'] ?? '' }}</p><p class="c-sum__sub">{{ $kit->flow($__rows[0]['sub'] ?? '') }}</p></div><a class="c-sum__edit" href="{{ $__f['back'] }}">{{ $kit->s('Edit') }}</a></div>
        @include('store_templates.book.components.laundry-picker', ['pre' => 'p', 'picker' => $__c['slots'], 'dateName' => 'date', 'slotName' => 'pickup', 'required' => true])
        @if ($__ret)
        @include('store_templates.book.components.laundry-picker', ['pre' => 'r', 'picker' => $__ret, 'dateName' => 'return_date', 'slotName' => 'return', 'required' => true])
        @endif
        <button class="c-btn c-btn--secondary" type="submit" form="tp-refresh" data-book-refresh-btn>{{ $kit->s('Show times') }}</button>
    </div>
    <aside class="c-timepage__side" aria-label="{{ $kit->s('Your selection') }}">
        <div class="c-selfoot"><p class="c-selfoot__text" role="status" aria-live="polite">{{ $kit->icon('calendar') }}<span data-book-picked>{{ $kit->s('Choose a time') }}</span></p>
            <section class="c-bksum" aria-label="{{ $kit->s('Summary') }}">
                @include('store_templates.book.components.summary', ['quote' => $__c['quote']])
            </section>
            <button class="c-btn c-btn--primary c-btn--lg c-btn--block c-continue" type="submit" form="tp-hold"><span>{{ $__c['place_label'] }}</span><bdi class="c-money">{{ $__c['quote']['total']['formatted'] }}</bdi></button>
        </div>
    </aside>
</main>
@include('store_templates._kit.sections.footer')
@endsection
