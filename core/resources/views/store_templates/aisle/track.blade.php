{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle §10: order tracking (shipment) — the status head (live region), the timeline with the carrier + tracking number, "Track with
     carrier" (an external link, says so in its name), the items + the receipt that sums to the charged total, and the after-sale
     actions: Start a return (a dialog; the store decides what can still be returned) and Get help. Reads only $vm (page = track). --}}
@extends('store_templates._kit.layout', ['theme' => 'aisle', 'bodyClass' => ! empty($vm['context']['kiosk']['on']) ? 'p-track p-kiosk p-kdone' : 'p-track'])
@php
    $__o = $vm['page']['order'];
    $__kiosk = ! empty($vm['context']['kiosk']['on']);
    $__carrier = $__o['carrier'] ?? null;
    $__returns = $vm['endpoints']['returns'] ?? null;
    $__refund = ! empty($vm['context']['refunds']['returns']);
@endphp
@section('content')
@if ($__kiosk)
<main class="c-kdonemain" id="main">@include('store_templates._kit.kiosk.done', ['order' => $__o])</main>
@else
@include('store_templates.aisle.sections.announcement')
<header class="c-topbar"><a class="c-iconbtn c-topbar__back" href="{{ $vm['endpoints']['home'] ?? '/' }}" aria-label="{{ $kit->s('Back') }}">{{ $kit->icon('arrow-left') }}</a><p class="c-topbar__title">{{ $kit->s('Track order') }}</p><a class="c-topbar__brand" href="{{ $vm['endpoints']['home'] ?? '/' }}">{{ $vm['store']['name'] }}</a></header>
<main class="c-pagemain c-track" id="main">
    <div class="c-track__cols">
        <div class="c-track__col">
            <section class="c-panel c-statushead" aria-live="polite"><p class="c-statushead__no">{{ $kit->t('Order no. :number', ['number' => $kit->ltr($__o['number'])]) }}</p><h1 class="c-statushead__status">{{ $__o['status_label'] }}</h1>@if (! empty($__o['eta_label']))<p class="c-statushead__eta">{{ $kit->flow($__o['eta_label']) }}</p>@endif</section>
            <section class="c-panel" aria-labelledby="progress-title"><h2 class="c-panel__title" id="progress-title">{{ $kit->s('Order progress') }}</h2>
                <ol class="c-steps">
                @foreach ($__o['steps'] as $__s)
                    @php($__word = ['done' => 'done', 'current' => 'current'][$__s['state']] ?? 'upcoming')
                    <li class="c-steps__item c-steps__item--{{ $__s['state'] }}" @if ($__s['state'] === 'current') aria-current="step" @endif><span class="c-steps__marker" aria-hidden="true">@if ($__s['state'] === 'done'){{ $kit->icon('check') }}@endif</span><span class="c-steps__text"><span class="u-sr">{{ $kit->s(':step, '.$__word, ['step' => $__s['label']]) }}. </span><span class="c-steps__label" aria-hidden="true">{{ $__s['label'] }}</span>@if (! empty($__s['sub']))<span class="c-steps__sub">{{ $kit->flow($__s['sub']) }}</span>@endif</span></li>
                @endforeach
                </ol>
                @if (! empty($__carrier['url']))<a class="c-btn c-btn--primary c-btn--block c-carrier" href="{{ $__carrier['url'] }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $kit->s('Track on :carrier (opens in a new tab)', ['carrier' => $__carrier['name'] ?? $kit->s('the carrier')]) }}">{{ $kit->icon('external-link') }}<span>{{ $kit->s('Track on :carrier', ['carrier' => $__carrier['name'] ?? $kit->s('the carrier')]) }}</span></a>@endif
            </section>
        </div>
        <div class="c-track__col">
            <section class="c-panel" aria-labelledby="items-title"><h2 class="c-panel__title" id="items-title">{{ $kit->s('In this order') }}</h2>
                <ul class="c-slines">
                @foreach ($__o['quote']['lines'] as $__l)
                    <li class="c-sline">@if (! empty($__l['thumb']))<img class="c-sline__thumb" src="{{ $__l['thumb']['src'] }}" alt="" width="64" height="80" decoding="async" loading="lazy">@else<span class="c-sline__thumb" aria-hidden="true"></span>@endif<div class="c-sline__body"><p class="c-sline__name">{{ $__l['name'] }}</p><p class="c-sline__variant">@if (! empty($__l['choices_label'])){{ $kit->flow($__l['choices_label']) }} · @endif<bdi dir="ltr" class="c-code">{{ $__l['qty'] }}×</bdi></p></div><p class="c-sline__price"><span class="c-sline__now">{{ $kit->money($__l['line_total']) }}</span>@if (! empty($__l['compare_at']))<s class="c-sline__was"><span aria-hidden="true">{{ $kit->money($__l['compare_at']) }}</span></s>@endif</p></li>
                @endforeach
                </ul>
                @include('store_templates._kit.components.quote-static', ['quote' => $__o['quote']])
                @if (! empty($__o['paid_label']))<p class="c-paid">{{ $kit->icon('check') }}<span>{{ $__o['paid_label'] }}</span></p>@endif
            </section>
            <section class="c-panel" aria-labelledby="after-title"><h2 class="c-panel__title" id="after-title">{{ $kit->s('Need help?') }}</h2>
                <div class="c-help">
                    @if ($__returns)<button class="c-btn c-btn--secondary" type="button" data-kit-returns-open data-url="{{ $__returns }}" data-juwri-open="returns" aria-controls="returns" aria-haspopup="dialog" aria-expanded="false">{{ $kit->icon('rotate-ccw') }}<span>{{ $kit->s('Start a return') }}</span></button>@endif
                    @foreach ($__o['actions'] ?? [] as $__a)
                    <a class="c-btn c-btn--ghost" href="{{ $__a['url'] }}" @if (str_starts_with($__a['url'], 'http')) target="_blank" rel="noopener noreferrer" @endif>{{ $kit->icon($__a['icon'] ?? 'phone') }}<span>{{ $__a['label'] }}</span></a>
                    @endforeach
                </div>
            </section>
        </div>
    </div>
</main>
@include('store_templates._kit.sections.footer')
@endif
@endsection
@section('dialogs')
@if (! empty($vm['endpoints']['returns']) && empty($vm['context']['kiosk']['on']))
@include('store_templates.aisle.components.returns-dialog', ['refund' => $__refund])
@endif
@endsection
