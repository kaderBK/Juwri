{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- brew: order tracking as a pickup ticket — the big order number to show or say at the counter, a progress bar, where to collect it,
     help, then the receipt that sums to the charged total (each line's note, e.g. the name on the cup, is on it). Reads only $vm (page = track). --}}
@extends('store_templates._kit.layout', ['theme' => 'brew', 'bodyClass' => ! empty($vm['context']['kiosk']['on']) ? 'p-track p-kiosk p-kdone' : 'p-track'])
@php
    $__o = $vm['page']['order'];
    $__ready = in_array($__o['status'] ?? '', ['ready', 'delivered', 'completed'], true);
@endphp
@section('content')
@if (! empty($vm['context']['kiosk']['on']))
<main class="c-kdonemain" id="main">@include('store_templates._kit.kiosk.done', ['order' => $__o])</main>
@else
@include('store_templates.brew.sections.header', ['inner' => true])
<div class="c-topbar" role="region" aria-labelledby="topbar-title"><a class="c-iconbtn c-topbar__back" href="{{ ($vm['endpoints']['home'] ?? '/') }}" aria-label="{{ $kit->s('Back') }}">{{ $kit->icon('arrow-left') }}</a><p class="c-topbar__title" id="topbar-title">{{ $kit->s('Track order') }}</p></div>
<main class="c-pagemain c-track" id="main">
    <div class="c-track__cols">
        <div class="c-track__col">
            <section class="c-ticket{{ $__ready ? ' c-ticket--ready' : '' }}" aria-live="polite"><p class="c-ticket__label">{{ $kit->s('Your order number') }}</p>
                <p class="c-ticket__no"><span class="u-sr">{{ $kit->s('Order no. :number', ['number' => str_replace('-', ' ', $__o['number'])]) }}</span><bdi dir="ltr" aria-hidden="true">{{ $__o['number'] }}</bdi></p>
                <h1 class="c-ticket__status">{{ $__o['status_label'] }}</h1>
                @if (! empty($__o['eta_label']))<p class="c-ticket__eta">{{ $kit->icon('timer') }}<span>{{ $kit->flow($__o['eta_label']) }}</span></p>@endif
            </section>
            <ol class="c-progress" aria-label="{{ $kit->s('Pickup progress') }}">
            @foreach ($__o['steps'] as $__s)
                @php($__word = ['done' => 'done', 'current' => 'current'][$__s['state']] ?? 'upcoming')
                <li class="c-progress__item c-progress__item--{{ $__s['state'] }}" @if ($__s['state'] === 'current') aria-current="step" @endif><span class="c-progress__bar" aria-hidden="true"></span><span class="u-sr">{{ $kit->s(':step, '.$__word, ['step' => $__s['label']]) }}</span><span class="c-progress__label" aria-hidden="true">@if ($__s['state'] === 'done'){{ $kit->icon('check') }}@endif<span>{{ $__s['label'] }}</span></span></li>
            @endforeach
            </ol>
            @if (! empty($__o['location']))
            <section class="c-pickupat" aria-labelledby="pickupat-title"><h2 class="u-sr" id="pickupat-title">{{ $kit->s('Pickup at') }}</h2>
                {{ $kit->icon($__o['location']['icon'] ?? 'map-pin') }}<div class="c-pickupat__text"><p class="c-pickupat__name">{{ $__o['location']['label'] }}</p>@if (! empty($__o['location']['sub']))<p class="c-pickupat__sub">{{ $__o['location']['sub'] }}</p>@endif</div>
                @if (! empty($__o['location']['url']))<a class="c-iconbtn c-pickupat__go" href="{{ $__o['location']['url'] }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $kit->s('Directions') }}">{{ $kit->icon('navigation') }}</a>@endif
            </section>
            @endif
        </div>
        <div class="c-track__col">
            @if (! empty($__o['actions']))
            <section aria-labelledby="help-title"><h2 class="u-sr" id="help-title">{{ $kit->s('Need help?') }}</h2>
                <div class="c-help">
                @foreach ($__o['actions'] as $__a)
                    <a class="c-btn c-btn--secondary c-btn--pill" href="{{ $__a['url'] }}" @if (str_starts_with($__a['url'], 'http')) target="_blank" rel="noopener noreferrer" @endif>{{ $kit->icon($__a['icon'] ?? 'phone') }}<span>{{ $__a['label'] }}</span></a>
                @endforeach
                </div>
            </section>
            @endif
            <section class="c-card-block c-receipt" aria-labelledby="receipt-title"><h2 class="c-receipt__title" id="receipt-title">{{ $kit->s('Receipt') }}</h2>
                @include('store_templates.brew.components.receipt-lines', ['quote' => $__o['quote']])
                @include('store_templates._kit.components.quote-static', ['quote' => $__o['quote']])
            </section>
        </div>
    </div>
</main>
@include('store_templates._kit.sections.footer')
@endif
@endsection
