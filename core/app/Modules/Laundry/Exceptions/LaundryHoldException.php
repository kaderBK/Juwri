<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Laundry\Exceptions;

/** P3-11: a laundry slot hold that cannot be made or used. `$errorCode` is the stable machine code (API-23). */
final class LaundryHoldException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    public static function slotTaken(string $field = 'pickup'): self
    {
        return new self($field === 'return' ? 'return_slot_taken' : 'slot_taken', $field === 'return'
            ? ___('That delivery time was just taken. Please pick another.')
            : ___('That pickup time was just taken. Please pick another.'));
    }

    public static function holdExpired(): self
    {
        return new self('hold_expired', ___('Your times are no longer held. Please pick them again.'), 410);
    }

    public static function notFound(): self
    {
        return new self('hold_not_found', ___('This hold was not found.'), 404);
    }

    public static function invalid(string $message): self
    {
        return new self('invalid_request', $message, 422);
    }

    public static function tooMany(): self
    {
        return new self('too_many_holds', ___('You are holding too many times. Finish or cancel one first.'), 429);
    }
}
