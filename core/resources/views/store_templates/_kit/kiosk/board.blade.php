{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P3-06 the kiosk screens board: the seven screens as specimens, each the real component. $start (the start screen's data) and $orderData
     (vm.page.order of a placed order) come from the page's controller / test scenario. --}}
@php
    $__order = $orderData ?? ['number' => 'A7-2041', 'paid_label' => $kit->s('Pay at the counter')];
    $__vmOn = $vm;
    $__vmOn['context']['kiosk'] = ($vm['context']['kiosk'] ?? []) + ['on' => true, 'idle_seconds' => 60, 'print' => ['auto' => true, 'width' => 48]];
    $__vmOpen = $__vmOn;
    $__vmOpen['store']['status']['open'] = true;
    $__vmPaused = $__vmOn;
    $__vmPaused['store']['status']['open'] = false;
@endphp
<main class="c-kboard" id="main"><h1 class="c-kboard__h1">{{ $kit->s('Kiosk screens') }}</h1>
<div class="c-kboard__grid">
    <section class="c-kboard__panel" aria-labelledby="kb-start"><h2 class="c-kboard__label" id="kb-start">{{ $kit->s('Start screen') }}</h2>@include('store_templates._kit.kiosk.start', ['vm' => $__vmOpen, 'mode' => 'normal', 'heading' => 'h3'])</section>
    <section class="c-kboard__panel" aria-labelledby="kb-idle"><h2 class="c-kboard__label" id="kb-idle">{{ $kit->s('Idle warning') }}</h2><div class="c-kscreen c-kscreen--scrim">@include('store_templates._kit.kiosk.idle', ['vm' => $__vmOn, 'live' => false])</div></section>
    <section class="c-kboard__panel" aria-labelledby="kb-done"><h2 class="c-kboard__label" id="kb-done">{{ $kit->s('Done screen') }}</h2>@include('store_templates._kit.kiosk.done', ['vm' => $__vmOn, 'order' => $__order, 'specimen' => true])</section>
    <section class="c-kboard__panel" aria-labelledby="kb-pin"><h2 class="c-kboard__label" id="kb-pin">{{ $kit->s('Staff exit') }}</h2><div class="c-kscreen c-kscreen--scrim">@include('store_templates._kit.kiosk.pin', ['live' => false])</div></section>
    <section class="c-kboard__panel" aria-labelledby="kb-paused"><h2 class="c-kboard__label" id="kb-paused">{{ $kit->s('Start screen, ordering paused') }}</h2>@include('store_templates._kit.kiosk.start', ['vm' => $__vmPaused, 'mode' => 'paused', 'heading' => 'h3'])</section>
    <section class="c-kboard__panel" aria-labelledby="kb-printer"><h2 class="c-kboard__label" id="kb-printer">{{ $kit->s('Done screen, printer offline') }}</h2>@include('store_templates._kit.kiosk.done', ['vm' => $__vmOn, 'order' => $__order, 'specimen' => true, 'printerOffline' => true])</section>
    <section class="c-kboard__panel" aria-labelledby="kb-offline"><h2 class="c-kboard__label" id="kb-offline">{{ $kit->s('Start screen, offline') }}</h2>@include('store_templates._kit.kiosk.start', ['vm' => $__vmOpen, 'mode' => 'offline', 'heading' => 'h3'])</section>
</div></main>
