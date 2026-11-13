{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P3-06 kiosk start screen (also a specimen on the kiosk-states board): language buttons, store identity, "Tap to start".
     $start = {url, setup, reopens, languages[{code,name,current,url}]}; $mode = normal | paused | offline (the page decides from the
     view model; the board shows each). The logo is the staff-exit target (a 3 s long press, core JS); nothing else here leaves the kiosk. --}}
@php
    $__s = $vm['store'];
    $__mode = $mode ?? (! empty($__s['status']['open']) ? 'normal' : 'paused');
    $__h = $heading ?? 'h1';
@endphp
<div class="c-kscreen c-kstart" data-kiosk-start @if (! empty($start['setup'])) data-kiosk-needs-setup @endif>
    @if (count($start['languages'] ?? []) > 1)
    <div class="c-klang" role="group" aria-label="{{ $kit->s('Language') }}">
        @foreach ($start['languages'] as $__l)
        @if ($__l['current'])
        <span class="c-pill c-pill--lang c-klang__cur" aria-current="true" lang="{{ $__l['code'] }}">{{ $kit->icon('globe') }}<span>{{ $__l['name'] }}</span></span>
        @else
        <a class="c-pill c-pill--lang" href="{{ $__l['url'] }}" lang="{{ $__l['code'] }}" hreflang="{{ $__l['code'] }}">{{ $kit->icon('globe') }}<span>{{ $__l['name'] }}</span></a>
        @endif
        @endforeach
    </div>
    @endif
    <div class="c-kstart__body">
        @include('store_templates._kit.components.logo', ['cls' => 'c-logo c-kstart__logo', 'kioskExit' => true])
        <{{ $__h }} class="c-kstart__name">{{ $__s['name'] }}</{{ $__h }}>
        @if (! empty($__s['tagline']))<p class="c-kstart__tag">{{ $kit->flow($__s['tagline']) }}</p>@endif
        @if ($__mode === 'paused')
        <div class="c-kstart__paused" role="status">
            <h2 class="c-kstart__ptitle">{{ $kit->s('Ordering is paused') }}</h2>
            @if (! empty($__s['status']['paused_reason']) && $__s['status']['paused_reason'] !== $kit->s('Ordering is paused'))
            <p class="c-kstart__preason">{{ $__s['status']['paused_reason'] }}</p>
            @elseif (! empty($start['reopens']))
            <p class="c-kstart__preason">{{ $kit->t('We are closed right now. We reopen at :time.', ['time' => $kit->ltr($start['reopens'])]) }}</p>
            @endif
            <p class="c-kstart__pdo"><strong>{{ $kit->s('Please order at the counter.') }}</strong></p>
        </div>
        @else
        <div class="c-banner c-banner--warning c-kstart__offline" role="status" data-kiosk-offline @unless ($__mode === 'offline') hidden @endunless>{{ $kit->icon('wifi-off') }}<span>{{ $kit->s('Connection lost — pay at the counter to order now') }}</span></div>
        <a class="c-btn c-btn--primary c-btn--xl c-kstart__go" href="{{ $start['url'] }}" data-kiosk-go>{{ $kit->s('Tap to start') }}{{ $kit->icon('arrow-right') }}</a>
        @endif
    </div>
</div>
