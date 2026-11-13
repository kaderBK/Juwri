<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Billing;

use Juwri\Core\Editions\Community\NoPrepaidCredits;

/**
 * Credit packs (PRICING.md §8): prepaid usage credits an owner bought (AI actions, SMS segments, WhatsApp messages),
 * for the Community modules that meter that usage (Ai, Messaging). They ask for credits only once the plan's monthly
 * allowance is used up. Billing (ee) binds its credit ledger; Community sells no credits ({@see NoPrepaidCredits}).
 *
 * A kind is `<channel>:<rate key>` (see {@see kindFor()}). Every draw is keyed by a usage key unique to one usage
 * event: drawing again with the same key draws nothing more (idempotent), and release() gives the units back when
 * the usage did not happen (the provider call or send failed).
 */
interface PrepaidCredits
{
    /**
     * The kind a usage draws from: `ai:action`; `sms:<country>` / `whatsapp:<category>:<country>` when the provider has
     * a cost for that country, else the `default` rate key (the same rule that prices the message). Null when this
     * edition sells no credits.
     */
    public function kindFor(string $channel, ?string $category = null, ?string $country = null): ?string;

    /**
     * Take $units credits of $kind for $userId (oldest expiry first). True when they were taken now or already for
     * this $usageKey; false when the owner has not enough unexpired credits (nothing is taken then).
     */
    public function draw(int $userId, string $kind, int $units, string $usageKey, ?string $feature = null): bool;

    /** Give back the credits a draw took (the usage did not happen). Idempotent; unknown keys are ignored. */
    public function release(int $userId, string $usageKey): void;
}
