{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle §7 / N5: "Notify me when it is back" — a variant that is sold out can be waited for. The shopper gives an email or a phone and
     opts in to the message (the store sends nothing without it). --}}
<div id="notify" class="c-overlay" data-juwri-sheet role="dialog" aria-modal="true" aria-labelledby="notify-title" hidden>
    <div class="c-overlay__scrim" data-juwri-overlay aria-hidden="true"></div>
    <div class="c-guide__panel">
        <div class="c-guide__head"><h2 class="c-guide__title" id="notify-title" tabindex="-1">{{ $kit->s('Notify me when it is back') }}</h2><button class="c-iconbtn" type="button" data-juwri-close aria-label="{{ $kit->s('Close') }}">{{ $kit->icon('x') }}</button></div>
        <form class="c-notify" data-kit-notify novalidate>
            <p class="c-guide__note" data-kit-notify-what></p>
            <div class="c-field"><label class="c-field__label" for="notify-contact">{{ $kit->s('Email or mobile number') }}</label><input class="c-input" id="notify-contact" name="contact" type="text" dir="ltr" autocomplete="email" required aria-required="true"></div>
            <label class="c-check" for="notify-optin"><input class="c-check__input u-vh" type="checkbox" id="notify-optin" name="marketing_opt_in" value="1"><span class="c-check__box" aria-hidden="true">{{ $kit->icon('check') }}</span><span class="c-check__label">{{ $kit->s('I agree to receive a message when this item is back in stock.') }}</span></label>
            <p class="c-field__help c-field__error" data-kit-notify-message role="alert"></p>
            <button class="c-btn c-btn--primary c-btn--lg c-btn--block" type="submit">{{ $kit->s('Notify me') }}</button>
        </form>
    </div>
</div>
