{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Reservations'))
@section('page_title', ___('Reservations'))
@section('page_sub', ___('Upcoming bookings and seating history.'))

@section('page_actions')
    @if (!empty($stores) && $stores->isNotEmpty())
        @include('owner.partials.context-store-select', ['showBranch' => false])
    @endif
@endsection

@section('content')
@php
    // E1: current booking-rule settings for the settings card below.
    $resBuffer = (int) (store_options($storeId, 'reservation_buffer_minutes', \Juwri\Modules\Reservations\Services\ReservationBookingService::WINDOW_MIN) ?: \Juwri\Modules\Reservations\Services\ReservationBookingService::WINDOW_MIN);
    $resBlackoutsRaw = store_options($storeId, 'reservation_blackouts', '');
    $resBlackouts = is_string($resBlackoutsRaw) && $resBlackoutsRaw !== ''
        ? (json_encode(json_decode($resBlackoutsRaw, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '')
        : '';
    $statusVariant = fn ($s) => match ($s) {
        'confirmed' => 'success',
        'pending'   => 'warning',
        'seated'    => 'accent',
        'cancelled', 'no_show' => 'danger',
        default     => 'neutral',
    };
    // C3: anti-no-show deposit lifecycle (none/pending/paid/refunded/refund_due/kept).
    $depositVariant = fn ($s) => match ($s) {
        'paid'       => 'success',
        'pending'    => 'warning',
        'refunded'   => 'accent',
        'refund_due' => 'warning',
        'kept'       => 'neutral',
        default      => 'neutral',
    };
    $depositCell = function ($r) use ($depositVariant) {
        $s = $r->deposit_status;
        if (! $s || $s === 'none') {
            return null;
        }
        $amt = (float) $r->deposit_amount > 0 ? ' · '.price_symbol_format((float) $r->deposit_amount) : '';
        return ['variant' => $depositVariant($s), 'label' => ___($s).$amt];
    };
@endphp

<div class="ui-scope ui-res">
{{-- Summary --}}
<section class="ui-res__summary" aria-label="{{ ___('Bookings') }}">
    <div class="ui-res__lead">
        <p class="ui-overline">{{ ___('Bookings') }}</p>
        <h2 class="ui-res__title">{{ ___('Tonight, this week, and beyond') }}</h2>
        <p class="ui-orders-muted">{{ ___('Confirm pending requests, mark seated, or cancel no-shows.') }}</p>
    </div>
    <div class="ui-res__stats">
        <div class="ui-stat"><span class="ui-stat__icon"><x-ui.icon name="calendar" /></span><span class="ui-stat__value">{{ $upcoming->count() }}</span><span class="ui-stat__label">{{ ___('Upcoming') }}</span></div>
        <div class="ui-stat"><span class="ui-stat__icon"><x-ui.icon name="clock" /></span><span class="ui-stat__value">{{ $past->count() }}</span><span class="ui-stat__label">{{ ___('Past 40') }}</span></div>
    </div>
</section>

<x-ui.card :title="___('Upcoming')" variant="flush">
    @if ($upcoming->isEmpty())
        <x-ui.empty :title="___('No upcoming reservations.')" icon="calendar" />
    @else
        <div>
            <table class="ui-table">
                <thead><tr><th>{{ ___('Guest') }}</th><th>{{ ___('Date') }}</th><th class="ui-table__num">{{ ___('Party') }}</th><th>{{ ___('Table') }}</th><th>{{ ___('Status') }}</th><th>{{ ___('Deposit') }}</th><th class="ui-table__num">{{ ___('Actions') }}</th></tr></thead>
                <tbody>
                    @foreach ($upcoming as $r)
                        <tr>
                            <td data-primary>
                                <span class="ui-res__guest"><strong>{{ $r->name }}</strong>
                                @if ($r->phone)<a class="ui-order__phone ui-ltr" href="tel:{{ $r->phone }}">{{ $r->phone }}</a>@endif</span>
                            </td>
                            <td data-label="{{ ___('Date') }}"><span class="ui-ltr">{{ $r->reserved_at?->format('Y-m-d H:i') }}</span></td>
                            <td class="ui-table__num" data-label="{{ ___('Party') }}">{{ $r->party_size }}</td>
                            <td data-label="{{ ___('Table') }}">{{ $r->table_number ?? '—' }}</td>
                            <td data-label="{{ ___('Status') }}"><x-ui.pill :tone="$statusVariant($r->status)">{{ ___($r->status) }}</x-ui.pill></td>
                            <td data-label="{{ ___('Deposit') }}">
                                @php($dep = $depositCell($r))
                                @if ($dep)
                                    <span data-deposit-badge><x-ui.pill :tone="$dep['variant']">{{ $dep['label'] }}</x-ui.pill></span>
                                    @if ($r->deposit_status === 'refund_due')
                                        {{-- BL-016: owner confirms the refund was made in the provider dashboard. --}}
                                        <form action="{{ route('reservations.deposit.manual-refund', $r->id) }}" method="post" data-manual-refund
                                              data-confirm="{{ ___('Confirm you refunded this deposit in your payment provider dashboard. This records the refund and cannot be undone.') }}">
                                            @csrf
                                            <x-ui.button type="submit" variant="ghost" size="sm" icon="check">{{ ___('Mark refunded manually') }}</x-ui.button>
                                        </form>
                                    @endif
                                @else
                                    <span class="ui-orders-muted">—</span>
                                @endif
                            </td>
                            <td data-label="">
                                <div class="ui-catalog__row-actions">
                                    @foreach (['seated' => 'check', 'cancelled' => 'x'] as $st => $icon)
                                        @if ($r->status !== $st)
                                            <form action="{{ route('reservations.status', $r->id) }}" method="post">
                                                @csrf
                                                <input type="hidden" name="status" value="{{ $st }}">
                                                <button type="submit" @class(['ui-btn ui-btn--sm', 'ui-btn--success' => $st === 'seated', 'ui-btn--danger' => $st !== 'seated'])><span class="ui-btn__icon"><x-ui.icon :name="$icon" /></span>{{ ___($st) }}</button>
                                            </form>
                                        @endif
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-ui.card>

<x-ui.card :title="___('Recent history')" variant="flush">
    @if ($past->isEmpty())
        <x-ui.empty :title="___('No past reservations yet.')" icon="clock" />
    @else
        <div>
            <table class="ui-table">
                <thead><tr><th>{{ ___('Guest') }}</th><th>{{ ___('Date') }}</th><th class="ui-table__num">{{ ___('Party') }}</th><th>{{ ___('Status') }}</th><th>{{ ___('Deposit') }}</th></tr></thead>
                <tbody>
                    @foreach ($past as $r)
                        <tr>
                            <td data-primary>{{ $r->name }}</td>
                            <td data-label="{{ ___('Date') }}"><span class="ui-ltr">{{ $r->reserved_at?->format('Y-m-d H:i') }}</span></td>
                            <td class="ui-table__num" data-label="{{ ___('Party') }}">{{ $r->party_size }}</td>
                            <td data-label="{{ ___('Status') }}"><x-ui.pill :tone="$statusVariant($r->status)">{{ ___($r->status) }}</x-ui.pill></td>
                            <td data-label="{{ ___('Deposit') }}">
                                @php($dep = $depositCell($r))
                                @if ($dep)
                                    <span data-deposit-badge><x-ui.pill :tone="$dep['variant']">{{ $dep['label'] }}</x-ui.pill></span>
                                    @if ($r->deposit_status === 'refund_due')
                                        {{-- BL-016: owner confirms the refund was made in the provider dashboard. --}}
                                        <form action="{{ route('reservations.deposit.manual-refund', $r->id) }}" method="post" data-manual-refund
                                              data-confirm="{{ ___('Confirm you refunded this deposit in your payment provider dashboard. This records the refund and cannot be undone.') }}">
                                            @csrf
                                            <x-ui.button type="submit" variant="ghost" size="sm" icon="check">{{ ___('Mark refunded manually') }}</x-ui.button>
                                        </form>
                                    @endif
                                @else
                                    <span class="ui-orders-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-ui.card>

{{-- E1: booking rules — clash buffer + blackout windows. Persists via the
     ReservationController, kept out of the big posts/edit form on purpose. --}}
@if ($storeId)
@php($blackoutHelp = ___('JSON array. Use {"date":"Y-m-d"} for a whole day or {"from":"Y-m-d H:i","to":"Y-m-d H:i"} for a window. Leave empty for none.'))
@php($blackoutExample = '[{"date":"2026-12-25"},{"from":"2026-12-31 18:00","to":"2027-01-01 02:00"}]')
<x-ui.card :title="___('Booking rules')">
    <form action="{{ route('reservations.settings') }}" method="post" class="ui-form-grid">
        @csrf
        <input type="hidden" name="store_id" value="{{ $storeId }}">
        <input type="hidden" name="settings_form" value="buffer">
        <x-ui.field name="reservation_buffer_minutes" type="number" :label="___('Double-book buffer (minutes)')" :value="$resBuffer" min="0" max="1440"
                    :help="___('Reject a second booking on the same table within this many minutes.')" />
        <div class="ui-form-grid__full">
            <x-ui.field name="blackouts" type="textarea" :rows="4" :label="___('Blackout dates / windows')" :value="$resBlackouts" class="ui-ltr ui-res__mono"
                        :placeholder="$blackoutExample" :help="$blackoutHelp" />
        </div>
        <div class="ui-form-grid__full">
            <x-ui.button type="submit" icon="check">{{ ___('Save booking rules') }}</x-ui.button>
        </div>
    </form>
</x-ui.card>

{{-- C7: Google — per-store reserve-with-Google + ordering settings. Dark by
     default (platform integration off until an owner wires Actions Center creds);
     the card explains its state and never implies bookings are live prematurely. --}}
<x-ui.card :title="___('Google')">

    {{-- raw php tags on purpose: the Blade block directive would pair with the inline php lines above --}}
    <?php
        $lastDrop = $rwg['lastDrop'] ?? null;
        $dropPill = match (is_array($lastDrop) ? ($lastDrop['status'] ?? null) : null) {
            'ok'      => ['success', ___('Sent')],
            'partial' => ['warning', ___('Partly sent')],
            'failed'  => ['danger', ___('Failed')],
            'written' => ['warning', ___('Written, not uploaded')],
            default   => ['neutral', ___('Not run yet')],
        };
        $dropProblems = is_array($lastDrop) ? (int) ($lastDrop['problems'] ?? 0) : 0;
    ?>

    @if (!($rwg['platformOn'] ?? false))
        <x-ui.banner class="ui-res__note">{{ ___('The Reserve with Google integration is not live yet — settings are saved and will apply once the platform connects the Actions Center account.') }}</x-ui.banner>
    @else
        <div class="ui-cluster ui-res__rwg-state">
            <x-ui.pill :tone="($rwg['driver'] ?? 'fake') === 'real' ? 'success' : 'warning'">{{ ($rwg['driver'] ?? 'fake') === 'real' ? ___('Production') : ___('Sandbox') }}</x-ui.pill>
            @if (!empty($rwg['feedAt']))
                <small class="ui-muted">{{ ___('Last feed generated') }}: {{ $rwg['feedAt']->diffForHumans() }}</small>
            @endif
        </div>
    @endif

    <dl class="ui-facts ui-res__rwg-drop">
        <dt>{{ ___('Last feed run') }}</dt>
        <dd>
            <span class="ui-cluster ui-res__rwg-drop__status">
                <x-ui.pill :tone="$dropPill[0]">{{ $dropPill[1] }}</x-ui.pill>
                @if (!empty($lastDrop['at']))
                    <small class="ui-muted">{{ $lastDrop['at']->diffForHumans() }}</small>
                @endif
            </span>
        </dd>
        @if ($dropProblems > 0)
            <dt>{{ ___('Problems') }}</dt>
            <dd><span class="ui-muted">{{ ___n('problem found in the feed', (int) $dropProblems) }}</span></dd>
        @endif
    </dl>

    {{-- P5-30: every branch is its own Google merchant — per-branch merchant id,
         place id and eligibility. Hidden until a store is selected and only shown
         when the store actually has branches. --}}
    @if (!empty($rwg['branches']))
        <section class="ui-res__google-branches" aria-label="{{ ___('Google branches') }}">
            <p class="ui-overline">{{ ___('Google branches') }}</p>
            <p class="ui-orders-muted">{{ ___('Each branch is its own Google merchant. Set the branch\'s place ID so Google links bookings and orders to the right listing.') }}</p>
            <div class="ui-table-wrap" tabindex="0" role="region" aria-label="{{ ___('Google branches') }}">
                <table class="ui-table">
                    <caption class="ui-visually-hidden">{{ ___('Google branches') }}</caption>
                    <thead>
                    <tr>
                        <th scope="col">{{ ___('Branch') }}</th>
                        <th scope="col">{{ ___('Google merchant ID') }}</th>
                        <th scope="col">{{ ___('Google place ID') }}</th>
                        <th scope="col">{{ ___('Google eligibility') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($rwg['branches'] as $b)
                        <tr>
                            <td data-primary>{{ $b['name'] }}</td>
                            <td data-label="{{ ___('Google merchant ID') }}">
                                @if ($b['merchant_id'])
                                    <code dir="ltr" class="ui-ltr">{{ $b['merchant_id'] }}</code>
                                @else
                                    <span class="ui-orders-muted">{{ ___('Not published') }}</span>
                                @endif
                            </td>
                            <td data-label="{{ ___('Google place ID') }}">
                                @if ($b['place_id'])
                                    <span class="ui-cluster">
                                        <span>{{ ___('Place ID set') }}</span>
                                        <code dir="ltr" class="ui-ltr">{{ $b['place_id'] }}</code>
                                    </span>
                                @else
                                    <span class="ui-orders-muted">{{ ___('Place ID missing') }}</span>
                                @endif
                            </td>
                            <td data-label="{{ ___('Google eligibility') }}">
                                @if ($b['eligible'])
                                    <x-ui.pill tone="success">{{ ___('Eligible for Google') }}</x-ui.pill>
                                @else
                                    <span class="ui-orders-muted">{{ ___('Not eligible: the branch must be active and have coordinates.') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <form action="{{ route('reservations.settings') }}" method="post" class="ui-form-grid ui-res__rwg">
        @csrf
        <input type="hidden" name="store_id" value="{{ $storeId }}">
        <input type="hidden" name="settings_form" value="rwg">

        <fieldset class="ui-form-grid ui-form-grid__full" style="margin: 0; padding: 0; border: 0; min-inline-size: 0">
            <legend class="ui-visually-hidden">{{ ___('Google') }}</legend>

            <div class="ui-form-grid__full">
                <x-ui.switch name="rwg_reservations_enabled" id="rwgEnabled" :checked="$rwg['enabled'] ?? false"
                             :label="___('List this :noun for reservations on Google', ['noun' => store_noun(null, 'lower')])" />
            </div>

            <div class="ui-form-grid__full">
                <x-ui.field name="google_place_id" :label="___('Google place ID')" :value="old('google_place_id', $rwg['placeId'] ?? '')" maxlength="255"
                            :help="___('Optional. The place ID of your Google Maps listing, so Google links bookings to the right listing.')" />
            </div>

            <div class="ui-form-grid__full">
                <x-ui.switch name="google_ordering_enabled" id="rwgOrdering" :checked="$rwg['ordering'] ?? false"
                             :label="___('Let customers order on Google')" />
            </div>

            <div class="ui-form-grid__full">
                <x-ui.field name="reservation_cancel_window_minutes" type="number" :label="___('Cancel window (minutes)')"
                            :value="$rwg['cancelWindow'] ?? 0" min="0" max="10080"
                            :help="___('Guests cannot cancel from Google inside this window before the booking time. 0 means no limit.')" />
            </div>

            <x-ui.field name="reservation_slot_interval" type="number" :label="___('Slot interval (min)')" :value="$rwg['interval'] ?? 30" min="5" max="240" />
            <x-ui.field name="reservation_covers_per_slot" type="number" :label="___('Covers per slot')" :value="$rwg['covers'] ?? 0" min="0" max="100000" :help="___('0 = unlimited')" />
            <x-ui.field name="reservation_max_party" type="number" :label="___('Max party size')" :value="$rwg['maxParty'] ?? 8" min="1" max="100" />
            <x-ui.field name="reservation_horizon_days" type="number" :label="___('Booking horizon (days)')" :value="$rwg['horizon'] ?? 30" min="1" max="365" />

            @if (($rwg['enabled'] ?? false) && ($rwg['coversUnlimited'] ?? false))
                <div class="ui-form-grid__full">
                    <x-ui.banner tone="warning">{{ ___('Set a positive “covers per slot” to publish bookable times to Google. With unlimited covers no availability is sent.') }}</x-ui.banner>
                </div>
            @endif

            @if ($rwg['ordering'] ?? false)
                <div class="ui-form-grid__full">
                    <x-ui.banner>{{ ___('Ordering on Google applies once your Google ordering link is live.') }}</x-ui.banner>
                </div>
            @endif
        </fieldset>

        <div class="ui-form-grid__full">
            <x-ui.button type="submit" icon="check">{{ ___('Save Google settings') }}</x-ui.button>
        </div>
    </form>
</x-ui.card>
@endif
</div>
@endsection
