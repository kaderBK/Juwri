{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- book: the booking page (the signed manage link): ticket, progress, directions, receipt, manage actions. Reads only $vm (page = track). --}}
@extends('store_templates._kit.layout', ['theme' => 'book', 'bodyClass' => 'p-track'])
@php
    $__o = $vm['page']['order'];
    $__top = \Juwri\Core\Support\ViewHelpers::actionsByIcon($__o['actions'] ?? [], ['calendar-plus', 'navigation', 'credit-card'], true);
    $__manage = \Juwri\Core\Support\ViewHelpers::actionsByIcon($__o['actions'] ?? [], ['calendar-plus', 'navigation', 'credit-card'], false);
@endphp
@section('content')
@include('store_templates.book.components.topbar', ['back' => $vm['endpoints']['home'], 'title' => $kit->s('Your booking'), 'step' => null])
<main class="c-pagemain c-bktrack" id="main">
    <div class="c-bktrack__cols">
        <div class="c-bktrack__col">
            @include('store_templates.book.components.messages', ['messages' => $__o['messages'] ?? []])
            <section class="c-bkstat" aria-live="polite" data-testid="booking-status" data-status="{{ $__o['status'] }}"><p class="c-bkchip">{{ $kit->icon($__o['status'] === 'cancelled' ? 'x' : 'circle-check') }}<span>{{ $__o['status_label'] }}</span></p><h1 class="c-bkstat__title">{{ $__o['heading'] ?? $__o['status_label'] }}</h1><p class="c-bkstat__no">{{ $kit->t('Booking :number', ['number' => $kit->ltr($__o['number'])]) }}</p></section>
            @if (! empty($__o['ticket']))
            <section class="c-ticket" aria-labelledby="ticket-h"><h2 class="u-sr" id="ticket-h">{{ $kit->s('Your appointment') }}</h2>
                <div class="c-ticket__when"><p class="c-ticket__day">{{ $__o['ticket']['title'] ?? '' }}</p><p class="c-ticket__big">{{ $kit->flow($__o['ticket']['big'] ?? '') }}</p><p class="c-ticket__sub">{{ $kit->flow($__o['ticket']['sub'] ?? '') }}</p></div>
            </section>
            @endif
            @if ($__top->isNotEmpty())
            <div class="c-bktrack__acts">@foreach ($__top as $__a)<a class="c-btn {{ $loop->first ? 'c-btn--primary' : 'c-btn--secondary' }}" href="{{ $__a['url'] }}" @if (str_starts_with($__a['url'], 'http')) target="_blank" rel="noopener noreferrer" @endif>{{ $kit->icon($__a['icon'] ?? 'calendar-plus') }}<span>{{ $__a['label'] }}</span></a>@endforeach</div>
            @endif
            <section class="c-card-block" aria-labelledby="tl-h"><h2 class="c-pagesection__title" id="tl-h">{{ $kit->s('Booking progress') }}</h2>
                <ol class="c-steps">
                @foreach ($__o['steps'] as $__s)
                    @php($__w = ['done' => 'done', 'current' => 'current'][$__s['state']] ?? 'upcoming')
                    <li class="c-steps__item c-steps__item--{{ $__s['state'] }}" @if ($__s['state'] === 'current') aria-current="step" @endif><span class="c-steps__marker" aria-hidden="true">@if ($__s['state'] === 'done'){{ $kit->icon('check') }}@endif</span><span class="c-steps__text"><span class="u-sr">{{ $kit->s(':step, '.$__w, ['step' => $__s['label']]) }}. </span><span class="c-steps__label" aria-hidden="true">{{ $__s['label'] }}</span>@if (! empty($__s['sub']))<span class="c-steps__sub">{{ $kit->flow($__s['sub']) }}</span>@endif</span></li>
                @endforeach
                </ol>
            </section>
        </div>
        <div class="c-bktrack__col">
            @if (! empty($__o['location']))
            <section class="c-card-block c-bkloccard" aria-labelledby="loc-h"><h2 class="c-pagesection__title" id="loc-h">{{ $kit->s('Location') }}</h2>
                <div class="c-bkloccard__row">{{ $kit->icon('map-pin') }}<div><p class="c-bkloccard__name">{{ $__o['location']['label'] }}</p>@if (! empty($__o['location']['sub']))<p class="c-bkloccard__sub">{{ $__o['location']['sub'] }}</p>@endif</div></div>
            </section>
            @endif
            <section class="c-card-block" aria-labelledby="rc-h"><h2 class="c-pagesection__title" id="rc-h">{{ $kit->s('Receipt') }}</h2>
                @include('store_templates.book.components.summary', ['quote' => $__o['quote']])
            </section>
            @if ($__manage->isNotEmpty())
            <section class="c-card-block c-manage" aria-labelledby="mg-h"><h2 class="c-pagesection__title" id="mg-h">{{ $kit->s('Manage booking') }}</h2>
                <div class="c-manage__acts">
                @foreach ($__manage as $__a)
                    @if (($__a['method'] ?? 'get') === 'post')
                    <form method="post" action="{{ $__a['url'] }}">@csrf<button class="c-btn c-btn--danger" type="submit" data-book-confirm="{{ $kit->s('Cancel this booking?') }}">{{ $kit->icon($__a['icon'] ?? 'x') }}<span>{{ $__a['label'] }}</span></button></form>
                    @else
                    <a class="c-btn c-btn--secondary" href="{{ $__a['url'] }}">{{ $kit->icon($__a['icon'] ?? 'calendar-clock') }}<span>{{ $__a['label'] }}</span></a>
                    @endif
                @endforeach
                </div>
                @if (! empty($__o['policy_note']))<p class="c-bkpolicy__line">{{ $kit->icon('info') }}<span>{{ $kit->flow($__o['policy_note']) }}</span></p>@endif
            </section>
            @endif
        </div>
    </div>
</main>
@include('store_templates._kit.sections.footer')
@endsection
