{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P3-06 done screen: the big order number, how to pay, "Take your receipt" when the kiosk prints, and a visible, polite countdown
     ("Starting over in 10 s", never announced every second) with a "Start now" button. The idle timer never runs on this screen.
     $order = vm.page.order; the printer-offline line is shown by the wrapper app through the juwri:kiosk:print-failed event. --}}
@php
    $__o = $order;
    $__print = ! empty($vm['context']['kiosk']['print']['auto']);
    $__start = $vm['endpoints']['kiosk_start'] ?? ($vm['endpoints']['home'] ?? '/');
    $__h = ! empty($specimen) ? 'h3' : 'h1';
    $__off = ! empty($printerOffline);
    $__sec = new \Illuminate\Support\HtmlString('<bdi dir="ltr" class="c-code" data-kiosk-left>'.(int) ($seconds ?? 10).'</bdi>');
@endphp
<div class="c-kscreen c-kdone" data-kiosk-done data-seconds="{{ (int) ($seconds ?? 10) }}" data-start="{{ $__start }}">
    <span class="c-kdone__icon">{{ $kit->icon('circle-check') }}</span>
    <{{ $__h }} class="c-kdone__title">{{ $kit->s('Order sent') }}</{{ $__h }}>
    <p class="c-kdone__label">{{ $kit->s('Your order number') }}</p>
    <p class="c-kdone__no">{{ $kit->ltr($__o['number']) }}</p>
    <p class="c-kdone__pay"><strong>{{ $__o['paid_label'] ?? $kit->s('Pay at the counter') }}</strong> <span>{{ $kit->s('Show this number to the cashier.') }}</span></p>
    @if ($__print)
    <p class="c-kdone__receipt" data-kiosk-receipt @if ($__off) hidden @endif>{{ $kit->icon('receipt') }}<span>{{ $kit->s('Take your receipt') }}</span></p>
    <p class="c-kdone__receipt c-kdone__receipt--off" role="status" data-kiosk-print-failed @unless ($__off) hidden @endunless>{{ $kit->icon('receipt') }}<span>{{ $kit->s("Your receipt couldn't print. Show this number to the cashier.") }}</span></p>
    @endif
    <p class="c-kdone__count" role="timer" aria-live="off">{{ $kit->t('Starting over in :seconds s', ['seconds' => $__sec]) }}</p>
    <a class="c-btn c-btn--primary c-btn--xl" href="{{ $__start }}" data-kiosk-startnow>{{ $kit->s('Start now') }}</a>
</div>
