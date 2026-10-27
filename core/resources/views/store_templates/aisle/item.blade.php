{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle §7: the product page — a page of its own (shareable, indexable), reading only $vm: breadcrumbs, the gallery, brand, name, price
     + was-price + "Save …", the variant form (colour × size, stock per variant), delivery and returns rows, details, "Complete the look".
     `?variant=<sku>` opens the page on that variant. --}}
@extends('store_templates._kit.layout', ['theme' => 'aisle', 'bodyClass' => 'p-item'])
@php
    $__d = $item;
    $__home = $vm['endpoints']['home'] ?? '/';
    $__gal = $__d['gallery'] ?? [];
    $__cat = \Juwri\Core\Support\ViewHelpers::categoryWithItem($vm['catalog']['categories'] ?? [], $__d['id']);
    $__tax = $vm['money']['tax'] ?? null;
    $__rows = $__d['info_rows'] ?? [];
    $__ship = $vm['store']['shipping_rate'] ?? null;
    $__details = $__d['details'] ?? [];
@endphp
@section('content')
@include('store_templates.aisle.sections.announcement')
@include('store_templates.aisle.sections.header', ['h1' => false])
<main class="c-main c-main--pdp" id="main">
    <nav class="c-crumbs" aria-label="{{ $kit->s('Breadcrumb') }}"><ol>
        <li><a class="c-crumbs__link" href="{{ $__home }}">{{ $kit->s('Home') }}</a></li>
        @if ($__cat)<li><a class="c-crumbs__link" href="{{ $__home }}?category={{ $__cat['id'] }}">{{ $__cat['name'] }}</a></li>@endif
        <li><span aria-current="page">{{ $__d['name'] }}</span></li>
    </ol></nav>
    <div class="c-pdp">
        <section class="c-gal" aria-label="{{ $kit->s('Product gallery') }}" data-kit-gallery>
            <ul class="c-gal__track" tabindex="0" data-kit-gallery-track>
            @forelse ($__gal as $__i => $__g)
                <li class="c-gal__slide"><img class="c-gal__img" src="{{ $__g['src'] }}" alt="{{ $__d['name'] }} ({{ $__i + 1 }}/{{ count($__gal) }})" width="800" height="1000" decoding="async"@if ($__i === 0) fetchpriority="high"@else loading="lazy"@endif></li>
            @empty
                <li class="c-gal__slide"><span class="c-gal__img" role="img" aria-label="{{ $__d['name'] }}"></span></li>
            @endforelse
            </ul>
            <a class="c-iconbtn c-iconbtn--glass c-gal__back" href="{{ $__home }}" aria-label="{{ $kit->s('Back') }}">{{ $kit->icon('arrow-left') }}</a>
            <button class="c-iconbtn c-iconbtn--glass c-gal__share" type="button" data-kit-share aria-label="{{ $kit->s('Share') }}">{{ $kit->icon('share-2') }}</button>
            @if (count($__gal) > 1)
            <div class="c-gal__nav"><button class="c-iconbtn c-iconbtn--glass" type="button" data-kit-gallery-prev aria-label="{{ $kit->s('Previous image') }}">{{ $kit->icon('chevron-left') }}</button><p class="c-gal__pager" aria-live="polite" data-kit-gallery-pager data-text="{{ $kit->s('Image :n of :total', ['n' => ':n', 'total' => ':total']) }}" data-total="{{ count($__gal) }}">{{ $kit->s('Image :n of :total', ['n' => '1', 'total' => (string) count($__gal)]) }}</p><button class="c-iconbtn c-iconbtn--glass" type="button" data-kit-gallery-next aria-label="{{ $kit->s('Next image') }}">{{ $kit->icon('chevron-right') }}</button></div>
            @endif
        </section>
        <div class="c-pdp__info">
            @if (! empty($__d['brand']))<p class="c-pdp__brand"><bdi>{{ $__d['brand'] }}</bdi></p>@endif
            <h1 class="c-pdp__name">{{ $__d['name'] }}</h1>
            <p class="c-pdp__price"><span class="c-pdp__now">@if (! empty($__d['from_price'])){{ $kit->t('from :amount', ['amount' => $kit->money($__d['from_price'])]) }}@else{{ $kit->money($__d['price']) }}@endif</span>@if (! empty($__d['compare_at']))<s class="c-pdp__was"><span class="u-sr">{{ $kit->t('Was :amount', ['amount' => $kit->money($__d['compare_at'])]) }}</span><span aria-hidden="true">{{ $kit->money($__d['compare_at']) }}</span></s>@endif @if (! empty($__d['saving_label']))<span class="c-pdp__save"><span>{{ $kit->flow($__d['saving_label']) }}</span></span>@endif</p>
            @if ($__tax)<p class="c-pdp__vat">{{ $kit->s(':tax is added at checkout.', ['tax' => ($__tax['label'] ?? 'VAT').' '.rtrim(rtrim(number_format((float) $__tax['rate'], 2, '.', ''), '0'), '.').'%']) }}</p>@endif
            @if (! empty($__d['bnpl_label']))<p class="c-bnpl c-bnpl--pdp"><span>{{ $kit->flow($__d['bnpl_label']) }}</span></p>@endif
            @include('store_templates.aisle.components.product-form', ['item' => $__d, 'variantId' => $variantId ?? null])
            @if ($__rows || $__ship)
            <ul class="c-inforows" aria-label="{{ $kit->s('Delivery and returns') }}">
                @if ($__ship)<li class="c-inforow">{{ $kit->icon('truck') }}<span class="c-inforow__text"><span class="c-inforow__label">{{ $kit->t('Shipping from :amount', ['amount' => $kit->money($__ship)]) }}</span></span></li>@endif
                @foreach ($__rows as $__r)<li class="c-inforow">{{ $kit->icon($__r['icon'] ?? 'info') }}<span class="c-inforow__text"><span class="c-inforow__label">{{ $kit->flow($__r['label']) }}</span>@if (! empty($__r['sub']))<span class="c-inforow__sub">{{ $kit->flow($__r['sub']) }}</span>@endif</span></li>@endforeach
            </ul>
            @endif
            <div class="c-discs" role="group" aria-label="{{ $kit->s('Product details') }}">
                @if (! empty($__d['description']))<details class="c-disc" open><summary class="c-disc__sum"><span>{{ $kit->s('Description') }}</span>{{ $kit->icon('chevron-down') }}</summary><div class="c-disc__body"><p>{{ $__d['description'] }}</p></div></details>@endif
                @foreach ($__details as $__x)<details class="c-disc"><summary class="c-disc__sum"><span>{{ $__x['title'] }}</span>{{ $kit->icon('chevron-down') }}</summary><div class="c-disc__body">{{ $kit->sanitised($__x['html']) }}</div></details>@endforeach
            </div>
        </div>
    </div>
    @if (! empty($related))
    <section class="c-related" aria-labelledby="related-title"><h2 class="c-related__title" id="related-title">{{ $kit->s('Complete the look') }}</h2>
        <ul class="c-related__rail">@foreach ($related as $__r)@include('store_templates.aisle.components.product-card', ['item' => $__r, 'first' => false])@endforeach</ul>
    </section>
    @endif
</main>
@include('store_templates._kit.sections.footer')
@include('store_templates.aisle.components.cart-bar')
@endsection
@section('dialogs')
@include('store_templates.aisle.components.bag-sheet', ['cart' => $cart ?? null, 'open' => $open ?? null])
@include('store_templates.aisle.components.checkout-sheet', ['cart' => $cart ?? null, 'checkout' => $checkout ?? null, 'open' => $open ?? null])
@include('store_templates.aisle.components.guide-dialog')
@include('store_templates.aisle.components.notify-dialog')
@endsection
