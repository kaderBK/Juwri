{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P3-06 staff PIN pad (the staff exit and the one-time device setup). The PIN is checked on the server only (KioskController);
     this is the keypad. $live = the real, hidden overlay; false = the dialog alone (the kiosk-states board). --}}
@if (! empty($live))<div class="c-koverlay" data-kiosk-overlay="pin" hidden>@endif
<div class="c-kdialog c-kdialog--pin" role="dialog" aria-modal="true" aria-labelledby="kpin-title" data-kiosk-pin>
    <span class="c-kdialog__icon">{{ $kit->icon('lock') }}</span>
    <p class="c-kdialog__kicker">{{ $kit->s('Staff only') }}</p>
    <{{ ! empty($live) ? 'h2' : 'h3' }} class="c-kdialog__title" id="kpin-title" tabindex="-1" data-kiosk-pin-title>{{ $kit->s('Enter the staff PIN') }}</{{ ! empty($live) ? 'h2' : 'h3' }}>
    <div class="c-pin__dots" role="img" aria-label="{{ $kit->s(':count of :total digits entered', ['count' => 0, 'total' => 4]) }}" data-kiosk-pin-dots>@foreach (range(1, 4) as $__d)<span class="c-pin__dot"></span>@endforeach</div>
    <p class="c-field__help c-field__error" role="alert" data-kiosk-pin-error></p>
    <div class="c-keypad" role="group" aria-label="{{ $kit->s('Number pad') }}" dir="ltr">
        @foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $__k)<button class="c-keypad__key" type="button" data-key="{{ $__k }}"><bdi>{{ $__k }}</bdi></button>@endforeach
        <span class="c-keypad__gap" aria-hidden="true"></span>
        <button class="c-keypad__key" type="button" data-key="0"><bdi>0</bdi></button>
        <button class="c-keypad__key c-keypad__key--del" type="button" data-kiosk-pin-delete aria-label="{{ $kit->s('Delete last digit') }}">{{ $kit->icon('delete') }}</button>
    </div>
    <div class="c-kdialog__actions">
        <button class="c-btn c-btn--secondary c-btn--xl" type="button" data-kiosk-pin-cancel>{{ $kit->s('Cancel') }}</button>
        <button class="c-btn c-btn--primary c-btn--xl" type="button" data-kiosk-pin-submit>{{ $kit->s('Exit kiosk') }}</button>
    </div>
</div>
@if (! empty($live))</div>@endif
