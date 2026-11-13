{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- table: order tracking. Reads only $vm (page = track): status head, timeline, help actions, the receipt that sums to the charged total. --}}
@extends('store_templates._kit.layout', ['theme' => 'table', 'bodyClass' => ! empty($vm['context']['kiosk']['on']) ? 'p-track p-kiosk p-kdone' : 'p-track'])
@php
    $__o = $vm['page']['order'];
    $__other = \Juwri\Core\Support\ViewHelpers::otherLocale($vm['store']['locales'] ?? [], $vm['store']['locale']);
    $__delivering = ($__o['status'] ?? '') === 'on_the_way';
@endphp
@section('content')
@if (! empty($vm['context']['kiosk']['on']))
<main class="c-kdonemain" id="main">@include('store_templates._kit.kiosk.done', ['order' => $__o])</main>
@else
<header class="c-topbar"><a class="c-iconbtn c-topbar__back" href="{{ ($vm['endpoints']['home'] ?? '/') }}" aria-label="{{ $kit->s('Back') }}">{{ $kit->icon('arrow-left') }}</a><p class="c-topbar__title">{{ $kit->s('Track order') }}</p><span class="c-topbar__brand">@include('store_templates._kit.components.logo', ['cls' => 'c-logo c-logo--sm'])<span class="c-topbar__store">{{ $vm['store']['name'] }}</span></span></header>
<main class="c-pagemain c-track" id="main">
    <div class="c-track__cols">
        <div class="c-track__col">
            <section class="c-statushead c-card-block" aria-live="polite"><p class="c-statushead__no">{{ $kit->t('Order no. :number', ['number' => $kit->ltr($__o['number'])]) }}</p><h1 class="c-statushead__status">{{ $__o['status_label'] }}</h1>@if (! empty($__o['eta_label']))<p class="c-statushead__eta">{{ $kit->flow($__o['eta_label']) }}</p>@endif</section>
            <section class="c-card-block" aria-labelledby="progress-title"><h2 class="c-pagesection__title" id="progress-title">{{ $kit->s('Order progress') }}</h2>
                <ol class="c-steps">
                @foreach ($__o['steps'] as $__s)
                    @php($__word = ['done' => 'done', 'current' => 'current'][$__s['state']] ?? 'upcoming')
                    <li class="c-steps__item c-steps__item--{{ $__s['state'] }}" @if ($__s['state'] === 'current') aria-current="step" @endif><span class="c-steps__marker" aria-hidden="true">@if ($__s['state'] === 'done'){{ $kit->icon('check') }}@endif</span><span class="c-steps__text"><span class="u-sr">{{ $kit->s(':step, '.$__word, ['step' => $__s['label']]) }}. </span><span class="c-steps__label" aria-hidden="true">{{ $__s['label'] }}</span>@if (! empty($__s['sub']))<span class="c-steps__sub">{{ $kit->flow($__s['sub']) }}</span>@endif</span></li>
                @endforeach
                </ol>
            </section>
        </div>
        <div class="c-track__col">
            @if (! empty($__o['actions']))
            <section class="c-card-block" aria-labelledby="help-title"><h2 class="c-pagesection__title" id="help-title">{{ $kit->s('Need help?') }}</h2>
                <div class="c-help">
                @foreach ($__o['actions'] as $__a)
                    <a class="c-btn {{ $__a['kind'] === 'secondary' ? 'c-btn--secondary' : 'c-btn--ghost' }}" href="{{ $__a['url'] }}" @if (str_starts_with($__a['url'], 'http')) target="_blank" rel="noopener noreferrer" @endif>{{ $kit->icon($__a['icon'] ?? 'phone') }}<span>{{ $__a['label'] }}</span></a>
                @endforeach
                </div>
            </section>
            @endif
            <section class="c-card-block" aria-labelledby="receipt-title"><h2 class="c-pagesection__title" id="receipt-title">{{ $kit->s('Receipt') }}</h2>
                @include('store_templates._kit.components.lines-list', ['quote' => $__o['quote']])
                @include('store_templates._kit.components.quote-static', ['quote' => $__o['quote']])
            </section>
        </div>
    </div>
</main>
@include('store_templates._kit.sections.footer')
@endif
@endsection
