<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Data;

use Juwri\Modules\Bookings\Contracts\BookingApi;
use RuntimeException;

/**
 * P3-06b phase D (public: the headless API renders it): a refused booking step with the stable machine code of the
 * kit API (kit/api README §6: slot_taken, hold_expired, hold_in_use, price_changed, past_deadline, booking_not_active,
 * bookings_not_open, idempotency_conflict, validation_failed, out_of_zone, rate_limited, not_found) and a
 * customer-facing message. `details.next_free_slot` (slot_taken, hold_expired) is a slot row of
 * {@see BookingApi::slots()}.
 */
final class BookingRefusal extends RuntimeException
{
    /** @param  array<string, mixed>  $details */
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 409, public readonly array $details = [], public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
