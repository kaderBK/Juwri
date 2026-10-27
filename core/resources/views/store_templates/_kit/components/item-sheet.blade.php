{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- the item sheet (one item with its choices). Server-rendered for a deep link and as the fragment the theme JS opens;
     the Add button's total is the server's (Juwri.cart.preview), never arithmetic here. --}}
@php
    $__d = $item;
    $__gal = ($__d['gallery'] ?? [])[0] ?? null;
    $__label = (string) ($__d['add_label'] ?? '');
    $__parts = explode(' · ', $__label);
    $__addTotal = count($__parts) > 1 ? array_pop($__parts) : '';
    $__sold = \Juwri\Core\Support\ViewHelpers::hasSoldOutBadge($__d['badges'] ?? []);
    $__facts = $kit->plain($__d['details'][0]['html'] ?? '');
@endphp
<div id="item-sheet" class="c-overlay" data-juwri-sheet role="dialog" aria-modal="true" aria-labelledby="sheet-title" @unless (($open ?? null) === 'item') hidden @endunless>
    <div class="c-overlay__scrim" data-juwri-overlay aria-hidden="true"></div>
    <div class="c-sheet">
        <form class="c-sheet__form" data-kit-item-form data-item="{{ $__d['id'] }}" novalidate>
            <div class="c-sheet__media">
                @if ($__gal)<img class="c-sheet__img" src="{{ $__gal['src'] }}" alt="" width="640" height="240" decoding="async">@endif
                <span class="c-sheet__handle" aria-hidden="true"></span>
                <button class="c-iconbtn c-iconbtn--glass c-sheet__close" type="button" data-juwri-close aria-label="{{ $kit->s('Close') }}">{{ $kit->icon('x') }}</button>
            </div>
            <div class="c-sheet__scroll">
                <div class="c-sheet__head"><h2 class="c-sheet__title" id="sheet-title" tabindex="-1">{{ $__d['name'] }}</h2>
                    @if (! empty($__d['description']))<p class="c-sheet__desc">{{ $__d['description'] }}</p>@endif
                    <ul class="c-facts">
                        @if ($vm['theme']['settings']['menu.show_calories'] ?? true)@foreach ($__d['nutrition'] ?? [] as $__n)<li class="c-fact">{{ $__n['value'] }}</li>@endforeach @endif
                        @if ($__facts !== '' && ($vm['theme']['settings']['menu.show_allergens'] ?? true))<li class="c-fact c-fact--allergen">{{ $kit->icon('alert-circle') }}<span>{{ $__d['details'][0]['title'] ?? '' }}: {{ $__facts }}</span></li>@endif
                    </ul>
                </div>
                @foreach ($__d['option_groups'] ?? [] as $__g)@include('store_templates._kit.components.option-group', ['group' => $__g])@endforeach
                @if (! empty($__d['variants']))<script type="application/json" data-kit-variants nonce="{{ csp_nonce() }}">@json($__d['variants'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>@endif
                <div class="c-field c-sheet__note"><label class="c-field__label" for="note">{{ $kit->s('Note for the kitchen') }}</label><input class="c-input" id="note" name="note" type="text" maxlength="140" placeholder="{{ $kit->s('e.g. no onions') }}" autocomplete="off"><p class="c-field__count" aria-hidden="true">0/140</p></div>
            </div>
            @if ($__sold)
            <div class="c-sheet__foot"><p class="c-badge c-badge--muted">{{ $kit->icon('ban') }}<span>{{ $kit->s('Sold out') }}</span></p></div>
            @else
            <div class="c-sheet__foot">
                <div class="c-stepper c-stepper--lg" role="group" aria-label="{{ $kit->s('Quantity') }}">
                    <button class="c-stepper__btn" type="button" data-kit-qty="-1" aria-label="{{ $kit->s('Decrease quantity') }}">{{ $kit->icon('minus') }}</button>
                    <output class="c-stepper__val" data-kit-qty-value>{{ $__d['quantity']['value'] ?? 1 }}</output>
                    <button class="c-stepper__btn" type="button" data-kit-qty="1" aria-label="{{ $kit->s('Increase quantity') }}">{{ $kit->icon('plus') }}</button>
                </div>
                <button class="c-btn c-btn--primary c-btn--lg c-sheet__add" type="button" data-kit-add><span>{{ $kit->s('Add') }}</span>@if ($__addTotal !== '')<bdi class="c-money" data-kit-add-total>{{ $__addTotal }}</bdi>@else<bdi class="c-money" data-kit-add-total></bdi>@endif</button>
            </div>
            @endif
        </form>
    </div>
</div>
