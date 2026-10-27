<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Events;

/**
 * P5-26: a SoftPOS charge for an order is captured and not yet settled. Fired on every reconcile that sees it captured
 * (charge answer, status read, provider callback) until the listener claims it, so a failed settlement is retried.
 */
final class TerminalPaymentCaptured
{
    public function __construct(
        public readonly int $paymentId,
        public readonly int $storeId,
        public readonly int $orderId,
        public readonly int $amountMinor,
        public readonly ?int $tipMinor,
        public readonly string $currency,
    ) {}
}
