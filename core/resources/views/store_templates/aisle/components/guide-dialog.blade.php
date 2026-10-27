{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle §7: the size guide dialog — the table of the product's guide (the store's own sizes), loaded when opened; a cm / in switch
     converts the numbers. Focus trap, Esc and the inert page are the core's. --}}
<div id="size-guide" class="c-overlay" data-juwri-sheet role="dialog" aria-modal="true" aria-labelledby="guide-title" hidden>
    <div class="c-overlay__scrim" data-juwri-overlay aria-hidden="true"></div>
    <div class="c-guide__panel" data-kit-guide data-text-cm="{{ $kit->s('Measurements in cm') }}" data-text-in="{{ $kit->s('Measurements in inches') }}" data-text-error="{{ $kit->s('The size guide could not be loaded.') }}">
        <div class="c-guide__head"><h2 class="c-guide__title" id="guide-title" tabindex="-1">{{ $kit->s('Size guide') }}</h2><button class="c-iconbtn" type="button" data-juwri-close aria-label="{{ $kit->s('Close') }}">{{ $kit->icon('x') }}</button></div>
        <p class="c-guide__note" data-kit-guide-note></p>
        <div class="c-guide__unit" role="group" aria-label="{{ $kit->s('Unit') }}" data-kit-guide-units hidden><button class="c-btn c-btn--secondary" type="button" data-kit-unit="cm" aria-pressed="true">cm</button><button class="c-btn c-btn--secondary" type="button" data-kit-unit="in" aria-pressed="false">in</button></div>
        <table class="c-guide__table" data-kit-guide-table><thead><tr></tr></thead><tbody></tbody></table>
    </div>
</div>
