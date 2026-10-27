{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    C1 — reservation deposit notice. ONE shared partial @included by all 17 theme
    booking sheets (no per-theme copies). Renders a pre-submit heads-up when the store
    requires a deposit, so the diner isn't surprised by the redirect to the deposit page
    after booking (publicBook redirects there when ReservationDepositService::required).

    The exact per-party amount depends on the party-size FORM input, so at render time we
    show the base amount + the per-guest / min-party rule from the store's config.

    Theme-neutral by design (see the shared-partial-placement gotcha): no background fill,
    inherits the sheet's own text colour via currentColor, sizes with em. Pass the
    restaurant id as `rid`; falls back to $store->id.
--}}
@php
    $rid = isset($rid) ? (int) $rid : (int) (($store->id ?? 0));
    $__depoOn = $rid > 0 && \App\Services\ReservationDepositService::enabled($rid);
    $__depoBase = $__depoOn ? \App\Services\ReservationDepositService::amountFor($rid, 1) : 0.0;
@endphp
@if ($__depoOn && $__depoBase > 0)
    @php
        $__depoPer = (string) (store_options($rid, 'reservation_deposit_per') ?: 'reservation');
        $__depoMin = max(1, (int) store_options($rid, 'reservation_deposit_min_party', 1));
        $__depoCur = cur_isolate(\App\Services\ReservationDepositService::currencyFor($rid));
        $__depoDec = function_exists('currency_decimals') ? (int) currency_decimals(store_options($rid, 'currency_code') ?: null) : 2;
        $__depoNum = number_format($__depoBase, $__depoDec, '.', ',');
        $__depoAmt = currency_pos_effective($rid) === 1 ? $__depoCur . ' ' . $__depoNum : $__depoNum . ' ' . $__depoCur;
    @endphp
    <div class="reservation-deposit-notice" role="note" data-deposit-notice
         style="display:flex;gap:.5rem;align-items:flex-start;margin:.75rem 0;padding:.6rem .7rem;border:1px solid currentColor;border-radius:.55rem;font-size:.82em;line-height:1.45;opacity:.8;">
        <span aria-hidden="true">🔒</span>
        <span>
            @if ($__depoPer === 'guest')
                {{ ___('A deposit of :amount per guest is required to confirm your reservation.', ['amount' => $__depoAmt]) }}
            @else
                {{ ___('A deposit of :amount is required to confirm your reservation.', ['amount' => $__depoAmt]) }}
            @endif
            @if ($__depoMin > 1)
                {{ ___('(for parties of :n or more)', ['n' => $__depoMin]) }}
            @endif
        </span>
    </div>
@endif
