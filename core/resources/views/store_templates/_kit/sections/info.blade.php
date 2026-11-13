{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $__s = $vm['store'];
    $__ct = $__s['contact'] ?? [];
    $__kiosk = ! empty($vm['context']['kiosk']['on']);
    $__pay = $pay ?? [];
    $__wa = preg_replace('/\D+/', '', (string) ($__ct['whatsapp'] ?? ''));
@endphp
<section class="c-info c-card-block" data-section="info" aria-labelledby="info-title">
    <h2 class="c-sectionhead__title" id="info-title">{{ $kit->s('Store info') }}</h2>
    <dl class="c-info__list">
        @if (! empty($__ct['address']))
        <div class="c-info__row"><dt>{{ $kit->icon('map-pin') }}<span>{{ $kit->s('Address') }}</span></dt><dd>{{ $__ct['address'] }}@if (! empty($__ct['map_url']) && ! $__kiosk) <a class="c-link" href="{{ $__ct['map_url'] }}" target="_blank" rel="noopener noreferrer">{{ $kit->s('Directions') }}</a>@endif</dd></div>
        @endif
        @if (! empty($__ct['phone']))
        <div class="c-info__row"><dt>{{ $kit->icon('phone') }}<span>{{ $kit->s('Phone') }}</span></dt><dd>@if ($__kiosk){{ $kit->ltr($__ct['phone']) }}@else<a class="c-link" href="tel:{{ preg_replace('/[^+\d]/', '', $__ct['phone']) }}">{{ $kit->ltr($__ct['phone']) }}</a>@endif</dd></div>
        @endif
        @if ($__wa !== '')
        <div class="c-info__row"><dt>{{ $kit->icon('message-circle') }}<span>{{ $kit->s('WhatsApp') }}</span></dt><dd>@if ($__kiosk){{ $kit->ltr($__ct['whatsapp']) }}@else<a class="c-link" href="{{ $kit->whatsappUrl($__ct['whatsapp']) }}" target="_blank" rel="noopener noreferrer">{{ $kit->ltr($__ct['whatsapp']) }}</a>@endif</dd></div>
        @endif
        @foreach ($__s['social'] ?? [] as $__so)
        <div class="c-info__row"><dt>{{ $kit->icon($__so['network'] === 'instagram' ? 'instagram' : 'globe') }}<span>{{ ucfirst($__so['network']) }}</span></dt><dd>@if ($__kiosk){{ last(explode('//', $__so['url'])) }}@else<a class="c-link" href="{{ $__so['url'] }}" target="_blank" rel="noopener noreferrer">{{ last(explode('//', $__so['url'])) }}</a>@endif</dd></div>
        @endforeach
        @if ($__pay)
        <div class="c-info__row"><dt>{{ $kit->icon('credit-card') }}<span>{{ $kit->s('We accept') }}</span></dt><dd><ul class="c-info__pay">@foreach ($__pay as $__p)<li class="c-paychip">{{ $kit->icon($__p['icon'] ?? 'credit-card') }}<span>{{ $__p['label'] }}</span></li>@endforeach</ul></dd></div>
        @endif
    </dl>
</section>
