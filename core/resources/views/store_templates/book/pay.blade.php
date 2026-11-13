{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- book: pay the deposit — the booking waits for it. Reads only $vm (page = checkout). --}}
@extends('store_templates._kit.layout', ['theme' => 'book', 'bodyClass' => 'p-checkout p-pay'])
@php
    $__c = $vm['page']['checkout'];
    $__f = $__c['form'];
@endphp
@section('content')
@include('store_templates.book.components.topbar', ['back' => $__f['back'], 'title' => $kit->s('Pay the deposit'), 'step' => null])
<main class="c-pagemain" id="main">
<form class="c-bkcheckout" method="post" action="{{ $__f['action'] }}">@csrf
    @include('store_templates.book.components.messages', ['messages' => $__c['messages'] ?? []])
    @include('store_templates.book.components.appointment', ['id' => 'appt-h', 'title' => $kit->s('Appointment'), 'rows' => $__c['summary_rows']])
    <div class="c-bkcheckout__form">
        <fieldset class="c-section"><legend class="c-section__title">{{ $kit->s('Payment') }}</legend>
            @if (empty($__c['payment_methods']))
            <p class="c-note c-note--warn">{{ $kit->icon('info') }}<span>{{ $kit->s('Online payment is not available at this business right now.') }}</span></p>
            @endif
            <div class="c-choices">
            @foreach ($__c['payment_methods'] as $__p)
                <label class="c-choice c-choice--card" for="pay-{{ $loop->index }}"><input class="c-choice__input u-vh" type="radio" id="pay-{{ $loop->index }}" name="payment_method" value="{{ $__p['id'] }}" @checked(! empty($__p['selected'])) required><span class="c-choice__mark c-choice__mark--radio" aria-hidden="true"><span class="c-choice__dot"></span></span>{{ $kit->icon($__p['icon'] ?? 'credit-card') }}<span class="c-choice__text"><span class="c-choice__label">{{ $__p['label'] }}</span></span></label>
            @endforeach
            </div>
        </fieldset>
    </div>
    <section class="c-bksum c-card-block" aria-labelledby="sum-h"><h2 class="c-section__title" id="sum-h">{{ $kit->s('Summary') }}</h2>
        @include('store_templates.book.components.summary', ['quote' => $__c['quote']])
        @if (! empty($__c['hold_note']))<p class="c-note c-note--warn">{{ $kit->icon('clock') }}<span>{{ $kit->flow($__c['hold_note']) }}</span></p>@endif
        <div class="c-confirm"><button class="c-btn c-btn--primary c-btn--lg c-btn--block" type="submit" @disabled(empty($__c['payment_methods']))>{{ $kit->flow($__c['place_label']) }}</button></div>
    </section>
</form>
</main>
@include('store_templates._kit.sections.footer')
@endsection
