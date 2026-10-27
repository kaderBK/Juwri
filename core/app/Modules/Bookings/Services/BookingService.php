<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Modules\Bookings\Exceptions\BookingException;
use Juwri\Modules\Bookings\Models\BookableService;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Bookings\Models\BookingHold;
use Juwri\Modules\Bookings\Models\BookingLine;

/**
 * P3-10: the booking lifecycle — book from a hold, cancel, reschedule (same price, until the deadline: API-8/9),
 * complete / no-show, and the payment-window expiry of unpaid deposits (API-10). Money moves only in DepositService.
 */
final class BookingService
{
    /** Hosted payment window for a deposit (API-10). */
    public const PAYMENT_WINDOW_MINUTES = 15;

    public function __construct(private SlotEngine $engine, private HoldService $holds, private DepositService $deposits) {}

    /**
     * Turn a live hold into a booking. With a deposit the booking waits as pending_payment (its time stays blocked
     * for the 15-minute payment window); without one it is confirmed at once. Idempotent per client key.
     *
     * @param  array{name: string, phone?: string|null, email?: string|null, note?: string|null, address?: string|null, lat?: float|null, lng?: float|null, remind_email?: bool, remind_sms?: bool, remind_whatsapp?: bool, source?: string, walk_in?: bool}  $customer
     */
    public function book(Store $store, string $holdId, string $token, array $customer, ?string $clientKey = null): Booking
    {
        if ($clientKey !== null) {
            $existing = Booking::query()->where('store_id', $store->id)->where('client_key', $clientKey)->first();
            if ($existing instanceof Booking) {
                $sameHold = BookingHold::query()->where('store_id', $store->id)->where('public_id', $holdId)->value('booking_id');
                if ((int) $sameHold === (int) $existing->id) {
                    return $existing; // a resend of the same booking
                }
                throw new BookingException('idempotency_conflict', ___('This request was already used for another booking.'));
            }
        }

        $name = trim((string) $customer['name']);
        $phone = trim((string) ($customer['phone'] ?? ''));
        if ($name === '' || $phone === '') {
            throw BookingException::invalid(___('Enter your name and mobile number.'));
        }

        return DB::transaction(function () use ($store, $holdId, $token, $customer, $clientKey, $name, $phone) {
            $hold = Str::isUuid($holdId)
                ? BookingHold::query()->where('store_id', $store->id)->where('public_id', $holdId)->lockForUpdate()->first()
                : null;
            if (! $hold instanceof BookingHold || ! $hold->tokenMatches($token)) {
                throw new BookingException('hold_not_found', ___('This hold was not found.'), 404);
            }
            if ($hold->booking_id !== null || ! $hold->isLive()) {
                throw BookingException::holdExpired();
            }

            $settings = BookingSettings::for((int) $store->id);
            $services = $this->engine->services((int) $store->id, $hold->service_ids, $hold->location);
            $where = ['lat' => isset($customer['lat']) ? (float) $customer['lat'] : null, 'lng' => isset($customer['lng']) ? (float) $customer['lng'] : null];
            if ($hold->location === 'at_home') {
                if (trim((string) ($customer['address'] ?? '')) === '') {
                    throw BookingException::invalid(___('Enter the address for the visit.'));
                }
                $this->holds->assertInArea($settings, $where);
            }

            $quote = BookingQuote::for((int) $store->id, $services);
            $currency = $quote['currency'];
            $total = $quote['total_minor'];
            $deposit = $quote['deposit_minor'];
            $start = CarbonImmutable::instance($hold->starts_at);
            // lane C (kit/api API-15): a kiosk walk-in is today, at the venue, without the deposit step
            if (! empty($customer['walk_in'])) {
                if ($hold->location !== 'venue' || $start->setTimezone($settings->timezone)->toDateString() !== CarbonImmutable::now($settings->timezone)->toDateString()) {
                    throw BookingException::invalid(___('At the kiosk you can book a time today only.'));
                }
                $deposit = 0;
            }

            $booking = Booking::query()->create([
                'store_id' => (int) $store->id,
                'staff_id' => $hold->staff_id,
                'number' => $this->number((int) $store->id),
                'status' => $deposit > 0 ? 'pending_payment' : 'confirmed',
                'location' => $hold->location,
                'customer_name' => Str::limit($name, 80, ''),
                'phone' => Str::limit($phone, 30, ''),
                'email' => isset($customer['email']) && filter_var($customer['email'], FILTER_VALIDATE_EMAIL) ? Str::limit((string) $customer['email'], 120, '') : null,
                'note' => isset($customer['note']) && trim((string) $customer['note']) !== '' ? Str::limit(trim((string) $customer['note']), 200, '') : null,
                'address' => $hold->location === 'at_home' ? Str::limit(trim((string) ($customer['address'] ?? '')), 255, '') : null,
                'lat' => $hold->location === 'at_home' ? $where['lat'] : null,
                'lng' => $hold->location === 'at_home' ? $where['lng'] : null,
                'starts_at' => $start,
                'ends_at' => $hold->ends_at,
                'block_starts_at' => $hold->block_starts_at,
                'block_ends_at' => $hold->block_ends_at,
                'duration_min' => (int) $services->sum('duration_min'),
                'total_minor' => $total,
                'deposit_minor' => $deposit,
                'tax_minor' => $quote['tax_minor'],
                'currency' => $currency,
                'deposit_status' => $deposit > 0 ? 'pending' : 'none',
                'payment_expires_at' => $deposit > 0 ? now('UTC')->addMinutes(self::PAYMENT_WINDOW_MINUTES) : null,
                'cancel_deadline_at' => $start->subHours($settings->cancelWindowHours),
                'manage_nonce' => Str::random(32),
                'remind_email' => (bool) ($customer['remind_email'] ?? false),
                'remind_sms' => (bool) ($customer['remind_sms'] ?? false),
                'remind_whatsapp' => (bool) ($customer['remind_whatsapp'] ?? false),
                'source' => in_array($customer['source'] ?? 'web', ['web', 'api', 'kiosk', 'staff'], true) ? (string) ($customer['source'] ?? 'web') : 'web',
                'client_key' => $clientKey,
            ]);
            foreach ($services->values() as $i => $service) {
                BookingLine::query()->create([
                    'store_id' => (int) $store->id,
                    'booking_id' => $booking->id,
                    'service_id' => $service->id,
                    'name' => $service->name,
                    'duration_min' => $service->duration_min,
                    'price_minor' => $service->price_minor,
                    'deposit_minor' => $deposit === 0 ? 0 : $service->depositMinor(),
                    'position' => $i,
                ]);
            }

            $moved = DB::table('booking_slot_cells')->where('hold_id', $hold->id)->update([
                'hold_id' => null,
                'booking_id' => $booking->id,
                'expires_at' => $booking->payment_expires_at?->copy()->utc()->format('Y-m-d H:i:s'),
            ]);
            if ($moved === 0) {
                throw BookingException::holdExpired();
            }
            $hold->forceFill(['status' => 'used', 'booking_id' => $booking->id])->save();

            return $booking->fresh(['lines', 'staff']) ?? $booking;
        });
    }

    /**
     * Cancel. Before the deadline a paid deposit is refunded only when the store turned deposit refunds on (D7/D8);
     * otherwise (or after the deadline) it is kept. Returns what happened to the deposit: refunded|kept|none, or
     * refund_pending when the gateway layer refused the refund (recorded for the owner to retry; the customer is told).
     */
    public function cancel(Booking $booking, string $by = 'customer'): string
    {
        $outcome = DB::transaction(function () use ($booking, $by) {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isActive()) {
                throw BookingException::notActive();
            }
            $settings = BookingSettings::for((int) $locked->store_id);
            $refund = $locked->deposit_status === 'paid' && $settings->depositRefunds && $locked->beforeDeadline();
            $locked->forceFill(['status' => 'cancelled', 'cancelled_at' => now('UTC'), 'cancelled_by' => $by])->save();
            DB::table('booking_slot_cells')->where('booking_id', $locked->id)->delete();

            return $locked->deposit_status === 'paid' ? ($refund ? 'refund' : 'keep') : 'none';
        });

        $fresh = $booking->fresh() ?? $booking;
        if ($outcome === 'refund') {
            try {
                $this->deposits->refund($fresh, 'cancel');
            } catch (BookingException $e) {
                if ($e->errorCode !== 'refund_refused') {
                    throw $e;
                }
                // lane C bug fix: the booking is already cancelled (its time freed); a refused refund is recorded as
                // pending for the owner (dashboard + "Retry refund") and the customer is told it is being processed
                if ($this->deposits->recordPendingRefund($fresh, $e->getMessage())) {
                    app(BookingCustomerNotices::class)->refundPending($booking->fresh() ?? $fresh);
                }

                return 'refund_pending';
            }

            return 'refunded';
        }
        if ($outcome === 'keep') {
            $this->deposits->keep($fresh);

            return 'kept';
        }

        return 'none';
    }

    /**
     * Move a confirmed booking to the time of its reschedule hold: same services, same price, deposit carried over,
     * no money moves (API-8); only until the cancellation deadline (API-9).
     */
    public function reschedule(Booking $booking, string $holdId, string $token): Booking
    {
        return DB::transaction(function () use ($booking, $holdId, $token) {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'confirmed') {
                throw BookingException::notActive();
            }
            if (! $locked->beforeDeadline()) {
                throw BookingException::deadlinePassed();
            }
            $hold = Str::isUuid($holdId)
                ? BookingHold::query()->where('store_id', $locked->store_id)->where('public_id', $holdId)->lockForUpdate()->first()
                : null;
            if (! $hold instanceof BookingHold || ! $hold->tokenMatches($token) || (int) $hold->booking_id !== (int) $locked->id) {
                throw new BookingException('hold_not_found', ___('This hold was not found.'), 404);
            }
            if (! $hold->isLive()) {
                throw BookingException::holdExpired();
            }

            $keep = array_map(fn (int $c) => CarbonImmutable::createFromTimestampUTC($c)->format('Y-m-d H:i:s'),
                $this->engine->cells($hold->block_starts_at, $hold->block_ends_at));
            if ((int) $hold->staff_id === (int) $locked->staff_id) {
                // cells the new time shares with the old one stay the booking's; the rest of the old time is freed
                DB::table('booking_slot_cells')->where('booking_id', $locked->id)->whereNotIn('cell_at', $keep)->delete();
            } else {
                DB::table('booking_slot_cells')->where('booking_id', $locked->id)->delete();
            }
            $moved = DB::table('booking_slot_cells')->where('hold_id', $hold->id)
                ->update(['hold_id' => null, 'booking_id' => $locked->id, 'expires_at' => null]);
            if ($moved === 0 && (int) $hold->staff_id !== (int) $locked->staff_id) {
                throw BookingException::holdExpired();
            }

            $settings = BookingSettings::for((int) $locked->store_id);
            $start = CarbonImmutable::instance($hold->starts_at);
            $locked->forceFill([
                'staff_id' => $hold->staff_id,
                'starts_at' => $start,
                'ends_at' => $hold->ends_at,
                'block_starts_at' => $hold->block_starts_at,
                'block_ends_at' => $hold->block_ends_at,
                'cancel_deadline_at' => $start->subHours($settings->cancelWindowHours),
                'reminder_sent_at' => null,
            ])->save();
            $hold->forceFill(['status' => 'used'])->save();

            return $locked->fresh(['lines', 'staff']) ?? $locked;
        });
    }

    /** Owner: the visit happened. */
    public function complete(Booking $booking): void
    {
        $this->finish($booking, 'completed');
    }

    /**
     * Owner: the customer did not come. A paid deposit is kept (D8). A no-show fee, when the store turned it on
     * (off by default), is recorded on the booking for the owner to collect — there is no saved-card charge yet.
     */
    public function noShow(Booking $booking): void
    {
        $this->finish($booking, 'no_show');
        $fresh = $booking->fresh() ?? $booking;
        if ($fresh->deposit_status === 'paid') {
            $this->deposits->keep($fresh);
        }
        $settings = BookingSettings::for((int) $fresh->store_id);
        if ($settings->noShowFee && $settings->noShowFeeMinor > 0) {
            $fresh->forceFill(['no_show_fee_minor' => $settings->noShowFeeMinor])->save();
        }
    }

    /**
     * API-10: cancel unpaid bookings whose payment window has ended and free their time. A deposit paid after this
     * becomes a late payment (received_late, API-21), never a revived booking.
     */
    public function expireUnpaid(?int $staffId = null): int
    {
        $ids = Booking::query()->where('status', 'pending_payment')->where('payment_expires_at', '<=', now('UTC'))
            ->when($staffId !== null, fn ($q) => $q->where('staff_id', $staffId))
            ->limit(200)->pluck('id');
        $n = 0;
        foreach ($ids as $id) {
            $n += Booking::query()->whereKey($id)->where('status', 'pending_payment')
                ->update(['status' => 'cancelled', 'cancelled_at' => now('UTC'), 'cancelled_by' => 'system', 'updated_at' => now('UTC')]);
            DB::table('booking_slot_cells')->where('booking_id', $id)->delete();
        }

        return $n;
    }

    private function finish(Booking $booking, string $status): void
    {
        $updated = Booking::query()->whereKey($booking->id)->where('status', 'confirmed')
            ->update(['status' => $status, 'updated_at' => now('UTC')]);
        if ($updated !== 1) {
            throw BookingException::notActive();
        }
        // the time stays blocked: a finished visit is history, not free time
    }

    /** A short booking number, unique per store ("SB-4821" style: letters + digits, no 0/O/1/I). */
    private function number(int $storeId): string
    {
        for ($i = 0; $i < 8; $i++) {
            $candidate = 'B-'.substr(str_replace(['0', 'O', '1', 'I', 'L'], '', strtoupper(Str::random(16))), 0, 6);
            if (strlen($candidate) === 8 && ! Booking::query()->where('store_id', $storeId)->where('number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException('booking number space exhausted');
    }
}
