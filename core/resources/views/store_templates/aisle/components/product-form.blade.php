{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle §7: the product form — colour swatches, size pills, the per-variant stock note, Add to bag / Notify me, wishlist. Shared by the
     product page and the kiosk's item sheet. Everything is server-rendered from the ItemDetail ($item); the shared kit script runs the
     option rules and the live price (the server quote), the aisle script the per-variant stock states and the URL (?variant=). --}}
@php
    $__d = $item;
    $__variants = $__d['variants'] ?? [];
    $__sku = $variantId ?? null;
    $__picked = [];
    foreach ($__variants as $__v) {
        if ($__sku !== null && ($__v['sku'] ?? null) === $__sku) {
            $__picked = array_values($__v['options']);
        }
    }
    $__sold = \Juwri\Core\Support\ViewHelpers::hasSoldOutBadge($__d['badges'] ?? []);
    $__label = (string) ($__d['add_label'] ?? '');
    $__parts = explode(' · ', $__label);
    $__addTotal = count($__parts) > 1 ? array_pop($__parts) : '';
    $__saved = in_array($__d['id'], $vm['customer']['wishlist'] ?? [], true);
    $__kiosk = ! empty($vm['context']['kiosk']['on']);
    $__wish = ! empty($vm['features']['wishlist']) && ! $__kiosk;
    $__alerts = ! empty($vm['endpoints']['stock_alerts']) && ! $__kiosk;
    $__variantUrl = ($vm['endpoints']['home'] ?? '/').'?item='.$__d['id'];
@endphp
<form class="c-pdp__form" data-kit-item-form data-item="{{ $__d['id'] }}" data-variant-url="{{ $__variantUrl }}" aria-label="{{ $kit->s('Product options') }}" novalidate>
    @foreach ($__d['option_groups'] ?? [] as $__g)
    @if (in_array($__g['kind'], ['swatch', 'size'], true))
    @php
        $__isSize = $__g['kind'] === 'size';
        $__first = \Juwri\Core\Support\ViewHelpers::firstPicked($__g['options'], $__picked);
        $__err = $__isSize ? $kit->s('Choose a size') : $kit->s('Choose a colour');
    @endphp
    <fieldset class="c-opt c-opt--{{ $__isSize ? 'size' : 'colour' }}" data-kit-group="{{ $__g['id'] }}" data-min="{{ $__g['min'] ?? 1 }}" data-max="1" @if (! empty($__g['required'])) data-required @endif data-kit-opt="{{ $__g['kind'] }}">
        <legend class="c-opt__legend">{{ $__g['label'] }}: <strong class="c-opt__value" data-kit-opt-value>{{ $__first['label'] ?? '' }}</strong></legend>
        @if ($__isSize && ! empty($__d['size_guide_url']) && ! $__kiosk)<button class="c-opt__guide c-textbtn" type="button" data-kit-size-guide="{{ $__d['size_guide_url'] }}" data-juwri-open="size-guide" aria-controls="size-guide" aria-haspopup="dialog" aria-expanded="false">{{ $kit->icon('ruler') }}<span>{{ $kit->s('Size guide') }}</span></button>@endif
        <div class="c-opt__row">
        @foreach ($__g['options'] as $__o)
            @php
                $__oid = 'opt-'.$__g['id'].'-'.$__o['id'];
                $__out = isset($__o['available']) && ! $__o['available'];
                $__on = ! empty($__o['selected']) || in_array($__o['id'], $__picked, true);
            @endphp
            @if ($__isSize)
            <label class="c-size{{ $__out ? ' is-soldout' : '' }}" for="{{ $__oid }}"><input class="c-size__input u-vh" type="radio" id="{{ $__oid }}" name="g-{{ $__g['id'] }}" value="{{ $__o['id'] }}" data-group="{{ $__g['id'] }}" data-label="{{ $__o['label'] }}" @checked($__on) @if ($__out) aria-disabled="true" @endif><span class="c-size__face">{{ $__o['label'] }}@if ($__out)<span class="u-sr">, {{ $kit->s('Sold out') }}</span>@endif</span></label>
            @else
            <label class="c-swatch{{ $__out ? ' is-soldout' : '' }}" for="{{ $__oid }}"><input class="c-swatch__input u-vh" type="radio" id="{{ $__oid }}" name="g-{{ $__g['id'] }}" value="{{ $__o['id'] }}" data-group="{{ $__g['id'] }}" data-label="{{ $__o['label'] }}" @checked($__on) @if ($__out) aria-disabled="true" @endif><span class="c-swatch__face"><svg class="c-sw c-sw--xl" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><circle class="c-sw__c" cx="10" cy="10" r="9" fill="{{ $__o['swatch'] ?? 'currentColor' }}"/></svg>@if ($__out)<span class="c-swatch__strike" aria-hidden="true"></span>@endif</span><span class="u-sr">{{ $__o['label'] }}@if ($__out), {{ $kit->s('Sold out') }}@endif</span></label>
            @endif
        @endforeach
        </div>
        @if (! empty($__g['required']))<p class="c-field__help c-field__error" data-kit-group-error hidden role="alert">{{ $__err }}</p>@endif
    </fieldset>
    @else
    @include('store_templates._kit.components.option-group', ['group' => $__g])
    @endif
    @endforeach
    @if ($__variants)<script type="application/json" data-kit-variants nonce="{{ csp_nonce() }}">@json($__variants, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>@endif
    <output hidden data-kit-qty-value>1</output>
    <p class="c-stock" role="status" aria-live="polite" data-kit-stock hidden data-text-low="{{ $kit->s('Only :count left in :option', ['count' => ':count', 'option' => ':option']) }}" data-text-low-plain="{{ $kit->s('Only :count left', ['count' => ':count']) }}" data-text-out="{{ $kit->s('Sold out') }}" data-text-none="{{ $kit->s('This combination is not available') }}">{{ $kit->icon('alert-circle') }}<span data-kit-stock-text></span></p>
    <div class="c-pdp__actions">
        @if ($__sold)
        <p class="c-badge c-badge--muted">{{ $kit->icon('ban') }}<span>{{ $kit->s('Sold out') }}</span></p>
        @else
        <button class="c-btn c-btn--primary c-btn--lg c-pdp__add" type="button" data-kit-add><span>{{ $kit->s('Add to bag') }}</span><span aria-hidden="true">·</span><bdi class="c-money" data-kit-add-total>{{ $__addTotal }}</bdi></button>
        @endif
        @if ($__alerts)<button class="c-btn c-btn--secondary c-btn--lg c-pdp__add" type="button" data-kit-notify-open data-juwri-open="notify" aria-controls="notify" aria-haspopup="dialog" aria-expanded="false" @if ($__sold) data-sold-out @else hidden @endif>{{ $kit->icon('bell') }}<span>{{ $kit->s('Notify me when it is back') }}</span></button>@endif
        @if ($__wish)<button class="c-wishbtn" type="button" data-kit-wish="{{ $__d['id'] }}" aria-pressed="{{ $__saved ? 'true' : 'false' }}" aria-label="{{ $kit->s('Save :name', ['name' => $__d['name']]) }}">{{ $kit->icon('heart') }}</button>@endif
    </div>
</form>
