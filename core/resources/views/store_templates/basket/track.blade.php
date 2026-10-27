{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- basket §10: order tracking — the status head (live region), the four steps (Placed, Picking, On the way, Delivered), how many items
     are picked, "Changes to review" (a replacement the picker proposed: keep it or refund it, one answer per line), help, then the
     receipt that sums to the charged total (a weighed line shows what was weighed). A kiosk shows the done screen. Reads only $vm. --}}
@extends('store_templates._kit.layout', ['theme' => 'basket', 'bodyClass' => ! empty($vm['context']['kiosk']['on']) ? 'p-track p-kiosk p-kdone' : 'p-track'])
@php
    $__o = $vm['page']['order'];
    $__kiosk = ! empty($vm['context']['kiosk']['on']);
    $__review = $__o['review'] ?? [];
    $__answer = $vm['endpoints']['replacement'] ?? null;
    $__current = \Juwri\Core\Support\ViewHelpers::firstOfKind($__o['steps'], 'state', 'current');
    $__links = \Juwri\Core\Support\ViewHelpers::withUrl($__o['actions'] ?? [], true);
    $__asks = \Juwri\Core\Support\ViewHelpers::withUrl($__o['actions'] ?? [], false); // answers the design draws for the whole review (the product asks per line)
@endphp
@section('content')
@if ($__kiosk)
<main class="c-kdonemain" id="main">@include('store_templates._kit.kiosk.done', ['order' => $__o])</main>
@else
@include('store_templates.basket.sections.header', ['h1' => false])
<div class="c-topbar" role="region" aria-labelledby="topbar-title"><a class="c-iconbtn c-topbar__back" href="{{ $vm['endpoints']['home'] ?? '/' }}" aria-label="{{ $kit->s('Back') }}">{{ $kit->icon('arrow-left') }}</a><p class="c-topbar__title" id="topbar-title">{{ $kit->s('Track order') }}</p></div>
<main class="c-pagemain c-track" id="main">
    <div class="c-track__cols">
        <div class="c-track__col">
            <section class="c-statushead" aria-live="polite"><p class="c-statushead__no">{{ $kit->t('Order no. :number', ['number' => $kit->ltr($__o['number'])]) }}</p><h1 class="c-statushead__status">{{ $__o['status_label'] }}</h1>@if (! empty($__o['eta_label']))<p class="c-statushead__eta">{{ $kit->flow($__o['eta_label']) }}</p>@endif</section>
            <ol class="c-stepbar" aria-label="{{ $kit->s('Order progress') }}">
            @foreach ($__o['steps'] as $__s)
                @php($__word = ['done' => 'done', 'current' => 'current'][$__s['state']] ?? 'upcoming')
                <li class="c-stepbar__item c-stepbar__item--{{ $__s['state'] }}" @if ($__s['state'] === 'current') aria-current="step" @endif><span class="c-stepbar__bar" aria-hidden="true"></span><span class="u-sr">{{ $kit->s(':step, '.$__word, ['step' => $__s['label']]) }}</span><span class="c-stepbar__label" aria-hidden="true">{{ $__s['label'] }}</span></li>
            @endforeach
            </ol>
            @if ($__current && ! empty($__current['sub']))
            <section class="c-pick" aria-label="{{ $__current['label'] }}"><span class="c-pick__icon">{{ $kit->icon('shopping-basket') }}</span><div class="c-pick__text"><p class="c-pick__title">{{ $kit->flow($__current['sub']) }}</p></div></section>
            @endif
            @if ($__review)
            <section class="c-review" role="alert" aria-labelledby="review-title" data-bk-review @if ($__answer) data-url="{{ $__answer }}" @endif>
                <div class="c-review__head">{{ $kit->icon('alert-triangle') }}<div><h2 class="c-review__title" id="review-title">{{ $kit->s('Changes to review') }}</h2><p class="c-review__sub">{{ $kit->n('replacement', count($__review)) }}</p></div></div>
                <ul class="c-swaps">
                @foreach ($__review as $__r)
                    <li class="c-swap" data-line="{{ $__r['id'] }}">@if (! empty($__r['thumb']))<img class="c-swap__thumb" src="{{ $__r['thumb']['src'] }}" alt="" width="56" height="56" decoding="async" loading="lazy">@endif
                        <div class="c-swap__body"><p class="c-swap__name">{{ $__r['name'] }}</p>@if (! empty($__r['substitution']))<p class="c-swap__from">{{ $kit->icon('refresh-cw') }}<span>{{ $kit->flow($__r['substitution']) }}</span></p>@endif
                            @if ($__answer)<div class="c-review__actions"><button class="c-btn c-btn--secondary" type="button" data-bk-answer="refused" data-line="{{ $__r['id'] }}">{{ $kit->icon('rotate-ccw') }}<span>{{ $kit->s('Refund instead') }}</span></button><button class="c-btn c-btn--primary" type="button" data-bk-answer="kept" data-line="{{ $__r['id'] }}">{{ $kit->icon('check') }}<span>{{ $kit->s('Keep replacement') }}</span></button></div>@endif
                        </div>
                        <p class="c-swap__price">{{ $kit->money($__r['line_total']) }}</p></li>
                @endforeach
                </ul>
                <p class="c-review__help">{{ $kit->s('Refund instead or keep the replacement') }}</p>
                @if (! $__answer && $__asks->isNotEmpty())<div class="c-review__actions">@foreach ($__asks as $__a)<button class="c-btn c-btn--{{ $__a['kind'] ?? 'secondary' }}" type="button">{{ $kit->icon($__a['icon'] ?? 'check') }}<span>{{ $__a['label'] }}</span></button>@endforeach</div>@endif
                <p class="c-field__help" data-bk-review-message role="status"></p>
            </section>
            @endif
        </div>
        <div class="c-track__col">
            @if ($__links->isNotEmpty())
            <div class="c-help" role="group" aria-label="{{ $kit->s('Need help?') }}">
                @foreach ($__links as $__a)
                <a class="c-btn c-btn--secondary c-btn--block" href="{{ $__a['url'] }}" @if (str_starts_with($__a['url'], 'http')) target="_blank" rel="noopener noreferrer" @endif>{{ $kit->icon($__a['icon'] ?? 'phone') }}<span>{{ $__a['label'] }}</span></a>
                @endforeach
            </div>
            @endif
            <section class="c-panel c-receipt" aria-labelledby="receipt-title"><h2 class="c-panel__title" id="receipt-title">{{ $kit->s('Receipt') }}</h2>
                <ul class="c-rlines">
                @foreach ($__o['quote']['lines'] as $__l)
                    <li class="c-rline"><span class="c-rline__qty"><bdi>{{ $__l['qty'] }}</bdi>×</span><div class="c-rline__body"><p class="c-rline__name">{{ $__l['name'] }}</p>@if (! empty($__l['unit_label']))<p class="c-rline__unit">{{ $kit->flow($__l['unit_label']) }}</p>@endif @if (! empty($__l['note']))<p class="c-bkline__note c-bkline__note--plain">{{ $kit->flow($__l['note']) }}</p>@endif @if (! empty($__l['substitution']))<p class="c-bkline__note c-bkline__note--plain">{{ $kit->flow($__l['substitution']) }}</p>@endif @if (! empty($__l['warning']))<p class="c-bkline__note">{{ $__l['warning'] }}</p>@endif</div><p class="c-rline__price">@if (! empty($__l['estimate']))<span aria-hidden="true">~ </span>@endif{{ $kit->money($__l['line_total']) }}</p></li>
                @endforeach
                </ul>
                @include('store_templates._kit.components.quote-static', ['quote' => $__o['quote']])
                @if (! empty($__o['quote']['tax_note']))<p class="c-quote__note">{{ $__o['quote']['tax_note'] }}</p>@endif
            </section>
        </div>
    </div>
</main>
@include('store_templates._kit.sections.footer')
@endif
@endsection
