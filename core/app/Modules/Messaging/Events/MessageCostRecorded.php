<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Events;

use Juwri\Modules\Messaging\Contracts\MessageChargeGate;

/**
 * P1-08b follow-up (J27): a message went out and its cost line was written to `message_costs` (the ledger row id is
 * null only when that write failed — the event still fires so billing never depends on the ledger insert).
 *
 * Dispatched synchronously right after a successful send. Billing listens (WhatsApp quota/wallet settlement from the
 * `charge` plan its {@see MessageChargeGate} returned before the send) — this
 * replaces Messaging's direct call into Billing. Money: `unitCostMinor`/`costMinor` are decimal strings in minor
 * units of `currency` (six fractional digits), null when the provider cost is unknown.
 */
final class MessageCostRecorded
{
    /**
     * @param  array<string, mixed>  $charge  the gate's charge plan (WhatsApp); [] when nothing was authorised
     */
    public function __construct(
        public readonly ?int $messageCostId,
        public readonly string $channel,
        public readonly string $category,
        public readonly ?int $storeId,
        public readonly ?int $accountId,
        public readonly ?int $userId,
        public readonly ?string $provider,
        public readonly ?string $feature,
        public readonly ?string $template,
        public readonly ?string $country,
        public readonly int $units,
        public readonly ?string $unitCostMinor,
        public readonly ?string $costMinor,
        public readonly ?string $currency,
        public readonly array $charge = [],
    ) {}
}
