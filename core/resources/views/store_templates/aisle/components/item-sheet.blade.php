{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle (kiosk): a product opens as a sheet — the same product form as the product page (the variant picker with stock per variant)
     in a dialog; the shared kit script fetches it (`?fragment=item`) and the core runs the dialog. --}}
@php
    $__d = $item;
    $__gal = ($__d['gallery'] ?? [])[0] ?? null;
@endphp
<div id="item-sheet" class="c-overlay" data-juwri-sheet role="dialog" aria-modal="true" aria-labelledby="sheet-title" @unless (($open ?? null) === 'item') hidden @endunless>
    <div class="c-overlay__scrim" data-juwri-overlay aria-hidden="true"></div>
    <div class="c-sheet c-sheet--aisle-item">
        <div class="c-sheet__bar"><h2 class="c-sheet__title" id="sheet-title" tabindex="-1">{{ $__d['name'] }}</h2><button class="c-iconbtn c-iconbtn--sm" type="button" data-juwri-close aria-label="{{ $kit->s('Close') }}">{{ $kit->icon('x') }}</button></div>
        <div class="c-sheet__scroll">
            @if ($__gal)<img class="c-sheet__thumb c-sheet__thumb--tall" src="{{ $__gal['src'] }}" alt="" width="320" height="400" decoding="async">@endif
            @if (! empty($__d['brand']))<p class="c-pdp__brand"><bdi>{{ $__d['brand'] }}</bdi></p>@endif
            <p class="c-pdp__price"><span class="c-pdp__now">@if (! empty($__d['from_price'])){{ $kit->t('from :amount', ['amount' => $kit->money($__d['from_price'])]) }}@else{{ $kit->money($__d['price']) }}@endif</span>@if (! empty($__d['compare_at']))<s class="c-pdp__was"><span aria-hidden="true">{{ $kit->money($__d['compare_at']) }}</span></s>@endif</p>
            @include('store_templates.aisle.components.product-form', ['item' => $__d, 'variantId' => null])
        </div>
    </div>
</div>
