{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Item 26: loyalty at the kiosk (only when the owner turned it on). The customer opts in, then proves it is them with a code sent to their
     phone or the pass QR from their own account (read by the kiosk scanner); only then are their name, points, favourites and recent
     orders shown. Wired by assets/storefront/kiosk-loyalty.js. --}}
@if (! empty($vm['context']['kiosk']['loyalty']))
<button class="c-btn c-btn--secondary c-kiosk__loyalty" type="button" data-kiosk-loyalty-open>{{ $kit->icon('star') }}<span>{{ $kit->s('Use my account') }}</span></button>
<div class="c-koverlay" data-kiosk-overlay="loyalty" hidden
     data-t-add="{{ $kit->s('Add') }}" data-t-items="{{ $kit->s('items') }}" data-t-order_again="{{ $kit->s('Order again') }}"
     data-t-added_to_your_order="{{ $kit->s('Added to your order') }}" data-t-kiosk_pick_choices_again="{{ $kit->s('Pick the choices again for:') }}"
     data-t-kiosk_not_available_today="{{ $kit->s('Not available today:') }}" data-t-kiosk_loyalty_opt_in_first="{{ $kit->s('Tick the box to use your account here.') }}"
     data-t-kiosk_code_did_not_work="{{ $kit->s('That code did not work.') }}"
     data-t-kiosk_reward="{{ $kit->s('Reward: :points points for :discount off') }}"
     data-t-kiosk_reward_not_yet="{{ $kit->s('Collect :points points for :discount off') }}">
    <div class="c-kdialog" role="dialog" aria-modal="true" aria-labelledby="kl-title">
        <h2 class="c-kdialog__title" id="kl-title" tabindex="-1">{{ $kit->s('Use my account') }}</h2>
        <div data-kl-step="phone">
            <label class="c-check"><input type="checkbox" data-kl-optin> <span>{{ $kit->s('Use my account at this kiosk: my favourites, past orders and points. Nothing is shown until I confirm with the code sent to my phone.') }}</span></label>
            <label class="c-field"><span>{{ $kit->s('Phone number') }}</span><input type="tel" inputmode="tel" autocomplete="off" maxlength="20" data-kl-phone dir="ltr"></label>
            <button class="c-btn c-btn--primary c-btn--xl" type="button" data-kl-send>{{ $kit->s('Send code') }}</button>
            <label class="c-field"><span>{{ $kit->s('Or scan the pass from your account') }}</span><input type="password" autocomplete="off" data-kl-pass></label>
        </div>
        <div data-kl-step="code" hidden>
            <label class="c-field"><span>{{ $kit->s('Code from your phone') }}</span><input type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="10" data-kl-code dir="ltr"></label>
            <button class="c-btn c-btn--primary c-btn--xl" type="button" data-kl-verify>{{ $kit->s('Confirm') }}</button>
        </div>
        <div data-kl-step="me" hidden>
            <p class="c-kdialog__text"><span>{{ $kit->s('Hi') }}</span> <bdi data-kl-name></bdi> · {{ $kit->s('Points') }}: <bdi data-kl-points dir="ltr">0</bdi></p>
            <div data-kl-reward hidden>
                <p class="c-kdialog__text"><span data-kl-reward-text></span></p>
                <label class="c-check"><input type="checkbox" data-kl-redeem> <span>{{ $kit->s('Use my points on this order') }}</span></label>
            </div>
            <h3>{{ $kit->s('Your favourites') }}</h3><ul class="c-klist" data-kl-favs></ul>
            <h3>{{ $kit->s('Order again') }}</h3><ul class="c-klist" data-kl-orders></ul>
            <button class="c-btn c-btn--secondary" type="button" data-kl-notme>{{ $kit->s('Not me') }}</button>
        </div>
        <p class="c-kdialog__text" role="status" aria-live="polite" data-kl-msg></p>
        <button class="c-btn c-btn--ghost" type="button" data-kl-close>{{ $kit->s('Close') }}</button>
    </div>
</div>
@endif
