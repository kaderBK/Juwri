<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Data;

use RuntimeException;

/**
 * P3-09 (public: the headless API renders it): a refused slot or hold step with a stable machine code (kit/api §6: slot_full, hold_expired, hold_in_use,
 * too_many_holds, not_found) and a customer-facing message; the API turns it into its error envelope.
 */
final class SlotException extends RuntimeException
{
    /** @param  array<string, mixed>  $details */
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 409, public readonly array $details = [])
    {
        parent::__construct($message);
    }

    /** @param  array<string, mixed>|null  $nextFree  the next slot with room (a DeliverySlot payload), if any */
    public static function full(?array $nextFree = null): self
    {
        return new self('slot_full', ___('This delivery time is now full. Pick another time.'), 409, $nextFree !== null ? ['next_free_slot' => $nextFree] : []);
    }

    public static function holdExpired(): self
    {
        return new self('hold_expired', ___('Your 10-minute hold has ended. Please choose a time again.'));
    }

    public static function inUse(): self
    {
        return new self('hold_in_use', ___('This time is held for an order that is waiting for payment. Finish or cancel the payment first.'));
    }

    public static function tooMany(): self
    {
        return new self('too_many_holds', ___('You are holding too many times. Finish or cancel one first.'), 429);
    }

    public static function notFound(): self
    {
        return new self('not_found', ___('This time is not available.'), 404);
    }

    public static function invalid(string $field, string $message): self
    {
        return new self('validation_failed', $message, 422, ['field' => $field]);
    }
}
