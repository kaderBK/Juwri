{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- book: step 2 — choose a time (also the reschedule page, `$reschedule`). Reads only $vm (page = checkout of the booking engine). --}}
@extends('store_templates._kit.layout', ['theme' => 'book', 'bodyClass' => ! empty($reschedule) ? 'p-time p-reschedule' : 'p-time'])
@php
    $__c = $vm['page']['checkout'];
    $__f = $__c['form'];
    $__re = ! empty($reschedule);
    $__rows = $__c['summary_rows'] ?? [];
@endphp
@section('content')
@include('store_templates.book.components.topbar', ['back' => $__f['back'], 'title' => $__re ? $kit->s('Reschedule') : $kit->s('Choose a time'), 'step' => $__c['step_label'] ?? null])
<main class="c-pagemain c-timepage" id="main">
    <form id="tp-refresh" method="get" action="{{ $__f['refresh'] }}" data-book-refresh>@foreach ($__f['hidden'] as $__h)<input type="hidden" name="{{ $__h['name'] }}" value="{{ $__h['value'] }}">@endforeach</form>
    <form id="tp-hold" method="post" action="{{ $__f['action'] }}">@csrf @foreach ($__f['hidden'] as $__h)<input type="hidden" name="{{ $__h['name'] }}" value="{{ $__h['value'] }}">@endforeach @if (($__c['selected_staff'] ?? 'any') !== 'any')<input type="hidden" name="staff_id" value="{{ $__c['selected_staff'] }}">@endif</form>
    <div class="c-timepage__main">
        @include('store_templates.book.components.messages', ['messages' => $__c['messages'] ?? []])
        @if ($__re)
        @include('store_templates.book.components.appointment', ['id' => 'rs-cur-h', 'title' => $kit->s('Current booking'), 'rows' => $__rows])
        <section class="c-rspick" aria-labelledby="rs-new-h"><h2 class="c-section__title" id="rs-new-h">{{ $kit->s('Choose a new time') }}</h2>
            @include('store_templates.book.components.picker', ['pre' => 'r'])
        </section>
        @if (! empty($__c['policy_note']))<section class="c-bkpolicy" aria-labelledby="rs-note-h">{{ $kit->icon('info') }}<div><h2 class="c-bkpolicy__title" id="rs-note-h">{{ $kit->s('Before you confirm') }}</h2><p>{{ $kit->flow($__c['policy_note']) }}</p></div></section>@endif
        <section class="c-bksum c-card-block" aria-labelledby="rs-sum-h"><h2 class="c-section__title" id="rs-sum-h">{{ $kit->s('Summary') }}</h2>
            @include('store_templates.book.components.summary', ['quote' => $__c['quote']])
        </section>
        @else
        <div class="c-sum"><div class="c-sum__text"><p class="c-sum__name">{{ $__rows[0]['label'] ?? '' }}</p><p class="c-sum__sub">{{ $kit->flow($__rows[0]['sub'] ?? '') }}</p></div><a class="c-sum__edit" href="{{ $__f['back'] }}">{{ $kit->s('Edit') }}</a></div>
        @include('store_templates.book.components.picker', ['pre' => 't'])
        @endif
        <button class="c-btn c-btn--secondary" type="submit" form="tp-refresh" data-book-refresh-btn>{{ $kit->s('Show times') }}</button>
    </div>
    <aside class="c-timepage__side" aria-label="{{ $kit->s('Your selection') }}">
        <div class="c-selfoot"><p class="c-selfoot__text" role="status" aria-live="polite">{{ $kit->icon('calendar') }}<span data-book-picked>{{ $kit->s('Choose a time') }}</span></p>
            <button class="c-btn c-btn--primary c-btn--lg c-btn--block c-continue" type="submit" form="tp-hold"><span>{{ $__c['place_label'] }}</span>@unless ($__re)<bdi class="c-money">{{ $__c['quote']['total']['formatted'] }}</bdi>@endunless</button>
            @if ($__re)<a class="c-btn c-btn--ghost c-btn--block" href="{{ $__f['back'] }}">{{ $kit->s('Keep current time') }}</a>@endif
        </div>
    </aside>
</main>
@include('store_templates._kit.sections.footer')
@endsection
