{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<div class="pb-sheet pb-item-sheet" id="pb-item-sheet" aria-hidden="true" role="dialog" aria-modal="true" aria-label="{{ ___('Item details') }}">
    <div class="pb-sheet__scrim" data-close-sheet></div>
    <div class="pb-sheet__panel">
        <button type="button" class="pb-sheet__close" data-close-sheet aria-label="{{ ___('Close') }}">✕</button>
        <div class="pb-item-sheet__img" id="pb-item-img"></div>
        <div class="pb-item-sheet__body">
            <h3 id="pb-item-name"></h3>
            <p class="pb-item-sheet__desc" id="pb-item-desc"></p>
            <div class="pb-item-sheet__price" id="pb-item-price"></div>

            <div class="pb-item-sheet__options" id="pb-item-variants" style="display:none;">
                <h4>{{ ___('Choose option') }}</h4>
                <div class="pb-option-list" id="pb-variant-list"></div>
            </div>

            <div class="pb-item-sheet__options" id="pb-item-extras" style="display:none;">
                <h4>{{ ___('Extras') }}</h4>
                <div class="pb-option-list" id="pb-extra-list"></div>
            </div>

            <div class="pb-item-sheet__qty">
                <button type="button" id="pb-qty-minus" aria-label="{{ ___('Decrease quantity') }}">−</button>
                <span id="pb-qty-value">1</span>
                <button type="button" id="pb-qty-plus" aria-label="{{ ___('Increase quantity') }}">+</button>
            </div>

            <button type="button" class="pb-btn pb-btn--primary" id="pb-item-add">{{ ___('Add to cart') }}</button>
        </div>
    </div>
</div>

<div class="pb-search-overlay" id="pb-search-overlay" aria-hidden="true">
    <div class="pb-search-overlay__header">
        <input type="search" id="pb-search-input" placeholder="{{ ___('Search dishes, ingredients…') }}" autocomplete="off">
        <button type="button" id="pb-search-close" aria-label="{{ ___('Close search') }}">✕</button>
    </div>
    <div class="pb-search-overlay__results" id="pb-search-results"></div>
</div>
