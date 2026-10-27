{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- book: step 3 — confirm: your details, how you pay, the summary that sums to the total. Reads only $vm (page = checkout). --}}
@extends('store_templates._kit.layout', ['theme' => 'book', 'bodyClass' => 'p-checkout'])
@php
    $__c = $vm['page']['checkout'];
    $__f = $__c['form'];
    $__autocomplete = ['name' => 'name', 'customer_name' => 'name', 'phone' => 'tel', 'phone_number' => 'tel', 'email' => 'email', 'address' => 'street-address'];
    $__laundry = ! empty($laundryMode);
    $__own = trim((string) ($vm['theme']['texts']['policy_note'] ?? ''));
@endphp
@section('content')
@include('store_templates.book.components.topbar', ['back' => $__f['back'], 'title' => $kit->s('Confirm booking'), 'step' => $__c['step_label'] ?? null])
<main class="c-pagemain" id="main">
<form class="c-bkcheckout" method="post" action="{{ $__f['action'] }}">@csrf @foreach ($__f['hidden'] as $__h)<input type="hidden" name="{{ $__h['name'] }}" value="{{ $__h['value'] }}">@endforeach
    @include('store_templates.book.components.messages', ['messages' => $__c['messages'] ?? []])
    @include('store_templates.book.components.appointment', ['id' => 'appt-h', 'title' => $__laundry ? $kit->s('Your times') : $kit->s('Appointment'), 'rows' => $__c['summary_rows'], 'change' => ['url' => $__f['back'], 'label' => $__laundry ? $kit->s('Change times') : $kit->s('Change time')]])
    <div class="c-bkcheckout__form">
        <fieldset class="c-section"><legend class="c-section__title">{{ $kit->s('Your details') }}</legend>
            <div class="c-section__fields">
            @foreach ($__c['fields'] as $__fl)
                <div class="c-field"><label class="c-field__label" for="f-{{ $__fl['id'] }}">{{ $__fl['label'] }}</label>
                @if (($__fl['type'] ?? 'text') === 'textarea')
                    <textarea class="c-input c-input--area" id="f-{{ $__fl['id'] }}" name="{{ $__fl['id'] }}" rows="3" maxlength="200">{{ $__fl['value'] ?? '' }}</textarea>
                @else
                    <input class="c-input" id="f-{{ $__fl['id'] }}" name="{{ $__fl['id'] }}" type="{{ $__fl['type'] ?? 'text' }}" value="{{ $__fl['value'] ?? '' }}" @if (! empty($__fl['dir'])) dir="{{ $__fl['dir'] }}" @endif @if (($__fl['type'] ?? '') === 'tel') inputmode="tel" @endif @if (isset($__autocomplete[$__fl['id']])) autocomplete="{{ $__autocomplete[$__fl['id']] }}" @endif @required(! empty($__fl['required']))>
                @endif
                </div>
            @endforeach
            </div>
        </fieldset>
        <fieldset class="c-section"><legend class="c-section__title">{{ $kit->s('Payment') }}</legend>
            <div class="c-choices">
            @foreach ($__c['payment_methods'] as $__p)
                <label class="c-choice c-choice--card" for="pay-{{ $__p['id'] }}"><input class="c-choice__input u-vh" type="radio" id="pay-{{ $__p['id'] }}" name="payment" value="{{ $__p['id'] }}" @checked(! empty($__p['selected']))><span class="c-choice__mark c-choice__mark--radio" aria-hidden="true"><span class="c-choice__dot"></span></span>{{ $kit->icon($__p['kind'] === 'venue' ? 'banknote' : 'credit-card') }}<span class="c-choice__text"><span class="c-choice__label">{{ $__p['label'] }}</span>@if (! empty($__p['sub']))<span class="c-choice__sub">{{ $kit->flow($__p['sub']) }}</span>@endif</span>@if (! empty($__p['amount']))<span class="c-choice__price c-choice__price--strong">{{ $kit->money($__p['amount']) }}</span>@endif</label>
            @endforeach
            </div>
        </fieldset>
    </div>
    <section class="c-bksum c-card-block" aria-labelledby="sum-h"><h2 class="c-section__title" id="sum-h">{{ $kit->s('Summary') }}</h2>
        @include('store_templates.book.components.summary', ['quote' => $__c['quote']])
        @if (! empty($__c['policy_note']) || $__own !== '')<section class="c-bkpolicy" aria-labelledby="pol-h">{{ $kit->icon('info') }}<div><h2 class="c-bkpolicy__title" id="pol-h">{{ $kit->s('Cancellation policy') }}</h2>@if (! empty($__c['policy_note']))<p>{{ $kit->flow($__c['policy_note']) }}</p>@endif @if ($__own !== '')<p>{{ $__own }}</p>@endif</div></section>@endif
        @if (! empty($__c['hold_note']))<p class="c-note c-note--tz">{{ $kit->icon('clock') }}<span>{{ $kit->flow($__c['hold_note']) }}</span></p>@endif
        <div class="c-confirm"><button class="c-btn c-btn--primary c-btn--lg c-btn--block" type="submit">{{ $kit->flow($__c['place_label']) }}</button></div>
    </section>
</form>
</main>
@include('store_templates._kit.sections.footer')
@endsection
