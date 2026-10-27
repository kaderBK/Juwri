<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Exceptions;

use RuntimeException;

/**
 * P3-10: a refused booking step with a stable machine code (the API error codes of kit/api §8: slot_taken,
 * hold_expired, bookings_not_open, booking_not_active, …) and a customer-facing message.
 */
final class BookingException extends RuntimeException
{
    /** @param  array<string, mixed>  $details */
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 409, public readonly array $details = [])
    {
        parent::__construct($message);
    }

    public static function slotTaken(): self
    {
        return new self('slot_taken', ___('This time was just taken. Please choose another time.'));
    }

    public static function holdExpired(): self
    {
        return new self('hold_expired', ___('Your 10-minute hold has ended. Please choose a time again.'));
    }

    public static function notOpen(): self
    {
        return new self('bookings_not_open', ___('Bookings open soon.'));
    }

    public static function notActive(): self
    {
        return new self('booking_not_active', ___('This booking can no longer be changed.'));
    }

    public static function deadlinePassed(): self
    {
        return new self('deadline_passed', ___('The time to change this booking online has passed. Please contact the business.'));
    }

    public static function invalid(string $message): self
    {
        return new self('invalid_request', $message, 422);
    }
}
