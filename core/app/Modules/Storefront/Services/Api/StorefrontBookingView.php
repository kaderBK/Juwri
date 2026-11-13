<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Api;

use Carbon\CarbonImmutable;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Modules\Bookings\Contracts\BookingApi;
use Juwri\Modules\Bookings\Data\BookingRefusal;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Bookings\Models\BookingHold;
use Juwri\Modules\Payments\Contracts\CustomerGateways;
use Juwri\Modules\Storefront\Services\ViewModel\MoneyFormat;

/**
 * P3-06b phase D — the booking payloads of the headless API (kit/api `BookingStaffResponse`, `BookingDaysResponse`,
 * `BookingSlotsResponse`, `BookingSlot`, `Hold`, `QuoteResponse` for services, `BookingRef`, `BookingCreated`,
 * `BookingResponse`, `PaymentState`): ISO times with the store's offset, money as Money, labels in the request
 * language, public ids only. The booking engine decides; this class only shapes.
 */
final class StorefrontBookingView
{
    /** How long a services quote stands (as the order quote). */
    private const QUOTE_MINUTES = 5;

    public function __construct(private readonly BookingApi $bookings, private readonly CustomerGateways $gateways) {}

    /**
     * @param  list<array{id: string, name: string, role: string, photo: string, service_ids: list<string>}>  $staff
     * @return array<string, mixed>
     */
    public function staff(array $staff): array
    {
        return [
            'staff' => array_map(fn (array $s) => array_filter([
                'id' => $s['id'],
                'name' => $s['name'],
                'role' => $s['role'] !== '' ? $s['role'] : null,
                'photo' => $s['photo'] !== '' ? ['src' => $s['photo'], 'alt' => $s['name']] : null,
                'service_ids' => $s['service_ids'],
            ], fn ($v) => $v !== null), $staff),
            'any_staff' => true,
        ];
    }

    /**
     * @param  list<array{date: string, state: string, first_slot_at: CarbonImmutable|null}>  $days
     * @return array<string, mixed>
     */
    public function days(Store $store, array $days): array
    {
        $tz = $this->bookings->timezone($store);

        return [
            'timezone' => $tz,
            'days' => array_map(fn (array $d) => [
                'date' => $d['date'],
                'state' => $d['state'],
                'first_slot_at' => $d['first_slot_at']?->setTimezone($tz)->toIso8601String(),
                'display' => $this->dayDisplay($d['date'], $tz, $d['state']),
            ], $days),
        ];
    }

    /**
     * @param  array{duration_min: int, slots: list<array<string, mixed>>}  $listing
     * @param  list<array{date: string, state: string, first_slot_at: CarbonImmutable|null}>  $strip
     * @param  array<string, mixed>|null  $nextFree
     * @return array<string, mixed>
     */
    public function slots(Store $store, string $date, array $listing, array $strip, ?Booking $reschedule, ?array $nextFree): array
    {
        $tz = $this->bookings->timezone($store);
        $slots = array_map(fn (array $s) => $this->slot($store, $s), $listing['slots']);
        $groups = [];
        foreach ($slots as $slot) {
            $hour = (int) CarbonImmutable::parse($slot['starts_at'])->setTimezone($tz)->format('G');
            $part = $hour < 12 ? ___('Morning') : ($hour < 17 ? ___('Afternoon') : ___('Evening'));
            $groups[$part][] = $slot['display'];
        }
        $title = CarbonImmutable::createFromFormat('!Y-m-d', $date, $tz);
        $body = [
            'date' => $date,
            'timezone' => $tz,
            'total_duration_min' => $listing['duration_min'],
            'slots' => $slots,
            'picker' => array_filter([
                'title' => $title !== null ? $title->translatedFormat('F Y') : null,
                'days' => array_map(fn (array $d) => $this->dayDisplay($d['date'], $tz, $d['date'] === $date && $d['state'] !== 'closed' ? 'selected' : $d['state']), $strip),
                'groups' => array_map(fn (string $label, array $items) => ['label' => $label, 'slots' => $items], array_keys($groups), array_values($groups)),
                'timezone_note' => self::timezoneNote($tz),
            ], fn ($v) => $v !== null),
        ];
        if ($reschedule !== null) {
            $body['reschedule_of'] = (string) $reschedule->public_id;
        }
        if ($nextFree !== null) {
            $body['next_free_slot'] = $this->slot($store, $nextFree);
        }

        return $body;
    }

    /**
     * @param  array{id: string, staff_id: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable, free: bool}|array<string, mixed>  $slot
     * @return array<string, mixed> BookingSlot
     */
    public function slot(Store $store, array $slot): array
    {
        $tz = $this->bookings->timezone($store);
        /** @var CarbonImmutable $start */
        $start = $slot['starts_at'];
        /** @var CarbonImmutable $end */
        $end = $slot['ends_at'];
        $state = $slot['free'] ? 'available' : 'full';
        $display = ['id' => (string) $slot['id'], 'label' => $start->setTimezone($tz)->format('H:i'), 'state' => $state];
        if (! $slot['free']) {
            $display['sub'] = ___('Taken');
        }

        return [
            'id' => (string) $slot['id'],
            'staff_id' => (string) $slot['staff_id'],
            'starts_at' => $start->setTimezone($tz)->toIso8601String(),
            'ends_at' => $end->setTimezone($tz)->toIso8601String(),
            'state' => $state,
            'display' => $display,
        ];
    }

    /** @return array<string, mixed> Hold */
    public function hold(Store $store, BookingHold $hold, string $token, string $slotId, ?Booking $reschedule): array
    {
        $out = [
            'id' => (string) $hold->public_id,
            'token' => $token,
            'kind' => 'booking_slot',
            'slot_id' => $slotId,
            'expires_at' => CarbonImmutable::instance($hold->expires_at)->setTimezone($this->bookings->timezone($store))->toIso8601String(),
            'ttl_seconds' => BookingHold::MINUTES * 60,
            'hold_note' => ___('We hold this time for 10 minutes.'),
        ];
        if ($reschedule !== null) {
            $out['booking_id'] = (string) $reschedule->public_id;
        }

        return $out;
    }

    /**
     * The services quote: lines, the total, and the deposit / due-later split as informational rows (contract §5.2).
     *
     * @param  array{currency: string, lines: list<array<string, mixed>>, total_minor: int, deposit_minor: int, due_later_minor: int, duration_min: int}  $q
     * @param  list<string>  $lineIds  the client's line ids, in order
     * @return array<string, mixed> View Model Quote
     */
    public function quote(Store $store, array $q, array $lineIds = []): array
    {
        $money = $this->money($store, $q['currency']);
        $lines = [];
        foreach ($q['lines'] as $i => $line) {
            $lines[] = [
                'id' => $lineIds[$i] ?? 'ln_'.($i + 1),
                'item_id' => (string) $line['id'],
                'name' => (string) $line['name'],
                'qty' => 1,
                'line_total' => $money((int) $line['price_minor']),
                'choices_label' => ___(':minutes min', ['minutes' => (string) $line['duration_min']]),
            ];
        }
        $rows = [['key' => 'subtotal', 'label' => ___('Total'), 'amount' => $money($q['total_minor']), 'kind' => 'subtotal']];
        if ($q['deposit_minor'] > 0) {
            $rows[] = ['key' => 'deposit', 'label' => ___('Due now (deposit)'), 'amount' => $money($q['deposit_minor']), 'kind' => 'deposit', 'muted' => true, 'informational' => true];
            $rows[] = ['key' => 'due_later', 'label' => ___('Due at the visit'), 'amount' => $money($q['due_later_minor']), 'kind' => 'due_later', 'muted' => true, 'informational' => true];
        }

        return ['lines' => $lines, 'rows' => $rows, 'total' => $money($q['total_minor']), 'total_label' => ___('Total')];
    }

    /**
     * QuoteResponse for a services cart. A deposit is paid on the core-hosted page (D8, API-2); without one the visit
     * is paid at the venue.
     *
     * @param  array{currency: string, lines: list<array<string, mixed>>, total_minor: int, deposit_minor: int, due_later_minor: int, duration_min: int}  $q
     * @param  list<string>  $lineIds
     * @param  list<string>  $requires
     * @return array<string, mixed>
     */
    public function quoteResponse(Store $store, array $q, array $lineIds, ?string $methodId, string $hash, array $requires, bool $kiosk = false): array
    {
        if ($kiosk) {
            // API-15: a kiosk walk-in has no deposit step — the whole total is paid at the visit
            $q['deposit_minor'] = 0;
            $q['due_later_minor'] = $q['total_minor'];
        }
        $money = $this->money($store, $q['currency']);
        $modes = $this->paymentModes($store, $q, $kiosk);
        $methods = [];
        if (in_array('hosted', $modes, true)) {
            $methods[] = ['id' => 'deposit', 'label' => ___('Pay a deposit now'), 'sub' => ___(':amount at the visit', ['amount' => $money($q['due_later_minor'])['formatted']]),
                'amount' => $money($q['deposit_minor']), 'kind' => 'deposit'];
        }
        if (in_array('pay_later', $modes, true)) {
            $methods[] = ['id' => 'venue', 'label' => ___('Pay at the visit'), 'sub' => ___('Cash or card on the day'), 'kind' => 'venue'];
        }
        $chosen = $methodId !== null && in_array($methodId, array_column($methods, 'id'), true) ? $methodId : ($methods[0]['id'] ?? null);
        foreach ($methods as $i => $method) {
            if ($method['id'] === $chosen) {
                $methods[$i]['selected'] = true;
            }
        }

        return [
            'quote' => $this->quote($store, $q, $lineIds),
            'quote_hash' => $hash,
            'expires_at' => now()->addMinutes(self::QUOTE_MINUTES)->setTimezone($this->bookings->timezone($store))->toIso8601String(),
            'payment_methods' => $methods,
            'warnings' => [],
            'requires' => $requires,
            'payment_modes' => $modes,
        ];
    }

    /**
     * D8 / API-2: a deposit is taken on the hosted page (the store's own gateway); a booking without one is paid at the
     * visit. At the kiosk there is no deposit step (API-15).
     *
     * @param  array{deposit_minor: int}  $q
     * @return list<string>
     */
    public function paymentModes(Store $store, array $q, bool $kiosk = false): array
    {
        if ($q['deposit_minor'] <= 0 || $kiosk) {
            return ['pay_later'];
        }

        // the deposit is paid on the store's own gateway (P1-23): none switched on, no way to pay it
        return $this->gateways->forStore((int) $store->id)->isNotEmpty() ? ['hosted'] : [];
    }

    /**
     * `qh_` + 32 hex of the priced inputs and the money (the client echoes it on POST /bookings).
     *
     * @param  array{currency: string, lines: list<array<string, mixed>>, total_minor: int, deposit_minor: int}  $q
     */
    public static function hash(Store $store, string $location, array $q): string
    {
        return 'qh_'.substr(hash('sha256', json_encode([
            (int) $store->id, $location, array_column($q['lines'], 'id'), array_column($q['lines'], 'price_minor'),
            $q['total_minor'], $q['deposit_minor'], $q['currency'],
        ]) ?: ''), 0, 32);
    }

    /** @return array<string, mixed> BookingRef */
    public function ref(Store $store, Booking $booking): array
    {
        $tz = $this->bookings->timezone($store);
        $urls = $this->bookings->urls($booking, $store);
        $deadline = CarbonImmutable::instance($booking->cancel_deadline_at)->setTimezone($tz)->toIso8601String();

        return [
            'public_id' => (string) $booking->public_id,
            'number' => (string) $booking->number,
            'status' => (string) $booking->status,
            'starts_at' => CarbonImmutable::instance($booking->starts_at)->setTimezone($tz)->toIso8601String(),
            'ends_at' => CarbonImmutable::instance($booking->ends_at)->setTimezone($tz)->toIso8601String(),
            'staff_id' => $this->bookings->staffId($booking),
            'service_ids' => $this->bookings->serviceIds($booking),
            'manage_token' => $this->bookings->manageToken($booking),
            'manage_url' => $urls['manage'],
            'ics_url' => $urls['calendar'],
            'cancel_deadline_at' => $deadline,
            // API-9: one deadline; null when it cannot be rescheduled (not confirmed)
            'reschedule_deadline_at' => $booking->status === 'confirmed' ? $deadline : null,
        ];
    }

    /** @return array<string, mixed> PaymentState of a booking's deposit */
    public function payment(Store $store, Booking $booking): array
    {
        $money = $this->money($store, (string) $booking->currency);
        if ((int) $booking->deposit_minor <= 0) {
            return ['mode' => 'pay_later', 'status' => 'pay_later', 'next_action' => null];
        }
        $amount = $money((int) $booking->deposit_minor);
        $tz = $this->bookings->timezone($store);

        return match ((string) $booking->deposit_status) {
            'pending' => $booking->status === 'pending_payment' && $booking->payment_expires_at !== null && $booking->payment_expires_at->isFuture()
                ? ['mode' => 'hosted', 'status' => 'pending', 'next_action' => [
                    'type' => 'redirect',
                    'url' => $this->bookings->urls($booking, $store)['pay'],
                    'expires_at' => CarbonImmutable::instance($booking->payment_expires_at)->setTimezone($tz)->toIso8601String(),
                ], 'amount_now' => $amount]
                : ['mode' => 'hosted', 'status' => 'failed', 'next_action' => null],
            'paid' => ['mode' => 'hosted', 'status' => 'paid', 'next_action' => null, 'amount_now' => $amount],
            'refunded' => ['mode' => 'hosted', 'status' => 'refunded', 'next_action' => null, 'amount_now' => $amount, 'refunded_amount' => $amount],
            'kept' => ['mode' => 'hosted', 'status' => 'kept', 'next_action' => null, 'amount_now' => $amount],
            'received_late' => ['mode' => 'hosted', 'status' => 'received_late', 'next_action' => null, 'amount_now' => $amount],
            default => ['mode' => 'hosted', 'status' => 'failed', 'next_action' => null],
        };
    }

    /**
     * BookingResponse (also BookingCreated with `replayed`).
     *
     * @return array<string, mixed>
     */
    public function booking(Store $store, Booking $booking, ?string $policyNote = null): array
    {
        $out = [
            'booking' => $this->ref($store, $booking),
            'quote' => $this->quote($store, $this->bookedQuote($booking)),
            'payment' => $this->payment($store, $booking),
        ];
        if ($policyNote !== null) {
            $out['policy_note'] = $policyNote;
        }

        return $out;
    }

    /**
     * The cancellation policy of a confirmed booking in words (book §7, D7/D8: the deposit copy follows the store's
     * deposit refund switch), or null when nothing can be changed any more.
     */
    public function policy(Store $store, Booking $booking): ?string
    {
        if ($booking->status !== 'confirmed') {
            return null;
        }
        $when = CarbonImmutable::instance($booking->cancel_deadline_at)->setTimezone($this->bookings->timezone($store))->translatedFormat('D j M, H:i');
        if ((int) $booking->deposit_minor <= 0) {
            return ___('Free cancellation or changes until :when.', ['when' => $when]);
        }

        return $this->bookings->rules($store)['deposit_refunds']
            ? ___('Free cancellation or changes until :when. After that the deposit is kept.', ['when' => $when])
            : ___('Deposits are not refunded. You can reschedule until :when.', ['when' => $when]);
    }

    /** The cancel outcome in words (D7/D8). */
    public function policyNote(Store $store, Booking $booking, string $outcome): string
    {
        $deposit = $this->money($store, (string) $booking->currency)((int) $booking->deposit_minor)['formatted'];

        return match ($outcome) {
            'refunded' => ___('Cancelled before the deadline, so your :amount deposit is refunded.', ['amount' => $deposit]),
            'kept' => ___('Your booking is cancelled. The :amount deposit is not refunded.', ['amount' => $deposit]),
            'refund_pending' => ___('Your booking is cancelled. Your :amount deposit refund is being processed.', ['amount' => $deposit]),
            default => ___('Your booking is cancelled.'),
        };
    }

    /** The API error of a refused booking step. */
    public function refusal(Store $store, BookingRefusal $e): StorefrontApiException
    {
        if ($e->errorCode === 'validation_failed') {
            return StorefrontApiException::validation([$e->field ?? 'request' => [$e->getMessage()]]);
        }
        $details = null;
        if (isset($e->details['next_free_slot']) && is_array($e->details['next_free_slot'])) {
            $details = ['next_free_slot' => $this->slot($store, $e->details['next_free_slot'])];
        }
        $headers = $e->status === 429 ? ['Retry-After' => '60'] : [];

        return new StorefrontApiException($e->status, $e->errorCode, $e->getMessage(), null, $details, $headers);
    }

    /**
     * The booking's own prices (as booked), as a quote.
     *
     * @return array{currency: string, lines: list<array<string, mixed>>, total_minor: int, deposit_minor: int, due_later_minor: int, duration_min: int}
     */
    private function bookedQuote(Booking $booking): array
    {
        $booking->loadMissing('lines');
        $ids = $this->bookings->serviceIds($booking);
        $lines = [];
        foreach ($booking->lines->values() as $i => $line) {
            $lines[] = ['id' => $ids[$i] ?? (string) $booking->public_id, 'name' => (string) $line->name, 'price_minor' => (int) $line->price_minor,
                'deposit_minor' => (int) $line->deposit_minor, 'duration_min' => (int) $line->duration_min];
        }

        return [
            'currency' => (string) $booking->currency,
            'lines' => $lines,
            'total_minor' => (int) $booking->total_minor,
            'deposit_minor' => (int) $booking->deposit_minor,
            'due_later_minor' => (int) $booking->total_minor - (int) $booking->deposit_minor,
            'duration_min' => (int) $booking->duration_min,
        ];
    }

    /** @return \Closure(int): array{minor: int, currency: string, formatted: string} */
    private function money(Store $store, string $currency): \Closure
    {
        $format = new MoneyFormat($store);
        $currency = strtoupper($currency);

        return fn (int $minor) => $currency === $format->currency
            ? $format->money($minor)
            : ['minor' => $minor, 'currency' => $currency, 'formatted' => $currency.' '.number_format(Money::majorFromMinor($minor, $currency), currency_decimals($currency), '.', ',')];
    }

    /** @return array{id: string, label: string, sub: string, state: string} */
    private function dayDisplay(string $date, string $tz, string $state): array
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, $tz) ?: CarbonImmutable::now($tz);

        return ['id' => 'd_'.$date, 'label' => $day->translatedFormat('D'), 'sub' => $day->format('j'), 'state' => $state];
    }

    private static function timezoneNote(string $tz): string
    {
        $parts = explode('/', $tz);
        $seconds = CarbonImmutable::now($tz)->getOffset();
        $offset = ($seconds < 0 ? '-' : '+').intdiv(abs($seconds), 3600).(abs($seconds) % 3600 !== 0 ? ':'.str_pad((string) intdiv(abs($seconds) % 3600, 60), 2, '0', STR_PAD_LEFT) : '');

        return ___('Times are in :city time (:offset)', ['city' => str_replace('_', ' ', (string) end($parts)), 'offset' => $seconds === 0 ? 'GMT' : 'GMT'.$offset]);
    }
}
