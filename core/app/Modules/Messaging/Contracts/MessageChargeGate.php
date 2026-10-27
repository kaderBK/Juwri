<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Contracts;

use Juwri\Modules\Messaging\Events\MessageCostRecorded;
use Juwri\Modules\Messaging\Services\NullMessageChargeGate;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppLimitExceededException;

/**
 * P1-08b follow-up (J27): the pre-send billing gate for a WhatsApp message. Messaging asks it before a send and
 * hands the returned charge plan back, untouched, in {@see MessageCostRecorded}
 * once the message went out — Billing settles quota/wallet from that event. Messaging never calls Billing.
 *
 * Bound by Billing (plan quota + prepaid wallet); the Messaging default ({@see NullMessageChargeGate})
 * bills nothing.
 */
interface MessageChargeGate
{
    /**
     * @return array{billable: bool, category: string, est: float, sell: float, walletCharge: float} the charge plan
     *
     * @throws WhatsAppLimitExceededException when the send is not allowed (reason quota|funds|pricing — no exchange rate to price it)
     */
    public function authorize(?int $userId, string $category, bool $billable, string $to): array;

    /**
     * The message was not sent: undo what authorize() reserved for it (credit packs: a prepaid credit). Never throws.
     *
     * @param  array<string, mixed>  $plan  the charge plan authorize() returned
     */
    public function release(array $plan, ?int $userId): void;
}
