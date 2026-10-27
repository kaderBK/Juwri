{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle §10 / D7: "Start a return" — what can still be returned (the store's window and quantities, read when the dialog opens), what the
     shopper wants (an exchange or store credit; a refund only when the store allows it), and why. With refunds off the copy says so. --}}
<div id="returns" class="c-overlay" data-juwri-sheet role="dialog" aria-modal="true" aria-labelledby="returns-title" hidden>
    <div class="c-overlay__scrim" data-juwri-overlay aria-hidden="true"></div>
    <div class="c-guide__panel" data-kit-returns data-text-until="{{ $kit->s('You can return items until :date.', ['date' => ':date']) }}" data-text-sent="{{ $kit->s('Your return request was sent to the store.') }}" data-text-error="{{ $kit->s('This request could not be completed.') }}">
        <div class="c-guide__head"><h2 class="c-guide__title" id="returns-title" tabindex="-1">{{ $kit->s('Start a return') }}</h2><button class="c-iconbtn" type="button" data-juwri-close aria-label="{{ $kit->s('Close') }}">{{ $kit->icon('x') }}</button></div>
        <div data-kit-returns-closed hidden><article class="c-state c-state--warning"><span class="c-state__icon">{{ $kit->icon('clock') }}</span><div class="c-state__body"><h3 class="c-state__title">{{ $kit->s('Returns window closed') }}</h3><p class="c-state__text">{{ $kit->s('This order is outside the return window. Contact us for faulty items.') }}</p></div></article></div>
        <form class="c-notify" data-kit-returns-form hidden novalidate>
            <p class="c-guide__note" data-kit-returns-until></p>
            <p class="c-guide__note">{{ $refund ? $kit->s('Choose what you would like instead.') : $kit->s('Returns are exchanged or credited to your store account.') }}</p>
            <fieldset class="c-section"><legend class="c-section__title">{{ $kit->s('Items to return') }}</legend><ul class="c-slines" data-kit-returns-lines><template data-kit-returns-line><li class="c-sline"><label class="c-check"><input class="c-check__input u-vh" type="checkbox"><span class="c-check__box" aria-hidden="true">{{ $kit->icon('check') }}</span><span class="c-check__label" data-kit-returns-name></span></label><span class="c-select-wrap c-select-wrap--sm"><select class="c-select" aria-label="{{ $kit->s('Quantity') }}"></select>{{ $kit->icon('chevron-down') }}</span></li></template></ul></fieldset>
            <fieldset class="c-section"><legend class="c-section__title">{{ $kit->s('What would you like?') }}</legend>
                <div class="c-choices" data-kit-returns-resolutions>
                    <template data-kit-returns-resolution><label class="c-choice c-choice--card"><input class="c-choice__input u-vh" type="radio" name="resolution" value=""><span class="c-choice__mark c-choice__mark--radio" aria-hidden="true"><span class="c-choice__dot"></span></span><span class="c-choice__text"><span class="c-choice__label" data-kit-returns-resolution-label></span></span></label></template>
                </div>
                <span hidden data-text-exchange="{{ $kit->s('Exchange') }}" data-text-store_credit="{{ $kit->s('Store credit') }}" data-text-refund="{{ $kit->s('Refund') }}" data-kit-resolution-labels></span>
            </fieldset>
            <div class="c-field"><label class="c-field__label" for="returns-reason">{{ $kit->s('Reason (optional)') }}</label><input class="c-input" id="returns-reason" name="reason" type="text" maxlength="500" autocomplete="off"></div>
            <p class="c-field__help c-field__error" data-kit-returns-message role="alert"></p>
            <button class="c-btn c-btn--primary c-btn--lg c-btn--block" type="submit">{{ $kit->s('Send return request') }}</button>
        </form>
        <p class="c-field__help" data-kit-returns-done role="status" hidden></p>
    </div>
</div>
