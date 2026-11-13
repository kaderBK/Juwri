{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- basket §7: the product sheet — the photo contained on a tile, the unit price ("SAR 11.80 / kg") and where it is from, the
     weight steps (a weighed product: four chips) with the estimate and cap note, the store's "If it's out of stock" choice, the
     nutrition and storage disclosures, then quantity and Add. The Add button's total is the server's quote (Juwri.cart.preview);
     for a weighed product it reads "~ SAR 5.90": an estimate, never a promise. Server-rendered for a deep link and as the fragment
     the shared script opens. --}}
@php
    $__d = $item;
    $__gal = ($__d['gallery'] ?? [])[0] ?? null;
    $__weighed = ! empty($__d['unit']['sold_by_weight']);
    $__sold = \Juwri\Core\Support\ViewHelpers::hasSoldOutBadge($__d['badges'] ?? []);
    $__parts = explode(' · ', (string) ($__d['add_label'] ?? ''));
    $__addTotal = count($__parts) > 1 ? array_pop($__parts) : '';
    $__weightGroup = \Juwri\Core\Support\ViewHelpers::firstOfKind($__d['option_groups'] ?? [], 'kind', 'weight');
    $__step = $__weightGroup ? \Juwri\Core\Support\ViewHelpers::firstSelectedOption($__weightGroup['options']) : null;
    if ($__weighed && $__step && ! empty($__step['price_delta'])) {
        $__addTotal = (string) $__step['price_delta']['formatted'];
    }
    $__facts = \Juwri\Core\Support\ViewHelpers::nutritionFacts($__d['nutrition'] ?? []);
    $__unit = (string) ($__d['unit']['label'] ?? '');
@endphp
<div id="item-sheet" class="c-overlay" data-juwri-sheet role="dialog" aria-modal="true" aria-labelledby="sheet-title" @unless (($open ?? null) === 'item') hidden @endunless>
    <div class="c-overlay__scrim" data-juwri-overlay aria-hidden="true"></div>
    <div class="c-sheet c-sheet--product">
        <form class="c-sheet__form" data-kit-item-form data-item="{{ $__d['id'] }}" novalidate>
            <div class="c-sheet__media">
                @if ($__gal)<img class="c-sheet__img" src="{{ $__gal['src'] }}" alt="{{ $__gal['alt'] ?? $__d['name'] }}" width="240" height="220" decoding="async">@endif
                <span class="c-sheet__handle" aria-hidden="true"></span>
                <button class="c-iconbtn c-sheet__close" type="button" data-juwri-close aria-label="{{ $kit->s('Close') }}">{{ $kit->icon('x') }}</button>
            </div>
            <div class="c-sheet__scroll">
                <div class="c-sheet__head"><h2 class="c-sheet__title" id="sheet-title" tabindex="-1">{{ $__d['name'] }}</h2>
                    <p class="c-sheet__unit">
                        @if ($__unit !== '')<span class="c-sheet__unitprice">{{ $kit->flow($__unit) }}</span>@elseif (! empty($__d['price']))<span class="c-sheet__unitprice">{{ $kit->money($__d['price']) }}</span>@endif
                        @if (! empty($__d['unit']['origin']))<span class="c-origin">{{ $kit->icon('map-pin') }}<span>{{ $__d['unit']['origin'] }}</span></span>@endif
                    </p>
                    @if (! empty($__d['description']))<p class="c-sheet__desc">{{ $__d['description'] }}</p>@endif
                </div>
                @foreach ($__d['option_groups'] ?? [] as $__g)
                @include('store_templates.basket.components.option-group', ['group' => $__g])
                @if (($__g['kind'] ?? '') === 'weight' && ! empty($__d['estimate_note']))
                <p class="c-note" data-bk-estimate>{{ $kit->icon('info') }}<span>{{ $kit->flow($__d['estimate_note']) }}</span></p>
                @endif
                @endforeach
                @if (! empty($__d['variants']))<script type="application/json" data-kit-variants nonce="{{ csp_nonce() }}">@json($__d['variants'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>@endif
                @if ($__facts !== '' || ! empty($__d['details']))
                <div class="c-discs">
                    @if ($__facts !== '' && empty($__d['details']))<details class="c-disc"><summary class="c-disc__sum"><span>{{ $kit->s('Nutrition') }}</span>{{ $kit->icon('chevron-down') }}</summary><div class="c-disc__body"><p>{{ $__facts }}</p></div></details>@endif
                    @foreach ($__d['details'] ?? [] as $__x)<details class="c-disc"><summary class="c-disc__sum"><span>{{ $__x['title'] }}</span>{{ $kit->icon('chevron-down') }}</summary><div class="c-disc__body">{{ $kit->sanitised($__x['html']) }}</div></details>@endforeach
                </div>
                @endif
            </div>
            @if ($__sold)
            <div class="c-sheet__foot"><p class="c-badge c-badge--muted">{{ $kit->icon('ban') }}<span>{{ $kit->s('Sold out') }}</span></p></div>
            @else
            <div class="c-sheet__foot">
                <div class="c-qty c-qty--sheet" role="group" aria-label="{{ $kit->s('Quantity') }}">
                    <button class="c-qty__btn" type="button" data-kit-qty="-1" aria-label="{{ $kit->s('Decrease quantity') }}">{{ $kit->icon('minus') }}</button>
                    <output class="c-qty__val" data-kit-qty-value>{{ $__d['quantity']['value'] ?? 1 }}</output>
                    <button class="c-qty__btn" type="button" data-kit-qty="1" aria-label="{{ $kit->s('Increase quantity') }}">{{ $kit->icon('plus') }}</button>
                </div>
                <button class="c-btn c-btn--primary c-btn--lg c-sheet__add" type="button" data-kit-add><span>{{ $kit->s('Add') }}</span>@if ($__weighed)<span class="c-sheet__approx" aria-hidden="true">~</span>@endif<bdi class="c-money" data-kit-add-total>{{ $__addTotal }}</bdi></button>
            </div>
            @endif
        </form>
    </div>
</div>
